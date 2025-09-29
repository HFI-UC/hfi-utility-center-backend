from datetime import datetime
from typing import Any, Generic, List, Optional, TypeVar

from fastapi.encoders import jsonable_encoder
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict

T = TypeVar("T")


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


class FromAttributesModel(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class CampusListResponse(FromAttributesModel):
    id: int | None
    name: str
    isPrivileged: bool = False
    createdAt: datetime | None = None


class RoomListResponse(FromAttributesModel):
    id: int | None
    name: str
    campus: int | None
    createdAt: datetime | None = None


class ClassListResponse(FromAttributesModel):
    id: int | None
    name: str
    campus: int | None
    createdAt: datetime | None = None


class PolicyListResponse(FromAttributesModel):
    id: int | None
    room: int | None
    days: List[int]
    startTime: List[int]
    endTime: List[int]
    enabled: bool


class ApproverListResponse(FromAttributesModel):
    id: int | None
    room: int | None
    admin: int | None


class AdminListResponse(FromAttributesModel):
    id: int | None
    name: str
    email: str
    createdAt: datetime | None = None


class ReservationBase(FromAttributesModel):
    id: int | None
    startTime: datetime
    endTime: datetime
    studentName: str
    email: str
    reason: str
    status: str


class ReservationGetResponse(ReservationBase):
    className: str | None = None
    roomName: str | None = None


class ReservationFutureResponse(ReservationBase):
    studentId: str
    className: str | None = None
    roomName: str | None = None
    createdAt: int
    campusName: str | None = None


class ReservationAllResponse(ReservationBase):
    studentId: str
    className: str | None = None
    roomName: str | None = None
    createdAt: datetime
    campusName: str | None = None
    latestExecutor: str | None = None


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


class BasicResponseBody(BaseModel, Generic[T]):
    success: bool
    data: Optional[T] = None
    message: Optional[str] = None


class BasicResponse(JSONResponse, Generic[T]):
    def __init__(
        self,
        success: bool,
        message: Optional[str] = None,
        data: Optional[T] = None,
        status_code: int = 200,
        **kwargs: Any,
    ) -> None:
        body = BasicResponseBody[T](success=success, data=data, message=message)
        content = jsonable_encoder(body.model_dump(exclude_none=True))
        super().__init__(content=content, status_code=status_code, **kwargs)
