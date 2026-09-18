use base64::{Engine as _, engine::general_purpose::URL_SAFE_NO_PAD};
use chrono::{DateTime, NaiveDateTime, Utc};
use chrono_tz::Asia::Shanghai;
use rand::RngCore;
use sha2::{Digest, Sha256};

pub(crate) fn epoch_to_local(epoch: i64) -> Option<NaiveDateTime> {
    DateTime::<Utc>::from_timestamp(epoch, 0).map(|dt| dt.with_timezone(&Shanghai).naive_local())
}

pub(crate) fn local_to_json(value: NaiveDateTime) -> String {
    value.format("%Y-%m-%dT%H:%M:%S").to_string()
}

pub(crate) fn token() -> String {
    let mut bytes = [0_u8; 32];
    rand::rng().fill_bytes(&mut bytes);
    URL_SAFE_NO_PAD.encode(bytes)
}

pub(crate) fn token_hash(raw: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(raw.as_bytes());
    format!("{:x}", hasher.finalize())
}
