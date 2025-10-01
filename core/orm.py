from datetime import datetime, timedelta, timezone
from sqlmodel import (
    SQLModel,
    create_engine,
    Session,
    select,
    or_,
    column,
)
from core.env import *
from typing import Sequence, List
from core.types import *

engine = create_engine(database_url)

session = Session(engine)

def create_error_log(error_log: ErrorLog) -> None:
    try:
        with Session(engine) as session:
            session.add(error_log)
            session.commit()
    except Exception:
        pass


def create_db_and_tables() -> None:
    SQLModel.metadata.create_all(engine)


def create_room(name: str, campus: Campus) -> None:
    room = Room(name=name, campusId=campus.id)
    session.add(room)
    session.commit()


def create_campus(name: str) -> None:
    campus = Campus(name=name)
    session.add(campus)
    session.commit()


def create_class(name: str, campus: Campus) -> None:
    class_ = Class(name=name, campusId=campus.id)
    session.add(class_)
    session.commit()


def get_campus() -> Sequence[Campus]:
    campuses = session.exec(select(Campus)).all()
    return campuses


def get_policy() -> Sequence[RoomPolicy]:
    policies = session.exec(select(RoomPolicy)).all()
    return policies


def get_policy_by_room_id(room_id: int | None) -> Sequence[RoomPolicy]:
    policies = session.exec(
        select(RoomPolicy).where(RoomPolicy.roomId == room_id)
    ).all()
    return policies


def get_class() -> Sequence[Class]:
    classes = session.exec(select(Class)).all()
    return classes


def get_room() -> Sequence[Room]:
    rooms = session.exec(
        select(Room)
    ).all()
    return rooms


def create_reservation(request: ReservationCreateRequest) -> int:
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
    update_analytic(datetime.now(timezone.utc), 0, 0, 0, 1, 0)
    update_analytic(
        datetime.fromtimestamp(request.startTime, tz=timezone.utc),
        1,
        0,
        0,
        0,
        0,
    )
    return reservation.id or -1


def get_reservation_by_room_id(room_id: int | None) -> Sequence[Reservation]:
    reservations = session.exec(
        select(Reservation).where(Reservation.roomId == room_id)
    ).all()
    return reservations


def get_room_by_id(room_id: int | None) -> Room | None:
    room = session.exec(select(Room).where(Room.id == room_id)).one_or_none()
    return room


def create_admin(email: str, name: str, password: str) -> None:
    admin = Admin(email=email, name=name, password=password)
    session.add(admin)
    session.commit()


def get_admin_login_by_cookie(cookie: str) -> AdminLogin | None:
    admin_login = session.exec(
        select(AdminLogin).where(AdminLogin.cookie == cookie)
    ).one_or_none()
    return admin_login


def get_admin_by_email(email: str) -> Admin | None:
    admin = session.exec(select(Admin).where(Admin.email == email)).first()
    return admin


def get_reservation(
    keyword: str | None = None, room_id: int | None = None, status: str | None = None
) -> Sequence[Reservation]:
    query = select(Reservation).where(
        Reservation.startTime >= datetime.now(timezone.utc) - timedelta(days=1)
    )
    if keyword:
        query = query.join(Room).where(
            or_(
                column("email").like(f"%{keyword}%"),
                column("reason").like(f"%{keyword}%"),
                column("studentId").like(f"%{keyword}%"),
                column("room.name").like(f"%{keyword}%"),
            )
        )
    if room_id:
        query = query.where(Reservation.roomId == room_id)
    if status:
        query = query.where(Reservation.status == status)
    reservations = session.exec(query).all()

    return reservations


def create_admin_login(email: str, cookie: str) -> None:
    user_login = AdminLogin(
        email=email,
        cookie=cookie,
        expiry=datetime.now(timezone.utc) + timedelta(seconds=3600),
    )
    session.add(user_login)
    session.commit()


def get_future_reservations() -> Sequence[Reservation]:
    reservations = session.exec(
        select(Reservation).where(
            Reservation.startTime >= datetime.now(timezone.utc)
        )
    ).all()
    return reservations


def get_future_reservations_by_approver_id(approver_id: int | None) -> Sequence[Reservation]:
    reservations = session.exec(
        select(Reservation)
        .join(Room)
        .join(RoomApprover)
        .where(Reservation.startTime >= datetime.now(timezone.utc))
        .where(RoomApprover.adminId == approver_id)
    ).all()
    return reservations


def change_reservation_status_by_id(
    id: int | None, status: str, admin: int, reason: str | None = None
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
            admin, reservation.id or -1, reservation.status, reason
        )
        update_analytic(
            datetime.now(timezone.utc),
            0,
            1 if status == "approved" else 0,
            1 if status == "rejected" else 0,
            0,
            0,
        )


def create_reservation_operation_log(
    admin: int, reservation: int, operation: str, reason: str | None = None
) -> None:
    log = ReservationOperationLog(
        adminId=admin, reservationId=reservation, operation=operation, reason=reason
    )
    session.add(log)
    session.commit()


def update_analytic(
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


def get_analytic_by_date(date: datetime) -> Analytic | None:
    analytic = session.exec(
        select(Analytic).where(
            Analytic.date
            == date.astimezone(timezone.utc).replace(
                hour=0, minute=0, second=0, microsecond=0
            )
        )
    ).one_or_none()
    return analytic


def get_analytics_between(start: datetime, end: datetime) -> Sequence[Analytic]:
    s = start.astimezone(timezone.utc).replace(
        hour=0, minute=0, second=0, microsecond=0
    )
    e = end.astimezone(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    analytics = session.exec(
        select(Analytic).where(Analytic.date >= s).where(Analytic.date <= e)
    ).all()
    return analytics


def get_reservation_by_id(id: int | None) -> Reservation | None:
    reservation = session.exec(
        select(Reservation).where(Reservation.id == id)
    ).one_or_none()
    return reservation


def get_campus_by_id(id: int | None) -> Campus | None:
    campus = session.exec(select(Campus).where(Campus.id == id)).one_or_none()
    return campus


def get_all_reservations() -> Sequence[Reservation]:
    reservations = session.exec(select(Reservation)).all()
    return reservations


def get_reservations_by_time_range(
    start: datetime | None, end: datetime | None
) -> Sequence[Reservation]:
    query = select(Reservation)
    if start:
        query = query.where(Reservation.startTime >= start)
    if end:
        query = query.where(Reservation.endTime <= end)
    reservations = session.exec(query).all()
    return reservations


def get_class_by_id(id: int | None) -> Class | None:
    class_ = session.exec(select(Class).where(Class.id == id)).one_or_none()
    return class_


def delete_campus(campus: Campus) -> None:
    rooms = session.exec(select(Room).where(Room.campusId == campus.id)).all()
    classes = session.exec(select(Class).where(Class.campusId == campus.id)).all()
    for room in rooms:
        delete_room(room)
    for class_ in classes:
        delete_class(class_)
    session.delete(campus)
    session.commit()


def delete_class(class_: Class) -> None:
    session.delete(class_)
    session.commit()


def delete_room(room: Room) -> None:
    policies = session.exec(
        select(RoomPolicy).where(RoomPolicy.roomId == room.id)
    ).all()
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room.id)
    ).all()
    for policy in policies:
        delete_policy(policy)
    for approver in approvers:
        delete_room_approver(approver)
    session.delete(room)
    session.commit()


def delete_policy(policy: RoomPolicy) -> None:
    session.delete(policy)
    session.commit()


def delete_room_approver(approver: RoomApprover) -> None:
    session.delete(approver)
    session.commit()


def create_policy(
    room: Room, days: List[int], startTime: List[int], endTime: List[int]
) -> None:
    policy = RoomPolicy(
        room=room, days=days, startTime=startTime, endTime=endTime
    )
    session.add(policy)
    session.commit()


def get_policy_by_id(id: int | None) -> RoomPolicy | None:
    policy = session.exec(
        select(RoomPolicy).where(RoomPolicy.id == id)
    ).one_or_none()
    return policy


def toggle_policy(policy: RoomPolicy) -> None:
    policy.enabled = not policy.enabled
    session.add(policy)
    session.commit()


def edit_policy(policy: RoomPolicy) -> None:
    session.add(policy)
    session.commit()


def edit_room(room: Room) -> None:
    session.add(room)
    session.commit()


def edit_campus(campus: Campus) -> None:
    session.add(campus)
    session.commit()


def edit_class(class_: Class) -> None:
    session.add(class_)
    session.commit()


def get_temp_admin_login_by_token(token: str) -> TempAdminLogin | None:
    temp_admin_login = session.exec(
        select(TempAdminLogin).where(TempAdminLogin.token == token)
    ).one_or_none()
    return temp_admin_login


def delete_temp_admin_login(temp_admin_login: TempAdminLogin) -> None:
    session.delete(temp_admin_login)
    session.commit()


def get_admin_by_id(admin_id: int | None) -> Admin | None:
    admin = session.exec(select(Admin).where(Admin.id == admin_id)).one_or_none()
    return admin


def create_room_approver(room: Room, admin: Admin) -> None:
    approver = RoomApprover(room=room, admin=admin)
    session.add(approver)
    session.commit()


def get_room_approvers() -> Sequence[RoomApprover]:
    approvers = session.exec(select(RoomApprover)).all()
    return approvers


def get_room_approver_by_id(id: int | None) -> RoomApprover | None:
    approver = session.exec(
        select(RoomApprover).where(RoomApprover.id == id)
    ).one_or_none()
    return approver


def get_room_approvers_by_admin_id(admin_id: int) -> Sequence[RoomApprover] | None:
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.adminId == admin_id)
    ).all()
    return approvers


def get_room_approvers_by_room_id(room_id: int) -> Sequence[RoomApprover] | None:
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.roomId == room_id)
    ).all()
    return approvers


def get_admins() -> Sequence[Admin]:
    admins = session.exec(select(Admin)).all()
    return admins


def create_temp_admin_login(email: str, token: str) -> None:
    temp_admin_login = TempAdminLogin(email=email, token=token)
    session.add(temp_admin_login)
    session.commit()


def get_reservations_by_time_range_and_room(
    start: datetime | None, end: datetime | None, room_id: int
) -> Sequence[Reservation]:
    query = select(Reservation).where(Reservation.roomId == room_id)
    if start:
        query = query.where(Reservation.startTime >= start)
    if end:
        query = query.where(Reservation.endTime <= end)
    reservations = session.exec(query).all()
    return reservations


def delete_admin(admin: Admin) -> None:
    approvers = session.exec(
        select(RoomApprover).where(RoomApprover.adminId == admin.id)
    ).all()
    for approver in approvers:
        delete_room_approver(approver)
    session.delete(admin)
    session.commit()


def change_admin_password(admin_id: int, new_password: str) -> None:
    admin = session.exec(select(Admin).where(Admin.id == admin_id)).one_or_none()
    if not admin:
        return
    admin.password = new_password
    session.add(admin)
    session.commit()


def create_access_log(log: AccessLog) -> None:
    with Session(engine) as session:
        session.add(log)
        session.commit()
        session.refresh(log)


def get_error_log_count() -> int:
    data = session.exec(select(ErrorLog)).all()
    return len(data)


def edit_admin(admin: Admin) -> None:
    session.add(admin)
    session.commit()
 
