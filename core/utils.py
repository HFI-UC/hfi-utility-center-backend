from openpyxl import Workbook
from openpyxl.worksheet.worksheet import Worksheet
from openpyxl.utils import get_column_letter
from core.orm import *
from core.env import *
from typing import Sequence
from playwright.async_api import async_playwright
import httpx


def get_exported_xlsx(reservations: Sequence[Reservation]) -> Workbook:
    classes = get_class()

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
    groups = {}
    for reservation in reservations:
        groups.setdefault(reservation.room, []).append(reservation)

    used = set()
    for room_id, reservations in groups.items():
        room = get_room_by_id(room_id)
        room_name = room.name if room else f"Room-{room_id}"
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

        for reservation in reservations:
            class_name = next(
                (cls.name for cls in classes if cls.id == reservation.classId), None
            )
            campus = get_campus_by_id(room.campusId) if room and room.campus else None
            ws.append(
                [
                    reservation.id,
                    (
                        reservation.startTime.replace(tzinfo=None)
                        if reservation.startTime
                        else None
                    ),
                    (
                        reservation.endTime.replace(tzinfo=None)
                        if reservation.endTime
                        else None
                    ),
                    reservation.studentName,
                    reservation.studentId,
                    reservation.email,
                    reservation.reason,
                    room.name if room else None,
                    class_name,
                    reservation.status.capitalize(),
                    (
                        reservation.createdAt.replace(tzinfo=None)
                        if reservation.createdAt
                        else None
                    ),
                    campus.name if campus else None,
                ]
            )
        dims = {}
        for row in ws.rows:
            for cell in row:
                if cell.value:
                    col_letter = getattr(
                        cell, "column_letter", None
                    ) or get_column_letter(cell.column or -1)
                    dims[col_letter] = (
                        max(dims.get(col_letter, 0), len(str(cell.value))) + 2
                    )
        for col, value in dims.items():
            ws.column_dimensions[col].width = value
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
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        context = await browser.new_context(
            viewport={"width": 800, "height": 900},
            device_scale_factor=device_scale,
        )
        page = await context.new_page()
        await page.goto(url, wait_until="networkidle")
        await page.emulate_media(media="print")
        await page.evaluate("document.fonts.ready")
        await page.reload()
        await page.wait_for_timeout(1000)
        await page.pdf(
            path=output,
            format="A4",
            print_background=True,
            prefer_css_page_size=True,
            margin={ "bottom": "6mm", "top": "6mm" }
        )
        await browser.close()


async def get_screenshot(url: str, output: str, device_scale: int = 2) -> None:
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        context = await browser.new_context(
            viewport={"width": 800, "height": 900},
            device_scale_factor=device_scale,
        )
        page = await context.new_page()
        await page.goto(url, wait_until="networkidle")
        await page.emulate_media(media="print")
        await page.evaluate("document.fonts.ready")
        await page.reload()
        await page.wait_for_timeout(1000)
        await page.screenshot(
            path=output,
            full_page=True,
        )
        await browser.close()
