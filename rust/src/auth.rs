use crate::util::token;
use crate::*;

pub(crate) fn cookie(headers: &HeaderMap, name: &str) -> Option<String> {
    headers
        .get(header::COOKIE)
        .and_then(|v| v.to_str().ok())
        .and_then(|value| {
            value.split(';').map(str::trim).find_map(|part| {
                let (key, value) = part.split_once('=')?;
                (key == name).then(|| value.to_string())
            })
        })
}

pub(crate) async fn current_admin(state: &AppState, headers: &HeaderMap) -> Option<AdminRow> {
    let session = cookie(headers, "uc")?;
    sqlx::query_as::<_, AdminRow>(
        "SELECT a.id,a.email,a.name,a.password FROM adminlogin l JOIN admin a ON a.email=l.email WHERE l.cookie=$1 AND l.expiry > now()",
    )
    .bind(session)
    .fetch_optional(&state.db)
    .await
    .ok()
    .flatten()
}

pub(crate) async fn consume_csrf(state: &AppState, headers: &HeaderMap) -> bool {
    let Some(value) = headers.get("x-csrf-token").and_then(|v| v.to_str().ok()) else {
        return false;
    };
    let now = Utc::now();
    let mut tokens = state.csrf.write().await;
    tokens.retain(|_, expires| *expires > now);
    tokens.remove(value).is_some()
}

async fn verify_turnstile(state: &AppState, response: &str) -> bool {
    if state.config.cloudflare_secret.is_empty() || response.is_empty() {
        return false;
    }
    let Ok(response) = state
        .http
        .post("https://challenges.cloudflare.com/turnstile/v0/siteverify")
        .form(&[
            ("secret", state.config.cloudflare_secret.as_str()),
            ("response", response),
        ])
        .send()
        .await
    else {
        return false;
    };
    let Ok(body) = response.json::<Value>().await else {
        return false;
    };
    body.get("success")
        .and_then(Value::as_bool)
        .unwrap_or(false)
}

pub(crate) async fn root() -> Html<&'static str> {
    Html(include_str!("../public/index.html"))
}

pub(crate) async fn health() -> Response {
    ok(json!({"status":"ok","service":"hfiuc-rust","time":Utc::now()}))
}

pub(crate) async fn csrf(State(state): State<AppState>) -> Response {
    let raw = token();
    state
        .csrf
        .write()
        .await
        .insert(raw.clone(), Utc::now() + Duration::minutes(10));
    let mut response = respond(StatusCode::OK, Some(json!(raw.clone())), None);
    response
        .headers_mut()
        .insert("x-csrf-token", HeaderValue::from_str(&raw).unwrap());
    response
}

pub(crate) async fn admin_login(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<LoginRequest>,
) -> Response {
    if current_admin(&state, &headers).await.is_some() {
        return fail(StatusCode::BAD_REQUEST, "User already logged in.");
    }
    if !consume_csrf(&state, &headers).await {
        return fail(StatusCode::FORBIDDEN, "CSRF token missing or invalid.");
    }
    if let Some(login_token) = payload.token.as_deref() {
        let temp = sqlx::query(
            "SELECT id,email FROM tempadminlogin WHERE token=$1 AND \"createdAt\" > now()-interval '15 minutes'",
        )
        .bind(login_token)
        .fetch_optional(&state.db)
        .await
        .ok()
        .flatten();
        let Some(temp) = temp else {
            return fail(StatusCode::BAD_REQUEST, "Invalid token or token expired.");
        };
        let email: String = temp.get("email");
        let session = token();
        let mut tx = match state.db.begin().await {
            Ok(tx) => tx,
            Err(_) => {
                return fail(
                    StatusCode::INTERNAL_SERVER_ERROR,
                    "Unable to create session",
                );
            }
        };
        if sqlx::query(
            "INSERT INTO adminlogin (email,cookie,expiry) VALUES ($1,$2,now()+interval '1 hour')",
        )
        .bind(email)
        .bind(&session)
        .execute(&mut *tx)
        .await
        .is_err()
            || sqlx::query("DELETE FROM tempadminlogin WHERE id=$1")
                .bind(temp.get::<i32, _>("id"))
                .execute(&mut *tx)
                .await
                .is_err()
            || tx.commit().await.is_err()
        {
            return fail(
                StatusCode::INTERNAL_SERVER_ERROR,
                "Unable to create session",
            );
        }
        let mut response = message("Login successful.");
        response.headers_mut().append(
            header::SET_COOKIE,
            HeaderValue::from_str(&format!(
                "uc={session}; Path=/; HttpOnly; Secure; SameSite=None"
            ))
            .unwrap(),
        );
        return response;
    }
    let Some(email) = payload.email else {
        return fail(StatusCode::BAD_REQUEST, "Email and password are required.");
    };
    let Some(password) = payload.password else {
        return fail(StatusCode::BAD_REQUEST, "Email and password are required.");
    };
    let Some(turnstile_token) = payload.turnstile_token.as_deref() else {
        return fail(StatusCode::FORBIDDEN, "Turnstile verification failed.");
    };
    if !verify_turnstile(&state, turnstile_token).await {
        return fail(StatusCode::FORBIDDEN, "Turnstile verification failed.");
    }
    let admin =
        sqlx::query_as::<_, AdminRow>("SELECT id,email,name,password FROM admin WHERE email=$1")
            .bind(email)
            .fetch_optional(&state.db)
            .await
            .ok()
            .flatten();
    let Some(admin) = admin else {
        return fail(StatusCode::UNAUTHORIZED, "Invalid email or password.");
    };
    if !bcrypt_verify(password, &admin.password).unwrap_or(false) {
        return fail(StatusCode::UNAUTHORIZED, "Invalid email or password.");
    }
    let session = token();
    if sqlx::query(
        "INSERT INTO adminlogin (email,cookie,expiry) VALUES ($1,$2,now()+interval '1 hour')",
    )
    .bind(admin.email)
    .bind(&session)
    .execute(&state.db)
    .await
    .is_err()
    {
        return fail(
            StatusCode::INTERNAL_SERVER_ERROR,
            "Unable to create session",
        );
    }
    let mut response = message("Login successful.");
    response.headers_mut().append(
        header::SET_COOKIE,
        HeaderValue::from_str(&format!(
            "uc={session}; Path=/; HttpOnly; Secure; SameSite=None"
        ))
        .unwrap(),
    );
    response
}

pub(crate) async fn admin_logout(State(state): State<AppState>, headers: HeaderMap) -> Response {
    let Some(session) = cookie(&headers, "uc") else {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    };
    let deleted = sqlx::query("DELETE FROM adminlogin WHERE cookie=$1")
        .bind(session)
        .execute(&state.db)
        .await
        .map(|result| result.rows_affected())
        .unwrap_or(0);
    if deleted == 0 {
        return fail(StatusCode::UNAUTHORIZED, "User is not logged in.");
    }
    let mut response = message("Logout successful.");
    response.headers_mut().append(
        header::SET_COOKIE,
        HeaderValue::from_static("uc=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=None"),
    );
    response
}

pub(crate) async fn check_login(State(state): State<AppState>, headers: HeaderMap) -> Response {
    if let Some(admin) = current_admin(&state, &headers).await {
        return ok(json!({"email":admin.email,"name":admin.name}));
    }
    fail(StatusCode::BAD_REQUEST, "User is not logged in.")
}

#[allow(clippy::result_large_err)]
pub(crate) async fn require_admin_write(
    state: &AppState,
    headers: &HeaderMap,
) -> Result<AdminRow, Response> {
    let Some(admin) = current_admin(state, headers).await else {
        return Err(fail(StatusCode::UNAUTHORIZED, "User is not logged in."));
    };
    if !consume_csrf(state, headers).await {
        return Err(fail(
            StatusCode::FORBIDDEN,
            "CSRF token missing or invalid.",
        ));
    }
    Ok(admin)
}
