from collections import defaultdict
from typing import Literal, Sequence

import httpx
from openpyxl import Workbook
from openpyxl.worksheet.worksheet import Worksheet
from playwright.async_api import async_playwright

from core.env import *
from core.orm import *


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
