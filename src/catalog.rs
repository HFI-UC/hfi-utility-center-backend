use crate::auth::{current_admin, require_admin_write};
use crate::util::local_to_json;
use crate::*;

pub(crate) async fn list_campuses(State(state): State<AppState>) -> Response {
    if let Some(value) = state.cache.read().await.campuses.clone() {
        return ok(value);
    }
    let rows = match sqlx::query_as::<_, CampusRow>(
        "SELECT id,name,\"isPrivileged\",\"createdAt\" FROM campus ORDER BY id",
    )
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows,
        Err(err) => {
            error!(%err, "list campuses");
            return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to load campuses");
        }
    };
    let value = Value::Array(rows.into_iter().map(|r| json!({"id":r.id,"name":r.name,"isPrivileged":r.is_privileged,"createdAt":r.created_at.map(local_to_json)})).collect());
    state.cache.write().await.campuses = Some(value.clone());
    ok(value)
}

pub(crate) async fn list_classes(State(state): State<AppState>) -> Response {
    if let Some(value) = state.cache.read().await.classes.clone() {
        return ok(value);
    }
    let rows = match sqlx::query_as::<_, ClassRow>(
        "SELECT id,name,\"campusId\",\"createdAt\" FROM class ORDER BY id",
    )
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows,
        Err(err) => {
            error!(%err, "list classes");
            return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to load classes");
        }
    };
    let value = Value::Array(rows.into_iter().map(|r| json!({"id":r.id,"name":r.name,"campus":r.campus_id,"createdAt":r.created_at.map(local_to_json)})).collect());
    state.cache.write().await.classes = Some(value.clone());
    ok(value)
}

pub(crate) async fn list_rooms(State(state): State<AppState>, headers: HeaderMap) -> Response {
    let is_admin = current_admin(&state, &headers).await.is_some();
    if !is_admin && let Some(value) = state.cache.read().await.rooms.clone() {
        return ok(value);
    }
    let rows = match sqlx::query_as::<_, RoomRow>(
        "SELECT id,name,\"campusId\",enabled,\"createdAt\" FROM room ORDER BY id",
    )
    .fetch_all(&state.db)
    .await
    {
        Ok(rows) => rows,
        Err(err) => {
            error!(%err, "list rooms");
            return fail(StatusCode::INTERNAL_SERVER_ERROR, "Unable to load rooms");
        }
    };
    let room_ids: Vec<i32> = rows.iter().map(|r| r.id).collect();
    let policies = if room_ids.is_empty() {
        Vec::new()
    } else {
        sqlx::query("SELECT id,\"roomId\",days,\"startTime\",\"endTime\",enabled FROM roompolicy WHERE \"roomId\" = ANY($1)").bind(&room_ids).fetch_all(&state.db).await.unwrap_or_default()
    };
    let value = Value::Array(rows.into_iter().map(|r| {
        let room_policies: Vec<Value> = policies.iter().filter(|p| p.get::<Option<i32>, _>("roomId") == Some(r.id)).map(|p| json!({"id":p.get::<i32,_>("id"),"roomId":p.get::<Option<i32>,_>("roomId"),"days":p.get::<Value,_>("days"),"startTime":p.get::<Value,_>("startTime"),"endTime":p.get::<Value,_>("endTime"),"enabled":p.get::<bool,_>("enabled")})).collect();
        json!({"id":r.id,"name":r.name,"campus":r.campus_id,"enabled":r.enabled.unwrap_or(true),"createdAt":r.created_at.map(local_to_json),"policies":room_policies})
    }).collect());
    if !is_admin {
        state.cache.write().await.rooms = Some(value.clone())
    }
    ok(value)
}

pub(crate) async fn admin_list(State(state): State<AppState>, headers: HeaderMap) -> Response {
    if current_admin(&state, &headers).await.is_none() {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    }
    let rows = sqlx::query("SELECT id,name,email,\"createdAt\",\"receiveReservationNotifications\" FROM admin ORDER BY id")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();
    ok(rows.into_iter().map(|r| json!({"id":r.get::<i32,_>("id"),"name":r.get::<String,_>("name"),"email":r.get::<String,_>("email"),"createdAt":r.get::<Option<NaiveDateTime>,_>("createdAt").map(local_to_json),"receiveReservationNotifications":r.get::<bool,_>("receiveReservationNotifications")})).collect::<Vec<_>>())
}

#[allow(clippy::result_large_err)]
pub(crate) async fn campus_create(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<CampusMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if payload.name.trim().is_empty() {
        return fail(StatusCode::BAD_REQUEST, "Campus name is required.");
    }
    if sqlx::query("INSERT INTO campus (name) VALUES ($1)")
        .bind(payload.name.trim())
        .execute(&state.db)
        .await
        .is_err()
    {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to create campus.",
        );
    }
    *state.cache.write().await = CatalogCache::default();
    message("Campus created successfully.")
}

pub(crate) async fn campus_edit(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<CampusMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let Some(id) = payload.id else {
        return fail(StatusCode::BAD_REQUEST, "Campus id is required.");
    };
    let changed = sqlx::query("UPDATE campus SET name=$1 WHERE id=$2")
        .bind(payload.name.trim())
        .bind(id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Campus not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Campus edited successfully.")
}

pub(crate) async fn campus_delete(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<IdRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let changed = sqlx::query("DELETE FROM campus WHERE id=$1")
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Campus not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Campus deleted successfully.")
}

pub(crate) async fn class_create(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<ClassMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let result = sqlx::query("INSERT INTO class (name,\"campusId\") VALUES ($1,$2)")
        .bind(payload.name.trim())
        .bind(payload.campus)
        .execute(&state.db)
        .await;
    if result.is_err() {
        return fail(StatusCode::BAD_REQUEST, "Invalid campus.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Class created successfully.")
}

pub(crate) async fn class_edit(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<ClassMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let Some(id) = payload.id else {
        return fail(StatusCode::BAD_REQUEST, "Class id is required.");
    };
    let changed = sqlx::query("UPDATE class SET name=$1,\"campusId\"=$2 WHERE id=$3")
        .bind(payload.name.trim())
        .bind(payload.campus)
        .bind(id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Class not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Class edited successfully.")
}

pub(crate) async fn class_delete(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<IdRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let changed = sqlx::query("DELETE FROM class WHERE id=$1")
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Class not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Class deleted successfully.")
}

pub(crate) async fn room_create(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<RoomMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if sqlx::query("INSERT INTO room (name,\"campusId\",enabled) VALUES ($1,$2,TRUE)")
        .bind(payload.name.trim())
        .bind(payload.campus)
        .execute(&state.db)
        .await
        .is_err()
    {
        return fail(StatusCode::BAD_REQUEST, "Invalid campus.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Room created successfully.")
}

pub(crate) async fn room_edit(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<RoomMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let Some(id) = payload.id else {
        return fail(StatusCode::BAD_REQUEST, "Room id is required.");
    };
    let changed = sqlx::query(
        "UPDATE room SET name=$1,\"campusId\"=$2,enabled=COALESCE($3,enabled) WHERE id=$4",
    )
    .bind(payload.name.trim())
    .bind(payload.campus)
    .bind(payload.enabled)
    .bind(id)
    .execute(&state.db)
    .await
    .map(|result| result.rows_affected())
    .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Room not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Room edited successfully.")
}

pub(crate) async fn room_delete(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<IdRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let changed = sqlx::query("DELETE FROM room WHERE id=$1")
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Room not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Room deleted successfully.")
}

fn valid_policy(payload: &PolicyMutation) -> bool {
    let mut days = payload.days.clone();
    days.sort_unstable();
    days.dedup();
    days.len() == payload.days.len()
        && days.iter().all(|day| (0..=6).contains(day))
        && matches!(payload.start_time.as_slice(), [hour, minute] if (0..=23).contains(hour) && (0..=59).contains(minute))
        && matches!(payload.end_time.as_slice(), [hour, minute] if (0..=23).contains(hour) && (0..=59).contains(minute))
}

pub(crate) async fn policy_create(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<PolicyMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if !valid_policy(&payload) {
        return fail(StatusCode::BAD_REQUEST, "Invalid room policy.");
    }
    let Some(room) = payload.room else {
        return fail(StatusCode::BAD_REQUEST, "Room id is required.");
    };
    let result = sqlx::query(
        "INSERT INTO roompolicy (\"roomId\",days,\"startTime\",\"endTime\",enabled) VALUES ($1,$2,$3,$4,TRUE)",
    )
    .bind(room)
    .bind(json!(payload.days))
    .bind(json!(payload.start_time))
    .bind(json!(payload.end_time))
    .execute(&state.db)
    .await;
    if result.is_err() {
        return fail(StatusCode::NOT_FOUND, "Room not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Policy created successfully.")
}

pub(crate) async fn policy_edit(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<PolicyMutation>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if !valid_policy(&payload) {
        return fail(StatusCode::BAD_REQUEST, "Invalid room policy.");
    }
    let Some(id) = payload.id else {
        return fail(StatusCode::BAD_REQUEST, "Policy id is required.");
    };
    let changed =
        sqlx::query("UPDATE roompolicy SET days=$1,\"startTime\"=$2,\"endTime\"=$3 WHERE id=$4")
            .bind(json!(payload.days))
            .bind(json!(payload.start_time))
            .bind(json!(payload.end_time))
            .bind(id)
            .execute(&state.db)
            .await
            .map(|result| result.rows_affected())
            .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Policy not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Policy edited successfully.")
}

pub(crate) async fn policy_toggle(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<IdRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let changed = sqlx::query("UPDATE roompolicy SET enabled=NOT enabled WHERE id=$1")
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Policy not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Policy toggled successfully.")
}

pub(crate) async fn policy_delete(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<IdRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let changed = sqlx::query("DELETE FROM roompolicy WHERE id=$1")
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Policy not found.");
    }
    *state.cache.write().await = CatalogCache::default();
    message("Policy deleted successfully.")
}

pub(crate) async fn admin_create(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<AdminCreateRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if payload.password.len() < 6 || !payload.email.contains('@') {
        return fail(StatusCode::BAD_REQUEST, "Invalid email or password.");
    }
    let Ok(password) = bcrypt_hash(payload.password, DEFAULT_COST) else {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to hash password.",
        );
    };
    let result = sqlx::query("INSERT INTO admin (name,email,password) VALUES ($1,$2,$3)")
        .bind(payload.name.trim())
        .bind(payload.email.trim())
        .bind(password)
        .execute(&state.db)
        .await;
    if result.is_err() {
        return fail(StatusCode::CONFLICT, "Admin already exists.");
    }
    message("Admin created successfully.")
}

pub(crate) async fn admin_edit(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<AdminEditRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if !payload.email.contains('@') {
        return fail(StatusCode::BAD_REQUEST, "Invalid email format.");
    }
    let changed = sqlx::query("UPDATE admin SET name=$1,email=$2 WHERE id=$3")
        .bind(payload.name.trim())
        .bind(payload.email.trim())
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Admin not found.");
    }
    message("Admin edited successfully.")
}

pub(crate) async fn admin_edit_password(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<AdminPasswordRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    if payload.new_password.len() < 6 {
        return fail(
            StatusCode::BAD_REQUEST,
            "Password must be at least 6 characters.",
        );
    }
    let Ok(password) = bcrypt_hash(payload.new_password, DEFAULT_COST) else {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to hash password.",
        );
    };
    let changed = sqlx::query("UPDATE admin SET password=$1 WHERE id=$2")
        .bind(password)
        .bind(payload.admin)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Admin not found.");
    }
    message("Password changed successfully.")
}

pub(crate) async fn admin_notification_settings(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<AdminNotificationRequest>,
) -> Response {
    if let Err(response) = require_admin_write(&state, &headers).await {
        return response;
    }
    let changed =
        sqlx::query("UPDATE admin SET \"receiveReservationNotifications\"=$1 WHERE id=$2")
            .bind(payload.enabled)
            .bind(payload.id)
            .execute(&state.db)
            .await
            .map(|result| result.rows_affected())
            .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Admin not found.");
    }
    message("Reservation notification settings updated successfully.")
}

pub(crate) async fn admin_delete(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<IdRequest>,
) -> Response {
    let admin = match require_admin_write(&state, &headers).await {
        Ok(admin) => admin,
        Err(response) => return response,
    };
    if admin.id == payload.id {
        return fail(
            StatusCode::CONFLICT,
            "You cannot delete your active account.",
        );
    }
    let changed = sqlx::query("DELETE FROM admin WHERE id=$1")
        .bind(payload.id)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if changed == 0 {
        return fail(StatusCode::NOT_FOUND, "Admin not found.");
    }
    message("Admin deleted successfully.")
}

pub(crate) async fn invalidate_catalog(State(state): State<AppState>) -> Response {
    *state.cache.write().await = CatalogCache::default();
    message("Catalog cache invalidated.")
}
