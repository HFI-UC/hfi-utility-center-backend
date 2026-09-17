mod analytics;
mod announcements;
mod app;
mod auth;
mod catalog;
mod reservations;
mod util;
mod worker;

use axum::{
    Json,
    body::Body,
    extract::{Query, State},
    http::{HeaderMap, HeaderValue, StatusCode, header},
    response::{Html, IntoResponse, Response},
};
use bcrypt::{DEFAULT_COST, hash as bcrypt_hash, verify as bcrypt_verify};
use chrono::{DateTime, Datelike, Duration, NaiveDate, NaiveDateTime, TimeZone, Timelike, Utc};
use chrono_tz::Asia::Shanghai;
use reqwest::Client;
use rust_xlsxwriter::Workbook;
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sqlx::{FromRow, Pool, Postgres, QueryBuilder, Row, postgres::PgPoolOptions};
use std::{collections::HashMap, env, sync::Arc};
use tokio::sync::RwLock;
use tracing::{error, info};

type Db = Pool<Postgres>;

#[derive(Clone)]
struct AppState {
    db: Db,
    http: Client,
    cache: Arc<RwLock<CatalogCache>>,
    csrf: Arc<RwLock<HashMap<String, DateTime<Utc>>>>,
    config: Arc<Config>,
}

#[derive(Clone, Default)]
struct CatalogCache {
    campuses: Option<Value>,
    rooms: Option<Value>,
    classes: Option<Value>,
}

#[derive(Clone)]
struct Config {
    frontend_url: String,
    smtp_server: String,
    smtp_email: String,
    smtp_password: String,
    ai_url: String,
    ai_secret: String,
    ai_enabled: bool,
    ai_admin_id: i32,
    cloudflare_secret: String,
}

impl Config {
    fn from_env() -> Self {
        Self {
            frontend_url: env::var("FRONTEND_URL")
                .unwrap_or_else(|_| "https://www.hfiuc.org".into()),
            smtp_server: env::var("SMTP_SERVER").unwrap_or_default(),
            smtp_email: env::var("SMTP_EMAIL").unwrap_or_default(),
            smtp_password: env::var("SMTP_PASSWORD").unwrap_or_default(),
            ai_url: env::var("AI_APPROVAL_URL").unwrap_or_default(),
            ai_secret: env::var("AI_APPROVAL_SECRET").unwrap_or_default(),
            ai_enabled: env::var("AI_APPROVAL_ENABLED")
                .map(|v| v.eq_ignore_ascii_case("true"))
                .unwrap_or(false),
            ai_admin_id: env::var("AI_APPROVAL_ADMIN_ID")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(0),
            cloudflare_secret: env::var("CLOUDFLARE_SECRET").unwrap_or_default(),
        }
    }
}

#[derive(Serialize)]
struct ApiEnvelope<T: Serialize> {
    success: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    data: Option<T>,
    #[serde(skip_serializing_if = "Option::is_none")]
    message: Option<String>,
}

fn respond<T: Serialize>(status: StatusCode, data: Option<T>, message: Option<String>) -> Response {
    (
        status,
        Json(ApiEnvelope {
            success: status.is_success(),
            data,
            message,
        }),
    )
        .into_response()
}

fn ok<T: Serialize>(data: T) -> Response {
    respond(StatusCode::OK, Some(data), None)
}
fn message(text: impl Into<String>) -> Response {
    respond(StatusCode::OK, None::<Value>, Some(text.into()))
}
fn fail(status: StatusCode, text: impl Into<String>) -> Response {
    respond(status, None::<Value>, Some(text.into()))
}

#[derive(Deserialize)]
struct CreateReservation {
    room: i32,
    #[serde(rename = "startTime")]
    start_time: i64,
    #[serde(rename = "endTime")]
    end_time: i64,
    #[serde(rename = "studentName")]
    student_name: String,
    email: String,
    reason: String,
    #[serde(rename = "classId")]
    class_id: Option<i32>,
    #[serde(rename = "studentId")]
    student_id: String,
    #[serde(rename = "purposeType")]
    purpose_type: Option<String>,
    #[serde(rename = "needsMultimedia", default)]
    needs_multimedia: bool,
}

#[derive(Deserialize)]
struct LoginRequest {
    email: Option<String>,
    password: Option<String>,
    token: Option<String>,
    #[serde(rename = "turnstileToken")]
    turnstile_token: Option<String>,
}

#[derive(Deserialize)]
struct ApprovalRequest {
    id: i32,
    approved: bool,
    reason: Option<String>,
}

#[derive(Deserialize)]
struct IdRequest {
    id: i32,
}

#[derive(Deserialize)]
struct CampusMutation {
    name: String,
    id: Option<i32>,
}

#[derive(Deserialize)]
struct ClassMutation {
    name: String,
    campus: i32,
    id: Option<i32>,
}

#[derive(Deserialize)]
struct RoomMutation {
    name: String,
    campus: i32,
    id: Option<i32>,
    enabled: Option<bool>,
}

#[derive(Deserialize)]
struct PolicyMutation {
    room: Option<i32>,
    id: Option<i32>,
    days: Vec<i32>,
    #[serde(rename = "startTime")]
    start_time: Vec<i32>,
    #[serde(rename = "endTime")]
    end_time: Vec<i32>,
}

#[derive(Deserialize)]
struct AdminCreateRequest {
    name: String,
    email: String,
    password: String,
}

#[derive(Deserialize)]
struct AdminEditRequest {
    id: i32,
    name: String,
    email: String,
}

#[derive(Deserialize)]
struct AdminPasswordRequest {
    admin: i32,
    #[serde(rename = "newPassword")]
    new_password: String,
}

#[derive(Deserialize)]
struct AdminNotificationRequest {
    id: i32,
    enabled: bool,
}

#[derive(Deserialize)]
struct AnnouncementUpdateRequest {
    title: String,
    content: String,
    enabled: bool,
}

#[derive(Deserialize)]
struct CancelQuery {
    token: String,
}

#[derive(Deserialize)]
struct CancelRequest {
    token: String,
}

#[derive(Deserialize)]
struct ReservationEditRequest {
    id: i32,
    room: i32,
    #[serde(rename = "startTime")]
    start_time: i64,
    #[serde(rename = "endTime")]
    end_time: i64,
    reason: String,
    #[serde(rename = "purposeType")]
    purpose_type: Option<String>,
    #[serde(rename = "needsMultimedia", default)]
    needs_multimedia: bool,
}

#[derive(Deserialize)]
struct RequesterModifyRequest {
    token: String,
    room: i32,
    #[serde(rename = "startTime")]
    start_time: i64,
    #[serde(rename = "endTime")]
    end_time: i64,
    reason: String,
    #[serde(rename = "purposeType")]
    purpose_type: Option<String>,
    #[serde(rename = "needsMultimedia", default)]
    needs_multimedia: bool,
}

#[derive(Deserialize)]
struct AvailabilityQuery {
    #[serde(rename = "roomId")]
    room_id: i32,
    date: String,
}

#[derive(Deserialize)]
struct ReservationQuery {
    #[serde(rename = "campusId")]
    campus_id: Option<i32>,
    #[serde(rename = "roomId")]
    room_id: Option<i32>,
    keyword: Option<String>,
    status: Option<String>,
    page: Option<i64>,
    #[serde(rename = "startTime")]
    start_time: Option<i64>,
    #[serde(rename = "endTime")]
    end_time: Option<i64>,
    #[serde(rename = "purposeType")]
    purpose_type: Option<String>,
    #[serde(rename = "needsMultimedia")]
    needs_multimedia: Option<bool>,
    sort: Option<String>,
}

#[derive(Deserialize)]
struct ExportQuery {
    #[serde(rename = "startTime")]
    start_time: Option<i64>,
    #[serde(rename = "endTime")]
    end_time: Option<i64>,
    mode: Option<String>,
}

#[derive(FromRow)]
struct RoomRow {
    id: i32,
    name: String,
    #[sqlx(rename = "campusId")]
    campus_id: Option<i32>,
    enabled: Option<bool>,
    #[sqlx(rename = "createdAt")]
    created_at: Option<NaiveDateTime>,
}

#[derive(FromRow)]
struct CampusRow {
    id: i32,
    name: String,
    #[sqlx(rename = "isPrivileged")]
    is_privileged: bool,
    #[sqlx(rename = "createdAt")]
    created_at: Option<NaiveDateTime>,
}

#[derive(FromRow)]
struct ClassRow {
    id: i32,
    name: String,
    #[sqlx(rename = "campusId")]
    campus_id: Option<i32>,
    #[sqlx(rename = "createdAt")]
    created_at: Option<NaiveDateTime>,
}

#[derive(FromRow)]
struct ReservationDetailRow {
    id: i32,
    #[sqlx(rename = "roomId")]
    room_id: Option<i32>,
    #[sqlx(rename = "startTime")]
    start_time: NaiveDateTime,
    #[sqlx(rename = "endTime")]
    end_time: NaiveDateTime,
    #[sqlx(rename = "studentName")]
    student_name: String,
    #[sqlx(rename = "studentId")]
    student_id: Option<String>,
    email: String,
    reason: String,
    status: String,
    #[sqlx(rename = "createdAt")]
    created_at: NaiveDateTime,
    room_name: Option<String>,
    class_name: Option<String>,
    campus_name: Option<String>,
    #[sqlx(rename = "purposeType")]
    purpose_type: Option<String>,
    #[sqlx(rename = "needsMultimedia")]
    needs_multimedia: bool,
    #[sqlx(rename = "editCount")]
    edit_count: i32,
}

#[derive(FromRow)]
struct AdminRow {
    id: i32,
    email: String,
    name: String,
    password: String,
}

#[derive(FromRow)]
struct CancelPreviewRow {
    id: i32,
    #[sqlx(rename = "roomId")]
    room_id: Option<i32>,
    status: String,
    #[sqlx(rename = "startTime")]
    start_time: NaiveDateTime,
    #[sqlx(rename = "endTime")]
    end_time: NaiveDateTime,
    room_name: Option<String>,
    student_name: String,
    reason: String,
    #[sqlx(rename = "purposeType")]
    purpose_type: Option<String>,
    #[sqlx(rename = "needsMultimedia")]
    needs_multimedia: bool,
    #[sqlx(rename = "expiresAt")]
    expires_at: NaiveDateTime,
    #[sqlx(rename = "editCount")]
    edit_count: i32,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    tracing_subscriber::fmt()
        .with_env_filter(
            env::var("RUST_LOG").unwrap_or_else(|_| "hfiuc_api=info,tower_http=info".into()),
        )
        .json()
        .init();
    let database_url = env::var("DATABASE_URL").expect("DATABASE_URL is required");
    let pool = PgPoolOptions::new()
        .max_connections(
            env::var("DB_MAX_CONNECTIONS")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(32),
        )
        .min_connections(2)
        .acquire_timeout(std::time::Duration::from_secs(5))
        .connect(&database_url)
        .await?;
    let state = AppState {
        db: pool,
        http: Client::builder()
            .connect_timeout(std::time::Duration::from_secs(2))
            .timeout(std::time::Duration::from_secs(5))
            .build()?,
        cache: Arc::new(RwLock::new(CatalogCache::default())),
        csrf: Arc::new(RwLock::new(HashMap::new())),
        config: Arc::new(Config::from_env()),
    };
    let mode = env::var("HFIUC_MODE").unwrap_or_else(|_| "api".into());
    if mode == "worker" {
        worker::run_worker(state).await;
        return Ok(());
    }
    if mode == "migrate" {
        sqlx::migrate!("./migrations").run(&state.db).await?;
        return Ok(());
    }
    let port = env::var("PORT")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(8000_u16);
    let bind_address = env::var("BIND_ADDRESS").unwrap_or_else(|_| "127.0.0.1".into());
    let listener = tokio::net::TcpListener::bind((bind_address.as_str(), port)).await?;
    info!(%bind_address, port, "HFI Utility Center Rust API listening");
    axum::serve(listener, app::app(state)).await?;
    Ok(())
}

#[cfg(test)]
mod contract_tests {
    use super::*;

    #[test]
    fn reservation_context_can_be_omitted() {
        let payload: CreateReservation = serde_json::from_value(json!({
            "room": 14,
            "startTime": 1_800_000_000,
            "endTime": 1_800_003_600,
            "studentName": "Student",
            "email": "student@gdhfi.com",
            "reason": "Detailed purpose",
            "studentId": "GJ20999999",
            "needsMultimedia": false
        }))
        .expect("reservation without class or purpose should deserialize");

        assert_eq!(payload.class_id, None);
        assert_eq!(payload.purpose_type, None);
    }
}
