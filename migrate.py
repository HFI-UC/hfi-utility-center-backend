from __future__ import annotations

import logging
from datetime import datetime, timezone
from typing import Iterable, Mapping, Optional

from sqlalchemy import MetaData, Table, create_engine, select as sa_select
from sqlalchemy.engine import Engine
from sqlmodel import Session, select

from core.types import Admin, Class, Reservation, Room

from dotenv import load_dotenv

import os

load_dotenv()

database_url = os.getenv("DATABASE_URL") or ""
legacy_database_url = os.getenv("LEGACY_DATABASE_URL") or ""

logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
logger = logging.getLogger(__name__)


ROOM_NAME_TO_NUMBER = {
	"iStudy Meeting Room 1": 101,
	"iStudy Meeting Room 2": 102,
	"Writing Center 1": 103,
	"Writing Center 2": 106,
}

ROOM_NUMBER_TO_NAME = {number: name for name, number in ROOM_NAME_TO_NUMBER.items()}

STATUS_MAP = {
	"no": "rejected",
	"yes": "approved",
	"non": "pending",
}


def _ensure_engine(url: str, label: str) -> Engine:
	if not url:
		raise RuntimeError(f"{label} environment variable is not set")
	return create_engine(url)


def _parse_epoch(value: object | None, *, assume_milliseconds: bool = False) -> Optional[datetime]:
	if value is None:
		return None
	text = str(value).strip()
	if not text:
		return None
	try:
		number = float(text)
	except ValueError:
		return None
	if assume_milliseconds or number >= 1_000_000_000_000:
		number /= 1000
	try:
		return datetime.fromtimestamp(number).astimezone(timezone.utc)
	except Exception:
		return None


def _parse_time_range(raw: object | None) -> tuple[Optional[datetime], Optional[datetime]]:
	if raw is None:
		return None, None
	parts = [part.strip() for part in str(raw).split("-") if part.strip()]
	if not parts:
		return None, None
	start = _parse_epoch(parts[0], assume_milliseconds=True)
	end = _parse_epoch(parts[1] if len(parts) > 1 else parts[0], assume_milliseconds=True)
	return start, end


def _parse_created_at(raw: object | None) -> Optional[datetime]:
	if raw is None:
		return None
	text = str(raw).strip()
	if not text:
		return None
	if text.isdigit():
		return _parse_epoch(text)
	normalized = text.replace("T", " ").replace("Z", "+00:00")
	try:
		dt = datetime.fromisoformat(normalized)
		return dt.astimezone(timezone.utc) if dt.tzinfo else dt.replace(tzinfo=timezone.utc)
	except ValueError:
		pass
	for fmt in ("%Y-%m-%d %H:%M:%S", "%Y/%m/%d %H:%M:%S"):
		try:
			return datetime.strptime(text, fmt).replace(tzinfo=timezone.utc)
		except ValueError:
			continue
	logger.warning("Unable to parse createdAt value '%s'", text)
	return None


def _split_name_field(raw: object | None) -> tuple[str, Optional[str]]:
	if not raw:
		return "", None
	parts = [part.strip() for part in str(raw).split(" / ")]
	student_name = parts[0] if parts else ""
	class_name = parts[1] if len(parts) > 1 else None
	return student_name, class_name


def _resolve_class_id(session: Session, class_name: Optional[str]) -> Optional[int]:
	if not class_name:
		return None
	class_obj = session.exec(select(Class).where(Class.name == class_name)).one_or_none()
	if class_obj:
		return class_obj.id
	logger.info("Class '%s' not found; leaving classId empty", class_name)
	return None


def _resolve_room_id(session: Session, raw_value: object | None) -> Optional[int]:
	if raw_value is None:
		return None
	text = str(raw_value).strip()
	if not text:
		return None
	numeric = None
	try:
		numeric = int(float(text))
	except ValueError:
		pass
	if numeric is not None and numeric in ROOM_NUMBER_TO_NAME:
		target_name = ROOM_NUMBER_TO_NAME[numeric]
	else:
		target_name = text
	room_obj = session.exec(select(Room).where(Room.name == target_name)).one_or_none()
	if room_obj:
		return room_obj.id
	logger.info(
		"Room '%s' (resolved from '%s') not found; leaving roomId empty",
		target_name,
		raw_value,
	)
	return None


def _resolve_admin_id(session: Session, operator_value: object | None) -> Optional[int]:
	if not operator_value:
		return None
	text = str(operator_value).strip()
	if not text:
		return None
	admin_obj = session.exec(select(Admin).where(Admin.name == text)).one_or_none()
	if not admin_obj and "@" in text:
		admin_obj = session.exec(select(Admin).where(Admin.email == text)).one_or_none()
	if admin_obj:
		return admin_obj.id
	logger.info("Admin '%s' not found; leaving latestExecutorId empty", text)
	return None


def _derive_student_id(raw_sid: object | None) -> str:
	if not raw_sid:
		return ""
	text = str(raw_sid).strip()
	if len(text) == 8:
		return f"GJ{text}"
	return ""


def _normalize_status(raw_status: object | None) -> str:
	if not raw_status:
		return "pending"
	return STATUS_MAP.get(str(raw_status).strip().lower(), "pending")


def _load_source_rows(engine: Engine) -> Iterable[tuple[str, Mapping[str, object]]]:
	metadata = MetaData()
	tables: dict[str, Table] = {}
	for table_name in ("history", "requests"):
		tables[table_name] = Table(table_name, metadata, autoload_with=engine)
	with engine.connect() as connection:
		for table_name, table in tables.items():
			result = connection.execute(sa_select(table)).mappings()
			for row in result:
				yield table_name, dict(row)


def _build_reservation(session: Session, row: Mapping[str, object]) -> Optional[Reservation]:
	raw_id = row.get("id")
	if raw_id is None:
		logger.warning("Skipping row without id: %s", row)
		return None
	try:
		reservation_id = int(float(str(raw_id).strip()))
	except (TypeError, ValueError):
		logger.warning("Invalid reservation id '%s'; skipping row", raw_id)
		return None

	start_time, end_time = _parse_time_range(row.get("time"))
	if not start_time or not end_time:
		logger.warning("Invalid time range for reservation %s; skipping", reservation_id)
		return None

	student_name, class_name = _split_name_field(row.get("name"))
	class_id = _resolve_class_id(session, class_name)
	room_id = _resolve_room_id(session, row.get("room"))
	latest_executor_id = _resolve_admin_id(session, row.get("operator"))
	created_at = _parse_created_at(row.get("addTime")) or start_time

	reservation = Reservation(
		id=reservation_id,
		roomId=room_id,
		startTime=start_time,
		endTime=end_time,
		studentName=student_name,
		email=str(row.get("email") or ""),
		reason=str(row.get("reason") or ""),
		classId=class_id,
		studentId=_derive_student_id(row.get("sid")),
		status=_normalize_status(row.get("auth")),
		latestExecutorId=latest_executor_id,
		createdAt=created_at,
	)
	return reservation


def migrate() -> None:
	target_engine = _ensure_engine(database_url, "DATABASE_URL")
	source_engine = (
		create_engine(legacy_database_url)
		if legacy_database_url
		else target_engine
	)
	inserted = 0
	updated = 0
	skipped = 0

	with Session(target_engine) as session:
		for source_table, row in _load_source_rows(source_engine):
			reservation = _build_reservation(session, row)
			if reservation is None:
				skipped += 1
				continue
			existing = session.get(Reservation, reservation.id)
			if existing:
				for field in (
					"roomId",
					"startTime",
					"endTime",
					"studentName",
					"email",
					"reason",
					"classId",
					"studentId",
					"status",
					"latestExecutorId",
					"createdAt",
				):
					setattr(existing, field, getattr(reservation, field))
				updated += 1
			else:
				session.add(reservation)
				inserted += 1
			logger.debug(
				"Processed reservation %s from %s", reservation.id if reservation else "<unknown>", source_table
			)
		session.commit()

	logger.info(
		"Migration finished: %s inserted, %s updated, %s skipped",
		inserted,
		updated,
		skipped,
	)


if __name__ == "__main__":
	migrate()

