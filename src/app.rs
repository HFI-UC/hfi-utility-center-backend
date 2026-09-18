use crate::{AppState, HeaderValue};
use crate::{analytics, announcements, auth, catalog, reservations};
use axum::{
    Router,
    routing::{get, post},
};
use http::{HeaderName, Method, header};
use tower::ServiceBuilder;
use tower_http::{
    cors::CorsLayer,
    request_id::{MakeRequestUuid, PropagateRequestIdLayer, SetRequestIdLayer},
    services::ServeDir,
    set_header::SetResponseHeaderLayer,
    trace::TraceLayer,
};

pub(crate) fn app(state: AppState) -> Router {
    let request_id_header = HeaderName::from_static("x-request-id");
    let assets = ServiceBuilder::new()
        .layer(SetResponseHeaderLayer::if_not_present(
            header::CACHE_CONTROL,
            HeaderValue::from_static("public, max-age=31536000, immutable"),
        ))
        .service(ServeDir::new("public/assets"));
    let allowed_origins = [
        state.config.frontend_url.as_str(),
        "https://hfiuc.org",
        "https://www.hfiuc.org",
        "https://preview.hfiuc.org",
        "https://neo.hfiuc.org",
        "http://localhost:3000",
        "http://127.0.0.1:3000",
        "http://localhost:5173",
        "http://127.0.0.1:5173",
        "http://localhost:5174",
        "http://127.0.0.1:5174",
    ]
    .into_iter()
    .filter_map(|origin| HeaderValue::from_str(origin).ok())
    .collect::<Vec<_>>();

    Router::new()
        .nest_service("/assets", assets)
        .route("/", get(auth::root))
        .route("/healthz", get(auth::health))
        .route("/_csrf", get(auth::csrf))
        .route(
            "/announcement/current",
            get(announcements::current_announcement),
        )
        .route(
            "/announcement/admin",
            get(announcements::admin_announcement),
        )
        .route(
            "/announcement/update",
            post(announcements::update_announcement),
        )
        .route("/campus/list", get(catalog::list_campuses))
        .route("/class/list", get(catalog::list_classes))
        .route("/room/list", get(catalog::list_rooms))
        .route("/campus/create", post(catalog::campus_create))
        .route("/campus/edit", post(catalog::campus_edit))
        .route("/campus/delete", post(catalog::campus_delete))
        .route("/class/create", post(catalog::class_create))
        .route("/class/edit", post(catalog::class_edit))
        .route("/class/delete", post(catalog::class_delete))
        .route("/room/create", post(catalog::room_create))
        .route("/room/edit", post(catalog::room_edit))
        .route("/room/delete", post(catalog::room_delete))
        .route("/policy/create", post(catalog::policy_create))
        .route("/policy/edit", post(catalog::policy_edit))
        .route("/policy/toggle", post(catalog::policy_toggle))
        .route("/policy/delete", post(catalog::policy_delete))
        .route("/reservation/availability", get(reservations::availability))
        .route(
            "/reservation/create",
            post(reservations::create_reservation),
        )
        .route("/reservation/get", get(reservations::get_reservations))
        .route(
            "/reservation/future",
            get(reservations::future_reservations),
        )
        .route("/reservation/export", get(reservations::reservation_export))
        .route(
            "/reservation/cancel/preview",
            get(reservations::cancel_preview),
        )
        .route(
            "/reservation/cancel",
            post(reservations::cancel_reservation),
        )
        .route(
            "/reservation/modify",
            post(reservations::modify_reservation),
        )
        .route(
            "/reservation/admin-edit",
            post(reservations::admin_edit_reservation),
        )
        .route(
            "/reservation/approval",
            post(reservations::approve_reservation),
        )
        .route("/admin/login", post(auth::admin_login))
        .route("/admin/logout", get(auth::admin_logout))
        .route("/admin/check-login", get(auth::check_login))
        .route("/admin/list", get(catalog::admin_list))
        .route("/admin/create", post(catalog::admin_create))
        .route("/admin/edit", post(catalog::admin_edit))
        .route("/admin/edit-password", post(catalog::admin_edit_password))
        .route(
            "/admin/notification-settings",
            post(catalog::admin_notification_settings),
        )
        .route("/admin/delete", post(catalog::admin_delete))
        .route("/analytics/overview", get(analytics::analytics_overview))
        .route("/analytics/weekly", get(analytics::analytics_weekly))
        .route(
            "/analytics/overview/export",
            get(analytics::analytics_export),
        )
        .route("/analytics/weekly/export", get(analytics::analytics_export))
        .route("/catalog/invalidate", post(catalog::invalidate_catalog))
        .layer(PropagateRequestIdLayer::new(request_id_header.clone()))
        .layer(TraceLayer::new_for_http())
        .layer(SetRequestIdLayer::new(request_id_header, MakeRequestUuid))
        .layer(
            CorsLayer::new()
                .allow_origin(allowed_origins)
                .allow_methods([Method::GET, Method::POST, Method::OPTIONS])
                .allow_headers([
                    header::ACCEPT,
                    header::CONTENT_TYPE,
                    HeaderName::from_static("x-csrf-token"),
                    HeaderName::from_static("x-request-id"),
                ])
                .allow_credentials(true),
        )
        .with_state(state)
}
