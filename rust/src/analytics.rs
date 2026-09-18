use crate::auth::current_admin;
use crate::util::local_to_json;
use crate::*;

pub(crate) async fn analytics_weekly(State(state): State<AppState>) -> Response {
    let start = Shanghai
        .from_utc_datetime(&Utc::now().naive_utc())
        .naive_local()
        .date()
        .and_hms_opt(0, 0, 0)
        .unwrap()
        - Duration::days(7);
    let end = start + Duration::days(7);
    let totals = sqlx::query(
        "SELECT count(*) FILTER (WHERE status='approved') AS approvals,count(*) FILTER (WHERE status='rejected') AS rejections,count(*) AS reservations,count(*) FILTER (WHERE \"createdAt\">=$1 AND \"createdAt\"<$2) AS creations FROM reservation WHERE \"startTime\">=$1 AND \"startTime\"<$2",
    )
    .bind(start)
    .bind(end)
    .fetch_one(&state.db)
    .await;
    let totals = totals.ok();
    let total_reservations = totals
        .as_ref()
        .map(|row| row.get::<i64, _>("reservations"))
        .unwrap_or(0);
    let total_creations = totals
        .as_ref()
        .map(|row| row.get::<i64, _>("creations"))
        .unwrap_or(0);
    let approvals = totals
        .as_ref()
        .map(|row| row.get::<i64, _>("approvals"))
        .unwrap_or(0);
    let rejections = totals
        .as_ref()
        .map(|row| row.get::<i64, _>("rejections"))
        .unwrap_or(0);
    let daily = sqlx::query("SELECT EXTRACT(DOW FROM \"startTime\")::int AS dow,count(*) AS count FROM reservation WHERE \"startTime\">=$1 AND \"startTime\"<$2 AND status='approved' GROUP BY 1")
        .bind(start)
        .bind(end)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();
    let mut daily_reservations = vec![0_i64; 7];
    for row in daily {
        let day = row.get::<i32, _>("dow") as usize;
        if day < 7 {
            daily_reservations[day] = row.get("count");
        }
    }
    let rooms = sqlx::query("SELECT rm.name AS \"roomName\",count(*) FILTER (WHERE r.\"startTime\">=$1 AND r.\"startTime\"<$2) AS reservations,count(*) FILTER (WHERE r.\"createdAt\">=$1 AND r.\"createdAt\"<$2) AS creations FROM room rm LEFT JOIN reservation r ON r.\"roomId\"=rm.id GROUP BY rm.id,rm.name ORDER BY reservations DESC LIMIT 5")
        .bind(start)
        .bind(end)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default()
        .into_iter()
        .map(|row| json!({"roomName":row.get::<String,_>("roomName"),"reservations":row.get::<i64,_>("reservations"),"reservationCreations":row.get::<i64,_>("creations")}))
        .collect::<Vec<_>>();
    ok(
        json!({"totalReservations":total_reservations,"totalReservationCreations":total_creations,"totalApprovals":approvals,"totalRejections":rejections,"rooms":rooms,"reasons":[],"hourlyReservations":vec![0_i64; 24],"dailyReservations":daily_reservations,"dailyReservationCreations":vec![0_i64; 7]}),
    )
}

pub(crate) async fn analytics_export(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Response {
    if current_admin(&state, &headers).await.is_none() {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    }
    let rows = sqlx::query("SELECT date,reservations,\"reservationCreations\",requests,approvals,rejections FROM analytic ORDER BY date DESC LIMIT 365")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();
    let mut csv =
        String::from("date,reservations,reservationCreations,requests,approvals,rejections\n");
    for row in rows {
        csv.push_str(&format!(
            "{},{},{},{},{},{}\n",
            local_to_json(row.get("date")),
            row.get::<i32, _>("reservations"),
            row.get::<i32, _>("reservationCreations"),
            row.get::<i64, _>("requests"),
            row.get::<i32, _>("approvals"),
            row.get::<i32, _>("rejections")
        ));
    }
    Response::builder()
        .status(StatusCode::OK)
        .header(header::CONTENT_TYPE, "text/csv; charset=utf-8")
        .header(
            header::CONTENT_DISPOSITION,
            "attachment; filename=analytics.csv",
        )
        .body(Body::from(csv))
        .unwrap()
}

pub(crate) async fn analytics_overview(State(state): State<AppState>) -> Response {
    let today: i64 = sqlx::query_scalar("SELECT count(*) FROM reservation WHERE \"startTime\" >= date_trunc('day',now()) AND \"startTime\" < date_trunc('day',now())+interval '1 day' AND status <> 'cancelled'").fetch_one(&state.db).await.unwrap_or(0);
    let pending: i64 =
        sqlx::query_scalar("SELECT count(*) FROM reservation WHERE status='pending'")
            .fetch_one(&state.db)
            .await
            .unwrap_or(0);
    ok(
        json!({"today":{"reservations":today,"reservationCreations":0,"requests":0,"approvals":0,"rejections":0},"pending":pending}),
    )
}
