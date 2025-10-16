from datetime import datetime, timedelta, timezone
from sqlmodel import (
    SQLModel,
    select,
    or_,
    col,
)
from sqlmodel.ext.asyncio.session import AsyncSession
from sqlalchemy.ext.asyncio import create_async_engine, async_sessionmaker
from core.env import *
from typing import Sequence, List
from core.types import *

engine = create_async_engine(database_url)

session_maker = async_sessionmaker(engine, expire_on_commit=False)

async def create_error_log(session: AsyncSession, error_log: ErrorLog) -> None:
    try:
        session.add(error_log)
        await session.commit()
    except Exception:
        pass


async def create_db_and_tables() -> None:
    async with engine.begin() as conn:
        await conn.run_sync(SQLModel.metadata.create_all)


async def create_room(session: AsyncSession, name: str, campus: Campus) -> None:
    room = Room(name=name, campusId=campus.id)
    session.add(room)
    await session.commit()


async def create_campus(session: AsyncSession, name: str) -> None:
    campus = Campus(name=name)
    session.add(campus)
    await session.commit()


async def create_class(session: AsyncSession, name: str, campus: Campus) -> None:
    class_ = Class(name=name, campusId=campus.id)
    session.add(class_)
    await session.commit()


async def get_campus(session: AsyncSession) -> Sequence[Campus]:
    campuses = (await session.exec(select(Campus))).all()
    return campuses


async def get_policy(session: AsyncSession) -> Sequence[RoomPolicy]:
    policies = (await session.exec(select(RoomPolicy))).all()
    return policies


async def get_policy_by_room_id(
    session: AsyncSession, room_id: int | None
) -> Sequence[RoomPolicy]:
    policies = (await session.exec(
        select(RoomPolicy).where(RoomPolicy.roomId == room_id)
    )).all()
    return policies


async def get_class(session: AsyncSession) -> Sequence[Class]:
    classes = (await session.exec(select(Class))).all()
    return classes


async def get_room(session: AsyncSession) -> Sequence[Room]:
    rooms = (await session.exec(select(Room))).all()
    return rooms


async def create_reservation(session: AsyncSession, request: ReservationCreateRequest) -> int:
    reservation = Reservation(
        roomId=request.room,
        startTime=datetime.fromtimestamp(request.startTime, tz=timezone.utc),
        endTime=datetime.fromtimestamp(request.endTime, tz=timezone.utc),
        studentName=request.studentName,
        email=request.email,
        reason=request.reason,
        classId=request.classId,
        studentId=request.studentId,
    )
    session.add(reservation)
    await session.commit()
    await update_analytic(session, datetime.now(timezone.utc), 0, 0, 0, 1, 0)
    await update_analytic(
        session,
        datetime.fromtimestamp(request.startTime, tz=timezone.utc),
        1,
        0,
        0,
        0,
        0,
    )
    await session.flush()
    return reservation.id or -1


async def get_reservation_by_room_id(
    session: AsyncSession, room_id: int | None
) -> Sequence[Reservation]:
    reservations = (await session.exec(
        select(Reservation).where(Reservation.roomId == room_id)
    )).all()
    return reservations


async def get_room_by_id(session: AsyncSession, room_id: int | None) -> Room | None:
    room = (await session.exec(select(Room).where(Room.id == room_id))).one_or_none()
    return room


async def create_admin(session: AsyncSession, email: str, name: str, password: str) -> None:
    admin = Admin(email=email, name=name, password=password)
    session.add(admin)
    await session.commit()


async def get_admin_login_by_cookie(session: AsyncSession, cookie: str) -> AdminLogin | None:
    admin_login = (await session.exec(
        select(AdminLogin).where(AdminLogin.cookie == cookie)
    )).one_or_none()
    return admin_login


async def get_admin_by_email(session: AsyncSession, email: str) -> Admin | None:
    admin = (await session.exec(select(Admin).where(Admin.email == email))).first()
    return admin


async def get_reservation(
    session: AsyncSession,
    keyword: str | None = None,
    room_id: int | None = None,
    status: str | None = None,
    page: int = 0,
    page_size: int = 20,
    start_time: datetime | None = None,
    end_time: datetime | None = None,
    seach_student_id: bool = False,
) -> tuple[Sequence[Reservation], int]:
    query = select(Reservation).order_by(col(Reservation.id).desc())
    if keyword:
        query = (
            query.join(Room)
            .join(Class, isouter=True)
            .where(
                or_(
                    col(Reservation.email).like(f"%{keyword}%"),
                    col(Reservation.reason).like(f"%{keyword}%"),
                    col(Reservation.studentName).like(f"%{keyword}%"),
                    Reservation.id == int(keyword) if keyword.isdigit() else False,
                    (
                        col(Reservation.studentId).like(f"%{keyword}%")
                        if seach_student_id
                        else False
                    ),
                    col(Reservation.studentName).like(f"%{keyword}%"),
                    col(Room.name).like(f"%{keyword}%"),
                    col(Class.name).like(f"%{keyword}%"),
                )
            )
        )
    if room_id:
        query = query.where(Reservation.roomId == room_id)
    if status:
        query = query.where(Reservation.status == status)
    if start_time:
        query = query.where(Reservation.startTime >= start_time)
    if end_time:
        query = query.where(Reservation.endTime <= end_time)

    total = len((await session.exec(query)).all())

    reservations = (await session.exec(query.offset(page * page_size).limit(page_size))).all()
    return reservations, total


async def create_admin_login(session: AsyncSession, email: str, cookie: str) -> None:
    user_login = AdminLogin(
        email=email,
        cookie=cookie,
        expiry=datetime.now(timezone.utc) + timedelta(seconds=3600),
    )
    session.add(user_login)
    await session.commit()


async def get_future_reservations(session: AsyncSession) -> Sequence[Reservation]:
    reservations = (await session.exec(
        select(Reservation).where(Reservation.startTime >= datetime.now(timezone.utc))
    )).all()
    return reservations


async def get_future_reservations_by_approver_id(
    session: AsyncSession, approver_id: int | None
) -> Sequence[Reservation]:
    reservations = (await session.exec(
        select(Reservation)
        .join(Room)
        .join(RoomApprover)
        .where(Reservation.startTime >= datetime.now(timezone.utc))
        .where(RoomApprover.adminId == approver_id)
        .where(
            or_(
                Reservation.latestExecutorId == approver_id,
                Reservation.latestExecutorId == None,
            )
        )
    )).all()
    return reservations


async def change_reservation_status_by_id(
    session: AsyncSession, id: int | None, status: str, admin: int, reason: str | None = None
) -> None:
    reservation = (await session.exec(
        select(Reservation).where(Reservation.id == id)
    )).one_or_none()
    if reservation:
        reservation.status = status
        reservation.latestExecutorId = admin
        await session.commit()
        await session.refresh(reservation)
        await create_reservation_operation_log(
            session, admin, reservation.id or -1, reservation.status, reason
        )
        await update_analytic(
            session,
            datetime.now(timezone.utc),
            0,
            1 if status == "approved" else 0,
            1 if status == "rejected" else 0,
            0,
            0,
        )


async def create_reservation_operation_log(
    session: AsyncSession,
    admin: int,
    reservation: int,
    operation: str,
    reason: str | None = None,
) -> None:
    log = ReservationOperationLog(
        adminId=admin, reservationId=reservation, operation=operation, reason=reason
    )
    session.add(log)
    await session.commit()


async def update_analytic(
    session: AsyncSession,
    date: datetime,
    reservations: int,
    approvals: int,
    rejections: int,
    reservationCreations: int,
    requests: int,
) -> None:
    analytic = (await session.exec(
        select(Analytic).where(
            Analytic.date
            == date.astimezone(timezone.utc).replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    )).one_or_none()
    if not analytic:
        analytic = Analytic(
            date=date.astimezone(timezone.utc).replace(
                hour=0, minute=0, second=0, microsecond=0
            ),
            reservations=reservations,
            approvals=approvals,
            rejections=rejections,
            reservationCreations=reservationCreations,
            requests=requests,
        )
        session.add(analytic)
        await session.commit()
        return
    if analytic:
        analytic.reservations += reservations
        analytic.approvals += approvals
        analytic.rejections += rejections
        analytic.reservationCreations += reservationCreations
        analytic.requests += requests
        await session.commit()


async def get_analytic_by_date(session: AsyncSession, date: datetime) -> Analytic | None:
    analytic = (await session.exec(
        select(Analytic).where(
            Analytic.date
            == date.astimezone(timezone.utc).replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    )).one_or_none()
    return analytic


async def get_analytics_between(
    session: AsyncSession, start: datetime, end: datetime
) -> Sequence[Analytic]:
    s = start.astimezone(timezone.utc).replace(
        hour=0, minute=0, second=0, microsecond=0
    )
    e = end.astimezone(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    analytics = (await session.exec(
        select(Analytic).where(Analytic.date >= s).where(Analytic.date <= e)
    )).all()
    return analytics


async def get_reservation_by_id(session: AsyncSession, id: int | None) -> Reservation | None:
    reservation = (await session.exec(
        select(Reservation).where(Reservation.id == id)
    )).one_or_none()
    return reservation


async def get_campus_by_id(session: AsyncSession, id: int | None) -> Campus | None:
    campus = (await session.exec(select(Campus).where(Campus.id == id))).one_or_none()
    return campus


async def get_all_reservations(session: AsyncSession) -> Sequence[Reservation]:
    reservations = (await session.exec(select(Reservation))).all()
    return reservations


async def get_reservations_by_time_range(
    session: AsyncSession, start: datetime | None, end: datetime | None
) -> Sequence[Reservation]:
    query = select(Reservation).order_by(col(Reservation.id).asc())
    if start:
        query = query.where(Reservation.startTime >= start)
    if end:
        query = query.where(Reservation.endTime <= end)
    reservations = (await session.exec(query)).all()
    return reservations


async def get_class_by_id(session: AsyncSession, id: int | None) -> Class | None:
    class_ = (await session.exec(select(Class).where(Class.id == id))).one_or_none()
    return class_


async def delete_campus(session: AsyncSession, campus: Campus) -> None:
    rooms = campus.rooms
    classes = campus.classes
    for room in rooms:
        await delete_room(session, room)
    for class_ in classes:
        await delete_class(session, class_)
    await session.delete(campus)
    await session.commit()


async def delete_class(session: AsyncSession, class_: Class) -> None:
    await session.delete(class_)
    await session.commit()


async def delete_room(session: AsyncSession, room: Room) -> None:
    policies = (await session.exec(
        select(RoomPolicy).where(RoomPolicy.roomId == room.id)
    )).all()
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room.id)
    )).all()
    for policy in policies:
        await delete_policy(session, policy)
    for approver in approvers:
        await delete_room_approver(session, approver)
    await session.delete(room)
    await session.commit()


async def delete_policy(session: AsyncSession, policy: RoomPolicy) -> None:
    await session.delete(policy)
    await session.commit()


async def delete_room_approver(session: AsyncSession, approver: RoomApprover) -> None:
    await session.delete(approver)
    await session.commit()


async def create_policy(
    session: AsyncSession,
    room: Room,
    days: List[int],
    startTime: List[int],
    endTime: List[int],
) -> None:
    policy = RoomPolicy(room=room, days=days, startTime=startTime, endTime=endTime)
    session.add(policy)
    await session.commit()


async def get_policy_by_id(session: AsyncSession, id: int | None) -> RoomPolicy | None:
    policy = (await session.exec(select(RoomPolicy).where(RoomPolicy.id == id))).one_or_none()
    return policy


async def toggle_policy(session: AsyncSession, policy: RoomPolicy) -> None:
    policy.enabled = not policy.enabled
    session.add(policy)
    await session.commit()


async def edit_policy(session: AsyncSession, policy: RoomPolicy) -> None:
    session.add(policy)
    await session.commit()


async def edit_room(session: AsyncSession, room: Room) -> None:
    session.add(room)
    await session.commit()


async def edit_campus(session: AsyncSession, campus: Campus) -> None:
    session.add(campus)
    await session.commit()


async def edit_class(session: AsyncSession, class_: Class) -> None:
    session.add(class_)
    await session.commit()


async def edit_approver(session: AsyncSession, approver: RoomApprover) -> None:
    session.add(approver)
    await session.commit()


async def get_temp_admin_login_by_token(
    session: AsyncSession, token: str
) -> TempAdminLogin | None:
    temp_admin_login = (await session.exec(
        select(TempAdminLogin).where(TempAdminLogin.token == token)
    )).one_or_none()
    return temp_admin_login


async def delete_temp_admin_login(session: AsyncSession, temp_admin_login: TempAdminLogin) -> None:
    await session.delete(temp_admin_login)
    await session.commit()


async def get_admin_by_id(session: AsyncSession, admin_id: int | None) -> Admin | None:
    admin = (await session.exec(select(Admin).where(Admin.id == admin_id))).one_or_none()
    return admin


async def create_room_approver(session: AsyncSession, room: Room, admin: Admin) -> None:
    approver = RoomApprover(room=room, admin=admin)
    session.add(approver)
    await session.commit()


async def get_room_approvers(session: AsyncSession) -> Sequence[RoomApprover]:
    approvers = (await session.exec(select(RoomApprover))).all()
    return approvers


async def get_room_approver_by_id(session: AsyncSession, id: int | None) -> RoomApprover | None:
    approver = (await session.exec(
        select(RoomApprover).where(RoomApprover.id == id)
    )).one_or_none()
    return approver


async def get_room_approvers_by_admin_id(
    session: AsyncSession, admin_id: int
) -> Sequence[RoomApprover] | None:
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.adminId == admin_id)
    )).all()
    return approvers


async def get_room_approvers_by_room_id(
    session: AsyncSession, room_id: int
) -> Sequence[RoomApprover] | None:
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room_id)
    )).all()
    return approvers


async def get_admins(session: AsyncSession) -> Sequence[Admin]:
    admins = (await session.exec(select(Admin))).all()
    return admins


async def create_temp_admin_login(session: AsyncSession, email: str, token: str) -> None:
    temp_admin_login = TempAdminLogin(email=email, token=token)
    session.add(temp_admin_login)
    await session.commit()


async def get_reservations_by_time_range_and_room(
    session: AsyncSession, start: datetime | None, end: datetime | None, room_id: int
) -> Sequence[Reservation]:
    query = select(Reservation).where(Reservation.roomId == room_id)
    if start and end:
        query = query.where(Reservation.startTime < end, Reservation.endTime > start)
    elif start:
        query = query.where(Reservation.endTime > start)
    elif end:
        query = query.where(Reservation.startTime < end)
    reservations = (await session.exec(query)).all()
    return reservations


async def delete_admin(session: AsyncSession, admin: Admin) -> None:
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.adminId == admin.id)
    )).all()
    for approver in approvers:
        await delete_room_approver(session, approver)
    await session.delete(admin)
    await session.commit()


async def change_admin_password(session: AsyncSession, admin_id: int, new_password: str) -> None:
    admin = (await session.exec(select(Admin).where(Admin.id == admin_id))).one_or_none()
    if not admin:
        return
    admin.password = new_password
    session.add(admin)
    await session.commit()


async def create_access_log(session: AsyncSession, log: AccessLog) -> None:
    session.add(log)
    await session.commit()
    await session.refresh(log)


async def get_error_log_count(session: AsyncSession) -> int:
    data = (await session.exec(select(ErrorLog))).all()
    return len(data)


async def edit_admin(session: AsyncSession, admin: Admin) -> None:
    session.add(admin)
    await session.commit()


async def create_cache(session: AsyncSession, cache: Cache) -> None:
    session.add(cache)
    await session.commit()


async def get_cache_by_key(session: AsyncSession, key: str) -> Cache | None:
    cache = (await session.exec(select(Cache).where(Cache.key == key))).one_or_none()
    return cache


async def clear_all_cache(session: AsyncSession) -> None:
    caches = (await session.exec(select(Cache))).all()
    for cache in caches:
        await session.delete(cache)
    await session.commit()
