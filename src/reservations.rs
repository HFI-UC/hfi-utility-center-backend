use crate::auth::{consume_csrf, current_admin};
use crate::util::{epoch_to_local, local_to_json, token, token_hash};
use crate::*;

pub(crate) async fn create_reservation(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<CreateReservation>,
) -> Response {
    if !consume_csrf(&state, &headers).await {
        return fail(StatusCode::FORBIDDEN, "CSRF token missing or invalid.");
    }
    if !payload.email.contains('@') {
        return fail(StatusCode::BAD_REQUEST, "Invalid email format.");
    }
    if payload.reason.trim().is_empty() {
        return fail(StatusCode::BAD_REQUEST, "Reservation reason is required.");
    }
    let Some(start) = epoch_to_local(payload.start_time) else {
        return fail(StatusCode::BAD_REQUEST, "Invalid start time.");
    };
    let Some(end) = epoch_to_local(payload.end_time) else {
        return fail(StatusCode::BAD_REQUEST, "Invalid end time.");
    };
    let now = Shanghai
        .from_utc_datetime(&Utc::now().naive_utc())
        .naive_local();
    if start >= end {
        return fail(
            StatusCode::BAD_REQUEST,
            "Start time must be before end time.",
        );
    }
    if end - start > Duration::hours(2) {
        return fail(
            StatusCode::BAD_REQUEST,
            "Reservation duration must not exceed 2 hours.",
        );
    }
    if start <= now {
        return fail(StatusCode::BAD_REQUEST, "Start time must be in the future.");
    }
    if start > now + Duration::days(30) {
        return fail(
            StatusCode::BAD_REQUEST,
            "Start time must be within 30 days.",
        );
    }
    if let Some(purpose) = &payload.purpose_type
        && !["personal", "class", "club"].contains(&purpose.as_str())
    {
        return fail(StatusCode::BAD_REQUEST, "Invalid purpose type.");
    }
    let mut tx = match state.db.begin().await {
        Ok(tx) => tx,
        Err(_) => {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to start reservation transaction",
            );
        }
    };
    if sqlx::query("SELECT pg_advisory_xact_lock($1)")
        .bind(payload.room as i64)
        .execute(&mut *tx)
        .await
        .is_err()
    {
        return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to lock room");
    }
    let room = sqlx::query("SELECT id,name,enabled FROM room WHERE id=$1")
        .bind(payload.room)
        .fetch_optional(&mut *tx)
        .await
        .unwrap_or_default();
    let Some(room) = room else {
        return fail(StatusCode::NOT_FOUND, "Room not found.");
    };
    if !room.get::<Option<bool>, _>("enabled").unwrap_or(true) {
        return fail(StatusCode::BAD_REQUEST, "Room not found or disabled.");
    }
    let privileged_group = if let Some(class_id) = payload.class_id {
        let class = sqlx::query("SELECT c.id,COALESCE(cp.\"isPrivileged\",FALSE) AS privileged FROM class c LEFT JOIN campus cp ON cp.id=c.\"campusId\" WHERE c.id=$1")
            .bind(class_id)
            .fetch_optional(&mut *tx)
            .await
            .ok()
            .flatten();
        let Some(class) = class else {
            return fail(StatusCode::BAD_REQUEST, "Class not found.");
        };
        class.get::<bool, _>("privileged")
    } else {
        false
    };
    let priority_admin = if privileged_group {
        sqlx::query_as::<_, AdminRow>(
            "SELECT id,email,name,password FROM admin WHERE lower(email)=lower($1) LIMIT 1",
        )
        .bind(payload.email.trim())
        .fetch_optional(&mut *tx)
        .await
        .ok()
        .flatten()
    } else {
        None
    };
    if privileged_group && priority_admin.is_none() {
        return fail(
            StatusCode::FORBIDDEN,
            "Office Teachers reservations require an administrator email address.",
        );
    }
    if priority_admin.is_none()
        && (payload.student_id.len() != 10
            || !payload.student_id.starts_with("GJ")
            || !payload.student_id[2..].chars().all(|c| c.is_ascii_digit()))
    {
        return fail(StatusCode::BAD_REQUEST, "Invalid student ID format.");
    }
    let policy_rows = sqlx::query(
        "SELECT days,\"startTime\",\"endTime\" FROM roompolicy WHERE \"roomId\"=$1 AND enabled=TRUE",
    )
    .bind(payload.room)
    .fetch_all(&mut *tx)
    .await
    .unwrap_or_default();
    let weekday = i64::from(start.weekday().num_days_from_sunday());
    let requested_start = i64::from(start.hour() * 60 + start.minute());
    let requested_end = i64::from(end.hour() * 60 + end.minute());
    let inside_bookable_hours = start.date() == end.date()
        && policy_rows.iter().any(|row| {
            policy_allows_range(
                row.get("days"),
                row.get("startTime"),
                row.get("endTime"),
                weekday,
                requested_start,
                requested_end,
            )
        });
    if priority_admin.is_none() && !inside_bookable_hours {
        return fail(
            StatusCode::BAD_REQUEST,
            "Requested time is outside the room's bookable hours.",
        );
    }
    if priority_admin.is_none() {
        let conflict: i64 = sqlx::query_scalar("SELECT count(*) FROM reservation WHERE \"roomId\"=$1 AND status NOT IN ('rejected','cancelled') AND \"startTime\" < $2 AND \"endTime\" > $3").bind(payload.room).bind(end).bind(start).fetch_one(&mut *tx).await.unwrap_or(1);
        if conflict > 0 {
            return fail(
                StatusCode::CONFLICT,
                "Start or end time conflicts with existing reservation.",
            );
        }
        let day_start = start.date().and_hms_opt(0, 0, 0).unwrap();
        let day_end = day_start + Duration::days(1);
        let count: i64 = sqlx::query_scalar("SELECT count(*) FROM reservation WHERE lower(email)=lower($1) AND \"startTime\" >= $2 AND \"endTime\" <= $3 AND status <> 'cancelled'").bind(&payload.email).bind(day_start).bind(day_end).fetch_one(&mut *tx).await.unwrap_or(0);
        if count >= 2 {
            return fail(
                StatusCode::BAD_REQUEST,
                "You have reached your limit on reservation requests on this day.",
            );
        }
    }
    let priority_admin_id = priority_admin.map(|current| current.id);
    let initial_status = if priority_admin_id.is_some() {
        "approved"
    } else {
        "pending"
    };
    let reservation_id: i32 = match sqlx::query_scalar("INSERT INTO reservation (\"roomId\",\"startTime\",\"endTime\",\"studentName\",email,reason,\"classId\",\"studentId\",status,\"purposeType\",\"needsMultimedia\",\"latestExecutorId\") VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING id").bind(payload.room).bind(start).bind(end).bind(&payload.student_name).bind(&payload.email).bind(&payload.reason).bind(payload.class_id).bind(if priority_admin_id.is_some() { "-" } else { &payload.student_id }).bind(initial_status).bind(&payload.purpose_type).bind(payload.needs_multimedia).bind(priority_admin_id).fetch_one(&mut *tx).await { Ok(id) => id, Err(err) => { error!(%err, "insert reservation"); return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to create reservation") } };
    let raw_cancel = token();
    let expires = start;
    if sqlx::query("INSERT INTO reservationcanceltoken (\"reservationId\",\"tokenHash\",\"expiresAt\") VALUES ($1,$2,$3)").bind(reservation_id).bind(token_hash(&raw_cancel)).bind(expires).execute(&mut *tx).await.is_err() { return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to create cancellation token") }
    let payload_json = json!({"reservationId":reservation_id,"email":payload.email,"studentName":payload.student_name,"room":room.get::<String,_>("name"),"start":local_to_json(start),"end":local_to_json(end),"cancelToken":raw_cancel,"reason":payload.reason,"status":initial_status});
    let email_kind = if priority_admin_id.is_some() {
        "reservation_status_changed"
    } else {
        "reservation_created"
    };
    let _ = sqlx::query("INSERT INTO outboxjob (kind,payload) VALUES ($1,$2)")
        .bind(email_kind)
        .bind(payload_json)
        .execute(&mut *tx)
        .await;
    if let Some(admin_id) = priority_admin_id {
        let overlaps = sqlx::query("SELECT id FROM reservation WHERE id<>$1 AND \"roomId\"=$2 AND status IN ('pending','approved') AND \"startTime\" < $3 AND \"endTime\" > $4 FOR UPDATE")
            .bind(reservation_id)
            .bind(payload.room)
            .bind(end)
            .bind(start)
            .fetch_all(&mut *tx)
            .await
            .unwrap_or_default();
        for overlap in overlaps {
            let displaced_id = overlap.get::<i32, _>("id");
            let _ = sqlx::query("UPDATE reservation SET status='cancelled',\"cancelledAt\"=now(),\"latestExecutorId\"=$1 WHERE id=$2")
                .bind(admin_id)
                .bind(displaced_id)
                .execute(&mut *tx)
                .await;
            let _ = sqlx::query("INSERT INTO reservationoperationlog (\"adminId\",\"reservationId\",operation,reason) VALUES ($1,$2,'cancelled_by_priority','Cancelled because an Office Teachers priority reservation occupies this time')")
                .bind(admin_id)
                .bind(displaced_id)
                .execute(&mut *tx)
                .await;
            let _ = sqlx::query(
                "INSERT INTO outboxjob (kind,payload) VALUES ('reservation_cancelled',$1)",
            )
            .bind(json!({"reservationId":displaced_id,"reason":"higher_priority"}))
            .execute(&mut *tx)
            .await;
        }
    } else {
        let _ = sqlx::query(
            "INSERT INTO outboxjob (kind,payload) SELECT 'admin_reservation_notification',jsonb_build_object('reservationId',$1,'adminId',id) FROM admin WHERE \"receiveReservationNotifications\"=TRUE",
        )
        .bind(reservation_id)
        .execute(&mut *tx)
        .await;
    }
    if priority_admin_id.is_none() && state.config.ai_enabled && !state.config.ai_url.is_empty() {
        let _ = sqlx::query("INSERT INTO outboxjob (kind,payload) VALUES ('ai_approval',$1)")
            .bind(json!({"reservationId":reservation_id}))
            .execute(&mut *tx)
            .await;
    }
    if tx.commit().await.is_err() {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to commit reservation",
        );
    }
    ok(json!({"reservationId":reservation_id}))
}

fn policy_allows_range(
    days: Value,
    start_time: Value,
    end_time: Value,
    weekday: i64,
    requested_start: i64,
    requested_end: i64,
) -> bool {
    let includes_day = days
        .as_array()
        .is_some_and(|values| values.iter().any(|value| value.as_i64() == Some(weekday)));
    let Some(policy_start) = policy_minutes(&start_time) else {
        return false;
    };
    let Some(policy_end) = policy_minutes(&end_time) else {
        return false;
    };

    includes_day && requested_start >= policy_start && requested_end <= policy_end
}

fn policy_minutes(value: &Value) -> Option<i64> {
    let values = value.as_array()?;
    let [hour, minute] = values.as_slice() else {
        return None;
    };
    Some(hour.as_i64()? * 60 + minute.as_i64()?)
}

pub(crate) async fn get_reservations(
    State(state): State<AppState>,
    headers: HeaderMap,
    Query(query): Query<ReservationQuery>,
) -> Response {
    let admin = current_admin(&state, &headers).await;
    let page = query.page.unwrap_or(0).max(0);
    let page_size = 20_i64;
    let mut filters = Vec::new();
    if let Some(campus) = query.campus_id {
        filters.push(ReservationFilter::Campus(campus));
    }
    if let Some(room) = query.room_id {
        filters.push(ReservationFilter::Room(room));
    }
    if let Some(status) = &query.status {
        filters.push(ReservationFilter::Status(status.clone()));
    }
    if let Some(purpose) = &query.purpose_type {
        filters.push(ReservationFilter::Purpose(purpose.clone()));
    }
    if let Some(needs) = query.needs_multimedia {
        filters.push(ReservationFilter::Needs(needs));
    }
    if let Some(start) = query.start_time.and_then(epoch_to_local) {
        filters.push(ReservationFilter::Start(start));
    }
    if let Some(end) = query.end_time.and_then(epoch_to_local) {
        filters.push(ReservationFilter::End(end));
    }
    if let Some(keyword) = query.keyword.as_ref().filter(|v| !v.is_empty()) {
        filters.push(ReservationFilter::Keyword(keyword.clone()));
    }

    let base = " FROM reservation r LEFT JOIN room rm ON rm.id=r.\"roomId\" LEFT JOIN class c ON c.id=r.\"classId\" LEFT JOIN campus cp ON cp.id=rm.\"campusId\" WHERE 1=1";
    let mut count_q = QueryBuilder::<Postgres>::new(format!("SELECT count(*){base}"));
    append_reservation_filters(&mut count_q, &filters);
    let total = count_q
        .build_query_scalar::<i64>()
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut data_q = QueryBuilder::<Postgres>::new(format!(
        "SELECT r.id,r.\"startTime\",r.\"endTime\",r.\"studentName\",r.\"studentId\",r.email,r.reason,r.status,r.\"createdAt\",r.\"roomId\",rm.name AS room_name,c.name AS class_name,cp.name AS campus_name,r.\"purposeType\",r.\"needsMultimedia\",r.\"editCount\"{base}"
    ));
    append_reservation_filters(&mut data_q, &filters);
    if query.sort.as_deref() == Some("time") {
        data_q.push(" ORDER BY r.\"startTime\" ASC,r.id ASC LIMIT ");
    } else {
        data_q.push(" ORDER BY r.id DESC LIMIT ");
    }
    data_q
        .push_bind(page_size)
        .push(" OFFSET ")
        .push_bind(page * page_size);
    let rows = match data_q
        .build_query_as::<ReservationDetailRow>()
        .fetch_all(&state.db)
        .await
    {
        Ok(rows) => rows,
        Err(err) => {
            error!(%err, "get reservations");
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to load reservations",
            );
        }
    };
    let data = rows
        .into_iter()
        .map(|r| reservation_json(r, admin.is_some()))
        .collect::<Vec<_>>();
    ok(json!({"reservations":data,"total":total}))
}

enum ReservationFilter {
    Campus(i32),
    Room(i32),
    Status(String),
    Purpose(String),
    Needs(bool),
    Start(NaiveDateTime),
    End(NaiveDateTime),
    Keyword(String),
}

fn append_reservation_filters(query: &mut QueryBuilder<Postgres>, filters: &[ReservationFilter]) {
    for filter in filters {
        match filter {
            ReservationFilter::Campus(value) => {
                query.push(" AND rm.\"campusId\" = ").push_bind(*value);
            }
            ReservationFilter::Room(value) => {
                query.push(" AND r.\"roomId\" = ").push_bind(*value);
            }
            ReservationFilter::Status(value) => {
                query.push(" AND r.status = ").push_bind(value.clone());
            }
            ReservationFilter::Purpose(value) => {
                query
                    .push(" AND r.\"purposeType\" = ")
                    .push_bind(value.clone());
            }
            ReservationFilter::Needs(value) => {
                query
                    .push(" AND r.\"needsMultimedia\" = ")
                    .push_bind(*value);
            }
            ReservationFilter::Start(value) => {
                query.push(" AND r.\"startTime\" >= ").push_bind(*value);
            }
            ReservationFilter::End(value) => {
                query.push(" AND r.\"endTime\" <= ").push_bind(*value);
            }
            ReservationFilter::Keyword(value) => {
                let pattern = format!("%{value}%");
                query
                    .push(" AND (r.email ILIKE ")
                    .push_bind(pattern.clone())
                    .push(" OR r.reason ILIKE ")
                    .push_bind(pattern.clone())
                    .push(" OR r.\"studentName\" ILIKE ")
                    .push_bind(pattern.clone())
                    .push(" OR rm.name ILIKE ")
                    .push_bind(pattern.clone())
                    .push(" OR c.name ILIKE ")
                    .push_bind(pattern)
                    .push(")");
            }
        }
    }
}

fn reservation_json(r: ReservationDetailRow, admin: bool) -> Value {
    json!({"id":r.id,"roomId":r.room_id,"startTime":local_to_json(r.start_time),"endTime":local_to_json(r.end_time),"studentName":r.student_name,"studentId":if admin {r.student_id} else {None::<String>},"email":if admin {Some(r.email)} else {None::<String>},"reason":r.reason,"status":r.status,"createdAt":local_to_json(r.created_at),"roomName":r.room_name,"className":r.class_name,"campusName":r.campus_name,"purposeType":r.purpose_type,"needsMultimedia":r.needs_multimedia,"editCount":r.edit_count})
}

pub(crate) async fn availability(
    State(state): State<AppState>,
    Query(query): Query<AvailabilityQuery>,
) -> Response {
    let date = match NaiveDate::parse_from_str(&query.date, "%Y-%m-%d") {
        Ok(date) => date,
        Err(_) => return fail(StatusCode::BAD_REQUEST, "Invalid date."),
    };
    let start = date.and_hms_opt(0, 0, 0).unwrap();
    let end = start + Duration::days(1);
    let rows = match sqlx::query("SELECT \"startTime\",\"endTime\",status FROM reservation WHERE \"roomId\"=$1 AND \"startTime\" < $2 AND \"endTime\" > $3 AND status NOT IN ('rejected','cancelled') ORDER BY \"startTime\"").bind(query.room_id).bind(end).bind(start).fetch_all(&state.db).await { Ok(v) => v, Err(err) => { error!(%err, "availability"); return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to load availability") } };
    let value: Vec<Value> = rows.into_iter().map(|r| json!({"startTime":local_to_json(r.get("startTime")),"endTime":local_to_json(r.get("endTime")),"status":r.get::<String,_>("status")})).collect();
    ok(json!({"roomId":query.room_id,"date":query.date,"occupied":value}))
}

pub(crate) async fn cancel_preview(
    State(state): State<AppState>,
    Query(query): Query<CancelQuery>,
) -> Response {
    let hash = token_hash(&query.token);
    let row = sqlx::query_as::<_, CancelPreviewRow>("SELECT r.id,r.\"roomId\",r.status,r.\"startTime\",r.\"endTime\",rm.name AS room_name,r.\"studentName\" AS student_name,r.reason,r.\"purposeType\",r.\"needsMultimedia\",t.\"expiresAt\",r.\"editCount\" FROM reservationcanceltoken t JOIN reservation r ON r.id=t.\"reservationId\" LEFT JOIN room rm ON rm.id=r.\"roomId\" WHERE t.\"tokenHash\"=$1 AND t.\"usedAt\" IS NULL").bind(hash).fetch_optional(&state.db).await;
    let row = match row {
        Ok(row) => row,
        Err(err) => {
            error!(%err, "load reservation management preview");
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to load reservation management information.",
            );
        }
    };
    let Some(row) = row else {
        return fail(
            StatusCode::NOT_FOUND,
            "Cancellation link is invalid or expired.",
        );
    };
    if row.expires_at
        < Shanghai
            .from_utc_datetime(&Utc::now().naive_utc())
            .naive_local()
    {
        return fail(StatusCode::GONE, "Cancellation link is expired.");
    }
    ok(
        json!({"reservationId":row.id,"roomId":row.room_id,"status":row.status,"roomName":row.room_name,"studentName":row.student_name,"reason":row.reason,"startTime":local_to_json(row.start_time),"endTime":local_to_json(row.end_time),"purposeType":row.purpose_type,"needsMultimedia":row.needs_multimedia,"editCount":row.edit_count,"remainingEdits":(2-row.edit_count).max(0)}),
    )
}

pub(crate) async fn cancel_reservation(
    State(state): State<AppState>,
    Json(payload): Json<CancelRequest>,
) -> Response {
    let hash = token_hash(&payload.token);
    let mut tx = match state.db.begin().await {
        Ok(tx) => tx,
        Err(_) => {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to start cancellation",
            );
        }
    };
    let row = sqlx::query("SELECT t.id,t.\"reservationId\",t.\"expiresAt\",t.\"usedAt\",r.status,r.\"startTime\" FROM reservationcanceltoken t JOIN reservation r ON r.id=t.\"reservationId\" WHERE t.\"tokenHash\"=$1 FOR UPDATE").bind(&hash).fetch_optional(&mut *tx).await.ok().flatten();
    let Some(row) = row else {
        return fail(
            StatusCode::NOT_FOUND,
            "Cancellation link is invalid or expired.",
        );
    };
    let reservation_id: i32 = row.get("reservationId");
    let status: String = row.get("status");
    if status == "cancelled" {
        let _ = tx.commit().await;
        return message("Reservation is already cancelled.");
    }
    if row.get::<Option<NaiveDateTime>, _>("usedAt").is_some()
        || row.get::<NaiveDateTime, _>("expiresAt")
            < Shanghai
                .from_utc_datetime(&Utc::now().naive_utc())
                .naive_local()
    {
        return fail(StatusCode::GONE, "Cancellation link is expired.");
    }
    let start: NaiveDateTime = row.get("startTime");
    if status == "rejected" {
        return fail(
            StatusCode::CONFLICT,
            "Rejected reservations cannot be cancelled.",
        );
    }
    if start
        <= Shanghai
            .from_utc_datetime(&Utc::now().naive_utc())
            .naive_local()
    {
        return fail(
            StatusCode::CONFLICT,
            "Past reservations cannot be cancelled.",
        );
    }
    if sqlx::query("UPDATE reservation SET status='cancelled',\"cancelledAt\"=now() WHERE id=$1 AND status IN ('pending','approved')").bind(reservation_id).execute(&mut *tx).await.is_err() { return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to cancel reservation") }
    let _ = sqlx::query("UPDATE reservationcanceltoken SET \"usedAt\"=now() WHERE id=$1")
        .bind(row.get::<i32, _>("id"))
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("INSERT INTO reservationoperationlog (\"reservationId\",operation,reason) VALUES ($1,'cancelled_by_requester','Cancelled using email link')").bind(reservation_id).execute(&mut *tx).await;
    let _ = sqlx::query("INSERT INTO outboxjob (kind,payload) VALUES ('reservation_cancelled',$1)")
        .bind(json!({"reservationId":reservation_id}))
        .execute(&mut *tx)
        .await;
    if tx.commit().await.is_err() {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to commit cancellation",
        );
    }
    message("Reservation cancelled successfully.")
}

fn edit_times(
    start_epoch: i64,
    end_epoch: i64,
) -> Result<(NaiveDateTime, NaiveDateTime), Box<Response>> {
    let Some(start) = epoch_to_local(start_epoch) else {
        return Err(Box::new(fail(
            StatusCode::BAD_REQUEST,
            "Invalid start time.",
        )));
    };
    let Some(end) = epoch_to_local(end_epoch) else {
        return Err(Box::new(fail(StatusCode::BAD_REQUEST, "Invalid end time.")));
    };
    let now = Shanghai
        .from_utc_datetime(&Utc::now().naive_utc())
        .naive_local();
    if start <= now || start >= end {
        return Err(Box::new(fail(
            StatusCode::BAD_REQUEST,
            "The edited reservation must start in the future and end after it starts.",
        )));
    }
    if end - start > Duration::hours(2) {
        return Err(Box::new(fail(
            StatusCode::BAD_REQUEST,
            "Reservation duration must not exceed 2 hours.",
        )));
    }
    if start > now + Duration::days(30) {
        return Err(Box::new(fail(
            StatusCode::BAD_REQUEST,
            "Start time must be within 30 days.",
        )));
    }
    Ok((start, end))
}

fn valid_purpose(purpose: Option<&str>) -> bool {
    purpose.is_none_or(|value| ["personal", "class", "club"].contains(&value))
}

async fn validate_edited_room(
    tx: &mut sqlx::Transaction<'_, Postgres>,
    reservation_id: i32,
    room_id: i32,
    start: NaiveDateTime,
    end: NaiveDateTime,
) -> Result<(), Box<Response>> {
    if sqlx::query("SELECT pg_advisory_xact_lock($1)")
        .bind(room_id as i64)
        .execute(&mut **tx)
        .await
        .is_err()
    {
        return Err(Box::new(fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to lock room.",
        )));
    }
    let room_enabled =
        sqlx::query_scalar::<_, Option<bool>>("SELECT enabled FROM room WHERE id=$1")
            .bind(room_id)
            .fetch_optional(&mut **tx)
            .await
            .ok()
            .flatten()
            .flatten()
            .unwrap_or(false);
    if !room_enabled {
        return Err(Box::new(fail(
            StatusCode::BAD_REQUEST,
            "Room not found or disabled.",
        )));
    }
    let policy_rows = sqlx::query(
        "SELECT days,\"startTime\",\"endTime\" FROM roompolicy WHERE \"roomId\"=$1 AND enabled=TRUE",
    )
    .bind(room_id)
    .fetch_all(&mut **tx)
    .await
    .unwrap_or_default();
    let weekday = i64::from(start.weekday().num_days_from_sunday());
    let requested_start = i64::from(start.hour() * 60 + start.minute());
    let requested_end = i64::from(end.hour() * 60 + end.minute());
    let inside_bookable_hours = start.date() == end.date()
        && policy_rows.iter().any(|row| {
            policy_allows_range(
                row.get("days"),
                row.get("startTime"),
                row.get("endTime"),
                weekday,
                requested_start,
                requested_end,
            )
        });
    if !inside_bookable_hours {
        return Err(Box::new(fail(
            StatusCode::BAD_REQUEST,
            "Requested time is outside the room's bookable hours.",
        )));
    }
    let conflict: i64 = sqlx::query_scalar(
        "SELECT count(*) FROM reservation WHERE id<>$1 AND \"roomId\"=$2 AND status NOT IN ('rejected','cancelled') AND \"startTime\" < $3 AND \"endTime\" > $4",
    )
    .bind(reservation_id)
    .bind(room_id)
    .bind(end)
    .bind(start)
    .fetch_one(&mut **tx)
    .await
    .unwrap_or(1);
    if conflict > 0 {
        return Err(Box::new(fail(
            StatusCode::CONFLICT,
            "The edited time conflicts with another reservation.",
        )));
    }
    Ok(())
}

pub(crate) async fn modify_reservation(
    State(state): State<AppState>,
    Json(payload): Json<RequesterModifyRequest>,
) -> Response {
    if payload.reason.trim().is_empty() || !valid_purpose(payload.purpose_type.as_deref()) {
        return fail(StatusCode::BAD_REQUEST, "Reason and purpose are required.");
    }
    let (start, end) = match edit_times(payload.start_time, payload.end_time) {
        Ok(times) => times,
        Err(response) => return *response,
    };
    let hash = token_hash(&payload.token);
    let mut tx = match state.db.begin().await {
        Ok(tx) => tx,
        Err(_) => {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to start modification.",
            );
        }
    };
    let row = sqlx::query("SELECT t.id AS token_id,t.\"reservationId\",t.\"expiresAt\",t.\"usedAt\",r.status,r.\"startTime\",r.\"editCount\" FROM reservationcanceltoken t JOIN reservation r ON r.id=t.\"reservationId\" WHERE t.\"tokenHash\"=$1 FOR UPDATE")
        .bind(&hash)
        .fetch_optional(&mut *tx)
        .await
        .ok()
        .flatten();
    let Some(row) = row else {
        return fail(
            StatusCode::NOT_FOUND,
            "Reservation management link is invalid.",
        );
    };
    let now = Shanghai
        .from_utc_datetime(&Utc::now().naive_utc())
        .naive_local();
    let status: String = row.get("status");
    let edit_count: i32 = row.get("editCount");
    if row.get::<Option<NaiveDateTime>, _>("usedAt").is_some()
        || row.get::<NaiveDateTime, _>("expiresAt") <= now
        || row.get::<NaiveDateTime, _>("startTime") <= now
    {
        return fail(StatusCode::GONE, "Reservation management link is expired.");
    }
    if !["pending", "approved"].contains(&status.as_str()) {
        return fail(StatusCode::CONFLICT, "This reservation cannot be modified.");
    }
    if edit_count >= 2 {
        return fail(
            StatusCode::CONFLICT,
            "This reservation has already been modified twice.",
        );
    }
    let reservation_id: i32 = row.get("reservationId");
    if let Err(response) =
        validate_edited_room(&mut tx, reservation_id, payload.room, start, end).await
    {
        return *response;
    }
    let next_edit_count = edit_count + 1;
    let changed = sqlx::query("UPDATE reservation SET \"roomId\"=$1,\"startTime\"=$2,\"endTime\"=$3,reason=$4,\"purposeType\"=$5,\"needsMultimedia\"=$6,\"editCount\"=$7,status='pending',\"latestExecutorId\"=NULL WHERE id=$8")
        .bind(payload.room)
        .bind(start)
        .bind(end)
        .bind(payload.reason.trim())
        .bind(&payload.purpose_type)
        .bind(payload.needs_multimedia)
        .bind(next_edit_count)
        .bind(reservation_id)
        .execute(&mut *tx)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Reservation not found.");
    }
    let _ = sqlx::query("UPDATE reservationcanceltoken SET \"expiresAt\"=$1 WHERE id=$2")
        .bind(start)
        .bind(row.get::<i32, _>("token_id"))
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("INSERT INTO reservationoperationlog (\"reservationId\",operation,reason) VALUES ($1,'modified_by_requester',$2)")
        .bind(reservation_id)
        .bind(format!("Requester modification {next_edit_count}/2"))
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("INSERT INTO outboxjob (kind,payload) VALUES ('reservation_modified',$1)")
        .bind(json!({"reservationId":reservation_id}))
        .execute(&mut *tx)
        .await;
    if state.config.ai_enabled && !state.config.ai_url.is_empty() {
        let _ = sqlx::query("INSERT INTO outboxjob (kind,payload) VALUES ('ai_approval',$1)")
            .bind(json!({"reservationId":reservation_id}))
            .execute(&mut *tx)
            .await;
    }
    if tx.commit().await.is_err() {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to commit modification.",
        );
    }
    ok(
        json!({"reservationId":reservation_id,"editCount":next_edit_count,"remainingEdits":2-next_edit_count}),
    )
}

pub(crate) async fn admin_edit_reservation(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<ReservationEditRequest>,
) -> Response {
    let Some(admin) = current_admin(&state, &headers).await else {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    };
    if !consume_csrf(&state, &headers).await {
        return fail(StatusCode::FORBIDDEN, "CSRF token missing or invalid.");
    }
    if payload.reason.trim().is_empty() || !valid_purpose(payload.purpose_type.as_deref()) {
        return fail(StatusCode::BAD_REQUEST, "Reason and purpose are required.");
    }
    let (start, end) = match edit_times(payload.start_time, payload.end_time) {
        Ok(times) => times,
        Err(response) => return *response,
    };
    let mut tx = match state.db.begin().await {
        Ok(tx) => tx,
        Err(_) => {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to start modification.",
            );
        }
    };
    let row = sqlx::query("SELECT status,\"endTime\" FROM reservation WHERE id=$1 FOR UPDATE")
        .bind(payload.id)
        .fetch_optional(&mut *tx)
        .await
        .ok()
        .flatten();
    let Some(row) = row else {
        return fail(StatusCode::NOT_FOUND, "Reservation not found.");
    };
    let now = Shanghai
        .from_utc_datetime(&Utc::now().naive_utc())
        .naive_local();
    if row.get::<String, _>("status") != "approved" || row.get::<NaiveDateTime, _>("endTime") <= now
    {
        return fail(
            StatusCode::CONFLICT,
            "Only approved, unexpired reservations can be edited by an admin.",
        );
    }
    if let Err(response) = validate_edited_room(&mut tx, payload.id, payload.room, start, end).await
    {
        return *response;
    }
    let changed = sqlx::query("UPDATE reservation SET \"roomId\"=$1,\"startTime\"=$2,\"endTime\"=$3,reason=$4,\"purposeType\"=$5,\"needsMultimedia\"=$6,\"latestExecutorId\"=$7 WHERE id=$8 AND status='approved'")
        .bind(payload.room)
        .bind(start)
        .bind(end)
        .bind(payload.reason.trim())
        .bind(&payload.purpose_type)
        .bind(payload.needs_multimedia)
        .bind(admin.id)
        .bind(payload.id)
        .execute(&mut *tx)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::CONFLICT, "Reservation could not be edited.");
    }
    let _ = sqlx::query("UPDATE reservationcanceltoken SET \"expiresAt\"=$1 WHERE \"reservationId\"=$2 AND \"usedAt\" IS NULL")
        .bind(start)
        .bind(payload.id)
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("INSERT INTO reservationoperationlog (\"adminId\",\"reservationId\",operation,reason) VALUES ($1,$2,'modified_by_admin','Approved reservation edited by administrator')")
        .bind(admin.id)
        .bind(payload.id)
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("INSERT INTO outboxjob (kind,payload) VALUES ('reservation_modified',$1)")
        .bind(json!({"reservationId":payload.id,"status":"approved"}))
        .execute(&mut *tx)
        .await;
    if tx.commit().await.is_err() {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to commit modification.",
        );
    }
    message("Reservation updated successfully.")
}

pub(crate) async fn approve_reservation(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<ApprovalRequest>,
) -> Response {
    let Some(admin) = current_admin(&state, &headers).await else {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    };
    if !consume_csrf(&state, &headers).await {
        return fail(StatusCode::FORBIDDEN, "CSRF token missing or invalid.");
    }
    let new_status = if payload.approved {
        "approved"
    } else {
        "rejected"
    };
    if !payload.approved && payload.reason.as_deref().unwrap_or("").trim().is_empty() {
        return fail(StatusCode::BAD_REQUEST, "Reason is required for rejection.");
    }
    let mut tx = match state.db.begin().await {
        Ok(tx) => tx,
        Err(_) => {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to start approval",
            );
        }
    };
    let changed = sqlx::query("UPDATE reservation SET status=$1,\"latestExecutorId\"=$2 WHERE id=$3 AND status='pending' AND \"startTime\" > now() RETURNING \"startTime\"").bind(new_status).bind(admin.id).bind(payload.id).fetch_optional(&mut *tx).await.ok().flatten();
    let Some(changed) = changed else {
        return fail(
            StatusCode::CONFLICT,
            "Reservation has already been processed or is no longer editable.",
        );
    };
    let _ = sqlx::query("INSERT INTO reservationoperationlog (\"adminId\",\"reservationId\",operation,reason) VALUES ($1,$2,$3,$4)").bind(admin.id).bind(payload.id).bind(new_status).bind(payload.reason).execute(&mut *tx).await;
    let raw_token = if payload.approved {
        let raw = token();
        let inserted = sqlx::query("INSERT INTO reservationcanceltoken (\"reservationId\",\"tokenHash\",\"expiresAt\") VALUES ($1,$2,$3)")
            .bind(payload.id)
            .bind(token_hash(&raw))
            .bind(changed.get::<NaiveDateTime, _>("startTime"))
            .execute(&mut *tx)
            .await;
        if inserted.is_err() {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to create reservation management link.",
            );
        }
        Some(raw)
    } else {
        None
    };
    let _ = sqlx::query(
        "INSERT INTO outboxjob (kind,payload) VALUES ('reservation_status_changed',$1)",
    )
    .bind(json!({"reservationId":payload.id,"status":new_status,"cancelToken":raw_token}))
    .execute(&mut *tx)
    .await;
    if tx.commit().await.is_err() {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to commit approval",
        );
    }
    message("Reservation updated successfully.")
}

pub(crate) async fn future_reservations(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Response {
    let Some(_admin) = current_admin(&state, &headers).await else {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    };
    let rows = sqlx::query_as::<_, ReservationDetailRow>(
        "SELECT r.id,r.\"roomId\",r.\"startTime\",r.\"endTime\",r.\"studentName\",r.\"studentId\",r.email,r.reason,r.status,r.\"createdAt\",rm.name AS room_name,c.name AS class_name,cp.name AS campus_name,r.\"purposeType\",r.\"needsMultimedia\",r.\"editCount\" FROM reservation r LEFT JOIN room rm ON rm.id=r.\"roomId\" LEFT JOIN class c ON c.id=r.\"classId\" LEFT JOIN campus cp ON cp.id=rm.\"campusId\" WHERE r.\"endTime\">now() ORDER BY r.\"startTime\"",
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();
    ok(rows
        .into_iter()
        .map(|row| reservation_json(row, true))
        .collect::<Vec<_>>())
}

pub(crate) async fn reservation_export(
    State(state): State<AppState>,
    headers: HeaderMap,
    Query(query): Query<ExportQuery>,
) -> Response {
    if current_admin(&state, &headers).await.is_none() {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    }
    let start = query.start_time.and_then(epoch_to_local);
    let end = query.end_time.and_then(epoch_to_local);
    if start.is_some() && end.is_some() && start >= end {
        return fail(StatusCode::BAD_REQUEST, "Invalid time range.");
    }
    let rows = sqlx::query("SELECT r.id,r.\"startTime\",r.\"endTime\",r.\"studentName\",r.\"studentId\",r.email,r.reason,r.status,rm.name AS room,c.name AS class,cp.name AS campus,r.\"purposeType\",r.\"needsMultimedia\" FROM reservation r LEFT JOIN room rm ON rm.id=r.\"roomId\" LEFT JOIN class c ON c.id=r.\"classId\" LEFT JOIN campus cp ON cp.id=rm.\"campusId\" WHERE ($1::timestamp IS NULL OR r.\"startTime\">=$1) AND ($2::timestamp IS NULL OR r.\"endTime\"<=$2) ORDER BY r.\"startTime\"")
        .bind(start)
        .bind(end)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();
    if rows.is_empty() {
        return fail(StatusCode::NOT_FOUND, "No reservations found.");
    }
    let mut workbook = Workbook::new();
    let worksheet = workbook.add_worksheet();
    let headers_out = [
        "ID",
        "Campus",
        "Room",
        "Class",
        "Start",
        "End",
        "Name",
        "Student ID",
        "Email",
        "Reason",
        "Status",
        "Purpose",
        "Multimedia",
    ];
    for (column, value) in headers_out.iter().enumerate() {
        let _ = worksheet.write_string(0, column as u16, *value);
    }
    for (index, row) in rows.iter().enumerate() {
        let row_index = (index + 1) as u32;
        let values = [
            row.get::<i32, _>("id").to_string(),
            row.get::<Option<String>, _>("campus").unwrap_or_default(),
            row.get::<Option<String>, _>("room").unwrap_or_default(),
            row.get::<Option<String>, _>("class").unwrap_or_default(),
            local_to_json(row.get("startTime")),
            local_to_json(row.get("endTime")),
            row.get::<String, _>("studentName"),
            row.get::<Option<String>, _>("studentId")
                .unwrap_or_default(),
            row.get::<String, _>("email"),
            row.get::<String, _>("reason"),
            row.get::<String, _>("status"),
            row.get::<Option<String>, _>("purposeType")
                .unwrap_or_default(),
            row.get::<bool, _>("needsMultimedia").to_string(),
        ];
        for (column, value) in values.iter().enumerate() {
            let _ = worksheet.write_string(row_index, column as u16, value);
        }
    }
    let bytes = match workbook.save_to_buffer() {
        Ok(bytes) => bytes,
        Err(err) => {
            error!(%err, "create reservation export");
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to create export.",
            );
        }
    };
    Response::builder()
        .status(StatusCode::OK)
        .header(
            header::CONTENT_TYPE,
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        )
        .header(
            header::CONTENT_DISPOSITION,
            format!(
                "attachment; filename=reservations-{}.xlsx",
                query.mode.as_deref().unwrap_or("by-room")
            ),
        )
        .body(Body::from(bytes))
        .unwrap_or_else(|_| {
            fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to create export.",
            )
        })
}
