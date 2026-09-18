use crate::auth::{consume_csrf, current_admin};
use crate::util::local_to_json;
use crate::*;

pub(crate) async fn current_announcement(State(state): State<AppState>) -> Response {
    let row = sqlx::query(
        "SELECT id,title,content,enabled,\"updatedAt\" FROM announcement ORDER BY id LIMIT 1",
    )
    .fetch_optional(&state.db)
    .await;
    let Some(row) = row.ok().flatten() else {
        return ok(Value::Null);
    };
    if !row.get::<bool, _>("enabled") || row.get::<String, _>("content").trim().is_empty() {
        return ok(Value::Null);
    }
    ok(json!({
        "id": row.get::<i32, _>("id"),
        "title": row.get::<String, _>("title"),
        "content": row.get::<String, _>("content"),
        "enabled": true,
        "updatedAt": local_to_json(row.get::<NaiveDateTime, _>("updatedAt")),
    }))
}

pub(crate) async fn admin_announcement(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Response {
    if current_admin(&state, &headers).await.is_none() {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    }
    let row = sqlx::query(
        "SELECT id,title,content,enabled,\"updatedAt\" FROM announcement ORDER BY id LIMIT 1",
    )
    .fetch_optional(&state.db)
    .await
    .ok()
    .flatten();
    let Some(row) = row else {
        return ok(json!({"id":null,"title":"","content":"","enabled":false,"updatedAt":null}));
    };
    ok(json!({
        "id": row.get::<i32, _>("id"),
        "title": row.get::<String, _>("title"),
        "content": row.get::<String, _>("content"),
        "enabled": row.get::<bool, _>("enabled"),
        "updatedAt": local_to_json(row.get::<NaiveDateTime, _>("updatedAt")),
    }))
}

pub(crate) async fn update_announcement(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<AnnouncementUpdateRequest>,
) -> Response {
    let Some(admin) = current_admin(&state, &headers).await else {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    };
    if !consume_csrf(&state, &headers).await {
        return fail(StatusCode::FORBIDDEN, "CSRF token missing or invalid.");
    }
    let title = payload.title.trim();
    let content = payload.content.trim();
    if title.len() > 120 || content.len() > 4_000 || (payload.enabled && content.is_empty()) {
        return fail(StatusCode::BAD_REQUEST, "Invalid announcement content.");
    }
    let result = sqlx::query(
        "INSERT INTO announcement (id,title,content,enabled,\"updatedAt\",\"updatedBy\") VALUES (1,$1,$2,$3,now(),$4) ON CONFLICT (id) DO UPDATE SET title=EXCLUDED.title,content=EXCLUDED.content,enabled=EXCLUDED.enabled,\"updatedAt\"=now(),\"updatedBy\"=EXCLUDED.\"updatedBy\"",
    )
    .bind(title)
    .bind(content)
    .bind(payload.enabled)
    .bind(admin.id)
    .execute(&state.db)
    .await;
    if result.is_err() {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to save announcement.",
        );
    }
    message("Announcement updated successfully.")
}
