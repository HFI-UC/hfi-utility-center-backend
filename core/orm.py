from datetime import datetime, timedelta, timezone
from sqlmodel import (
    SQLModel,
    DateTime,
    create_engine,
    Session,
    select,
    or_,
    column,
    Field,
    JSON,
    Column,
    BIGINT,
    func,
)
from core.env import *
from typing import Sequence, List
from core.types import *
import traceback


class Class(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    name: str
    campus: int
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class Campus(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    name: str
    isPrivileged: bool = Field(default=False)
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class Room(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    name: str
    campus: int
    createdAt: datetime | None = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class RoomApprover(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    room: int
    admin: int


class Reservation(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    room: int
    startTime: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    endTime: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    studentName: str
    email: str
    reason: str
    classId: int
    studentId: str
    status: str = "pending"
    latestExecutor: int | None = Field(default=None)
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class RoomPolicy(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    room: int
    days: List[int] = Field(sa_column=Column(JSON))
    startTime: List[int] = Field(sa_column=Column(JSON))
    endTime: List[int] = Field(sa_column=Column(JSON))
    enabled: bool = True


class Admin(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    email: str
    name: str
    password: str
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class TempAdminLogin(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    email: str
    token: str
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class AdminLogin(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    email: str
    cookie: str
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    expiry: datetime = Field(
        sa_column=Column(DateTime(timezone=True)),
        default_factory=lambda: datetime.now(timezone.utc) + timedelta(hours=1),
    )


class AccessLog(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    uuid: str
    time: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    userAgent: str
    payload: str | None = None
    ip: str | None = None
    url: str
    method: str
    status: int
    port: int | None = None
    responseTime: float | None = None


class ErrorLog(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    error: str
    time: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    uuid: str | None = None
    traceback: str | None = None


class ReservationOperationLog(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    admin: int
    reservation: int
    operation: str
    reason: str | None = None
    time: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class Analytic(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    date: datetime = Field(
        sa_column=Column(DateTime(timezone=True)),
        default_factory=lambda: datetime.now(timezone.utc).replace(
            hour=0, minute=0, second=0, microsecond=0
        ),
    )
    reservations: int = 0
    reservationCreations: int = 0
    approvals: int = 0
    rejections: int = 0
    requests: int = Field(sa_column=Column(BIGINT), default=0)
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


engine = create_engine(database_url)


def create_error_log(error_log: ErrorLog) -> None:
    try:
        with Session(engine) as session:
            session.add(error_log)
            session.commit()
    except Exception:
        pass


def create_db_and_tables() -> None:
    SQLModel.metadata.create_all(engine)


def create_room(name: str, campus: int) -> None:
    with Session(engine) as session:
        room = Room(name=name, campus=campus)
        session.add(room)
        session.commit()


def create_campus(name: str) -> None:
    with Session(engine) as session:
        campus = Campus(name=name)
        session.add(campus)
        session.commit()


def create_class(name: str, campus: int) -> None:
    with Session(engine) as session:
        _class = Class(name=name, campus=campus)
        session.add(_class)
        session.commit()


def get_campus() -> Sequence[Campus]:
    with Session(engine) as session:
        campuses = session.exec(select(Campus)).all()
        return campuses


def get_policy() -> Sequence[RoomPolicy]:
    with Session(engine) as session:
        policies = session.exec(select(RoomPolicy)).all()
        return policies


def get_policy_by_room_id(room_id: int) -> Sequence[RoomPolicy]:
    with Session(engine) as session:
        policies = session.exec(
            select(RoomPolicy).where(RoomPolicy.room == room_id)
        ).all()
        return policies


def get_class() -> Sequence[Class]:
    with Session(engine) as session:
        classes = session.exec(select(Class)).all()
        return classes


def get_room() -> Sequence[Room]:
    with Session(engine) as session:
        rooms = session.exec(select(Room)).all()
        return rooms


def create_reservation(request: ReservationCreateRequest) -> int:
    with Session(engine) as session:
        reservation = Reservation(
            room=request.room,
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


def get_reservation_by_room_id(room_id: int) -> Sequence[Reservation]:
    with Session(engine) as session:
        reservations = session.exec(
            select(Reservation).where(Reservation.room == room_id)
        ).all()
        return reservations


def get_room_by_id(room_id: int) -> Room | None:
    with Session(engine) as session:
        room = session.exec(select(Room).where(Room.id == room_id)).one_or_none()
        return room


def create_admin(email: str, name: str, password: str) -> None:
    with Session(engine) as session:
        admin = Admin(email=email, name=name, password=password)
        session.add(admin)
        session.commit()


def get_admin_login_by_cookie(cookie: str) -> AdminLogin | None:
    with Session(engine) as session:
        admin_login = session.exec(
            select(AdminLogin).where(AdminLogin.cookie == cookie)
        ).one_or_none()
        return admin_login


def get_admin_by_email(email: str) -> Admin | None:
    with Session(engine) as session:
        admin = session.exec(select(Admin).where(Admin.email == email)).first()
        return admin


def get_reservation(
    keyword: str | None = None, room_id: int | None = None, status: str | None = None
) -> Sequence[Reservation]:
    with Session(engine) as session:
        query = select(Reservation).where(
            Reservation.startTime >= datetime.now(timezone.utc) - timedelta(days=1)
        )
        if keyword:
            room = session.exec(
                select(Room).where(column("name").like(f"%{keyword}%"))
            ).one_or_none()
            if room and not room_id:
                query = query.where(Reservation.room == room.id)
            query = query.where(
                or_(
                    column("email").like(f"%{keyword}%"),
                    column("reason").like(f"%{keyword}%"),
                    column("studentId").like(f"%{keyword}%"),
                )
            )
        if room_id:
            query = query.where(Reservation.room == room_id)
        if status:
            query = query.where(Reservation.status == status)
        reservations = session.exec(query).all()

        return reservations


def create_admin_login(email: str, cookie: str) -> None:
    with Session(engine) as session:
        user_login = AdminLogin(
            email=email,
            cookie=cookie,
            expiry=datetime.now(timezone.utc) + timedelta(seconds=3600),
        )
        session.add(user_login)
        session.commit()


def get_future_reservations() -> Sequence[Reservation]:
    with Session(engine) as session:
        reservations = session.exec(
            select(Reservation).where(
                Reservation.startTime >= datetime.now(timezone.utc)
            )
        ).all()
        return reservations


def get_future_reservations_by_approver_id(approver_id: int) -> Sequence[Reservation]:
    with Session(engine) as session:
        approvers = session.exec(
            select(RoomApprover).where(RoomApprover.admin == approver_id)
        ).all()
        reservations = []
        if not approvers:
            return reservations
        for approver in approvers:
            reservations.extend(
                session.exec(
                    select(Reservation)
                    .where(Reservation.startTime >= datetime.now(timezone.utc))
                    .where(Reservation.room == approver.room)
                ).all()
            )
        return reservations


def change_reservation_status_by_id(
    id: int, status: str, admin: int, reason: str | None = None
) -> None:
    with Session(engine) as session:
        reservation = session.exec(
            select(Reservation).where(Reservation.id == id)
        ).one_or_none()
        if reservation:
            reservation.status = status
            reservation.latestExecutor = admin
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
    with Session(engine) as session:
        log = ReservationOperationLog(
            admin=admin, reservation=reservation, operation=operation, reason=reason
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
    with Session(engine) as session:
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
    with Session(engine) as session:
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
    with Session(engine) as session:
        analytics = session.exec(
            select(Analytic).where(Analytic.date >= s).where(Analytic.date <= e)
        ).all()
        return analytics


def get_reservation_by_id(id: int) -> Reservation | None:
    with Session(engine) as session:
        reservation = session.exec(
            select(Reservation).where(Reservation.id == id)
        ).one_or_none()
        return reservation


def get_campus_by_id(id: int) -> Campus | None:
    with Session(engine) as session:
        campus = session.exec(select(Campus).where(Campus.id == id)).one_or_none()
        return campus


def get_all_reservations() -> Sequence[Reservation]:
    with Session(engine) as session:
        reservations = session.exec(select(Reservation)).all()
        return reservations


def get_reservations_by_time_range(
    start: datetime | None, end: datetime | None
) -> Sequence[Reservation]:
    with Session(engine) as session:
        query = select(Reservation)
        if start:
            query = query.where(Reservation.startTime >= start)
        if end:
            query = query.where(Reservation.endTime <= end)
        reservations = session.exec(query).all()
        return reservations


def get_class_by_id(id: int) -> Class | None:
    with Session(engine) as session:
        _class = session.exec(select(Class).where(Class.id == id)).one_or_none()
        return _class


def delete_campus(campus: Campus) -> None:
    with Session(engine) as session:
        with Session(engine) as session:
            rooms = session.exec(select(Room).where(Room.campus == campus.id)).all()
            classes = session.exec(select(Class).where(Class.campus == campus.id)).all()
            session.close()
            for room in rooms:
                delete_room(room)
            for _class in classes:
                delete_class(_class)
        session.delete(campus)
        session.commit()


def delete_class(_class: Class) -> None:
    with Session(engine) as session:
        session.delete(_class)
        session.commit()


def delete_room(room: Room) -> None:
    with Session(engine) as session:
        with Session(engine) as session:
            policies = session.exec(
                select(RoomPolicy).where(RoomPolicy.room == room.id)
            ).all()
            approvers = session.exec(
                select(RoomApprover).where(RoomApprover.room == room.id)
            ).all()
            session.close()
            for policy in policies:
                delete_policy(policy)
            for approver in approvers:
                delete_room_approver(approver)
        session.delete(room)
        session.commit()


def delete_policy(policy: RoomPolicy) -> None:
    with Session(engine) as session:
        session.delete(policy)
        session.commit()


def delete_room_approver(approver: RoomApprover) -> None:
    with Session(engine) as session:
        session.delete(approver)
        session.commit()


def create_policy(
    room: int, days: List[int], startTime: List[int], endTime: List[int]
) -> None:
    with Session(engine) as session:
        policy = RoomPolicy(room=room, days=days, startTime=startTime, endTime=endTime)
        session.add(policy)
        session.commit()


def get_policy_by_id(id: int) -> RoomPolicy | None:
    with Session(engine) as session:
        policy = session.exec(
            select(RoomPolicy).where(RoomPolicy.id == id)
        ).one_or_none()
        return policy


def toggle_policy(policy: RoomPolicy) -> None:
    with Session(engine) as session:
        policy.enabled = not policy.enabled
        session.add(policy)
        session.commit()


def edit_policy(policy: RoomPolicy) -> None:
    with Session(engine) as session:
        session.add(policy)
        session.commit()


def edit_room(room: Room) -> None:
    with Session(engine) as session:
        session.add(room)
        session.commit()


def edit_campus(campus: Campus) -> None:
    with Session(engine) as session:
        session.add(campus)
        session.commit()


def edit_class(_class: Class) -> None:
    with Session(engine) as session:
        session.add(_class)
        session.commit()


def get_temp_admin_login_by_token(token: str) -> TempAdminLogin | None:
    with Session(engine) as session:
        temp_admin_login = session.exec(
            select(TempAdminLogin).where(TempAdminLogin.token == token)
        ).one_or_none()
        return temp_admin_login


def delete_temp_admin_login(temp_admin_login: TempAdminLogin) -> None:
    with Session(engine) as session:
        session.delete(temp_admin_login)
        session.commit()


def get_admin_by_id(admin_id: int) -> Admin | None:
    with Session(engine) as session:
        admin = session.exec(select(Admin).where(Admin.id == admin_id)).one_or_none()
        return admin


def create_room_approver(room_id: int, admin_id: int) -> None:
    with Session(engine) as session:
        approver = RoomApprover(room=room_id, admin=admin_id)
        session.add(approver)
        session.commit()


def get_room_approvers() -> Sequence[RoomApprover]:
    with Session(engine) as session:
        approvers = session.exec(select(RoomApprover)).all()
        return approvers


def get_room_approver_by_id(id: int) -> RoomApprover | None:
    with Session(engine) as session:
        approver = session.exec(
            select(RoomApprover).where(RoomApprover.id == id)
        ).one_or_none()
        return approver


def get_room_approvers_by_admin_id(admin_id: int) -> Sequence[RoomApprover] | None:
    with Session(engine) as session:
        approvers = session.exec(
            select(RoomApprover).where(RoomApprover.admin == admin_id)
        ).all()
        return approvers


def get_room_approvers_by_room_id(room_id: int) -> Sequence[RoomApprover] | None:
    with Session(engine) as session:
        approvers = session.exec(
            select(RoomApprover).where(RoomApprover.room == room_id)
        ).all()
        return approvers


def get_admins() -> Sequence[Admin]:
    with Session(engine) as session:
        admins = session.exec(select(Admin)).all()
        return admins


def create_temp_admin_login(email: str, token: str) -> None:
    with Session(engine) as session:
        temp_admin_login = TempAdminLogin(email=email, token=token)
        session.add(temp_admin_login)
        session.commit()


def get_reservations_by_time_range_and_room(
    start: datetime | None, end: datetime | None, room_id: int
) -> Sequence[Reservation]:
    with Session(engine) as session:
        query = select(Reservation).where(Reservation.room == room_id)
        if start:
            query = query.where(Reservation.startTime >= start)
        if end:
            query = query.where(Reservation.endTime <= end)
        reservations = session.exec(query).all()
        return reservations


def delete_admin(admin: Admin) -> None:
    with Session(engine) as session:
        with Session(engine) as session:
            approvers = session.exec(
                select(RoomApprover).where(RoomApprover.admin == admin.id)
            ).all()
            session.close()
            for approver in approvers:
                delete_room_approver(approver)
        session.delete(admin)
        session.commit()


def change_admin_password(admin_id: int, new_password: str) -> None:
    with Session(engine) as session:
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
    with Session(engine) as session:
        data = session.exec(select(ErrorLog)).all()
        return len(data)


def edit_admin(admin: Admin) -> None:
    with Session(engine) as session:
        session.add(admin)
        session.commit()
