from __future__ import annotations

import hashlib
import json
from datetime import date, datetime, time, timedelta
from typing import Any, Literal

from fastapi import APIRouter, Header, Query, Request
from fastapi.responses import Response
from pydantic import BaseModel
from sqlmodel.ext.asyncio.session import AsyncSession

from core.native_auth import (
    ACCESS_TOKEN_MINUTES,
    REFRESH_TOKEN_DAYS,
    NativeRefreshRequest,
    NativeRevokeRequest,
    NativeTokenRequest,
    authenticate_native_credentials,
    issue_native_tokens,
    revoke_native_token,
    rotate_native_tokens,
)
from core.orm import (
    engine,
    get_admin_by_email,
    get_admin_login_by_cookie,
    get_app_setting,
    get_campus,
    get_class,
    get_reservations_by_time_range_and_room,
    get_room,
    get_room_by_id,
    set_app_setting,
)
from core.native_auth import get_native_principal
from core.types import ApiResponse


router = APIRouter(prefix="/api/v1", tags=["api-v1"])


class TimeSlot(BaseModel):
    startTime: int
    endTime: int
    status: Literal["available", "occupied", "policy", "past"]


def _catalog_payload(campuses, classes, rooms) -> dict[str, Any]:
    return {
        "schemaVersion": 2,
        "generatedAt": datetime.now().isoformat(),
        "campuses": [
            {
                "id": campus.id,
                "name": campus.name,
                "isPrivileged": campus.isPrivileged,
                "createdAt": campus.createdAt,
            }
            for campus in campuses
        ],
        "classes": [
            {
                "id": class_.id,
                "name": class_.name,
                "campus": class_.campusId,
                "createdAt": class_.createdAt,
            }
            for class_ in classes
        ],
        "rooms": [
            {
                "id": room.id,
                "name": room.name,
                "campus": room.campusId,
                "enabled": room.enabled,
                "createdAt": room.createdAt,
                "policies": [
                    {
                        "id": policy.id,
                        "roomId": policy.roomId,
                        "days": policy.days,
                        "startTime": policy.startTime,
                        "endTime": policy.endTime,
                        "enabled": policy.enabled,
                    }
                    for policy in room.policies
                ],
            }
            for room in rooms
        ],
        "specialFacilities": [
            {
                "key": "auditorium",
                "name": "Auditorium",
                "bookingMode": "email-template",
                "templates": {
                    "zh-CN": {
                        "subject": "Auditorium 使用申请 - {date} {startTime}",
                        "body": "您好，\n\n我希望申请使用 Auditorium。\n\n姓名：{studentName}\n班级：{className}\n日期：{date}\n时间：{startTime} - {endTime}\n用途：{purpose}\n预计人数：{attendeeCount}\n多媒体设备需求：{multimedia}\n详细说明：{reason}\n\n谢谢。",
                    },
                    "en-US": {
                        "subject": "Auditorium use request - {date} {startTime}",
                        "body": "Hello,\n\nI would like to request use of the Auditorium.\n\nName: {studentName}\nClass: {className}\nDate: {date}\nTime: {startTime} - {endTime}\nPurpose: {purpose}\nExpected attendees: {attendeeCount}\nMultimedia requirements: {multimedia}\nDetails: {reason}\n\nThank you.",
                    },
                },
            }
        ],
    }


@router.get("/bootstrap")
async def bootstrap(if_none_match: str | None = Header(default=None)):
    async with AsyncSession(engine) as session:
        payload = _catalog_payload(
            await get_campus(session), await get_class(session), await get_room(session)
        )
    version_source = json.dumps(
        {key: value for key, value in payload.items() if key != "generatedAt"},
        default=str,
        sort_keys=True,
        separators=(",", ":"),
    )
    data_version = hashlib.sha256(version_source.encode("utf-8")).hexdigest()[:16]
    payload["dataVersion"] = data_version
    etag = f'"{data_version}"'
    headers = {"ETag": etag, "Cache-Control": "public, max-age=60, stale-while-revalidate=300"}
    if if_none_match == etag:
        return Response(status_code=304, headers=headers)
    return ApiResponse(success=True, data=payload, headers=headers)


def _overlaps_policy(room, slot_start: datetime, slot_end: datetime) -> bool:
    weekday = (slot_start.weekday() + 1) % 7
    for policy in room.policies:
        if not policy.enabled or weekday not in policy.days:
            continue
        blocked_start = slot_start.replace(
            hour=policy.startTime[0], minute=policy.startTime[1], second=0, microsecond=0
        )
        blocked_end = slot_start.replace(
            hour=policy.endTime[0], minute=policy.endTime[1], second=0, microsecond=0
        )
        if blocked_start < slot_end and blocked_end > slot_start:
            return True
    return False


@router.get("/availability")
async def availability(
    room_id: int = Query(alias="roomId"), selected_date: date = Query(alias="date")
):
    async with AsyncSession(engine) as session:
        room = await get_room_by_id(session, room_id)
        if not room or not room.enabled:
            return ApiResponse(
                success=False,
                code="ROOM_UNAVAILABLE",
                message="Room not found or disabled.",
                status_code=404,
            )
        day_start = datetime.combine(selected_date, time.min)
        day_end = datetime.combine(selected_date, time.max)
        reservations = await get_reservations_by_time_range_and_room(
            session, day_start, day_end, room_id
        )

    active_reservations = [item for item in reservations if item.status != "rejected"]
    cursor = datetime.combine(selected_date, time(hour=8))
    final_start = datetime.combine(selected_date, time(hour=21, minute=15))
    now = datetime.now()
    slots: list[TimeSlot] = []
    while cursor <= final_start:
        slot_end = cursor + timedelta(minutes=15)
        status: Literal["available", "occupied", "policy", "past"] = "available"
        if slot_end <= now:
            status = "past"
        elif any(
            item.startTime < slot_end and item.endTime > cursor
            for item in active_reservations
        ):
            status = "occupied"
        elif _overlaps_policy(room, cursor, slot_end):
            status = "policy"
        slots.append(
            TimeSlot(
                startTime=int(cursor.timestamp()),
                endTime=int(slot_end.timestamp()),
                status=status,
            )
        )
        cursor = slot_end

    return ApiResponse(
        success=True,
        data={
            "roomId": room_id,
            "date": selected_date.isoformat(),
            "slotMinutes": 15,
            "maxDurationMinutes": 120,
            "slots": [slot.model_dump() for slot in slots],
        },
    )


def _token_payload(access_token: str, refresh_token: str, admin) -> dict[str, Any]:
    return {
        "accessToken": access_token,
        "refreshToken": refresh_token,
        "tokenType": "Bearer",
        "accessExpiresIn": ACCESS_TOKEN_MINUTES * 60,
        "refreshExpiresIn": REFRESH_TOKEN_DAYS * 24 * 60 * 60,
        "admin": {"id": admin.id, "email": admin.email, "name": admin.name},
    }


@router.post("/auth/token")
async def native_token(payload: NativeTokenRequest):
    admin = await authenticate_native_credentials(payload)
    if not admin:
        return ApiResponse(
            success=False,
            code="INVALID_CREDENTIALS",
            message="Invalid credentials or verification.",
            status_code=401,
        )
    async with AsyncSession(engine) as session:
        access_token, refresh_token, _ = await issue_native_tokens(
            session, admin.email, payload.deviceName
        )
    return ApiResponse(success=True, data=_token_payload(access_token, refresh_token, admin))


@router.post("/auth/refresh")
async def native_refresh(payload: NativeRefreshRequest):
    async with AsyncSession(engine) as session:
        result = await rotate_native_tokens(session, payload.refreshToken)
        if not result:
            return ApiResponse(
                success=False,
                code="INVALID_REFRESH_TOKEN",
                message="Refresh token is invalid or expired.",
                status_code=401,
            )
        access_token, refresh_token, native_session = result
        from core.orm import get_admin_by_email

        admin = await get_admin_by_email(session, native_session.adminEmail)
        if not admin:
            return ApiResponse(
                success=False,
                code="ADMIN_NOT_FOUND",
                message="Administrator not found.",
                status_code=404,
            )
    return ApiResponse(success=True, data=_token_payload(access_token, refresh_token, admin))


@router.post("/auth/revoke")
async def native_revoke(payload: NativeRevokeRequest):
    async with AsyncSession(engine) as session:
        revoked = await revoke_native_token(session, payload.refreshToken)
    return ApiResponse(success=True, data={"revoked": revoked})


class AIApprovalSetting(BaseModel):
    strength: Literal["relaxed", "standard", "strict"]


async def _request_admin(request: Request):
    principal = await get_native_principal(request)
    if principal:
        return principal
    cookie = request.cookies.get("uc")
    if not cookie:
        return None
    async with AsyncSession(engine) as session:
        login = await get_admin_login_by_cookie(session, cookie)
        if not login or login.expiry < datetime.now():
            return None
        return await get_admin_by_email(session, login.email)


@router.get("/admin/settings/ai-approval")
async def get_ai_approval_setting(request: Request):
    if not await _request_admin(request):
        return ApiResponse(success=False, code="UNAUTHORIZED", status_code=401)
    async with AsyncSession(engine) as session:
        strength = await get_app_setting(session, "aiApprovalStrength", "strict")
    return ApiResponse(success=True, data={"strength": strength})


@router.put("/admin/settings/ai-approval")
async def update_ai_approval_setting(request: Request, payload: AIApprovalSetting):
    if not await _request_admin(request):
        return ApiResponse(success=False, code="UNAUTHORIZED", status_code=401)
    async with AsyncSession(engine) as session:
        await set_app_setting(session, "aiApprovalStrength", payload.strength)
    return ApiResponse(success=True, data={"strength": payload.strength})
