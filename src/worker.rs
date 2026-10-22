use crate::util::{token, token_hash};
use crate::*;
use lettre::{AsyncSmtpTransport, AsyncTransport, Message, Tokio1Executor, message::Mailbox};
use tokio::time::{Duration as TokioDuration, sleep};
use tracing::{info, warn};

#[derive(Deserialize)]
struct AiApprovalResponse {
    status: String,
    message: Option<String>,
}

pub(crate) async fn run_worker(state: AppState) {
    info!("outbox worker started");
    loop {
        if let Err(err) = process_one_job(&state).await {
            warn!(%err, "outbox processing failed");
        }
        sleep(TokioDuration::from_millis(500)).await;
    }
}

async fn process_one_job(state: &AppState) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let mut tx = state.db.begin().await?;
    let row = sqlx::query(
        "SELECT id,kind,payload FROM outboxjob WHERE status='pending' AND \"availableAt\" <= now() ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1",
    )
    .fetch_optional(&mut *tx)
    .await?;
    let Some(row) = row else {
        tx.rollback().await?;
        return Ok(());
    };
    let id: i64 = row.get("id");
    let kind: String = row.get("kind");
    let payload: Value = row.get("payload");
    sqlx::query(
        "UPDATE outboxjob SET status='processing',\"lockedAt\"=now(),attempts=attempts+1 WHERE id=$1",
    )
    .bind(id)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;

    let result = match kind.as_str() {
        "ai_approval" => ai_approval(state, payload).await,
        "reservation_created"
        | "reservation_modified"
        | "reservation_cancelled"
        | "reservation_status_changed" => send_job_email(state, &kind, payload).await,
        "admin_reservation_notification" => send_admin_notification(state, payload).await,
        _ => Ok(()),
    };
    match result {
        Ok(()) => {
            sqlx::query(
                "UPDATE outboxjob SET status='completed',\"completedAt\"=now() WHERE id=$1",
            )
            .bind(id)
            .execute(&state.db)
            .await?;
        }
        Err(err) => {
            sqlx::query("UPDATE outboxjob SET status=CASE WHEN attempts >= 8 THEN 'failed' ELSE 'pending' END,\"lastError\"=$2,\"availableAt\"=now()+least(power(2,attempts),300)*interval '1 second' WHERE id=$1")
                .bind(id)
                .bind(err.to_string())
                .execute(&state.db)
                .await?;
        }
    }
    Ok(())
}

async fn send_admin_notification(
    state: &AppState,
    payload: Value,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    if state.config.smtp_server.is_empty() || state.config.smtp_email.is_empty() {
        return Ok(());
    }
    let reservation_id = payload
        .get("reservationId")
        .and_then(Value::as_i64)
        .unwrap_or_default() as i32;
    let admin_id = payload
        .get("adminId")
        .and_then(Value::as_i64)
        .unwrap_or_default() as i32;
    let Some(row) = sqlx::query(
        "SELECT a.email AS admin_email,r.\"studentName\",r.\"studentId\",r.reason,r.\"startTime\",r.\"endTime\",r.\"purposeType\",r.\"needsMultimedia\",rm.name AS room_name,c.name AS class_name,cp.name AS campus_name FROM admin a CROSS JOIN reservation r LEFT JOIN room rm ON rm.id=r.\"roomId\" LEFT JOIN class c ON c.id=r.\"classId\" LEFT JOIN campus cp ON cp.id=rm.\"campusId\" WHERE a.id=$1 AND a.\"receiveReservationNotifications\"=TRUE AND r.id=$2",
    )
    .bind(admin_id)
    .bind(reservation_id)
    .fetch_optional(&state.db)
    .await?
    else {
        return Ok(());
    };
    let email: String = row.get("admin_email");
    let start: NaiveDateTime = row.get("startTime");
    let end: NaiveDateTime = row.get("endTime");
    let body = reservation_email_html(
        "New reservation awaiting review",
        "A new reservation was submitted. All administrators may review it in the management platform.",
        &row.get::<String, _>("studentName"),
        row.get::<Option<String>, _>("studentId")
            .as_deref()
            .unwrap_or("—"),
        row.get::<Option<String>, _>("room_name")
            .as_deref()
            .unwrap_or("—"),
        row.get::<Option<String>, _>("class_name")
            .as_deref()
            .unwrap_or("—"),
        row.get::<Option<String>, _>("campus_name")
            .as_deref()
            .unwrap_or("—"),
        &row.get::<String, _>("reason"),
        row.get::<Option<String>, _>("purposeType")
            .as_deref()
            .unwrap_or("—"),
        row.get::<bool, _>("needsMultimedia"),
        start,
        end,
        None,
    );
    let message = Message::builder()
        .from(Mailbox::new(
            Some("HFI-UC".into()),
            state.config.smtp_email.parse()?,
        ))
        .to(email.parse()?)
        .subject("[HFI-UC] New reservation awaiting review")
        .header(lettre::message::header::ContentType::TEXT_HTML)
        .body(body)?;
    let transport = AsyncSmtpTransport::<Tokio1Executor>::relay(&state.config.smtp_server)?
        .credentials(lettre::transport::smtp::authentication::Credentials::new(
            state.config.smtp_email.clone(),
            state.config.smtp_password.clone(),
        ))
        .build();
    transport.send(message).await?;
    Ok(())
}

async fn ai_approval(
    state: &AppState,
    payload: Value,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    if !state.config.ai_enabled || state.config.ai_url.is_empty() {
        return Ok(());
    }
    let id = payload
        .get("reservationId")
        .and_then(Value::as_i64)
        .unwrap_or_default() as i32;
    if id <= 0 {
        return Err(std::io::Error::other("AI approval job has no reservation ID").into());
    }
    let Some(row) = sqlx::query("SELECT reason FROM reservation WHERE id=$1")
        .bind(id)
        .fetch_optional(&state.db)
        .await?
    else {
        return Ok(());
    };
    let reason: String = row.get("reason");
    let response = state
        .http
        .get(&state.config.ai_url)
        .query(&[
            ("s", state.config.ai_secret.as_str()),
            ("reason", reason.as_str()),
        ])
        .timeout(std::time::Duration::from_secs(10))
        .send()
        .await
        .map_err(|_| std::io::Error::other("AI approval service request failed"))?;
    if !response.status().is_success() {
        return Err(std::io::Error::other(format!(
            "AI approval service returned HTTP {}",
            response.status()
        ))
        .into());
    }
    let body = response
        .json::<AiApprovalResponse>()
        .await
        .map_err(|_| std::io::Error::other("AI approval service returned invalid JSON"))?;
    let status = body.status.as_str();
    if status == "pending" {
        return Ok(());
    }
    if status == "approved" || status == "rejected" {
        let mut tx = state.db.begin().await?;
        let changed = sqlx::query("UPDATE reservation SET status=$1,\"latestExecutorId\"=$2 WHERE id=$3 AND status='pending' RETURNING \"startTime\"")
            .bind(status)
            .bind(state.config.ai_admin_id)
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
        if let Some(changed) = changed {
            let raw_token = if status == "approved" {
                let raw = token();
                sqlx::query("INSERT INTO reservationcanceltoken (\"reservationId\",\"tokenHash\",\"expiresAt\") VALUES ($1,$2,$3)")
                    .bind(id)
                    .bind(token_hash(&raw))
                    .bind(changed.get::<NaiveDateTime, _>("startTime"))
                    .execute(&mut *tx)
                    .await?;
                Some(raw)
            } else {
                None
            };
            sqlx::query(
                "INSERT INTO outboxjob (kind,payload) VALUES ('reservation_status_changed',$1)",
            )
            .bind(json!({"reservationId":id,"status":status,"cancelToken":raw_token}))
            .execute(&mut *tx)
            .await?;
            sqlx::query("INSERT INTO reservationoperationlog (\"adminId\",\"reservationId\",operation,reason) VALUES ($1,$2,$3,$4)")
                .bind(state.config.ai_admin_id)
                .bind(id)
                .bind(status)
                .bind(body.message.as_deref())
                .execute(&mut *tx)
                .await?;
        }
        tx.commit().await?;
        return Ok(());
    }
    Err(std::io::Error::other("AI approval service returned an unsupported status").into())
}

async fn send_job_email(
    state: &AppState,
    kind: &str,
    payload: Value,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    if state.config.smtp_server.is_empty() || state.config.smtp_email.is_empty() {
        return Ok(());
    }
    let id = payload
        .get("reservationId")
        .and_then(Value::as_i64)
        .unwrap_or_default() as i32;
    let Some(reservation) = sqlx::query(
        "SELECT r.email,r.\"studentName\",r.\"studentId\",r.reason,r.status,r.\"startTime\",r.\"endTime\",r.\"purposeType\",r.\"needsMultimedia\",rm.name AS room_name,c.name AS class_name,cp.name AS campus_name FROM reservation r LEFT JOIN room rm ON rm.id=r.\"roomId\" LEFT JOIN class c ON c.id=r.\"classId\" LEFT JOIN campus cp ON cp.id=rm.\"campusId\" WHERE r.id=$1",
    )
    .bind(id)
    .fetch_optional(&state.db)
    .await?
    else {
        return Ok(());
    };

    let email: String = reservation.get("email");
    if email.is_empty() {
        return Ok(());
    }
    let stored_status: String = reservation.get("status");
    let status = payload
        .get("status")
        .and_then(Value::as_str)
        .unwrap_or(&stored_status);
    let (subject, title, details) = match kind {
        "reservation_created" => (
            "Reservation Created",
            "Your reservation has been created",
            "We received your reservation request. You can review the details below.",
        ),
        "reservation_modified" => (
            "Reservation Modified",
            "Your reservation has been updated",
            "The reservation details were changed successfully. Please review the latest room and time below.",
        ),
        "reservation_cancelled"
            if payload.get("reason").and_then(Value::as_str) == Some("higher_priority") =>
        {
            (
                "Reservation Cancelled",
                "Your reservation was cancelled",
                "A higher-priority Office Teachers reservation requires this room and time. Your original reservation has been cancelled automatically, and the time is no longer available.",
            )
        }
        "reservation_cancelled" => (
            "Reservation Cancelled",
            "Your reservation has been cancelled",
            "This reservation is no longer active. The released time is available for booking again.",
        ),
        _ if status == "approved" => (
            "Reservation Approved",
            "Your reservation has been approved",
            "Your reservation request was approved. Please arrive on time and follow the room rules.",
        ),
        _ if status == "rejected" => (
            "Reservation Rejected",
            "Your reservation was not approved",
            "Your reservation request was reviewed but could not be approved.",
        ),
        _ => (
            "Reservation Updated",
            "Your reservation has been updated",
            "The status of your reservation changed. The latest details are shown below.",
        ),
    };
    let student_name: String = reservation.get("studentName");
    let student_id: Option<String> = reservation.get("studentId");
    let reason: String = reservation.get("reason");
    let room_name: Option<String> = reservation.get("room_name");
    let class_name: Option<String> = reservation.get("class_name");
    let campus_name: Option<String> = reservation.get("campus_name");
    let start: NaiveDateTime = reservation.get("startTime");
    let end: NaiveDateTime = reservation.get("endTime");
    let purpose: Option<String> = reservation.get("purposeType");
    let needs_multimedia: bool = reservation.get("needsMultimedia");
    let action_url = payload
        .get("cancelToken")
        .and_then(Value::as_str)
        .map(|token| {
            format!(
                "{}/reservation/cancel?token={}",
                state.config.frontend_url.trim_end_matches('/'),
                token
            )
        });
    let body = reservation_email_html(
        title,
        details,
        &student_name,
        student_id.as_deref().unwrap_or("—"),
        room_name.as_deref().unwrap_or("—"),
        class_name.as_deref().unwrap_or("—"),
        campus_name.as_deref().unwrap_or("—"),
        &reason,
        purpose.as_deref().unwrap_or("—"),
        needs_multimedia,
        start,
        end,
        action_url.as_deref(),
    );
    let message = Message::builder()
        .from(Mailbox::new(
            Some("HFI-UC".into()),
            state.config.smtp_email.parse()?,
        ))
        .to(email.parse()?)
        .subject(format!("[HFI-UC] {subject}"))
        .header(lettre::message::header::ContentType::TEXT_HTML)
        .body(body)?;
    let transport = AsyncSmtpTransport::<Tokio1Executor>::relay(&state.config.smtp_server)?
        .credentials(lettre::transport::smtp::authentication::Credentials::new(
            state.config.smtp_email.clone(),
            state.config.smtp_password.clone(),
        ))
        .build();
    transport.send(message).await?;
    Ok(())
}

#[allow(clippy::too_many_arguments)]
fn reservation_email_html(
    title: &str,
    details: &str,
    student_name: &str,
    student_id: &str,
    room: &str,
    _class_name: &str,
    campus: &str,
    reason: &str,
    purpose: &str,
    needs_multimedia: bool,
    start: NaiveDateTime,
    end: NaiveDateTime,
    action_url: Option<&str>,
) -> String {
    let detail_card = |icon: &str, label: &str, value: &str| {
        format!(
            "<td width=\"50%\" style=\"padding:6px;vertical-align:top\"><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f8f7fb;border:1px solid #ebe8ef;border-radius:16px\"><tr><td style=\"padding:16px\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\"><tr><td style=\"width:34px;height:34px;border-radius:11px;background:#fff0eb;color:#d74220;text-align:center;vertical-align:middle;font-size:17px\">{}</td><td style=\"padding-left:11px\"><span style=\"display:block;color:#777680;font-size:10px;line-height:15px;letter-spacing:.08em;text-transform:uppercase\">{}</span><strong style=\"display:block;margin-top:2px;color:#1b1b1f;font-size:13px;line-height:19px\">{}</strong></td></tr></table></td></tr></table></td>",
            icon,
            escape_html(label),
            escape_html(value)
        )
    };
    let action = action_url.map_or_else(String::new, |url| {
        format!(
            "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin-top:24px\"><tr><td align=\"center\"><a href=\"{}\" target=\"_blank\" style=\"display:block;padding:16px 24px;background:#ff5a36;color:#ffffff;text-decoration:none;font-size:14px;font-weight:750;border-radius:14px;box-shadow:0 8px 20px rgba(255,90,54,.22)\">Manage reservation&nbsp;&nbsp;→</a></td></tr></table><p style=\"margin:12px 6px 0;color:#74777f;font-size:11px;line-height:17px;text-align:center\">This private link lets you modify or cancel before the reservation begins.</p>",
            escape_html(url)
        )
    });
    let time = format!(
        "{} – {}",
        start.format("%Y-%m-%d %H:%M"),
        end.format("%H:%M")
    );
    let person = format!("{student_name} · {student_id}");
    let place = format!("{room} · {campus}");
    let equipment = if needs_multimedia {
        "Multimedia equipment required"
    } else {
        "No multimedia equipment"
    };
    let cards = format!(
        "<tr>{}{}</tr><tr>{}{}</tr><tr>{}{}</tr>",
        detail_card("&#9673;", "Location", &place),
        detail_card("&#9719;", "Date & time", &time),
        detail_card("&#9786;", "Reserved by", &person),
        detail_card("&#9670;", "Purpose", purpose),
        detail_card("&#9638;", "Equipment", equipment),
        detail_card("&#9998;", "Reason", reason),
    );
    let approved = title.to_ascii_lowercase().contains("approved");
    let rejected = title.to_ascii_lowercase().contains("rejected")
        || title.to_ascii_lowercase().contains("cancelled");
    let accent = if approved {
        "#0f9f6e"
    } else if rejected {
        "#c43b32"
    } else {
        "#ff5a36"
    };
    let accent_soft = if approved {
        "#e7f7f1"
    } else if rejected {
        "#fceceb"
    } else {
        "#fff0eb"
    };
    let hero_icon = if approved {
        "&#10003;"
    } else if rejected {
        "&#10005;"
    } else {
        "&#9719;"
    };

    format!(
        "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{}</title></head><body style=\"margin:0;padding:0;background:#f7f7fb;font-family:Inter,Roboto,Arial,sans-serif;color:#1b1b1f\"><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr><td align=\"center\" style=\"padding:48px 16px\"><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:640px;background:#ffffff;border:1px solid #e4e3e8;border-radius:28px;overflow:hidden;box-shadow:0 18px 54px rgba(37,38,44,.10)\"><tr><td style=\"height:6px;background:{};font-size:0\">&nbsp;</td></tr><tr><td style=\"padding:32px 38px 30px\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\"><tr><td style=\"width:48px;height:48px;background:#fff0eb;border-radius:16px;text-align:center;vertical-align:middle\"><img src=\"https://s21.ax1x.com/2025/09/25/pV5T6mt.png\" width=\"34\" height=\"34\" alt=\"HFI\" style=\"display:inline-block;border:0;vertical-align:middle\"></td><td style=\"padding-left:12px\"><strong style=\"display:block;color:#1b1b1f;font-size:15px;line-height:20px\">HFI Utility Center</strong><span style=\"display:block;color:#777680;font-size:10px;line-height:16px;letter-spacing:.12em;text-transform:uppercase\">Reservation service</span></td></tr></table><div style=\"margin-top:32px\"><span style=\"display:inline-block;width:48px;height:48px;line-height:48px;background:{};color:{};border-radius:16px;text-align:center;font-size:24px;font-weight:800\">{}</span><h1 style=\"margin:18px 0 0;color:#1b1b1f;font-size:28px;line-height:35px;letter-spacing:-.03em\">{}</h1><p style=\"margin:10px 0 0;color:#5f6068;font-size:14px;line-height:23px\">{}</p></div><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin-top:24px;border-collapse:separate;border-spacing:0\">{}</table>{}<div style=\"margin-top:30px;padding-top:20px;border-top:1px solid #ecebf0;color:#85858e;font-size:11px;line-height:18px;text-align:center\">© MAKERs&apos; Club 2026</div></td></tr></table></td></tr></table></body></html>",
        escape_html(title),
        accent,
        accent_soft,
        accent,
        hero_icon,
        escape_html(title),
        escape_html(details),
        cards,
        action
    )
}

fn escape_html(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn creation_email_keeps_details_and_cancel_action() {
        let start = NaiveDateTime::parse_from_str("2026-09-17 08:00", "%Y-%m-%d %H:%M")
            .expect("valid start");
        let end =
            NaiveDateTime::parse_from_str("2026-09-17 09:00", "%Y-%m-%d %H:%M").expect("valid end");
        let html = reservation_email_html(
            "Reservation created",
            "Details",
            "A & B",
            "GJ20280001",
            "505",
            "Lieb",
            "Knowledge City Campus",
            "Club <meeting>",
            "Club",
            true,
            start,
            end,
            Some("https://www.hfiuc.org/reservation/cancel?token=abc"),
        );

        assert!(html.contains("Manage reservation"));
        assert!(html.contains("A &amp; B"));
        assert!(html.contains("Club &lt;meeting&gt;"));
        assert!(html.contains("Multimedia equipment"));
        if let Ok(path) = std::env::var("HFIUC_EMAIL_PREVIEW_PATH") {
            std::fs::write(path, &html).expect("write email preview");
        }
    }

    #[test]
    fn approved_email_keeps_management_action() {
        let start = NaiveDateTime::parse_from_str("2026-09-17 08:00", "%Y-%m-%d %H:%M")
            .expect("valid start");
        let end =
            NaiveDateTime::parse_from_str("2026-09-17 09:00", "%Y-%m-%d %H:%M").expect("valid end");
        let html = reservation_email_html(
            "Reservation approved",
            "Details",
            "Student",
            "GJ20280001",
            "505",
            "Lieb",
            "Knowledge City Campus",
            "Meeting",
            "Personal",
            false,
            start,
            end,
            Some("https://www.hfiuc.org/reservation/cancel?token=approved"),
        );

        assert!(html.contains("Manage reservation"));
        if let Ok(path) = std::env::var("HFIUC_APPROVAL_EMAIL_PREVIEW_PATH") {
            std::fs::write(path, &html).expect("write approval email preview");
        }
    }
}
