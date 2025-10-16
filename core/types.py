from datetime import datetime
from typing import Any, Generic, List, Optional, TypeVar, Sequence

from fastapi.encoders import jsonable_encoder
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict

from sqlmodel import (
    SQLModel,
    DateTime,
    Field,
    JSON,
    Column,
    BIGINT,
    func,
    Relationship as _relationship,
)
from datetime import datetime, timedelta, timezone


T = TypeVar("T")

def Relationship(*args, **kwargs) -> Any:
    kwargs.setdefault("sa_relationship_kwargs", {"lazy": "selectin"})
    return _relationship(*args, **kwargs)

class Class(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    name: str
    campusId: int | None = Field(default=None, foreign_key="campus.id")
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    campus: "Campus" = Relationship(back_populates="classes")
    reservations: List["Reservation"] = Relationship(back_populates="class_")


class Campus(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    name: str
    isPrivileged: bool = Field(default=False)
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    classes: List["Class"] = Relationship(back_populates="campus")
    rooms: List["Room"] = Relationship(back_populates="campus")


class Room(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    name: str
    campusId: int | None = Field(default=None, foreign_key="campus.id")
    createdAt: datetime | None = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    campus: "Campus" = Relationship(back_populates="rooms")
    enabled: bool = Field(default=True)
    reservations: List["Reservation"] = Relationship(back_populates="room")
    approvers: List["RoomApprover"] = Relationship(back_populates="room")
    policies: List["RoomPolicy"] = Relationship(back_populates="room")


class RoomApprover(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    roomId: int | None = Field(default=None, foreign_key="room.id")
    adminId: int | None = Field(default=None, foreign_key="admin.id")
    notificationsEnabled: bool = Field(default=True)
    room: "Room" = Relationship(back_populates="approvers")
    admin: "Admin" = Relationship(back_populates="approvers")


class Reservation(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    roomId: int | None = Field(default=None, foreign_key="room.id")
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
    classId: int | None = Field(default=None, foreign_key="class.id")
    studentId: str
    status: str = "pending"
    latestExecutorId: int | None = Field(default=None, foreign_key="admin.id")
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    room: "Room" = Relationship(back_populates="reservations")
    class_: "Class" = Relationship(back_populates="reservations")
    logs: List["ReservationOperationLog"] = Relationship(back_populates="reservation")
    latestExecutor: "Admin" = Relationship(back_populates="executedReservations")


class RoomPolicy(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    roomId: int | None = Field(default=None, foreign_key="room.id")
    days: List[int] = Field(sa_column=Column(JSON))
    startTime: List[int] = Field(sa_column=Column(JSON))
    endTime: List[int] = Field(sa_column=Column(JSON))
    enabled: bool = True
    room: "Room" = Relationship(back_populates="policies")


class Admin(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    email: str
    name: str
    password: str
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    approvers: List["RoomApprover"] = Relationship(back_populates="admin")
    operationLogs: List["ReservationOperationLog"] = Relationship(
        back_populates="admin"
    )
    executedReservations: List["Reservation"] = Relationship(
        back_populates="latestExecutor"
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
    adminId: int | None = Field(default=None, foreign_key="admin.id")
    reservationId: int | None = Field(default=None, foreign_key="reservation.id")
    operation: str
    reason: str | None = None
    time: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )
    admin: "Admin" = Relationship(back_populates="operationLogs")
    reservation: "Reservation" = Relationship(back_populates="logs")


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

class Cache(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    key: str
    value: dict = Field(sa_column=Column(JSON))
    createdAt: datetime = Field(
        sa_column=Column(DateTime(timezone=True), server_default=func.now()),
        default_factory=None,
    )


class ReservationCreateRequest(BaseModel):
    room: int
    startTime: int
    endTime: int
    studentName: str
    email: str
    reason: str
    classId: int
    studentId: str
    turnstileToken: str


class ReservationGetRequest(BaseModel):
    keyword: str | None
    room: int | None
    status: str | None


class AdminLoginRequest(BaseModel):
    email: str | None
    password: str | None
    token: str | None
    turnstileToken: str | None


class ReservationApproveRequest(BaseModel):
    id: int
    approved: bool
    reason: str | None


class ReservationExportRequest(BaseModel):
    startTime: int
    endTime: int


class CampusDeleteRequest(BaseModel):
    id: int


class RoomDeleteRequest(BaseModel):
    id: int


class ClassDeleteRequest(BaseModel):
    id: int


class RoomPolicyDeleteRequest(BaseModel):
    id: int


class ClassCreateRequest(BaseModel):
    name: str
    campus: int


class RoomPolicyCreateRequest(BaseModel):
    room: int
    days: List[int]
    startTime: List[int]
    endTime: List[int]


class RoomPolicyEditRequest(BaseModel):
    id: int
    days: List[int]
    startTime: List[int]
    endTime: List[int]


class RoomCreateRequest(BaseModel):
    name: str
    campus: int


class RoomPolicyToggleRequest(BaseModel):
    id: int


class CampusCreateRequest(BaseModel):
    name: str


class RoomEditRequest(BaseModel):
    id: int
    name: str
    campus: int
    enabled: bool


class CampusEditRequest(BaseModel):
    id: int
    name: str


class ClassEditRequest(BaseModel):
    id: int
    name: str
    campus: int


class RoomApproverCreateRequest(BaseModel):
    room: int
    admin: int

class RoomApproverNotificationsToggleRequest(BaseModel):
    id: int

class RoomApproverDeleteRequest(BaseModel):
    id: int


class AdminCreateRequest(BaseModel):
    name: str
    email: str
    password: str


class AdminEditPasswordRequest(BaseModel):
    newPassword: str
    admin: int


class AdminDeleteRequest(BaseModel):
    id: int


class AdminEditRequest(BaseModel):
    id: int
    name: str
    email: str


class ORMBaseModel(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class CampusResponse(ORMBaseModel):
    id: int | None
    name: str
    isPrivileged: bool = False
    createdAt: datetime | None = None


class RoomResponse(ORMBaseModel):
    id: int | None
    name: str
    campus: int | None
    createdAt: datetime | None = None
    enabled: bool = True
    policies: Sequence["RoomPolicyResponseBase"] = []

class RoomAdminResponse(RoomResponse):
    approvers: Sequence["RoomApproverResponseBase"] = []

class ClassResponse(ORMBaseModel):
    id: int | None
    name: str
    campus: int | None
    createdAt: datetime | None = None


class RoomPolicyResponseBase(ORMBaseModel):
    id: int | None
    roomId: int | None
    days: List[int]
    startTime: List[int]
    endTime: List[int]
    enabled: bool


class RoomApproverResponseBase(ORMBaseModel):
    id: int | None
    roomId: int | None
    adminId: int | None
    notificationsEnabled: bool


class AdminResponse(ORMBaseModel):
    id: int | None
    name: str
    email: str
    createdAt: datetime | None = None


class ReservationResponseBase(ORMBaseModel):
    id: int | None
    startTime: datetime
    endTime: datetime
    studentName: str
    email: str
    reason: str
    status: str

class ReservationResponseDetail(ReservationResponseBase):
    roomName: str | None = None
    className: str | None = None

class ReservationQueryResponse(BaseModel):
    total: int
    reservations: List["ReservationResponseDetail"] = []


class ReservationUpcomingResponse(ReservationResponseBase):
    studentId: str
    className: str | None = None
    roomName: str | None = None
    createdAt: int
    campusName: str | None = None


class ReservationFullResponse(ReservationResponseBase):
    studentId: str
    className: str | None = None
    roomName: str | None = None
    createdAt: datetime
    campusName: str | None = None
    latestExecutor: str | None = None


class ReservationFullQueryResponse(BaseModel):
    total: int
    reservations: List["ReservationFullResponse"] = []


class ReservationCreateResponse(BaseModel):
    reservationId: int


class AnalyticsOverviewDailyDetail(BaseModel):
    reservations: List[int]
    reservationCreations: List[int]
    requests: List[int]
    approvals: List[int]
    rejections: List[int]


class AnalyticsOverviewWeeklyDetail(BaseModel):
    reservations: List[int]
    reservationCreations: List[int]
    approvals: List[int]
    rejections: List[int]


class AnalyticsOverviewMonthlyDetail(BaseModel):
    reservations: List[int]
    reservationCreations: List[int]
    approvals: List[int]
    rejections: List[int]


class AnalyticsOverviewTodayDetail(BaseModel):
    reservations: int
    reservationCreations: int
    requests: int
    approvals: int
    rejections: int


class AnalyticsOverviewResponse(BaseModel):
    daily: AnalyticsOverviewDailyDetail
    weekly: AnalyticsOverviewWeeklyDetail
    monthly: AnalyticsOverviewMonthlyDetail
    today: AnalyticsOverviewTodayDetail

class AnalyticsWeeklyRoomDetail(BaseModel):
    roomName: str
    reservations: int
    reservationCreations: int

class AnalyticsReasonDetail(BaseModel):
    word: str
    count: int

class AnalyticsWeeklyResponse(BaseModel):
    rooms: List[AnalyticsWeeklyRoomDetail]
    totalReservations: int
    totalReservationCreations: int
    totalApprovals: int
    totalRejections: int
    reasons: List[AnalyticsReasonDetail]
    hourlyReservations: List[int]
    dailyReservations: List[int]
    dailyReservationCreations: List[int]


class ApiResponseBody(BaseModel, Generic[T]):
    success: bool
    data: Optional[T] = None
    message: Optional[str] = None


class ApiResponse(JSONResponse, Generic[T]):
    def __init__(
        self,
        success: bool,
        message: Optional[str] = None,
        data: Optional[T] = None,
        status_code: int = 200,
        **kwargs: Any,
    ) -> None:
        body = ApiResponseBody[T](success=success, data=data, message=message)
        content = jsonable_encoder(body.model_dump(exclude_none=True))
        super().__init__(content=content, status_code=status_code, **kwargs)
