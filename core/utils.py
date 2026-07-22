from collections import defaultdict
import json
import secrets
from typing import Literal, Sequence

import httpx
from openpyxl import Workbook
from openpyxl.worksheet.worksheet import Worksheet
from core.env import *
from core.orm import *
from core.email import *


def reservation_email_copy(locale: str, kind: str, **values: object) -> dict[str, str]:
    zh = locale == "zh-CN"
    reservation_id = values.get("reservation_id")
    student_name = values.get("student_name")
    room_name = values.get("room_name")
    time_range = values.get("time_range")
    reason = values.get("reason")
    if kind == "created":
        return {
            "email_title": "预约已创建" if zh else "Reservation Created",
            "title": f"预约 #{reservation_id} 已创建" if zh else f"Reservation #{reservation_id} created",
            "details": (
                f"{student_name}，你的 {room_name} 预约已创建，目前正在等待审核。预约时间：<b>{time_range}</b>。"
                if zh else
                f"Hi {student_name}! Your reservation for {room_name} has been created and is pending approval. Time: <b>{time_range}</b>."
            ),
        }
    if kind == "approved":
        return {
            "email_title": "预约已通过" if zh else "Reservation Approved",
            "title": "你的预约已通过" if zh else "Your reservation has been approved",
            "details": (
                f"{student_name}，你的预约 #{reservation_id}（{room_name}）已通过。"
                if zh else
                f"Hi {student_name}! Your reservation #{reservation_id} for {room_name} has been approved."
            ),
        }
    return {
        "email_title": "预约已拒绝" if zh else "Reservation Rejected",
        "title": "你的预约未通过" if zh else "Your reservation has been rejected",
        "details": (
            f"{student_name}，你的预约 #{reservation_id}（{room_name}）未通过。原因：{reason}"
            if zh else
            f"Hi {student_name}! Your reservation #{reservation_id} for {room_name} has been rejected. Reason: {reason}"
        ),
    }

def get_exported_xlsx(
    reservations: Sequence[Reservation],
    format: Literal["by-room", "single-sheet"] = "by-room",
) -> Workbook:
    os.makedirs("cache", exist_ok=True)
    workbook = Workbook()
    default = workbook.active
    if default is not None:
        workbook.remove(default)

    headers = [
        "ID",
        "Start Time",
        "End Time",
        "Student Name",
        "Student ID",
        "E-mail",
        "Reason",
        "Purpose",
        "Multimedia Required",
        "Multimedia Details",
        "Room Name",
        "Class Name",
        "Status",
        "Creation Time",
        "Campus Name",
    ]

    if format == "single-sheet":
        ws: Worksheet = workbook.create_sheet(title="All Reservations")
        ws.append(headers)

        for reservation in reservations:
            room = reservation.room
            class_ = reservation.class_
            campus = room.campus if room and room.campus else None
            ws.append(
                [
                    reservation.id,
                    reservation.startTime,
                    reservation.endTime,
                    reservation.studentName,
                    reservation.studentId,
                    reservation.email,
                    reservation.reason,
                    reservation.purposeType,
                    reservation.multimediaRequired,
                    reservation.multimediaDetails,
                    room.name if room else None,
                    class_.name if class_ else None,
                    reservation.status.capitalize() if reservation.status else None,
                    reservation.createdAt,
                    campus.name if campus else None,
                ]
            )
    else:
        reservations_by_room: dict[int | None, list[Reservation]] = defaultdict(list)
        for reservation in reservations:
            reservations_by_room[reservation.roomId].append(reservation)

        used = set()
        for room_id, room_reservations in reservations_by_room.items():
            room_obj = next((res.room for res in room_reservations if res.room), None)
            room_name = (room_obj.name if room_obj else None) or f"Room-{room_id}"
            base = (room_name or "")[:31]
            sheet_name = base
            i = 1
            while sheet_name in used:
                suffix = f"-{i}"
                if len(base) + len(suffix) > 31:
                    sheet_name = base[: 31 - len(suffix)] + suffix
                else:
                    sheet_name = base + suffix
                i += 1
            used.add(sheet_name)

            ws: Worksheet = workbook.create_sheet(title=sheet_name)
            ws.append(headers)

            for reservation in room_reservations:
                room = reservation.room
                class_ = reservation.class_
                campus = room.campus if room and room.campus else None
                ws.append(
                    [
                        reservation.id,
                        reservation.startTime,
                        reservation.endTime,
                        reservation.studentName,
                        reservation.studentId,
                        reservation.email,
                        reservation.reason,
                        reservation.purposeType,
                        reservation.multimediaRequired,
                        reservation.multimediaDetails,
                        room.name if room else None,
                        class_.name if class_ else None,
                        reservation.status.capitalize() if reservation.status else None,
                        (
                            reservation.createdAt
                            if reservation.createdAt
                            else None
                        ),
                        campus.name if campus else None,
                    ]
                )
    return workbook


def verify_turnstile_token(token: str) -> bool:
    if debug and not cloudflare_secret and token == "development":
        return True
    try:
        with httpx.Client() as client:
            response = client.post(
                "https://challenges.cloudflare.com/turnstile/v0/siteverify",
                json={"secret": cloudflare_secret, "response": token},
            )
            data = response.json()
            if data["success"]:
                return True
            else:
                return False
    except Exception:
        return False


async def get_exported_pdf(url: str, output: str, device_scale: int = 2) -> None:
    from playwright.async_api import async_playwright

    os.makedirs("cache", exist_ok=True)
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        context = await browser.new_context(
            viewport={"width": 800, "height": 900},
            device_scale_factor=device_scale,
        )
        page = await context.new_page()
        await page.goto(url, wait_until="networkidle")
        await page.emulate_media(media="print")
        await page.wait_for_timeout(2000)
        await page.pdf(
            path=output,
            format="A4",
            print_background=True,
            prefer_css_page_size=True,
            margin={"bottom": "6mm", "top": "6mm"},
        )
        await browser.close()


async def get_screenshot(url: str, output: str, device_scale: int = 2) -> None:
    from playwright.async_api import async_playwright

    os.makedirs("cache", exist_ok=True)
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        context = await browser.new_context(
            viewport={"width": 800, "height": 900},
            device_scale_factor=device_scale,
        )
        page = await context.new_page()
        await page.goto(url, wait_until="networkidle")
        await page.emulate_media(media="print")
        await page.wait_for_timeout(2000)
        await page.screenshot(
            path=output,
            full_page=True,
        )
        await browser.close()


async def ai_approval(session: AsyncSession, id: int) -> None:
    reservation = await get_reservation_by_id(session, id)
    if not reservation:
        return
    strength = await get_app_setting(session, "aiApprovalStrength", "strict")
    request_payload = {
        "studentName": reservation.studentName,
        "className": reservation.class_.name if reservation.class_ else None,
        "roomName": reservation.room.name if reservation.room else None,
        "startTime": reservation.startTime.isoformat(),
        "endTime": reservation.endTime.isoformat(),
        "purposeType": reservation.purposeType,
        "multimediaRequired": reservation.multimediaRequired,
        "multimediaDetails": reservation.multimediaDetails,
        "reason": reservation.reason,
    }
    async with httpx.AsyncClient(timeout=10) as client:
        try:
            response = await client.post(
                ai_approval_url,
                headers={"Authorization": f"Bearer {ai_approval_secret}"},
                json={"request": request_payload, "strength": strength},
            )
            response.raise_for_status()
            response_json = response.json()
            decision = response_json.get("decision")
            status = {
                "APPROVED": "approved",
                "REJECTED": "rejected",
                "MANUAL_REVIEW": "pending",
            }.get(decision, response_json.get("status", "pending"))
            data = AIApprovalResponse(status=status, message=response_json.get("reason") or response_json.get("message"))
        except Exception:
            try:
                response = await client.get(
                    ai_approval_url,
                    params={"s": ai_approval_secret, "reason": reservation.reason},
                )
                data = AIApprovalResponse.model_validate(response.json())
            except Exception:
                return
        if data.status != "pending":
            await change_reservation_status_by_id(
                session,
                reservation.id,
                "approved" if data.status == "approved" else "rejected",
                ai_approval_admin_id,
                data.message,
            )
        if data.status == "approved":
            send_reservation_approval_email(
                email_title="Reservation Approval",
                title="Your reservation has been approved!",
                email=reservation.email,
                details=f"Hi {reservation.studentName}! Your reservation #{reservation.id} for {reservation.room.name if reservation.room else None} has been approved. Below is the detailed information.",
                user=reservation.studentName,
                room=reservation.room.name if reservation.room else "",
                class_name=reservation.class_.name or "",
                student_id=reservation.studentId,
                reason=reservation.reason,
                time=f"{reservation.startTime.strftime('%Y-%m-%d %H:%M')} - {reservation.endTime.strftime('%H:%M')}",
            )
        elif data.status == "rejected":
            send_normal_update_email(
                email_title="Reservation Rejected",
                title="Your reservation has been rejected.",
                email=reservation.email,
                details=f"Hi {reservation.studentName}! Your reservation #{reservation.id} for {reservation.room.name if reservation.room else None} has been rejected. Reason: {data.message}",
            )
        else:
            for approver in reservation.room.approvers:
                admin = approver.admin
                if not admin or not approver.notificationsEnabled:
                    continue
                token = secrets.token_hex(32)
                send_normal_update_with_external_link_email(
                    email_title="New Reservation Request",
                    title=f"Hi {admin.name}! A new reservation request has been created.",
                    email=admin.email,
                    details=f"Reservation ID #{reservation.id}, click the button below for reservation details.",
                    button_text="View Reservation",
                    link=f"{base_url}/admin/reservation/?token={token}",
                )
                await create_temp_admin_login(session, admin.email, token)
