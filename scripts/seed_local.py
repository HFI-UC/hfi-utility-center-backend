import asyncio
import bcrypt
import sys
from pathlib import Path

from sqlalchemy import delete
from sqlmodel import select
from sqlmodel.ext.asyncio.session import AsyncSession

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from core.orm import create_db_and_tables, engine
from core.types import Admin, AppSetting, Campus, Class, Reservation, Room, RoomApprover, RoomPolicy


CAMPUSES = [
    (1, "Shipai Campus", False),
    (2, "Knowledge City Campus", False),
    (3, "Office", True),
]

CLASSES = [
    (1, "Ainsworth", 1), (3, "Demis", 1), (4, "Rana", 1), (5, "Yann", 1),
    (6, "Calatrava", 1), (7, "Kate", 1), (9, "Andrew", 1), (10, "Feifei", 1),
    (11, "Gibson", 1), (12, "Loftus", 1), (13, "Seligman", 1), (14, "Maslow", 1),
    (15, "Piaget", 1), (16, "Skinner", 1), (25, "Bandura", 1), (8, "Geoffrey", 1),
    (17, "Aspect", 2), (19, "Kitaev", 2), (18, "Clauser", 2), (20, "Lieb", 2),
    (21, "Lukin", 2), (22, "Pan", 2), (23, "Shor", 2), (24, "Zeilinger", 2),
    (26, "Teachers", 3),
]

ROOMS = [
    (14, "iStudy Meeting Room 1", 1, True), (15, "iStudy Meeting Room 2", 1, True),
    (16, "Writing Center 1", 1, True), (17, "Writing Center 2", 1, True),
    (18, "606", 1, True), (23, "206", 1, True), (24, "511", 2, True),
    (25, "512", 2, True), (26, "513", 2, True), (21, "602", 1, False),
    (22, "601", 1, False), (28, "524", 2, False), (29, "105", 1, False),
    (34, "104", 1, False), (27, "514", 2, False), (33, "303", 1, True),
    (32, "603", 1, False), (19, "605", 1, True), (30, "501", 2, False),
    (35, "502", 2, True), (36, "101", 1, True),
]

POLICIES = [
    (5, 14, [0, 1, 2, 3, 4, 5, 6], [12, 0], [13, 0]),
    (6, 15, [0, 1, 2, 3, 4, 5, 6], [12, 0], [13, 0]),
    (7, 16, [0, 1, 2, 3, 4, 5, 6], [12, 0], [13, 0]),
    (8, 17, [0, 1, 2, 3, 4, 5, 6], [12, 0], [13, 0]),
    (22, 18, [3], [19, 0], [21, 0]),
    (9, 24, [0, 1, 2, 3], [6, 30], [12, 30]),
    (10, 24, [0, 1, 2, 3], [13, 30], [19, 0]),
    (11, 25, [0, 1, 2, 3], [6, 30], [12, 30]),
    (12, 25, [0, 1, 2, 3], [13, 30], [19, 0]),
    (13, 26, [0, 1, 2, 3], [6, 30], [12, 30]),
    (20, 26, [0], [19, 0], [21, 0]),
    (14, 26, [0, 1, 2, 3], [13, 30], [19, 0]),
    (2, 28, [0, 1, 2, 3, 4, 5, 6], [10, 15], [11, 10]),
    (21, 33, [3], [19, 0], [21, 0]),
    (17, 30, [0, 1, 2, 3], [6, 30], [12, 30]),
    (18, 30, [0, 1, 2, 3], [13, 30], [19, 0]),
    (23, 36, [0, 1, 2, 3, 4, 5, 6], [18, 0], [22, 0]),
]


async def seed() -> None:
    await create_db_and_tables()
    async with AsyncSession(engine) as session:
        for model in (Reservation, RoomApprover, RoomPolicy, Room, Class, Campus, AppSetting):
            await session.exec(delete(model))
        await session.commit()

        session.add_all([Campus(id=id_, name=name, isPrivileged=privileged) for id_, name, privileged in CAMPUSES])
        session.add_all([Class(id=id_, name=name, campusId=campus) for id_, name, campus in CLASSES])
        session.add_all([Room(id=id_, name=name, campusId=campus, enabled=enabled) for id_, name, campus, enabled in ROOMS])
        session.add_all([
            RoomPolicy(id=id_, roomId=room, days=days, startTime=start, endTime=end, enabled=True)
            for id_, room, days, start, end in POLICIES
        ])
        session.add(AppSetting(key="aiApprovalStrength", value="strict"))

        admin = (await session.exec(select(Admin).where(Admin.email == "local.admin@hfiuc.org"))).first()
        if not admin:
            session.add(Admin(
                name="Local Administrator",
                email="local.admin@hfiuc.org",
                password=bcrypt.hashpw(b"LocalDev123!", bcrypt.gensalt()).decode(),
            ))
        await session.commit()
        print("Production catalog snapshot, policies, AI settings, and local administrator created.")


if __name__ == "__main__":
    asyncio.run(seed())
