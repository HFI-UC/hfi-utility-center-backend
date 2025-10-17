from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.cron import CronTrigger
from core.orm import *
from core.utils import *
from core.email import *
from datetime import datetime, timedelta
from io import BytesIO
from core.env import *

import shutil

scheduler = AsyncIOScheduler()


async def send_daily_reservation_report_email() -> None:
    try:
        async with AsyncSession(engine) as session:
            reservations = await get_reservations_by_time_range(
                session,
                datetime.now(timezone.utc).replace(
                    hour=0, minute=0, second=0, microsecond=0
                )
                + timedelta(days=1),
                datetime.now(timezone.utc).replace(
                    hour=23, minute=59, second=59, microsecond=999999
                )
                + timedelta(days=1),
            )
        if not reservations:
            for recipient in daily_report_recipients:
                send_normal_update_email(
                    "Daily Reservation Report",
                    "Hi teachers! Check here for the daily reservation report.",
                    recipient,
                    "No reservations for tomorrow. :)",
                )
            return
        workbook = get_exported_xlsx(reservations)
        output = BytesIO()
        workbook.save(output)
        output.seek(0)
        for recipient in daily_report_recipients:
            send_normal_update_email_with_attached_files(
                "Daily Reservation Report",
                "Hi teachers! Check here for the daily reservation report.",
                recipient,
                "Please find the attached reservation report for tomorrow.",
                [
                    (
                        f"reservation_{(datetime.now(timezone.utc) + timedelta(days=1)).strftime('%Y-%m-%d')}.xlsx",
                        output,
                    )
                ],
            )
            output.seek(0)
    except Exception:
        pass

async def clear_cache() -> None:
    try:
        shutil.rmtree("./cache")
        async with AsyncSession(engine) as session:
            await clear_all_cache(session)
    except Exception:
        pass

scheduler.add_job(send_daily_reservation_report_email, CronTrigger(hour=20, minute=0))
scheduler.add_job(clear_cache, CronTrigger(day=1))