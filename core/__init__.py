from fastapi import BackgroundTasks, Depends, FastAPI
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.types import Receive, Scope, Send, Message
from fastapi.requests import Request
from fastapi.responses import FileResponse
from slowapi import _rate_limit_exceeded_handler, Limiter
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
from starlette.types import ASGIApp, Receive, Scope, Send
from anyio import to_thread
from contextlib import asynccontextmanager
from core.orm import *
from core.types import *
from core.email import *
from core.utils import *
from core.schedulers import *
from datetime import datetime, timedelta, timezone
from typing import Any

import uuid
import hashlib
import random
import bcrypt
import re
import traceback
import time
import jieba
import unicodedata


@asynccontextmanager
async def lifespan(_: FastAPI):
    create_db_and_tables()
    scheduler.start()
    yield


app = FastAPI(lifespan=lifespan)
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://localhost:5173",
        "https://www.hfiuc.org",
        "https://preview.hfiuc.org",
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)
limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)  # type: ignore


@app.exception_handler(Exception)
async def generic_exception_handler(request: Request, exc: Exception) -> ApiResponse:
    scope = request.scope
    _uuid = scope.get("state", {}).get("request_id", str(uuid.uuid4()))
    return ApiResponse(
        success=False,
        message=f"An internal server error occurred. Please contact support with request ID: {_uuid}",
        status_code=500,
    )


class LogMiddleware(BaseHTTPMiddleware):
    def __init__(self, app: ASGIApp):
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)

        start_time = time.time()
        _uuid = str(uuid.uuid4())
        if "state" not in scope:
            scope["state"] = {}
        scope["state"]["request_id"] = _uuid

        req_body = bytearray()

        async def recv_wrapper() -> Message:
            message = await receive()
            if message["type"] == "http.request":
                req_body.extend(message.get("body", b""))
            return message

        status_code = 500
        resp_chunks = []

        async def send_wrapper(message: Message) -> None:
            nonlocal status_code
            if message["type"] == "http.response.start":
                status_code = message["status"]
                message["headers"].append((b"x-request-id", _uuid.encode()))
            elif message["type"] == "http.response.body":
                resp_chunks.append(message.get("body", b""))
            await send(message)

        try:
            await self.app(scope, recv_wrapper, send_wrapper)
        except Exception as e:
            status_code = 500
            error_log = ErrorLog(
                uuid=_uuid,
                error=str(e),
                traceback=traceback.format_exc(),
            )
            try:
                await to_thread.run_sync(create_error_log, error_log)
            except Exception:
                pass
            raise e from None
        finally:
            response_time_ms = int((time.time() - start_time) * 1000)
            headers = dict((k.lower(), v) for k, v in scope.get("headers", []))
            ua = headers.get(b"user-agent", b"").decode()
            client = scope.get("client") or ("", 0)
            ip = headers.get(b"x-forwarded-for")
            if ip:
                ip = ip.decode()
            else:
                ip = client[0]
            try:
                payload = req_body.decode("utf-8")
            except UnicodeDecodeError:
                payload = None
            log = AccessLog(
                userAgent=ua,
                uuid=_uuid,
                ip=ip or client[0],
                port=client[1],
                url=scope.get("path", ""),
                method=scope.get("method", ""),
                status=status_code,
                payload=payload,
                responseTime=response_time_ms,
            )
            try:
                await to_thread.run_sync(create_access_log, log)
                await to_thread.run_sync(update_analytic, datetime.now(), 0, 0, 0, 0, 1)
            except Exception:
                pass


app.add_middleware(LogMiddleware)


def password_hash(password: str) -> str:
    salt = bcrypt.gensalt()
    hashed = bcrypt.hashpw(password.encode(), salt)
    return hashed.decode()


def verify_password(password: str, hashed: str) -> bool:
    return bcrypt.checkpw(password.encode(), hashed.encode())


def get_current_user(request: Request) -> AdminLogin | None:
    cookie = request.cookies.get("UCCOOKIE")
    if not cookie:
        return None
    user_login = get_admin_login_by_cookie(cookie)
    if not user_login:
        return None
    if user_login.expiry < datetime.now(timezone.utc):
        return None
    return user_login


@app.get(
    "/room/list",
    response_model=ApiResponseBody[list[RoomResponse]],
)
@limiter.limit("5/second")
async def room_list(request: Request) -> ApiResponse[list[RoomResponse]]:
    rooms = get_room()
    data = [
        RoomResponse(
            id=room.id,
            name=room.name,
            campus=room.campusId,
            createdAt=room.createdAt,
            policies=[
                RoomPolicyResponseBase.model_validate(policy)
                for policy in room.policies
            ],
            approvers=[
                RoomApproverResponse.model_validate(approver)
                for approver in room.approvers
            ],
            enabled=room.enabled,
        )
        for room in rooms
    ]
    return ApiResponse(success=True, data=data)


@app.get(
    "/campus/list",
    response_model=ApiResponseBody[list[CampusResponse]],
)
@limiter.limit("5/second")
async def campus_list(request: Request) -> ApiResponse[list[CampusResponse]]:
    campuses = get_campus()
    data = [
        CampusResponse(
            id=campus.id,
            name=campus.name,
            isPrivileged=campus.isPrivileged,
            createdAt=campus.createdAt,
        )
        for campus in campuses
    ]
    return ApiResponse(success=True, data=data)


@app.get(
    "/class/list",
    response_model=ApiResponseBody[list[ClassResponse]],
)
@limiter.limit("5/second")
async def class_list(request: Request) -> ApiResponse[list[ClassResponse]]:
    classes = get_class()
    data = [
        ClassResponse(
            id=class_.id,
            name=class_.name,
            campus=class_.campusId,
            createdAt=class_.createdAt,
        )
        for class_ in classes
    ]
    return ApiResponse(success=True, data=data)


@app.post(
    "/campus/delete",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def campus_delete(
    request: Request, payload: CampusDeleteRequest
) -> ApiResponse[Any]:
    campus = get_campus_by_id(payload.id)
    if not campus:
        return ApiResponse(success=False, message="Campus not found.", status_code=404)
    delete_campus(campus)
    return ApiResponse(success=True, message="Campus deleted successfully.")


@app.post(
    "/room/delete",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def room_delete(request: Request, payload: RoomDeleteRequest) -> ApiResponse[Any]:
    room = get_room_by_id(payload.id)
    if not room:
        return ApiResponse(success=False, message="Room not found.", status_code=404)
    delete_room(room)
    return ApiResponse(success=True, message="Room deleted successfully.")


@app.post(
    "/class/delete",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def class_delete(
    request: Request, payload: ClassDeleteRequest
) -> ApiResponse[Any]:
    class_ = get_class_by_id(payload.id)
    if not class_:
        return ApiResponse(success=False, message="Class not found.", status_code=404)
    delete_class(class_)
    return ApiResponse(success=True, message="Class deleted successfully.")


@app.post(
    "/reservation/create",
    response_model=ApiResponseBody[ReservationCreateResponse],
)
@limiter.limit("5/second")
async def reservation_create(
    request: Request,
    payload: ReservationCreateRequest,
    background_task: BackgroundTasks,
) -> ApiResponse[ReservationCreateResponse]:
    if not verify_turnstile_token(payload.turnstileToken):
        return ApiResponse(
            success=False, message="Turnstile verification failed.", status_code=403
        )
    reservations = get_reservation_by_room_id(payload.room)
    room = get_room_by_id(payload.room)
    errors = []
    class_ = get_class_by_id(payload.classId)
    if not room or not room.enabled:
        errors.append("Room not found or disabled.")
    if not class_:
        errors.append("Class not found.")
    if errors:
        return ApiResponse(success=False, message="\n".join(errors), status_code=400)

    def validate_time_conflict(time: datetime) -> bool:
        for reservation in reservations:
            if (
                reservation.status != "rejected"
                and reservation.startTime <= time <= reservation.endTime
            ):
                return False
        return True

    def validate_policy(_time: int) -> bool:
        if room:
            policies = get_policy_by_room_id(room.id)
            time_obj = datetime.fromtimestamp(_time)
            day = time_obj.weekday()
            for policy in policies:
                if not policy.enabled:
                    continue
                if day in policy.days:
                    start_hour, start_minute = policy.startTime
                    end_hour, end_minute = policy.endTime
                    start_time = datetime(
                        time_obj.year,
                        time_obj.month,
                        time_obj.day,
                        start_hour,
                        start_minute,
                    )
                    end_time = datetime(
                        time_obj.year,
                        time_obj.month,
                        time_obj.day,
                        end_hour,
                        end_minute,
                    )
                    if start_time <= time_obj <= end_time:
                        return False
        return True

    def validate_email_format(email: str) -> bool:
        if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email):
            return False
        return True

    if not payload.studentId.startswith("GJ") and not len(payload.studentId) == 10:
        errors.append("Invalid student ID format.")
    if not validate_email_format(payload.email):
        errors.append("Invalid email format.")
    if payload.startTime >= payload.endTime:
        errors.append("Start time must be before end time.")
    if payload.endTime - payload.startTime > 2 * 3600:
        errors.append("Reservation duration must not exceed 2 hours.")
    if payload.startTime < datetime.now().timestamp():
        errors.append("Start time must be in the future.")
    if payload.startTime > (datetime.now() + timedelta(days=30)).timestamp():
        errors.append("Start time must be within 30 days.")
    admin = get_admin_by_email(payload.email)
    if not admin:
        if not validate_policy(payload.startTime) or not validate_policy(
            payload.endTime
        ):
            errors.append("Start or end time violates room policy.")
        if not validate_time_conflict(
            datetime.fromtimestamp(payload.startTime, timezone.utc)
        ) or not validate_time_conflict(
            datetime.fromtimestamp(payload.endTime, timezone.utc)
        ):
            errors.append("Start or end time conflicts with existing reservation.")
    if errors:
        return ApiResponse(success=False, message="\n".join(errors), status_code=400)

    approvers = get_room_approvers_by_room_id(room.id if room and room.id else -1)
    if not approvers:
        return ApiResponse(
            success=False, message="No approvers found.", status_code=404
        )

    result = create_reservation(payload)

    background_task.add_task(
        send_normal_update_email,
        email_title="Reservation Created",
        title=f"Hi {payload.studentName}! Your reservation has been created.",
        email=payload.email,
        details=(
            f"Your reservation for room {room.name if room else 'Unknown'} for the time period "
            f"<b>{datetime.fromtimestamp(payload.startTime).strftime('%Y-%m-%d %H:%M')} - "
            f"{datetime.fromtimestamp(payload.endTime).strftime('%H:%M')}</b> has been created and is currently pending approval."
        ),
    )

    if admin:
        class_name = next(
            (cls.name for cls in get_class() if cls.id == payload.classId), None
        )
        background_task.add_task(
            send_reservation_approval_email,
            email_title="Reservation Approval",
            title="Your reservation has been approved!",
            email=payload.email,
            details=f"Hi {payload.studentName}! Your reservation for {room.name if room else None} has been approved. Below is the detailed information.",
            user=payload.studentName,
            room=room.name if room else "",
            class_name=class_name or "",
            student_id=payload.studentId,
            reason=payload.reason,
            time=f"{datetime.fromtimestamp(payload.startTime).strftime('%Y-%m-%d %H:%M')} - {datetime.fromtimestamp(payload.endTime).strftime('%H:%M')}",
        )
        reservations = get_reservations_by_time_range_and_room(
            datetime.fromtimestamp(payload.startTime),
            datetime.fromtimestamp(payload.endTime),
            room_id=payload.room,
        )
        for reservation in reservations:
            if reservation.id != result:
                change_reservation_status_by_id(
                    reservation.id or -1, "rejected", admin.id or -1
                )
                background_task.add_task(
                    send_normal_update_email,
                    email_title="Reservation Rejected",
                    title="Your reservation has been rejected.",
                    email=reservation.email,
                    details=f"Hi {reservation.studentName}! Your reservation for {room.name if room else None} has been rejected due to a higher priority reservation.",
                )
        return ApiResponse(
            success=True,
            message="Your reservation has been created and approved.",
            data=ReservationCreateResponse(reservationId=result),
        )

    for approver in approvers:
        admin = get_admin_by_id(approver.id or -1)
        if not admin:
            continue
        token = hashlib.md5(
            (
                payload.email
                + payload.studentName
                + str(payload.startTime)
                + str(random.randint(100000, 999999))
                + str(datetime.now().timestamp())
            ).encode()
        ).hexdigest()
        background_task.add_task(
            send_normal_update_with_external_link_email,
            email_title="New Reservation Request",
            title=f"Hi {admin.name}! A new reservation request has been created.",
            email=admin.email,
            details=f"Reservation ID #{result}, click the button below for reservation details.",
            button_text="View Reservation",
            link=f"{base_url}/admin/reservation/?token={token}",
        )
        create_temp_admin_login(admin.email, token)
    return ApiResponse(
        success=True,
        message="Your reservation has been created.",
        data=ReservationCreateResponse(reservationId=result),
    )


@app.post(
    "/reservation/get",
    response_model=ApiResponseBody[list[ReservationQueryResponse]],
)
@limiter.limit("5/second")
async def reservation_get(
    request: Request, payload: ReservationGetRequest
) -> ApiResponse[list[ReservationQueryResponse]]:
    if payload.room and not get_room_by_id(payload.room):
        return ApiResponse(success=False, message="Room not found.", status_code=404)

    reservations = get_reservation(payload.keyword, payload.room, payload.status)
    classes = get_class()
    res: list[ReservationQueryResponse] = []
    for reservation in reservations:
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.roomId)
        res.append(
            ReservationQueryResponse(
                id=reservation.id,
                startTime=reservation.startTime,
                endTime=reservation.endTime,
                studentName=reservation.studentName,
                email=reservation.email,
                reason=reservation.reason,
                className=class_name,
                roomName=room.name if room else None,
                status=reservation.status,
            )
        )
    return ApiResponse(success=True, data=res)


@app.post(
    "/admin/login",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_login(
    request: Request, payload: AdminLoginRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if admin_login:
        return ApiResponse(
            success=False, message="User already logged in.", status_code=400
        )

    if payload.token:
        temp_admin_login = get_temp_admin_login_by_token(payload.token)
        if not temp_admin_login:
            return ApiResponse(
                success=False,
                message="Invalid token or token expired.",
                status_code=400,
            )
        cookie = hashlib.md5(
            (
                temp_admin_login.email
                + temp_admin_login.token
                + str(random.randint(100000, 999999))
                + str(datetime.now().timestamp())
            ).encode()
        ).hexdigest()
        create_admin_login(temp_admin_login.email, cookie)
        delete_temp_admin_login(temp_admin_login)
        response = ApiResponse(success=True, message="Login successful.")
        response.set_cookie(
            "UCCOOKIE", cookie, httponly=True, samesite="none", secure=True
        )
        return response
    if not payload.email or not payload.password:
        return ApiResponse(
            success=False, message="Email and password are required.", status_code=400
        )

    if not payload.turnstileToken or not verify_turnstile_token(payload.turnstileToken):
        return ApiResponse(
            success=False, message="Turnstile verification failed.", status_code=403
        )

    admin = get_admin_by_email(payload.email)
    if not admin or not verify_password(payload.password, admin.password):
        return ApiResponse(
            success=False, message="Invalid email or password.", status_code=401
        )
    cookie = hashlib.md5(
        (
            payload.email
            + payload.password
            + str(random.randint(100000, 999999))
            + str(datetime.now().timestamp())
        ).encode()
    ).hexdigest()
    create_admin_login(payload.email, cookie)
    response = ApiResponse(success=True, message="Login successful.")
    response.set_cookie("UCCOOKIE", cookie, httponly=True, samesite="none", secure=True)
    return response


@app.get(
    "/admin/logout",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_logout(
    request: Request, user_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not user_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    response = ApiResponse(success=True, message="Logout successful.")
    response.delete_cookie("UCCOOKIE")
    return response


@app.get(
    "/admin/check-login",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_check_login(
    request: Request, user_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if user_login:
        return ApiResponse(success=True)
    return ApiResponse(success=False, status_code=400)


@app.get(
    "/reservation/future",
    response_model=ApiResponseBody[list[ReservationUpcomingResponse]],
)
@limiter.limit("5/second")
async def reservation_future(
    request: Request, admin_login=Depends(get_current_user)
) -> ApiResponse[list[ReservationUpcomingResponse]]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    admin = get_admin_by_email(admin_login.email)
    future_reservations = get_future_reservations_by_approver_id(
        admin.id if admin and admin.id is not None else -1
    )
    classes = get_class()
    res: list[ReservationUpcomingResponse] = []
    for reservation in future_reservations:
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.roomId)
        campus = get_campus_by_id(room.campusId) if room and room.campusId else None
        res.append(
            ReservationUpcomingResponse(
                id=reservation.id,
                startTime=reservation.startTime,
                endTime=reservation.endTime,
                studentName=reservation.studentName,
                email=reservation.email,
                reason=reservation.reason,
                roomName=room.name if room else None,
                className=class_name,
                studentId=reservation.studentId,
                status=reservation.status,
                createdAt=int(reservation.createdAt.timestamp()),
                campusName=campus.name if campus else None,
            )
        )
    return ApiResponse(success=True, data=res)


@app.post(
    "/reservation/approval",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def reservation_approval(
    request: Request,
    payload: ReservationApproveRequest,
    background_task: BackgroundTasks,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    reservation = get_reservation_by_id(payload.id)

    admin = get_admin_by_email(admin_login.email)

    if not reservation:
        return ApiResponse(
            success=False, message="Reservation not found.", status_code=404
        )
    if reservation.latestExecutorId is not None and reservation.latestExecutorId != admin.id if admin and admin.id else -1:
        return ApiResponse(
            success=False,
            message="Reservation has already been processed by another admin.",
            status_code=403,
        )

    if not payload.approved and not payload.reason:
        return ApiResponse(
            success=False, message="Reason is required for rejection.", status_code=400
        )
    if reservation.startTime < datetime.now(timezone.utc):
        return ApiResponse(
            success=False, message="Cannot change status of past reservations."
        )

    def check_status() -> bool:
        return reservation.status == "pending" or (
            reservation.status != "pending"
            and reservation.status != ("approved" if payload.approved else "rejected")
        )

    if not check_status():
        return ApiResponse(
            success=False, message="Invalid approval request.", status_code=400
        )

    admin = get_admin_by_email(admin_login.email)
    if not admin:
        return ApiResponse(
            success=False, message="User is not a room approver.", status_code=403
        )
    approvers = get_room_approvers_by_admin_id(
        admin.id if admin and admin.id is not None else -1
    )

    if not approvers:
        return ApiResponse(
            success=False, message="User is not a room approver.", status_code=403
        )
    authorized = all(approver.admin == admin.id for approver in approvers)

    if not authorized:
        return ApiResponse(
            success=False,
            message="User is not authorized to approve this reservation.",
            status_code=403,
        )

    change_reservation_status_by_id(
        payload.id,
        "approved" if payload.approved else "rejected",
        admin.id or -1,
        payload.reason,
    )
    classes = get_class()
    class_name = next(
        (cls.name for cls in classes if cls.id == reservation.classId), None
    )
    room = get_room_by_id(reservation.roomId)
    if payload.approved:
        background_task.add_task(
            send_reservation_approval_email,
            email_title="Reservation Approval",
            title="Your reservation has been approved!",
            email=reservation.email,
            details=f"Hi {reservation.studentName}! Your reservation for {room.name if room else None} has been approved. Below is the detailed information.",
            user=reservation.studentName,
            room=room.name if room else "",
            class_name=class_name or "",
            student_id=reservation.studentId,
            reason=reservation.reason,
            time=f"{reservation.startTime.strftime('%Y-%m-%d %H:%M')} - {reservation.endTime.strftime('%H:%M')}",
        )
    else:
        background_task.add_task(
            send_normal_update_email,
            email_title="Reservation Rejected",
            title="Your reservation has been rejected.",
            email=reservation.email,
            details=f"Hi {reservation.studentName}! Your reservation for {room.name if room else None} has been rejected. Reason: {payload.reason}",
        )
    return ApiResponse(success=True, message="Reservation updated successfully.")


@app.get(
    "/reservation/all",
    response_model=ApiResponseBody[list[ReservationFullResponse]],
)
@limiter.limit("5/second")
async def reservation_all(
    request: Request, admin_login=Depends(get_current_user)
) -> ApiResponse[list[ReservationFullResponse]]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    all_reservations = get_all_reservations()
    classes = get_class()
    res: list[ReservationFullResponse] = []
    for reservation in all_reservations:
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.roomId)
        campus = get_campus_by_id(room.campusId) if room and room.campus else None
        executor = (
            get_admin_by_id(reservation.latestExecutorId)
            if reservation.latestExecutor
            else None
        )
        res.append(
            ReservationFullResponse(
                id=reservation.id,
                startTime=reservation.startTime,
                endTime=reservation.endTime,
                studentName=reservation.studentName,
                studentId=reservation.studentId,
                email=reservation.email,
                reason=reservation.reason,
                roomName=room.name if room else None,
                className=class_name,
                status=reservation.status,
                createdAt=reservation.createdAt,
                campusName=campus.name if campus else None,
                latestExecutor=executor.email if executor else None,
            )
        )
    return ApiResponse(success=True, data=res)


@app.get("/reservation/export", response_model=None)
@limiter.limit("1/second")
async def reservation_export(
    request: Request,
    startTime: int = -1,
    endTime: int = -1,
    admin_login=Depends(get_current_user),
) -> FileResponse | ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    if startTime != -1 and endTime != -1 and startTime > endTime:
        return ApiResponse(
            success=False, message="Invalid time range.", status_code=400
        )
    reservations = get_reservations_by_time_range(
        datetime.fromtimestamp(startTime) if startTime != -1 else None,
        datetime.fromtimestamp(endTime) if endTime != -1 else None,
    )
    if not reservations:
        return ApiResponse(
            success=False, message="No reservations found.", status_code=404
        )
    workbook = get_exported_xlsx(reservations)
    export_uuid = uuid.uuid4()
    workbook.save(f"cache/reservations_{export_uuid}.xlsx")
    return FileResponse(
        path=f"cache/reservations_{export_uuid}.xlsx",
        filename=f"reservations_{export_uuid}.xlsx",
    )


@app.post(
    "/class/create",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def class_create(
    request: Request, payload: ClassCreateRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    campus = get_campus_by_id(payload.campus)
    if not campus:
        return ApiResponse(success=False, message="Invalid campus.", status_code=400)
    create_class(name=payload.name, campus=campus)
    return ApiResponse(success=True, message="Class created successfully.")


@app.post(
    "/campus/create",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def campus_create(
    request: Request,
    payload: CampusCreateRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    create_campus(name=payload.name)
    return ApiResponse(success=True, message="Campus created successfully.")


@app.post(
    "/room/create",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def room_create(
    request: Request, payload: RoomCreateRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    campus = get_campus_by_id(payload.campus)
    if not campus:
        return ApiResponse(success=False, message="Invalid campus.", status_code=400)
    create_room(name=payload.name, campus=campus)
    return ApiResponse(success=True, message="Room created successfully.")


@app.post(
    "/policy/create",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def policy_create(
    request: Request,
    payload: RoomPolicyCreateRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    if len(payload.days) != len(set(payload.days)):
        return ApiResponse(
            success=False, message="Days must be unique.", status_code=400
        )

    if not all(6 >= day >= 0 for day in payload.days) or len(payload.days) > 7:
        return ApiResponse(success=False, message="Invalid days.", status_code=400)

    if (
        not len(payload.startTime) == 2
        or not 23 >= payload.startTime[0] >= 0
        or not 59 >= payload.startTime[1] >= 0
    ):
        return ApiResponse(
            success=False, message="Invalid start times.", status_code=400
        )

    if (
        not len(payload.endTime) == 2
        or not 23 >= payload.endTime[0] >= 0
        or not 59 >= payload.endTime[1] >= 0
    ):
        return ApiResponse(success=False, message="Invalid end times.", status_code=400)
    room = get_room_by_id(payload.room)
    if not room:
        return ApiResponse(success=False, message="Room not found.", status_code=404)
    create_policy(
        room=room,
        days=sorted(payload.days),
        startTime=payload.startTime,
        endTime=payload.endTime,
    )
    return ApiResponse(success=True, message="Policy created successfully.")


@app.post(
    "/policy/delete",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def policy_delete(
    request: Request,
    payload: RoomPolicyDeleteRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    policy = get_policy_by_id(payload.id)
    if not policy:
        return ApiResponse(success=False, message="Policy not found.", status_code=404)
    delete_policy(policy)
    return ApiResponse(success=True, message="Policy deleted successfully.")


@app.post(
    "/policy/toggle",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def policy_toggle(
    request: Request,
    payload: RoomPolicyToggleRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    policy = get_policy_by_id(payload.id)
    if not policy:
        return ApiResponse(success=False, message="Policy not found.", status_code=404)
    toggle_policy(policy)
    return ApiResponse(success=True, message="Policy toggled successfully.")


@app.post(
    "/policy/edit",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def policy_edit(
    request: Request,
    payload: RoomPolicyEditRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    policy = get_policy_by_id(payload.id)
    if not policy:
        return ApiResponse(success=False, message="Policy not found.", status_code=404)

    if not all(6 >= day >= 0 for day in payload.days) or len(payload.days) > 7:
        return ApiResponse(success=False, message="Invalid days.", status_code=400)

    if (
        not len(payload.startTime) == 2
        or not 23 >= payload.startTime[0] >= 0
        or not 59 >= payload.startTime[1] >= 0
    ):
        return ApiResponse(
            success=False, message="Invalid start times.", status_code=400
        )

    if (
        not len(payload.endTime) == 2
        or not 23 >= payload.endTime[0] >= 0
        or not 59 >= payload.endTime[1] >= 0
    ):
        return ApiResponse(success=False, message="Invalid end times.", status_code=400)

    if (
        policy.days == payload.days
        and policy.startTime == payload.startTime
        and policy.endTime == payload.endTime
    ):
        return ApiResponse(success=True, message="No changes detected.")
    policy.days = payload.days
    policy.startTime = payload.startTime
    policy.endTime = payload.endTime
    edit_policy(policy=policy)
    return ApiResponse(success=True, message="Policy edited successfully.")


@app.post(
    "/room/edit",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def room_edit(
    request: Request, payload: RoomEditRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    room = get_room_by_id(payload.id)
    if not room:
        return ApiResponse(success=False, message="Room not found.", status_code=404)

    room.name = payload.name
    room.campusId = payload.campus
    room.enabled = payload.enabled
    edit_room(room)
    return ApiResponse(success=True, message="Room edited successfully.")


@app.post(
    "/campus/edit",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def campus_edit(
    request: Request, payload: CampusEditRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    campus = get_campus_by_id(payload.id)
    if not campus:
        return ApiResponse(success=False, message="Campus not found.", status_code=404)

    campus.name = payload.name
    edit_campus(campus)
    return ApiResponse(success=True, message="Campus edited successfully.")


@app.post(
    "/class/edit",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def class_edit(
    request: Request, payload: ClassEditRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    class_ = get_class_by_id(payload.id)
    if not class_:
        return ApiResponse(success=False, message="Class not found.", status_code=404)

    class_.name = payload.name
    class_.campusId = payload.campus
    edit_class(class_)
    return ApiResponse(success=True, message="Class edited successfully.")


@app.post(
    "/approver/create",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def approver_create(
    request: Request,
    payload: RoomApproverCreateRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    room = get_room_by_id(payload.room)
    admin = get_admin_by_id(payload.admin)
    if not room:
        return ApiResponse(success=False, message="Room not found.", status_code=404)

    if not admin:
        return ApiResponse(success=False, message="Admin not found.", status_code=404)

    approvers = get_room_approvers_by_room_id(payload.room)

    if approvers and any(approver.admin == payload.admin for approver in approvers):
        return ApiResponse(
            success=False,
            message="Admin is already an approver for this room.",
            status_code=409,
        )

    create_room_approver(room=room, admin=admin)
    return ApiResponse(success=True, message="Room approver created successfully.")


@app.post(
    "/approver/delete",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def approver_delete(
    request: Request,
    payload: RoomApproverDeleteRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    approver = get_room_approver_by_id(payload.id)
    if not approver:
        return ApiResponse(
            success=False, message="Approver not found.", status_code=404
        )

    delete_room_approver(approver=approver)
    return ApiResponse(success=True, message="Room approver deleted successfully.")


@app.get(
    "/admin/list",
    response_model=ApiResponseBody[list[AdminResponse]],
)
@limiter.limit("5/second")
async def admin_list(
    request: Request, admin_login=Depends(get_current_user)
) -> ApiResponse[list[AdminResponse]]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )

    admins = get_admins()
    res = [
        AdminResponse(
            id=admin.id,
            name=admin.name,
            email=admin.email,
            createdAt=admin.createdAt,
        )
        for admin in admins
    ]
    return ApiResponse(success=True, data=res)


@app.post(
    "/admin/create",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_create(
    request: Request, payload: AdminCreateRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    if get_admin_by_email(payload.email):
        return ApiResponse(
            success=False, message="Admin already exists.", status_code=409
        )
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", payload.email):
        return ApiResponse(
            success=False, message="Invalid email format.", status_code=400
        )
    if len(payload.password) < 6:
        return ApiResponse(
            success=False,
            message="Password must be at least 6 characters.",
            status_code=400,
        )
    create_admin(
        name=payload.name, email=payload.email, password=password_hash(payload.password)
    )
    return ApiResponse(success=True, message="Admin created successfully.")


@app.post(
    "/admin/edit-password",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_edit_password(
    request: Request,
    payload: AdminEditPasswordRequest,
    admin_login=Depends(get_current_user),
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    admin = get_admin_by_id(payload.admin)
    if not admin:
        return ApiResponse(success=False, message="Admin not found.", status_code=404)
    change_admin_password(payload.admin, password_hash(payload.newPassword))
    return ApiResponse(success=True, message="Password changed successfully.")


@app.post(
    "/admin/edit",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_edit(
    request: Request, payload: AdminEditRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    admin = get_admin_by_id(payload.id)
    if not admin:
        return ApiResponse(success=False, message="Admin not found.", status_code=404)
    if admin.email != payload.email and get_admin_by_email(payload.email):
        return ApiResponse(
            success=False, message="Email already in use.", status_code=409
        )
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", payload.email):
        return ApiResponse(
            success=False, message="Invalid email format.", status_code=400
        )
    if admin.name == payload.name and admin.email == payload.email:
        return ApiResponse(success=True, message="No changes detected.")
    admin.name = payload.name
    admin.email = payload.email
    edit_admin(admin)
    return ApiResponse(success=True, message="Admin edited successfully.")


@app.post(
    "/admin/delete",
    response_model=ApiResponseBody[Any],
)
@limiter.limit("5/second")
async def admin_delete(
    request: Request, payload: AdminDeleteRequest, admin_login=Depends(get_current_user)
) -> ApiResponse[Any]:
    if not admin_login:
        return ApiResponse(
            success=False, message="User is not logged in.", status_code=401
        )
    admin = get_admin_by_id(payload.id)
    if not admin:
        return ApiResponse(success=False, message="Admin not found.", status_code=404)
    delete_admin(admin)
    return ApiResponse(success=True, message="Admin deleted successfully.")


@app.get(
    "/analytics/overview",
    response_model=ApiResponseBody[AnalyticsOverviewResponse],
)
@limiter.limit("1/second")
async def analytics_overview(
    request: Request,
) -> ApiResponse[AnalyticsOverviewResponse]:
    daily_reservations: list[int] = []
    daily_reservation_creations: list[int] = []
    daily_requests: list[int] = []
    daily_approvals: list[int] = []
    daily_rejections: list[int] = []

    weekly_reservations: list[int] = []
    weekly_reservation_creations: list[int] = []
    weekly_approvals: list[int] = []
    weekly_rejections: list[int] = []

    monthly_reservations: list[int] = []
    monthly_reservation_creations: list[int] = []
    monthly_approvals: list[int] = []
    monthly_rejections: list[int] = []
    now = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    start = now - timedelta(days=365)
    analytics = get_analytics_between(start, now)
    analytics_by_date: dict[Any, Analytic] = {a.date.date(): a for a in analytics}
    for i in range(30):
        d = (now - timedelta(days=29 - i)).date()
        a = analytics_by_date.get(d)
        if a:
            daily_reservations.append(a.reservations or 0)
            daily_reservation_creations.append(a.reservationCreations or 0)
            daily_requests.append(a.requests or 0)
            daily_approvals.append(a.approvals or 0)
            daily_rejections.append(a.rejections or 0)
        else:
            daily_reservations.append(0)
            daily_reservation_creations.append(0)
            daily_requests.append(0)
            daily_approvals.append(0)
            daily_rejections.append(0)
    for i in range(7):
        d = (now - timedelta(days=6 - i)).date()
        a = analytics_by_date.get(d)
        if a:
            weekly_reservations.append(a.reservations or 0)
            weekly_reservation_creations.append(a.reservationCreations or 0)
            weekly_approvals.append(a.approvals or 0)
            weekly_rejections.append(a.rejections or 0)
        else:
            weekly_reservations.append(0)
            weekly_reservation_creations.append(0)
            weekly_approvals.append(0)
            weekly_rejections.append(0)
    monthly_map: dict[tuple[int, int], list[int]] = {}
    for a in analytics:
        key = (a.date.year, a.date.month)
        if key not in monthly_map:
            monthly_map[key] = [0, 0, 0, 0, 0]
        monthly_map[key][0] += a.reservations or 0
        monthly_map[key][1] += a.reservationCreations or 0
        monthly_map[key][2] += a.requests or 0
        monthly_map[key][3] += a.approvals or 0
        monthly_map[key][4] += a.rejections or 0

    current_year = now.year
    current_month = now.month
    for i in range(12):
        months_ago = 11 - i
        month_no = current_month - months_ago
        year = current_year
        while month_no <= 0:
            month_no += 12
            year -= 1
        v = monthly_map.get((year, month_no), [0, 0, 0, 0, 0])
        monthly_reservations.append(v[0])
        monthly_reservation_creations.append(v[1])
        monthly_approvals.append(v[3])
        monthly_rejections.append(v[4])

    data = AnalyticsOverviewResponse(
        daily=AnalyticsOverviewDailyDetail(
            reservations=daily_reservations,
            reservationCreations=daily_reservation_creations,
            requests=daily_requests,
            approvals=daily_approvals,
            rejections=daily_rejections,
        ),
        weekly=AnalyticsOverviewWeeklyDetail(
            reservations=weekly_reservations,
            reservationCreations=weekly_reservation_creations,
            approvals=weekly_approvals,
            rejections=weekly_rejections,
        ),
        monthly=AnalyticsOverviewMonthlyDetail(
            reservations=monthly_reservations,
            reservationCreations=monthly_reservation_creations,
            approvals=monthly_approvals,
            rejections=monthly_rejections,
        ),
        today=AnalyticsOverviewTodayDetail(
            reservations=daily_reservations[-1] if daily_reservations else 0,
            reservationCreations=(
                daily_reservation_creations[-1] if daily_reservation_creations else 0
            ),
            requests=daily_requests[-1] if daily_requests else 0,
            approvals=daily_approvals[-1] if daily_approvals else 0,
            rejections=daily_rejections[-1] if daily_rejections else 0,
        ),
    )
    return ApiResponse(success=True, data=data)


@app.get("/analytics/weekly", response_model=ApiResponseBody[AnalyticsWeeklyResponse])
@limiter.limit("1/second")
async def analytics_weekly(
    request: Request,
) -> ApiResponse[AnalyticsWeeklyResponse]:
    def is_meaningful(token: str) -> bool:
        token = token.strip()
        if not token:
            return False
        cats = {unicodedata.category(ch) for ch in token}
        if all(c[0] in {"P", "Z", "S"} for c in cats):
            return False
        return True

    now = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    start = now - timedelta(days=now.weekday() + 7)
    end = start + timedelta(days=6, hours=23, minutes=59, seconds=59)

    if cached := get_cache_by_key(f"analytics-weekly-{start.date()}"):
        return ApiResponse(
            success=True,
            data=AnalyticsWeeklyResponse.model_validate(cached.value),
        )

    analytics = get_analytics_between(start, end)
    analytics_by_date: dict[Any, Analytic] = {a.date.date(): a for a in analytics}
    total_reservations = 0
    total_reservation_creations = 0
    total_approvals = 0
    total_rejections = 0
    total_approvals = 0
    reasons: dict[str, int] = {}
    rooms: list[AnalyticsWeeklyRoomDetail] = []
    daily_reservations = [0] * 7
    daily_reservation_creations = [0] * 7
    for i in range(7):
        analytic_for_day = analytics_by_date.get((start + timedelta(days=i)).date())
        if analytic_for_day:
            total_reservation_creations += analytic_for_day.reservationCreations or 0
            total_reservations += analytic_for_day.reservations or 0
            total_approvals += analytic_for_day.approvals or 0
            total_rejections += analytic_for_day.rejections or 0
            total_approvals += analytic_for_day.approvals or 0
            daily_reservations[i] = analytic_for_day.reservations or 0
            daily_reservation_creations[i] = analytic_for_day.reservationCreations or 0
    all_rooms = get_room()
    hourly_reservations = [0] * 24

    for room in all_rooms:
        room_reservations = 0
        room_reservation_creations = 0
        _reservations = room.reservations
        for reservation in _reservations:
            for i in range(7):
                day = (start + timedelta(days=i)).date()
                if reservation.startTime.date() == day:
                    room_reservations += 1
                    if reservation.status == "approved":
                        i = (reservation.startTime.astimezone(timezone.utc).hour)
                        while i != (reservation.endTime.astimezone(timezone.utc).hour):
                            hourly_reservations[i] += 1
                            i = (i + 1) % 24
                if reservation.createdAt.date() == day:
                    room_reservation_creations += 1
            words = jieba.cut(reservation.reason)
            for word in words:
                if not is_meaningful(word):
                    continue
                reasons[word] = reasons.get(word, 0) + 1

        rooms.append(
            AnalyticsWeeklyRoomDetail(
                roomName=room.name,
                reservationCreations=room_reservation_creations,
                reservations=room_reservations,
            )
        )
    data = AnalyticsWeeklyResponse(
        totalReservations=total_reservations,
        totalReservationCreations=total_reservation_creations,
        totalApprovals=total_approvals,
        totalRejections=total_rejections,
        rooms=sorted(
            rooms,
            key=lambda r: (r.reservations, r.reservationCreations),
            reverse=True,
        )[:5],
        reasons=[
            AnalyticsReasonDetail(word=word, count=count)
            for word, count in sorted(
                reasons.items(), key=lambda item: item[1], reverse=True
            )[:150]
        ],
        hourlyReservations=hourly_reservations,
        dailyReservations=daily_reservations,
        dailyReservationCreations=daily_reservation_creations,
    )
    cache = Cache(key=f"analytics-weekly-{start.date()}", value=data.model_dump())
    create_cache(cache)
    return ApiResponse(success=True, data=data)


@app.get("/analytics/overview/export", response_model=None)
@limiter.limit("1/second")
async def analytics_overview_export(
    request: Request,
    type: str,
    turnstileToken: str,
) -> FileResponse | ApiResponse[Any]:
    if not verify_turnstile_token(turnstileToken):
        return ApiResponse(
            success=False, message="Turnstile verification failed.", status_code=403
        )
    export_uuid = uuid.uuid4()
    if type == "pdf":
        await get_exported_pdf(
            f"{frontend_url}/reservation/analytics/raw/overview",
            f"cache/overview_{export_uuid}.pdf",
        )
        return FileResponse(
            f"cache/overview_{export_uuid}.pdf",
            media_type="application/pdf",
            filename=f"overview_{export_uuid}.pdf",
        )
    elif type == "png":
        await get_screenshot(
            f"{frontend_url}/reservation/analytics/raw/overview",
            f"cache/overview_{export_uuid}.png",
        )
        return FileResponse(
            f"cache/overview_{export_uuid}.png",
            media_type="image/png",
            filename=f"overview_{export_uuid}.png",
        )
    return ApiResponse(success=False, message="Invalid export type.", status_code=400)

@app.get("/analytics/weekly/export", response_model=None)
@limiter.limit("1/second")
async def analytics_weekly_export(
    request: Request,
    type: str,
    turnstileToken: str,
) -> FileResponse | ApiResponse[Any]:
    if not verify_turnstile_token(turnstileToken):
        return ApiResponse(
            success=False, message="Turnstile verification failed.", status_code=403
        )
    export_uuid = uuid.uuid4()
    start = (datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0) - timedelta(days=datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0).weekday() + 7)).date()
    if type == "pdf":
        if cached := get_cache_by_key(f"analytics-weekly-export-pdf-{start}"):
            return FileResponse(
                path=f"cache/weekly_{cached.value['exportUuid']}.pdf",
                media_type="application/pdf",
                filename=f"weekly_{cached.value['exportUuid']}.pdf",
            )
        await get_exported_pdf(
            f"{frontend_url}/reservation/analytics/raw/weekly",
            f"cache/weekly_{export_uuid}.pdf",
        )
        create_cache(Cache(key=f"analytics-weekly-export-pdf-{start}", value={"exportUuid": str(export_uuid)}))
        return FileResponse(
            f"cache/weekly_{export_uuid}.pdf",
            media_type="application/pdf",
            filename=f"weekly_{export_uuid}.pdf",
        )
    elif type == "png":
        if cached := get_cache_by_key(f"analytics-weekly-export-png-{start}"):
            return FileResponse(
                path=f"cache/weekly_{cached.value['exportUuid']}.png",
                media_type="image/png",
                filename=f"weekly_{cached.value['exportUuid']}.png",
            )
        await get_screenshot(
            f"{frontend_url}/reservation/analytics/raw/weekly",
            f"cache/weekly_{export_uuid}.png",
        )
        create_cache(Cache(key=f"analytics-weekly-export-png-{start}", value={"exportUuid": str(export_uuid)}))
        return FileResponse(
            f"cache/weekly_{export_uuid}.png",
            media_type="image/png",
            filename=f"weekly_{export_uuid}.png",
        )
    return ApiResponse(success=False, message="Invalid export type.", status_code=400)