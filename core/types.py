from fastapi.responses import JSONResponse
from fastapi.encoders import jsonable_encoder
from pydantic import BaseModel
from typing import Any, List, Optional


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


class BasicResponse(JSONResponse):
    def __init__(
        self,
        success: bool,
        message: Optional[str] = None,
        data: Any = None,
        status_code: int = 200,
        **kwargs,
    ) -> None:
        serialized_data = None
        try:
            serialized_data = jsonable_encoder(data)
        except Exception:
            try:
                serialized_data = str(data)
            except Exception:
                serialized_data = None

        content: dict[str, Any] = {"success": success, "data": serialized_data}
        if message is not None:
            content["message"] = message

        super().__init__(content=content, status_code=status_code, **kwargs)
