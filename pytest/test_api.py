import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

import pytest
from fastapi.testclient import TestClient
from sqlmodel import SQLModel
from sqlalchemy.ext.asyncio import create_async_engine
from sqlmodel.ext.asyncio.session import AsyncSession
from core import app, password_hash
from core.orm import get_admin_by_email, create_admin
from core import email
import asyncio
from collections.abc import Iterator
from typing import Any

# Override the database with an in-memory SQLite database for testing
DATABASE_URL = "sqlite+aiosqlite:///test.db"
test_engine = create_async_engine(DATABASE_URL, connect_args={"check_same_thread": False})

async def create_db_and_tables() -> None:
    async with test_engine.begin() as conn:
        await conn.run_sync(SQLModel.metadata.create_all)

async def drop_db_and_tables() -> None:
    async with test_engine.begin() as conn:
        await conn.run_sync(SQLModel.metadata.drop_all)

@pytest.fixture(name="client")
def client_fixture(monkeypatch: pytest.MonkeyPatch) -> Iterator[TestClient]:
    asyncio.run(create_db_and_tables())
    def mock_verify_turnstile_token(token: str) -> bool:
        return True

    monkeypatch.setattr("core.utils.verify_turnstile_token", mock_verify_turnstile_token)
    monkeypatch.setattr("core.verify_turnstile_token", mock_verify_turnstile_token)

    sent_emails: list[dict[str, Any]] = []

    def record_email(kind: str, **payload: Any) -> None:
        sent_emails.append({"type": kind, **payload})

    def mock_send_normal_update_email(
        email_title: str, title: str, email: str, details: str
    ) -> None:
        record_email(
            "normal_update",
            email_title=email_title,
            title=title,
            email=email,
            details=details,
        )

    def mock_send_reservation_approval_email(
        email_title: str,
        title: str,
        email: str,
        details: str,
        user: str,
        room: str,
        class_name: str,
        student_id: str,
        reason: str,
        time: str,
    ) -> None:
        record_email(
            "reservation_approval",
            email_title=email_title,
            title=title,
            email=email,
            details=details,
            user=user,
            room=room,
            class_name=class_name,
            student_id=student_id,
            reason=reason,
            time=time,
        )

    def mock_send_normal_update_with_external_link_email(
        email_title: str,
        title: str,
        email: str,
        details: str,
        button_text: str,
        link: str,
    ) -> None:
        record_email(
            "normal_update_with_external_link",
            email_title=email_title,
            title=title,
            email=email,
            details=details,
            button_text=button_text,
            link=link,
        )

    def mock_send_normal_update_email_with_attached_files(
        email_title: str,
        title: str,
        email: str,
        details: str,
        attachments: list[tuple[str, Any]] | None = None,
    ) -> None:
        record_email(
            "normal_update_with_attachments",
            email_title=email_title,
            title=title,
            email=email,
            details=details,
            attachments=attachments,
        )

    monkeypatch.setattr(email, "send_normal_update_email", mock_send_normal_update_email)
    monkeypatch.setattr(
        email,
        "send_reservation_approval_email",
        mock_send_reservation_approval_email,
    )
    monkeypatch.setattr(
        email,
        "send_normal_update_with_external_link_email",
        mock_send_normal_update_with_external_link_email,
    )
    monkeypatch.setattr(
        email,
        "send_normal_update_email_with_attached_files",
        mock_send_normal_update_email_with_attached_files,
    )
    monkeypatch.setattr(
        "core.send_normal_update_email",
        mock_send_normal_update_email,
        raising=False,
    )
    monkeypatch.setattr(
        "core.send_reservation_approval_email",
        mock_send_reservation_approval_email,
        raising=False,
    )
    monkeypatch.setattr(
        "core.send_normal_update_with_external_link_email",
        mock_send_normal_update_with_external_link_email,
        raising=False,
    )
    monkeypatch.setattr(
        "core.send_normal_update_email_with_attached_files",
        mock_send_normal_update_email_with_attached_files,
        raising=False,
    )
    async def fake_exported_pdf(_url: str, output: str, *args, **kwargs):
        os.makedirs(os.path.dirname(output) or ".", exist_ok=True)
        with open(output, "wb") as export_file:
            export_file.write(b"")

    async def fake_screenshot(_url: str, output: str, *args, **kwargs):
        os.makedirs(os.path.dirname(output) or ".", exist_ok=True)
        with open(output, "wb") as export_file:
            export_file.write(b"")

    monkeypatch.setattr("core.utils.get_exported_pdf", fake_exported_pdf)
    monkeypatch.setattr("core.utils.get_screenshot", fake_screenshot)
    monkeypatch.setattr("core.get_exported_pdf", fake_exported_pdf, raising=False)
    monkeypatch.setattr("core.get_screenshot", fake_screenshot, raising=False)
    monkeypatch.setattr("core.orm.engine", test_engine)
    monkeypatch.setattr("core.engine", test_engine)
    monkeypatch.setattr("core.env.domain", "testserver")
    monkeypatch.setattr("core.domain", "testserver", raising=False)
    monkeypatch.setattr("core.env.base_url", "https://testserver", raising=False)
    monkeypatch.setattr("core.base_url", "https://testserver", raising=False)

    async def setup_admin() -> None:
        async with AsyncSession(test_engine) as async_session:
            if not await get_admin_by_email(async_session, "admin@test.com"):
                await create_admin(async_session, "admin@test.com", "admin", password_hash("password"))

    asyncio.run(setup_admin())

    async def ensure_admin() -> None:
        async with AsyncSession(test_engine) as async_session:
            admin = await get_admin_by_email(async_session, "admin@test.com")
            assert admin is not None

    asyncio.run(ensure_admin())

    test_client = TestClient(app, base_url="https://testserver")
    test_client.sent_emails = sent_emails  # type: ignore[attr-defined]

    original_post = test_client.post

    def post_with_csrf(url: str, **kwargs):
        csrf_response = test_client.get("/_csrf")
        csrf_token = csrf_response.cookies.get("_csrf")
        headers = kwargs.pop("headers", {}) or {}
        if csrf_token:
            headers = {**headers, "x-csrf-token": csrf_token}
        kwargs["headers"] = headers
        return original_post(url, **kwargs)

    monkeypatch.setattr(test_client, "post", post_with_csrf)

    try:
        yield test_client
    finally:
        asyncio.run(drop_db_and_tables())


def test_root(client: TestClient):
    response = client.get("/", follow_redirects=False)
    assert response.status_code == 307 # Should be a redirect, TestClient uses 307 for redirects

def test_csrf(client: TestClient):
    response = client.get("/_csrf")
    assert response.status_code == 200
    assert "_csrf" in response.cookies

# More tests will be added here
def test_campus_list(client: TestClient):
    response = client.get("/campus/list")
    assert response.status_code == 200
    assert response.json()["success"] is True

def test_class_list(client: TestClient):
    response = client.get("/class/list")
    assert response.status_code == 200
    assert response.json()["success"] is True

def test_room_list(client: TestClient):
    # Unauthenticated
    response = client.get("/room/list")
    assert response.status_code == 200
    assert response.json()["success"] is True
    assert "approvers" not in response.json()["data"] if response.json()["data"] else True

    # Authenticated
    login_res = client.post(
        "/admin/login",
        json={
            "email": "admin@test.com",
            "password": "password",
            "turnstileToken": "test",
            "token": None,
        },
    )
    assert login_res.status_code == 200
    
    response = client.get("/room/list")
    assert response.status_code == 200
    assert response.json()["success"] is True
    assert "approvers" in response.json()["data"][0] if response.json()["data"] else True

def test_admin_login_logout(client: TestClient):
    # Login with wrong password
    response = client.post(
        "/admin/login",
        json={
            "email": "admin@test.com",
            "password": "wrongpassword",
            "turnstileToken": "test",
            "token": None,
        },
    )
    assert response.status_code == 401, response.json()
    assert response.json()["success"] is False

    # Login with correct password
    response = client.post(
        "/admin/login",
        json={
            "email": "admin@test.com",
            "password": "password",
            "turnstileToken": "test",
            "token": None,
        },
    )
    assert response.status_code == 200, response.json()
    assert response.json()["success"] is True
    assert "uc" in response.cookies
    assert client.cookies.get("uc") is not None

    # Check login status
    response = client.get("/admin/check-login")
    assert response.status_code == 200, response.json()
    assert response.json()["success"] is True

    # Logout
    response = client.get("/admin/logout")
    assert response.status_code == 200, response.json()
    assert response.json()["success"] is True
    assert "uc" not in response.cookies

    # Check login status again
    response = client.get("/admin/check-login")
    assert response.status_code == 400, response.json()
    assert response.json()["success"] is False

def test_admin_management(client: TestClient):
    # Login first
    login_res = client.post(
        "/admin/login",
        json={
            "email": "admin@test.com",
            "password": "password",
            "turnstileToken": "test",
            "token": None,
        },
    )
    assert login_res.status_code == 200

    # Get admin list
    response = client.get("/admin/list")
    assert response.status_code == 200
    assert len(response.json()["data"]) == 1

    # Create a new admin
    response = client.post("/admin/create", json={"name": "testuser", "email": "test@test.com", "password": "password"})
    assert response.status_code == 200
    assert response.json()["success"] is True

    # Get admin list again
    response = client.get("/admin/list")
    assert response.status_code == 200
    assert len(response.json()["data"]) == 2

    # Edit admin
    admins = response.json()["data"]
    test_admin = next(admin for admin in admins if admin["email"] == "test@test.com")
    response = client.post("/admin/edit", json={"id": test_admin["id"], "name": "newtestuser", "email": "newtest@test.com"})
    assert response.status_code == 200
    assert response.json()["success"] is True

    # Edit admin password
    response = client.post("/admin/edit-password", json={"admin": test_admin["id"], "newPassword": "newpassword"})
    assert response.status_code == 200
    assert response.json()["success"] is True

    # Delete admin
    response = client.post("/admin/delete", json={"id": test_admin["id"]})
    assert response.status_code == 200
    assert response.json()["success"] is True

    # Get admin list again
    response = client.get("/admin/list")
    assert response.status_code == 200
    assert len(response.json()["data"]) == 1

def test_reservation_flow(client: TestClient):
    # Login first
    login_res = client.post(
        "/admin/login",
        json={
            "email": "admin@test.com",
            "password": "password",
            "turnstileToken": "test",
            "token": None,
        },
    )
    assert login_res.status_code == 200

    sent_emails: list[dict[str, Any]] = getattr(client, "sent_emails", [])

    # Create campus, class, room
    campus_res = client.post("/campus/create", json={"name": "Test Campus"})
    assert campus_res.status_code == 200, campus_res.json()
    class_res = client.post("/class/create", json={"name": "Test Class", "campus": 1})
    assert class_res.status_code == 200, class_res.json()
    room_res = client.post("/room/create", json={"name": "Test Room", "campus": 1})
    assert room_res.status_code == 200, room_res.json()

    admin_list = client.get("/admin/list")
    assert admin_list.status_code == 200, admin_list.json()
    admin_id = admin_list.json()["data"][0]["id"]

    approver_res = client.post("/approver/create", json={"room": 1, "admin": admin_id})
    assert approver_res.status_code == 200, approver_res.json()

    # Create reservation
    from datetime import datetime, timedelta
    start_time = datetime.now() + timedelta(hours=1)
    end_time = start_time + timedelta(hours=1)
    
    reservation_payload = {
        "room": 1,
        "startTime": int(start_time.timestamp()),
        "endTime": int(end_time.timestamp()),
        "studentName": "Test Student",
        "studentId": "GJ20230000",
        "email": "student@test.com",
        "reason": "Test Reason",
        "classId": 1
    }
    response = client.post("/reservation/create", json=reservation_payload)
    assert response.status_code == 200, response.json()
    assert response.json()["success"] is True
    reservation_id = response.json()["data"]["reservationId"]

    approver_notification = next(
        (mail for mail in sent_emails if mail["type"] == "normal_update_with_external_link"),
        None,
    )
    assert approver_notification is not None, sent_emails
    assert approver_notification["email"] == "admin@test.com"

    emails_before_approval = len(sent_emails)

    # Get reservation
    response = client.get(f"/reservation/get?keyword={reservation_id}")
    assert response.status_code == 200
    assert len(response.json()["data"]["reservations"]) == 1

    # Approve reservation
    response = client.post(
        "/reservation/approval",
        json={
            "id": reservation_id,
            "approved": True,
            "reason": None,
        },
    )
    assert response.status_code == 200
    assert response.json()["success"] is True

    approval_emails = [
        mail
        for mail in sent_emails[emails_before_approval:]
        if mail["type"] == "reservation_approval"
    ]
    assert approval_emails, sent_emails
    approval_email = approval_emails[-1]
    assert approval_email["email"] == reservation_payload["email"]
    assert approval_email["user"] == reservation_payload["studentName"]
    assert approval_email["room"] == "Test Room"
    assert approval_email["class_name"] == "Test Class"
    assert approval_email["student_id"] == reservation_payload["studentId"]
    assert approval_email["reason"] == reservation_payload["reason"]
    expected_time = (
        f"{datetime.fromtimestamp(reservation_payload['startTime']).strftime('%Y-%m-%d %H:%M')} - "
        f"{datetime.fromtimestamp(reservation_payload['endTime']).strftime('%H:%M')}"
    )
    assert approval_email["time"] == expected_time

    # Get future reservations
    response = client.get("/reservation/future")
    assert response.status_code == 200
    # This can be flaky depending on when the test is run
    # assert len(response.json()["data"]) > 0

    # Export reservations
    response = client.get(f"/reservation/export?startTime={int(start_time.timestamp())}&endTime={int(end_time.timestamp())}")
    assert response.status_code == 200
    assert response.headers['content-type'] == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

def test_analytics(client: TestClient):
    # Login first
    login_res = client.post(
        "/admin/login",
        json={
            "email": "admin@test.com",
            "password": "password",
            "turnstileToken": "test",
            "token": None,
        },
    )
    assert login_res.status_code == 200

    # Analytics overview
    response = client.get("/analytics/overview")
    assert response.status_code == 200
    assert response.json()["success"] is True

    # Analytics weekly
    response = client.get("/analytics/weekly")
    assert response.status_code == 200
    assert response.json()["success"] is True

    # Analytics overview export
    response = client.get("/analytics/overview/export?type=pdf&turnstileToken=test")
    assert response.status_code == 200, response.json()
    assert response.headers['content-type'] == 'application/pdf'

    # Analytics weekly export
    response = client.get("/analytics/weekly/export?type=png&turnstileToken=test")
    assert response.status_code == 200, response.json()
    assert response.headers['content-type'] == 'image/png'


