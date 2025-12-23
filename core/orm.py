from datetime import datetime, timedelta
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
        startTime=datetime.fromtimestamp(request.startTime),
        endTime=datetime.fromtimestamp(request.endTime),
        studentName=request.studentName,
        email=request.email,
        reason=request.reason,
        classId=request.classId,
        studentId=request.studentId,
    )
    session.add(reservation)
    await session.commit()
    await update_reservation_analytic(session, datetime.now(), 0, 0, 0, 1, 0)
    await update_reservation_analytic(
        session,
        datetime.fromtimestamp(request.startTime),
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

async def get_users(session: AsyncSession) -> Sequence[User]:
    users = (await session.exec(select(User).order_by(col(User.id).asc()))).all()
    return users

async def get_user_login_by_cookie(session: AsyncSession, cookie: str) -> UserLogin | None:
    user_login = (await session.exec(
        select(UserLogin).where(UserLogin.cookie == cookie)
    )).one_or_none()
    return user_login


async def get_user_by_cookie(session: AsyncSession, cookie: str) -> User | None:
    statement = select(User, UserLogin).where(UserLogin.cookie == cookie).where(User.email == UserLogin.email)
    result = (await session.exec(statement)).first()
    if not result:
        return None
    user, user_login = result
    if user_login.expiry < datetime.now():
        return None
    return user


async def get_admin_by_email(session: AsyncSession, email: str) -> User | None:
    user = (await session.exec(select(User).where(User.email == email))).first()
    if user and user.role == Role.ADMIN:
        return user
    return None


async def get_user_by_email(session: AsyncSession, email: str) -> User | None:
    user = (await session.exec(select(User).where(User.email == email))).first()
    return user


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


async def create_user_login(session: AsyncSession, email: str, cookie: str) -> None:
    user_login = UserLogin(
        email=email,
        cookie=cookie,
        expiry=datetime.now() + timedelta(seconds=3600),
    )
    session.add(user_login)
    await session.commit()


async def get_future_reservations(session: AsyncSession) -> Sequence[Reservation]:
    reservations = (await session.exec(
        select(Reservation).where(Reservation.startTime >= datetime.now())
    )).all()
    return reservations


async def get_future_reservations_by_approver_id(
    session: AsyncSession, approver_id: int | None
) -> Sequence[Reservation]:
    reservations = (await session.exec(
        select(Reservation)
        .join(Room)
        .join(RoomApprover)
        .where(Reservation.startTime >= datetime.now())
        .where(RoomApprover.userId == approver_id)
        .where(
            or_(
                Reservation.latestExecutorId == approver_id,
                Reservation.latestExecutorId == None,
            )
        )
    )).all()
    return reservations


async def change_reservation_status_by_id(
    session: AsyncSession, id: int | None, status: str, user: int, reason: str | None = None
) -> None:
    reservation = (await session.exec(
        select(Reservation).where(Reservation.id == id)
    )).one_or_none()
    if reservation:
        reservation.status = status
        reservation.latestExecutorId = user
        await session.commit()
        await session.refresh(reservation)
        await create_reservation_operation_log(
            session, user, reservation.id or -1, reservation.status, reason
        )
        await update_reservation_analytic(
            session,
            datetime.now(),
            0,
            1 if status == "approved" else 0,
            1 if status == "rejected" else 0,
            0,
            0,
        )


async def create_reservation_operation_log(
    session: AsyncSession,
    user: int,
    reservation: int,
    operation: str,
    reason: str | None = None,
) -> None:
    log = ReservationOperationLog(
        userId=user, reservationId=reservation, operation=operation, reason=reason
    )
    session.add(log)
    await session.commit()


async def update_reservation_analytic(
    session: AsyncSession,
    date: datetime,
    reservations: int,
    approvals: int,
    rejections: int,
    reservationCreations: int,
    requests: int,
) -> None:
    analytic = (await session.exec(
        select(ReservationAnalytic).where(
            ReservationAnalytic.date
            == date.replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    )).one_or_none()
    if not analytic:
        analytic = ReservationAnalytic(
            date=date.replace(
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


async def get_reservation_analytic_by_date(session: AsyncSession, date: datetime) -> ReservationAnalytic | None:
    analytic = (await session.exec(
        select(ReservationAnalytic).where(
            ReservationAnalytic.date
            == date.replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    )).one_or_none()
    return analytic


async def get_reservation_analytics_between(
    session: AsyncSession, start: datetime, end: datetime
) -> Sequence[ReservationAnalytic]:
    s = start.replace(
        hour=0, minute=0, second=0, microsecond=0
    )
    e = end.replace(hour=0, minute=0, second=0, microsecond=0)
    analytics = (await session.exec(
        select(ReservationAnalytic).where(ReservationAnalytic.date >= s).where(ReservationAnalytic.date <= e)
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


async def get_login_token_by_token(
    session: AsyncSession, token: str
) -> UserLoginToken | None:
    user_login_token = (await session.exec(
        select(UserLoginToken).where(UserLoginToken.token == token)
    )).one_or_none()
    return user_login_token


async def delete_login_token(session: AsyncSession, login_token: UserLoginToken) -> None:
    await session.delete(login_token)
    await session.commit()


async def get_admin_by_id(session: AsyncSession, user_id: int | None) -> User | None:
    user = (await session.exec(select(User).where(User.id == user_id))).one_or_none()
    if user and user.role == Role.ADMIN:
        return user
    return None

async def get_user_by_id(session: AsyncSession, user_id: int | None) -> User | None:
    user = (await session.exec(select(User).where(User.id == user_id))).one_or_none()
    return user

async def create_room_approver(session: AsyncSession, room: Room, user: User) -> None:
    approver = RoomApprover(room=room, user=user)
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


async def get_room_approvers_by_user_id(
    session: AsyncSession, user_id: int
) -> Sequence[RoomApprover] | None:
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.userId == user_id)
    )).all()
    return approvers


async def get_room_approvers_by_room_id(
    session: AsyncSession, room_id: int
) -> Sequence[RoomApprover] | None:
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room_id)
    )).all()
    return approvers


async def get_admins(session: AsyncSession) -> Sequence[User]:
    users = (await session.exec(select(User).where(User.role == Role.ADMIN).order_by(col(User.id).asc()))).all()
    return users


async def create_login_token(session: AsyncSession, email: str, token: str) -> None:
    user_login_token = UserLoginToken(email=email, token=token)
    session.add(user_login_token)
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


async def delete_user(session: AsyncSession, user: User) -> None:
    approvers = (await session.exec(
        select(RoomApprover).where(RoomApprover.userId == user.id)
    )).all()
    for approver in approvers:
        await delete_room_approver(session, approver)
    await session.delete(user)
    await session.commit()


async def change_user_password(session: AsyncSession, user_id: int, new_password: str) -> None:
    user = (await session.exec(select(User).where(User.id == user_id))).one_or_none()
    if not user:
        return
    user.password = new_password
    session.add(user)
    await session.commit()


async def create_access_log(session: AsyncSession, log: AccessLog) -> None:
    session.add(log)
    await session.commit()
    await session.refresh(log)


async def get_error_log_count(session: AsyncSession) -> int:
    data = (await session.exec(select(ErrorLog))).all()
    return len(data)


async def edit_user(session: AsyncSession, user: User) -> None:
    session.add(user)
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

async def create_user_registration_token(session: AsyncSession, email: str, token: str) -> None:
    user_registration_token = UserRegistrationToken(email=email, token=token, expiry=datetime.now() + timedelta(minutes=10))
    session.add(user_registration_token)
    await session.commit()

async def get_user_registration_token_by_email(session: AsyncSession, email: str) -> UserRegistrationToken | None:
    token = (await session.exec(
        select(UserRegistrationToken).where(UserRegistrationToken.email == email).order_by(col(UserRegistrationToken.expiry).desc())
    )).one_or_none()
    return token

async def get_user_registration_token_by_token(
    session: AsyncSession, token: str
) -> UserRegistrationToken | None:
    user_registration_token = (await session.exec(
        select(UserRegistrationToken).where(UserRegistrationToken.token == token)
    )).one_or_none()
    return user_registration_token

async def create_user(session: AsyncSession, name: str, email: str, password: str, student_id: str | None, role: Role) -> None:
    user = User(name=name, email=email, password=password, role=role, studentId=student_id)
    session.add(user)
    await session.commit()

async def delete_user_registration_token(session: AsyncSession, registration_token: UserRegistrationToken) -> None:
    await session.delete(registration_token)
    await session.commit()