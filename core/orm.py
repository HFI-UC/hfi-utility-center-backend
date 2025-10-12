from datetime import datetime, timedelta, timezone
from sqlmodel import (
    SQLModel,
    create_engine,
    Session,
    select,
    or_,
    col,
)
from core.env import *
from typing import Sequence, List
from core.types import *

engine = create_engine(database_url)


def create_error_log(session: Session, error_log: ErrorLog) -> None:
    try:
        session.add(error_log)
        session.commit()
    except Exception:
        pass


def create_db_and_tables() -> None:
    SQLModel.metadata.create_all(engine)


def create_room(session: Session, name: str, campus: Campus) -> None:
    room = Room(name=name, campusId=campus.id)
    session.add(room)
    session.commit()


def create_campus(session: Session, name: str) -> None:
    campus = Campus(name=name)
    session.add(campus)
    session.commit()


def create_class(session: Session, name: str, campus: Campus) -> None:
    class_ = Class(name=name, campusId=campus.id)
    session.add(class_)
    session.commit()


def get_campus(session: Session) -> Sequence[Campus]:
    campuses = session.exec(select(Campus)).all()
    return campuses


def get_policy(session: Session) -> Sequence[RoomPolicy]:
    policies = session.exec(select(RoomPolicy)).all()
    return policies


def get_policy_by_room_id(
    session: Session, room_id: int | None
) -> Sequence[RoomPolicy]:
    policies = session.exec(
        select(RoomPolicy).where(RoomPolicy.roomId == room_id)
    ).all()
    return policies


def get_class(session: Session) -> Sequence[Class]:
    classes = session.exec(select(Class)).all()
    return classes


def get_room(session: Session) -> Sequence[Room]:
    rooms = session.exec(select(Room)).all()
    return rooms


def create_reservation(session: Session, request: ReservationCreateRequest) -> int:
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
    session.commit()
    session.refresh(reservation)
    update_analytic(session, datetime.now(timezone.utc), 0, 0, 0, 1, 0)
    update_analytic(
        session,
        datetime.fromtimestamp(request.startTime, tz=timezone.utc),
        1,
        0,
        0,
        0,
        0,
    )
    return reservation.id or -1


def get_reservation_by_room_id(
    session: Session, room_id: int | None
) -> Sequence[Reservation]:
    reservations = session.exec(
        select(Reservation).where(Reservation.roomId == room_id)
    ).all()
    return reservations


def get_room_by_id(session: Session, room_id: int | None) -> Room | None:
    room = session.exec(select(Room).where(Room.id == room_id)).one_or_none()
    return room


def create_admin(session: Session, email: str, name: str, password: str) -> None:
    admin = Admin(email=email, name=name, password=password)
    session.add(admin)
    session.commit()


def get_admin_login_by_cookie(session: Session, cookie: str) -> AdminLogin | None:
    admin_login = session.exec(
        select(AdminLogin).where(AdminLogin.cookie == cookie)
    ).one_or_none()
    return admin_login


def get_admin_by_email(session: Session, email: str) -> Admin | None:
    admin = session.exec(select(Admin).where(Admin.email == email)).first()
    return admin


def get_reservation(
    session: Session,
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
            .join(Class)
            .where(
                or_(
                    col(Reservation.email).like(f"%{keyword}%"),
                    col(Reservation.reason).like(f"%{keyword}%"),
                    (
                        col(Reservation.studentId).like(f"%{keyword}%")
                        if seach_student_id
                        else False
                    ),
                    col(Reservation.studentName).like(f"%{keyword}%"),
                    col(Room.name).like(f"%{keyword}%"),
                    col(Class.name).like(f"%{keyword}%"),
                    Reservation.id == int(keyword) if keyword.isdigit() else False,
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

    total = len(session.exec(query).all())

    reservations = session.exec(query.offset(page * page_size).limit(page_size)).all()
    return reservations, total


def get_reservation_count(
    session: Session,
    keyword: str | None = None,
    room_id: int | None = None,
    status: str | None = None,
    seach_student_id: bool = False,
) -> int:
    query = select(Reservation)
    if keyword:
        query = (
            query.join(Room)
            .join(Class)
            .where(
                or_(
                    col(Reservation.email).like(f"%{keyword}%"),
                    col(Reservation.reason).like(f"%{keyword}%"),
                    (
                        col(Reservation.studentId).like(f"%{keyword}%")
                        if seach_student_id
                        else False
                    ),
                    col(Reservation.studentName).like(f"%{keyword}%"),
                    col(Room.name).like(f"%{keyword}%"),
                    col(Class.name).like(f"%{keyword}%"),
                    Reservation.id == int(keyword) if keyword.isdigit() else False,
                )
            )
        )
    if room_id:
        query = query.where(Reservation.roomId == room_id)
    if status:
        query = query.where(Reservation.status == status)
    if not keyword and not status:
        query = query.where(
            Reservation.startTime >= datetime.now(timezone.utc) - timedelta(days=1)
        )
    count = len(session.exec(query).all())
    return count


def create_admin_login(session: Session, email: str, cookie: str) -> None:
    user_login = AdminLogin(
        email=email,
        cookie=cookie,
        expiry=datetime.now(timezone.utc) + timedelta(seconds=3600),
    )
    session.add(user_login)
    session.commit()


def get_future_reservations(session: Session) -> Sequence[Reservation]:
    reservations = session.exec(
        select(Reservation).where(Reservation.startTime >= datetime.now(timezone.utc))
    ).all()
    return reservations


def get_future_reservations_by_approver_id(
    session: Session, approver_id: int | None
) -> Sequence[Reservation]:
    reservations = session.exec(
        select(Reservation)
        .join(Room)
        .join(RoomApprover)
        .where(Reservation.startTime >= datetime.now(timezone.utc))
        .where(RoomApprover.adminId == approver_id)
        .where(
            or_(
                Reservation.latestExecutorId == approver_id,
                Reservation.latestExecutorId == None
            )
        )
    ).all()
    return reservations


def change_reservation_status_by_id(
    session: Session, id: int | None, status: str, admin: int, reason: str | None = None
) -> None:
    reservation = session.exec(
        select(Reservation).where(Reservation.id == id)
    ).one_or_none()
    if reservation:
        reservation.status = status
        reservation.latestExecutorId = admin
        session.commit()
        session.refresh(reservation)
        create_reservation_operation_log(
            session, admin, reservation.id or -1, reservation.status, reason
        )
        update_analytic(
            session,
            datetime.now(timezone.utc),
            0,
            1 if status == "approved" else 0,
            1 if status == "rejected" else 0,
            0,
            0,
        )


def create_reservation_operation_log(
    session: Session,
    admin: int,
    reservation: int,
    operation: str,
    reason: str | None = None,
) -> None:
    log = ReservationOperationLog(
        adminId=admin, reservationId=reservation, operation=operation, reason=reason
    )
    session.add(log)
    session.commit()


def update_analytic(
    session: Session,
    date: datetime,
    reservations: int,
    approvals: int,
    rejections: int,
    reservationCreations: int,
    requests: int,
) -> None:
    analytic = session.exec(
        select(Analytic).where(
            Analytic.date
            == date.astimezone(timezone.utc).replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    ).one_or_none()
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
        session.commit()
        return
    if analytic:
        analytic.reservations += reservations
        analytic.approvals += approvals
        analytic.rejections += rejections
        analytic.reservationCreations += reservationCreations
        analytic.requests += requests
        session.commit()


def get_analytic_by_date(session: Session, date: datetime) -> Analytic | None:
    analytic = session.exec(
        select(Analytic).where(
            Analytic.date
            == date.astimezone(timezone.utc).replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    ).one_or_none()
    return analytic


def get_analytics_between(
    session: Session, start: datetime, end: datetime
) -> Sequence[Analytic]:
    s = start.astimezone(timezone.utc).replace(
        hour=0, minute=0, second=0, microsecond=0
    )
    e = end.astimezone(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    analytics = session.exec(
        select(Analytic).where(Analytic.date >= s).where(Analytic.date <= e)
    ).all()
    return analytics


def get_reservation_by_id(session: Session, id: int | None) -> Reservation | None:
    reservation = session.exec(
        select(Reservation).where(Reservation.id == id)
    ).one_or_none()
    return reservation


def get_campus_by_id(session: Session, id: int | None) -> Campus | None:
    campus = session.exec(select(Campus).where(Campus.id == id)).one_or_none()
    return campus


def get_all_reservations(session: Session) -> Sequence[Reservation]:
    reservations = session.exec(select(Reservation)).all()
    return reservations


def get_reservations_by_time_range(
    session: Session, start: datetime | None, end: datetime | None
) -> Sequence[Reservation]:
    query = select(Reservation).order_by(col(Reservation.id).asc())
    if start:
        query = query.where(Reservation.startTime >= start)
    if end:
        query = query.where(Reservation.endTime <= end)
    reservations = session.exec(query).all()
    return reservations


def get_class_by_id(session: Session, id: int | None) -> Class | None:
    class_ = session.exec(select(Class).where(Class.id == id)).one_or_none()
    return class_


def delete_campus(session: Session, campus: Campus) -> None:
    rooms = session.exec(select(Room).where(Room.campusId == campus.id)).all()
    classes = session.exec(select(Class).where(Class.campusId == campus.id)).all()
    for room in rooms:
        delete_room(session, room)
    for class_ in classes:
        delete_class(session, class_)
    session.delete(campus)
    session.commit()


def delete_class(session: Session, class_: Class) -> None:
    session.delete(class_)
    session.commit()


def delete_room(session: Session, room: Room) -> None:
    policies = session.exec(
        select(RoomPolicy).where(RoomPolicy.roomId == room.id)
    ).all()
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room.id)
    ).all()
    for policy in policies:
        delete_policy(session, policy)
    for approver in approvers:
        delete_room_approver(session, approver)
    session.delete(room)
    session.commit()


def delete_policy(session: Session, policy: RoomPolicy) -> None:
    session.delete(policy)
    session.commit()


def delete_room_approver(session: Session, approver: RoomApprover) -> None:
    session.delete(approver)
    session.commit()


def create_policy(
    session: Session,
    room: Room,
    days: List[int],
    startTime: List[int],
    endTime: List[int],
) -> None:
    policy = RoomPolicy(room=room, days=days, startTime=startTime, endTime=endTime)
    session.add(policy)
    session.commit()


def get_policy_by_id(session: Session, id: int | None) -> RoomPolicy | None:
    policy = session.exec(select(RoomPolicy).where(RoomPolicy.id == id)).one_or_none()
    return policy


def toggle_policy(session: Session, policy: RoomPolicy) -> None:
    policy.enabled = not policy.enabled
    session.add(policy)
    session.commit()


def edit_policy(session: Session, policy: RoomPolicy) -> None:
    session.add(policy)
    session.commit()


def edit_room(session: Session, room: Room) -> None:
    session.add(room)
    session.commit()


def edit_campus(session: Session, campus: Campus) -> None:
    session.add(campus)
    session.commit()


def edit_class(session: Session, class_: Class) -> None:
    session.add(class_)
    session.commit()


def get_temp_admin_login_by_token(
    session: Session, token: str
) -> TempAdminLogin | None:
    temp_admin_login = session.exec(
        select(TempAdminLogin).where(TempAdminLogin.token == token)
    ).one_or_none()
    return temp_admin_login


def delete_temp_admin_login(session: Session, temp_admin_login: TempAdminLogin) -> None:
    session.delete(temp_admin_login)
    session.commit()


def get_admin_by_id(session: Session, admin_id: int | None) -> Admin | None:
    admin = session.exec(select(Admin).where(Admin.id == admin_id)).one_or_none()
    return admin


def create_room_approver(session: Session, room: Room, admin: Admin) -> None:
    approver = RoomApprover(room=room, admin=admin)
    session.add(approver)
    session.commit()


def get_room_approvers(session: Session) -> Sequence[RoomApprover]:
    approvers = session.exec(select(RoomApprover)).all()
    return approvers


def get_room_approver_by_id(session: Session, id: int | None) -> RoomApprover | None:
    approver = session.exec(
        select(RoomApprover).where(RoomApprover.id == id)
    ).one_or_none()
    return approver


def get_room_approvers_by_admin_id(
    session: Session, admin_id: int
) -> Sequence[RoomApprover] | None:
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.adminId == admin_id)
    ).all()
    return approvers


def get_room_approvers_by_room_id(
    session: Session, room_id: int
) -> Sequence[RoomApprover] | None:
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room_id)
    ).all()
    return approvers


def get_admins(session: Session) -> Sequence[Admin]:
    admins = session.exec(select(Admin)).all()
    return admins


def create_temp_admin_login(session: Session, email: str, token: str) -> None:
    temp_admin_login = TempAdminLogin(email=email, token=token)
    session.add(temp_admin_login)
    session.commit()


def get_reservations_by_time_range_and_room(
    session: Session, start: datetime | None, end: datetime | None, room_id: int
) -> Sequence[Reservation]:
    query = select(Reservation).where(Reservation.roomId == room_id)
    if start:
        query = query.where(Reservation.startTime >= start)
    if end:
        query = query.where(Reservation.endTime <= end)
    reservations = session.exec(query).all()
    return reservations


def delete_admin(session: Session, admin: Admin) -> None:
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.adminId == admin.id)
    ).all()
    for approver in approvers:
        delete_room_approver(session, approver)
    session.delete(admin)
    session.commit()


def change_admin_password(session: Session, admin_id: int, new_password: str) -> None:
    admin = session.exec(select(Admin).where(Admin.id == admin_id)).one_or_none()
    if not admin:
        return
    admin.password = new_password
    session.add(admin)
    session.commit()


def create_access_log(session: Session, log: AccessLog) -> None:
    session.add(log)
    session.commit()
    session.refresh(log)


def get_error_log_count(session: Session) -> int:
    data = session.exec(select(ErrorLog)).all()
    return len(data)


def edit_admin(session: Session, admin: Admin) -> None:
    session.add(admin)
    session.commit()


def create_cache(session: Session, cache: Cache) -> None:
    session.add(cache)
    session.commit()


def get_cache_by_key(session: Session, key: str) -> Cache | None:
    cache = session.exec(select(Cache).where(Cache.key == key)).one_or_none()
    return cache


def clear_all_cache(session: Session) -> None:
    caches = session.exec(select(Cache)).all()
    for cache in caches:
        session.delete(cache)
    session.commit()
