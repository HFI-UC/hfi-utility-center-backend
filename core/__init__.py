from logging import log
from fastapi import Depends, FastAPI, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.types import Receive, Scope, Send, Message
from fastapi.requests import Request
from fastapi.responses import JSONResponse, StreamingResponse
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
from io import BytesIO

import uuid
import psutil
import hashlib
import random
import bcrypt
import re


@asynccontextmanager
async def lifespan(_: FastAPI):
    create_db_and_tables()
    scheduler.start()
    yield


app = FastAPI(lifespan=lifespan)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173", "https://www.hfiuc.org", "https://preview.hfiuc.org"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)
limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)  # type: ignore


class LogMiddleware(BaseHTTPMiddleware):
    def __init__(self, app: ASGIApp):
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)

        req_body = bytearray()
        async def recv_wrapper() -> Message:
            message = await receive()
            if message["type"] == "http.request":
                req_body.extend(message.get("body", b""))
            return message
        status_code = 500
        resp_chunks = []
        _uuid = str(uuid.uuid4())
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
        finally:
            headers = dict((k.lower(), v) for k, v in scope.get("headers", []))
            ua = headers.get(b"user-agent", b"").decode("latin-1")
            client = scope.get("client") or ("", 0)
            try:
                payload = req_body.decode("utf-8")
            except UnicodeDecodeError:
                payload = None
            log = AccessLog(
                userAgent=ua,
                uuid=_uuid,
                ip=client[0],
                port=client[1],
                url=scope.get("path", ""),
                method=scope.get("method", ""),
                status=status_code,
                payload=payload,
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


@app.get("/room/list")
@limiter.limit("5/second")
async def room_list(request: Request) -> BasicResponse:
    data = get_room()
    return BasicResponse(success=True, data=data)


@app.get("/campus/list")
@limiter.limit("5/second")
async def campus_list(request: Request) -> BasicResponse:
    data = get_campus()
    return BasicResponse(success=True, data=data)


@app.get("/class/list")
@limiter.limit("5/second")
async def class_list(request: Request) -> BasicResponse:
    data = get_class()
    return BasicResponse(success=True, data=data)


@app.post("/campus/delete")
@limiter.limit("5/second")
async def campus_delete(
    request: Request, payload: CampusDeleteRequest
) -> BasicResponse:
    campus = get_campus_by_id(payload.id)
    if not campus:
        return BasicResponse(success=False, message="Campus not found.")
    delete_campus(campus)
    return BasicResponse(success=True, message="Campus deleted successfully.")


@app.post("/room/delete")
@limiter.limit("5/second")
async def room_delete(request: Request, payload: RoomDeleteRequest) -> BasicResponse:
    room = get_room_by_id(payload.id)
    if not room:
        return BasicResponse(success=False, message="Room not found.")
    delete_room(room)
    return BasicResponse(success=True, message="Room deleted successfully.")


@app.post("/class/delete")
@limiter.limit("5/second")
async def class_delete(request: Request, payload: ClassDeleteRequest) -> BasicResponse:
    _class = get_class_by_id(payload.id)
    if not _class:
        return BasicResponse(success=False, message="Class not found.")
    delete_class(_class)
    return BasicResponse(success=True, message="Class deleted successfully.")


@app.post("/reservation/create")
@limiter.limit("5/second")
async def reservation_create(
    request: Request,
    payload: ReservationCreateRequest,
    background_task: BackgroundTasks,
) -> BasicResponse:
    if not verify_turnstile_token(payload.turnstileToken):
        return BasicResponse(success=False, message="Turnstile verification failed.")
    reservations = get_reservation_by_room_id(payload.room)
    room = get_room_by_id(payload.room)
    errors = []
    _class = get_class_by_id(payload.classId)
    if not room:
        errors.append("Room not found.")
    if not _class:
        errors.append("Class not found.")
    if errors:
        return BasicResponse(success=False, message="\n".join(errors))

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
            policies = get_policy_by_room_id(room.id or -1)
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

    if not validate_email_format(payload.email):
        errors.append("Invalid email format.")
    if payload.startTime >= payload.endTime:
        errors.append("Start time must be before end time.")
    if payload.endTime - payload.startTime > 2 * 3600:
        errors.append("Reservation duration must not exceed 2 hours.")
    if payload.startTime < datetime.today().timestamp():
        errors.append("Start time must be in the future.")
    admin = get_admin_by_email(payload.email)
    if not admin:
        if not validate_policy(payload.startTime) or not validate_policy(
            payload.endTime
        ):
            errors.append("Start or end time violates room policy.")
        if not validate_time_conflict(
            datetime.fromtimestamp(payload.startTime, timezone.utc)
        ) or not validate_time_conflict(datetime.fromtimestamp(payload.endTime, timezone.utc)):
            errors.append("Start or end time conflicts with existing reservation.")
    if errors:
        return BasicResponse(success=False, message="\n".join(errors))

    approvers = get_room_approvers_by_room_id(room.id if room and room.id else -1)
    if not approvers:
        return BasicResponse(success=False, message="No approvers found.")

    result = create_reservation(payload)
    if not result:
        return BasicResponse(success=False, message="Failed to create reservation.")

    background_task.add_task(
        send_normal_update_email,
        email_title="Reservation Created",
        title=f"Hi {payload.studentName}! Your reservation has been created.",
        email=payload.email,
        details=f"Your reservation for room {room.name if room else "Unknown"} for the time period <b>{datetime.fromtimestamp(payload.startTime).strftime('%Y-%m-%d %H:%M')} - {datetime.fromtimestamp(payload.endTime).strftime('%H:%M')}</b> has been created and is currently pending approval.",
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
        return BasicResponse(
            success=True, message="Your reservation has been created and approved."
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
    return BasicResponse(success=True, message=f"Your reservation has been created.")


@app.post("/reservation/get")
@limiter.limit("5/second")
async def reservation_get(
    request: Request, payload: ReservationGetRequest
) -> BasicResponse:
    if payload.room and not get_room_by_id(payload.room):
        return BasicResponse(success=False, message="Room not found.")
    reservations = get_reservation(payload.keyword, payload.room, payload.status)
    classes = get_class()
    res = []
    for reservation in reservations:
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.room)
        res.append(
            {
                "id": reservation.id,
                "startTime": reservation.startTime,
                "endTime": reservation.endTime,
                "studentName": reservation.studentName,
                "email": reservation.email,
                "reason": reservation.reason,
                "className": class_name,
                "roomName": room.name if room else None,
                "status": reservation.status,
            }
        )
    return BasicResponse(success=True, data=res)


@app.post("/admin/login")
@limiter.limit("5/second")
async def admin_login(
    request: Request, payload: AdminLoginRequest, admin_login=Depends(get_current_user)
) -> JSONResponse:
    if admin_login:
        return JSONResponse(
            BasicResponse(success=False, message="User already logged in.").model_dump()
        )

    if payload.token:
        temp_admin_login = get_temp_admin_login_by_token(payload.token)
        if not temp_admin_login:
            return JSONResponse(
                BasicResponse(
                    success=False, message="Invalid token or token expired."
                ).model_dump()
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
        response = JSONResponse(
            BasicResponse(success=True, message="Login successful.").model_dump()
        )
        response.set_cookie("UCCOOKIE", cookie)
        return response
    if not payload.email or not payload.password:
        return JSONResponse(
            BasicResponse(
                success=False, message="Email and password are required."
            ).model_dump()
        )
    if not payload.turnstileToken or not verify_turnstile_token(payload.turnstileToken):
        return JSONResponse(
            BasicResponse(success=False, message="Turnstile verification failed.").model_dump()
        )
    admin = get_admin_by_email(payload.email)
    if not admin or not verify_password(payload.password, admin.password):
        return JSONResponse(
            BasicResponse(
                success=False, message="Invalid email or password."
            ).model_dump()
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
    response = JSONResponse(
        BasicResponse(success=True, message="Login successful.").model_dump()
    )
    response.set_cookie("UCCOOKIE", cookie, httponly=True)
    return response


@app.get("/admin/logout")
@limiter.limit("5/second")
async def admin_logout(
    request: Request, user_login=Depends(get_current_user)
) -> JSONResponse:
    if not user_login:
        return JSONResponse(
            BasicResponse(success=False, message="User is not logged in.").model_dump()
        )
    response = JSONResponse(
        BasicResponse(success=True, message="Logout successful.").model_dump()
    )
    response.delete_cookie("UCCOOKIE")
    return response


@app.get("/admin/check-login")
@limiter.limit("5/second")
async def admin_check_login(
    request: Request, user_login=Depends(get_current_user)
) -> BasicResponse:
    if user_login:
        return BasicResponse(success=True)
    return BasicResponse(success=False)


@app.get("/reservation/future")
@limiter.limit("5/second")
async def reservation_future(
    request: Request, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    admin = get_admin_by_email(admin_login.email)
    future_reservations = get_future_reservations_by_approver_id(
        admin.id if admin and admin.id is not None else -1
    )
    classes = get_class()
    res = []
    for reservation in future_reservations:
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.room)
        campus = get_campus_by_id(room.campus) if room and room.campus else None
        res.append(
            {
                "id": reservation.id,
                "startTime": reservation.startTime,
                "endTime": reservation.endTime,
                "studentName": reservation.studentName,
                "email": reservation.email,
                "reason": reservation.reason,
                "roomName": room.name if room else None,
                "className": class_name,
                "studentId": reservation.studentId,
                "status": reservation.status,
                "createdAt": int(reservation.createdAt.timestamp()),
                "campusName": campus.name if campus else None,
            }
        )
    return BasicResponse(success=True, data=res)


@app.post("/reservation/approval")
@limiter.limit("5/second")
async def reservation_approval(
    request: Request,
    payload: ReservationApproveRequest,
    background_task: BackgroundTasks,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    reservation = get_reservation_by_id(payload.id)

    if not reservation:
        return BasicResponse(success=False, message="Reservation not found.")
    if not payload.approved and not payload.reason:
        return BasicResponse(success=False, message="Reason is required for rejection.")
    if reservation.startTime < datetime.now(timezone.utc):
        return BasicResponse(
            success=False, message="Cannot change status of past reservations."
        )

    def check_status() -> bool:
        return reservation.status == "pending" or (
            reservation.status != "pending"
            and reservation.status != ("approved" if payload.approved else "rejected")
        )

    if not check_status():
        return BasicResponse(success=False, message="Invalid approval request.")

    admin = get_admin_by_email(admin_login.email)
    if not admin:
        return BasicResponse(success=False, message="User is not a room approver.")
    approvers = get_room_approvers_by_admin_id(
        admin.id if admin and admin.id is not None else -1
    )

    if not approvers:
        return BasicResponse(success=False, message="User is not a room approver.")
    authorized = all(approver.admin == admin.id for approver in approvers)

    if not authorized:
        return BasicResponse(
            success=False, message="User is not authorized to approve this reservation."
        )

    if change_reservation_status_by_id(
        payload.id, "approved" if payload.approved else "rejected", admin.id or -1, payload.reason
    ):
        classes = get_class()
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.room)
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
        return BasicResponse(success=True, message="Reservation updated successfully.")
    return BasicResponse(success=False, message="Failed to update reservation.")


@app.get("/reservation/all")
@limiter.limit("5/second")
async def reservation_all(
    request: Request, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    all_reservations = get_all_reservations()
    classes = get_class()
    res = []
    for reservation in all_reservations:
        class_name = next(
            (cls.name for cls in classes if cls.id == reservation.classId), None
        )
        room = get_room_by_id(reservation.room)
        campus = get_campus_by_id(room.campus) if room and room.campus else None
        executor = (
            get_admin_by_id(reservation.latestExecutor)
            if reservation.latestExecutor
            else None
        )
        res.append(
            {
                "id": reservation.id,
                "startTime": reservation.startTime,
                "endTime": reservation.endTime,
                "studentName": reservation.studentName,
                "studentId": reservation.studentId,
                "email": reservation.email,
                "reason": reservation.reason,
                "roomName": room.name if room else None,
                "className": class_name,
                "status": reservation.status,
                "createdAt": reservation.createdAt,
                "campusName": campus.name if campus else None,
                "latestExecutor": executor.email if executor else None,
            }
        )
    return BasicResponse(success=True, data=res)


@app.post("/reservation/export", response_model=None)
@limiter.limit("1/second")
async def reservation_export(
    request: Request,
    payload: ReservationExportRequest,
    admin_login=Depends(get_current_user),
) -> StreamingResponse | JSONResponse:
    if not admin_login:
        return JSONResponse(
            status_code=200,
            content={"success": False, "message": "User is not logged in."},
        )
    if payload.startTime and payload.endTime and payload.startTime > payload.endTime:
        return JSONResponse(
            status_code=200,
            content={"success": False, "message": "Invalid time range."},
        )
    reservations = get_reservations_by_time_range(
        datetime.fromtimestamp(payload.startTime) if payload.startTime else None,
        datetime.fromtimestamp(payload.endTime) if payload.endTime else None,
    )
    if not reservations:
        return JSONResponse(
            status_code=200,
            content={"success": False, "message": "No reservations found."},
        )
    workbook = get_exported_xlsx(reservations)

    output = BytesIO()
    workbook.save(output)
    output.seek(0)
    return StreamingResponse(
        output,
        headers={
            "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "Content-Disposition": "attachment; filename=reservations.xlsx",
        },
    )


@app.post("/class/create")
@limiter.limit("5/second")
async def class_create(
    request: Request, payload: ClassCreateRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    campus = get_campus_by_id(payload.campus)
    if not campus:
        return BasicResponse(success=False, message="Invalid campus.")
    success = create_class(name=payload.name, campus=payload.campus)
    if success:
        return BasicResponse(success=True, message="Class created successfully.")
    return BasicResponse(success=False, message="Failed to create class.")


@app.post("/campus/create")
@limiter.limit("5/second")
async def campus_create(
    request: Request,
    payload: CampusCreateRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    success = create_campus(name=payload.name)
    if success:
        return BasicResponse(success=True, message="Campus created successfully.")
    return BasicResponse(success=False, message="Failed to create campus.")


@app.post("/room/create")
@limiter.limit("5/second")
async def room_create(
    request: Request, payload: RoomCreateRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    campus = get_campus_by_id(payload.campus)
    if not campus:
        return BasicResponse(success=False, message="Invalid campus.")
    success = create_room(name=payload.name, campus=payload.campus)
    if success:
        return BasicResponse(success=True, message="Room created successfully.")
    return BasicResponse(success=False, message="Failed to create room.")


@app.get("/policy/list")
@limiter.limit("5/second")
async def policy_list(request: Request) -> BasicResponse:
    policies = get_policy()
    return BasicResponse(success=True, data=policies)


@app.post("/policy/create")
@limiter.limit("5/second")
async def policy_create(
    request: Request,
    payload: RoomPolicyCreateRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    if not all(6 >= day >= 0 for day in payload.days) or len(payload.days) > 7:
        return BasicResponse(success=False, message="Invalid days.")

    if (
        not len(payload.startTime) == 2
        or not 23 >= payload.startTime[0] >= 0
        or not 59 >= payload.startTime[1] >= 0
    ):
        return BasicResponse(success=False, message="Invalid start times.")

    if (
        not len(payload.endTime) == 2
        or not 23 >= payload.endTime[0] >= 0
        or not 59 >= payload.endTime[1] >= 0
    ):
        return BasicResponse(success=False, message="Invalid end times.")

    success = create_policy(
        room=payload.room,
        days=sorted(payload.days),
        startTime=payload.startTime,
        endTime=payload.endTime,
    )
    if success:
        return BasicResponse(success=True, message="Policy created successfully.")
    return BasicResponse(success=False, message="Failed to create policy.")


@app.post("/policy/delete")
@limiter.limit("5/second")
async def policy_delete(
    request: Request,
    payload: RoomPolicyDeleteRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    policy = get_policy_by_id(payload.id)
    if not policy:
        return BasicResponse(success=False, message="Policy not found.")
    success = delete_policy(policy)
    if success:
        return BasicResponse(success=True, message="Policy deleted successfully.")
    return BasicResponse(success=False, message="Failed to delete policy.")


@app.post("/policy/toggle")
@limiter.limit("5/second")
async def policy_toggle(
    request: Request,
    payload: RoomPolicyToggleRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    policy = get_policy_by_id(payload.id)
    if not policy:
        return BasicResponse(success=False, message="Policy not found.")
    success = toggle_policy(policy)
    if success:
        return BasicResponse(success=True, message="Policy toggled successfully.")
    return BasicResponse(success=False, message="Failed to toggle policy.")


@app.post("/policy/edit")
@limiter.limit("5/second")
async def policy_edit(
    request: Request,
    payload: RoomPolicyEditRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    policy = get_policy_by_id(payload.id)
    if not policy:
        return BasicResponse(success=False, message="Policy not found.")

    if not all(6 >= day >= 0 for day in payload.days) or len(payload.days) > 7:
        return BasicResponse(success=False, message="Invalid days.")

    if (
        not len(payload.startTime) == 2
        or not 23 >= payload.startTime[0] >= 0
        or not 59 >= payload.startTime[1] >= 0
    ):
        return BasicResponse(success=False, message="Invalid start times.")

    if (
        not len(payload.endTime) == 2
        or not 23 >= payload.endTime[0] >= 0
        or not 59 >= payload.endTime[1] >= 0
    ):
        return BasicResponse(success=False, message="Invalid end times.")

    if (
        policy.days == payload.days
        and policy.startTime == payload.startTime
        and policy.endTime == payload.endTime
    ):
        return BasicResponse(success=True, message="No changes detected.")
    policy.days = payload.days
    policy.startTime = payload.startTime
    policy.endTime = payload.endTime
    success = edit_policy(policy=policy)
    if success:
        return BasicResponse(success=True, message="Policy edited successfully.")
    return BasicResponse(success=False, message="Failed to edit policy.")


@app.post("/room/edit")
@limiter.limit("5/second")
async def room_edit(
    request: Request, payload: RoomEditRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    room = get_room_by_id(payload.id)
    if not room:
        return BasicResponse(success=False, message="Room not found.")

    room.name = payload.name
    room.campus = payload.campus
    success = edit_room(room)
    if success:
        return BasicResponse(success=True, message="Room edited successfully.")
    return BasicResponse(success=False, message="Failed to edit room.")


@app.post("/campus/edit")
@limiter.limit("5/second")
async def campus_edit(
    request: Request, payload: CampusEditRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    campus = get_campus_by_id(payload.id)
    if not campus:
        return BasicResponse(success=False, message="Campus not found.")

    campus.name = payload.name
    success = edit_campus(campus)
    if success:
        return BasicResponse(success=True, message="Campus edited successfully.")
    return BasicResponse(success=False, message="Failed to edit campus.")


@app.post("/class/edit")
@limiter.limit("5/second")
async def class_edit(
    request: Request, payload: ClassEditRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    _class = get_class_by_id(payload.id)
    if not _class:
        return BasicResponse(success=False, message="Class not found.")

    _class.name = payload.name
    success = edit_class(_class)
    if success:
        return BasicResponse(success=True, message="Class edited successfully.")
    return BasicResponse(success=False, message="Failed to edit class.")


@app.post("/approver/create")
@limiter.limit("5/second")
async def approver_create(
    request: Request,
    payload: RoomApproverCreateRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    if not get_room_by_id(payload.room):
        return BasicResponse(success=False, message="Room not found.")

    if not get_admin_by_id(payload.admin):
        return BasicResponse(success=False, message="Admin not found.")

    approvers = get_room_approvers_by_room_id(payload.room)

    if approvers and any(approver.admin == payload.admin for approver in approvers):
        return BasicResponse(
            success=False, message="Admin is already an approver for this room."
        )

    success = create_room_approver(room_id=payload.room, admin_id=payload.admin)
    if success:
        return BasicResponse(
            success=True, message="Room approver created successfully."
        )
    return BasicResponse(success=False, message="Failed to create room approver.")


@app.get("/approver/list")
@limiter.limit("5/second")
async def approver_list(
    request: Request, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    approvers = get_room_approvers()
    return BasicResponse(success=True, data=approvers)


@app.post("/approver/delete")
@limiter.limit("5/second")
async def approver_delete(
    request: Request,
    payload: RoomApproverDeleteRequest,
    admin_login=Depends(get_current_user),
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    approver = get_room_approver_by_id(payload.id)
    if not approver:
        return BasicResponse(success=False, message="Approver not found.")

    success = delete_room_approver(approver=approver)
    if success:
        return BasicResponse(
            success=True, message="Room approver deleted successfully."
        )
    return BasicResponse(success=False, message="Failed to delete room approver.")


@app.get("/admin/list")
@limiter.limit("5/second")
async def admin_list(
    request: Request, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    admins = get_admins()
    res = []
    for admin in admins:
        res.append(
            {
                "id": admin.id,
                "name": admin.name,
                "email": admin.email,
                "createdAt": admin.createdAt,
            }
        )
    return BasicResponse(success=True, data=res)


@app.post("/admin/create")
@limiter.limit("5/second")
async def admin_create(
    request: Request, payload: AdminCreateRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    if get_admin_by_email(payload.email):
        return BasicResponse(success=False, message="Admin already exists.")
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", payload.email):
        return BasicResponse(success=False, message="Invalid email format.")
    if len(payload.password) < 6:
        return BasicResponse(success=False, message="Password must be at least 6 characters.")
    if create_admin(
        name=payload.name, email=payload.email, password=password_hash(payload.password)
    ):
        return BasicResponse(success=True, message="Admin created successfully.")
    return BasicResponse(success=False, message="Failed to create admin.")

@app.post("/admin/edit-password")
@limiter.limit("5/second")
async def admin_edit_password(request: Request, payload: AdminEditPasswordRequest, admin_login=Depends(get_current_user)):
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    admin = get_admin_by_id(payload.admin)
    if not admin:
        return BasicResponse(success=False, message="Admin not found.")
    result = change_admin_password(payload.admin, password_hash(payload.newPassword))
    if result:
        return BasicResponse(success=True, message="Password changed successfully.")
    return BasicResponse(success=False, message="Failed to change password.")

@app.post("/admin/edit")
@limiter.limit("5/second")
async def admin_edit(
    request: Request, payload: AdminEditRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    admin = get_admin_by_id(payload.id)
    if not admin:
        return BasicResponse(success=False, message="Admin not found.")
    if admin.email != payload.email and get_admin_by_email(payload.email):
        return BasicResponse(success=False, message="Email already in use.")
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", payload.email):
        return BasicResponse(success=False, message="Invalid email format.")
    admin.name = payload.name
    admin.email = payload.email
    if edit_admin(admin):
        return BasicResponse(success=True, message="Admin edited successfully.")
    return BasicResponse(success=False, message="Failed to edit admin.")

@app.post("/admin/delete")
@limiter.limit("5/second")
async def admin_delete(
    request: Request, payload: AdminDeleteRequest, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")
    admin = get_admin_by_id(payload.id)
    if not admin:
        return BasicResponse(success=False, message="Admin not found.")
    if delete_admin(admin):
        return BasicResponse(success=True, message="Admin deleted successfully.")
    return BasicResponse(success=False, message="Failed to delete admin.")

@app.get("/analytic/get")
async def analytic_get(
    request: Request, admin_login=Depends(get_current_user)
) -> BasicResponse:
    if not admin_login:
        return BasicResponse(success=False, message="User is not logged in.")

    daily_reservations = []
    daily_reservation_creations = []
    daily_requests = []
    daily_approvals = []
    daily_rejections = []

    weekly_reservations = []
    weekly_reservation_creations = []
    weekly_approvals = []
    weekly_rejections = []

    monthly_reservations = []
    monthly_reservation_creations = []
    monthly_approvals = []
    monthly_rejections = []
    now = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    start = now - timedelta(days=365)
    analytics = get_analytics_between(start, now)
    analytics_by_date: dict = {a.date.date(): a for a in analytics}
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
    monthly_map: dict = {}
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

    process = psutil.Process()
    data = {
        "daily": {
            "reservations": daily_reservations,
            "reservationCreations": daily_reservation_creations,
            "requests": daily_requests,
            "approvals": daily_approvals,
            "rejections": daily_rejections,
        },
        "weekly": {
            "reservations": weekly_reservations,
            "reservationCreations": weekly_reservation_creations,
            "approvals": weekly_approvals,
            "rejections": weekly_rejections,
        },
        "monthly": {
            "reservations": monthly_reservations,
            "reservationCreations": monthly_reservation_creations,
            "approvals": monthly_approvals,
            "rejections": monthly_rejections,
        },
        "today": {
            "reservations": daily_reservations[-1] if daily_reservations else 0,
            "reservationCreations": daily_reservation_creations[-1] if daily_reservation_creations else 0,
            "requests": daily_requests[-1] if daily_requests else 0,
            "approvals": daily_approvals[-1] if daily_approvals else 0,
            "rejections": daily_rejections[-1] if daily_rejections else 0,
        },
        "errorLogs": get_error_log_count(),
        "cpu": process.cpu_percent(interval=0.1),
        "memory": process.memory_info().rss,
    }
    return BasicResponse(success=True, data=data)