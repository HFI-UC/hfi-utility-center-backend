from __future__ import annotations

import hashlib
import secrets
from datetime import datetime, timedelta

import bcrypt
from fastapi import Request
from pydantic import BaseModel
from sqlmodel import select
from sqlmodel.ext.asyncio.session import AsyncSession

from core.orm import engine, get_admin_by_email
from core.types import AdminLogin, NativeSession
from core.utils import verify_turnstile_token


ACCESS_TOKEN_MINUTES = 15
REFRESH_TOKEN_DAYS = 30


class NativeTokenRequest(BaseModel):
    email: str
    password: str
    turnstileToken: str
    deviceName: str | None = None


class NativeRefreshRequest(BaseModel):
    refreshToken: str


class NativeRevokeRequest(BaseModel):
    refreshToken: str


def hash_token(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def verify_password(password: str, hashed: str) -> bool:
    return bcrypt.checkpw(password.encode("utf-8"), hashed.encode("utf-8"))


async def issue_native_tokens(
    session: AsyncSession, admin_email: str, device_name: str | None = None
) -> tuple[str, str, NativeSession]:
    access_token = secrets.token_urlsafe(48)
    refresh_token = secrets.token_urlsafe(64)
    now = datetime.now()
    native_session = NativeSession(
        adminEmail=admin_email,
        accessTokenHash=hash_token(access_token),
        refreshTokenHash=hash_token(refresh_token),
        deviceName=device_name,
        accessExpiry=now + timedelta(minutes=ACCESS_TOKEN_MINUTES),
        refreshExpiry=now + timedelta(days=REFRESH_TOKEN_DAYS),
    )
    session.add(native_session)
    await session.commit()
    await session.refresh(native_session)
    return access_token, refresh_token, native_session


async def rotate_native_tokens(
    session: AsyncSession, refresh_token: str
) -> tuple[str, str, NativeSession] | None:
    native_session = (
        await session.exec(
            select(NativeSession).where(
                NativeSession.refreshTokenHash == hash_token(refresh_token)
            )
        )
    ).one_or_none()
    now = datetime.now()
    if (
        not native_session
        or native_session.revokedAt is not None
        or native_session.refreshExpiry <= now
    ):
        return None

    access_token = secrets.token_urlsafe(48)
    next_refresh_token = secrets.token_urlsafe(64)
    native_session.accessTokenHash = hash_token(access_token)
    native_session.refreshTokenHash = hash_token(next_refresh_token)
    native_session.accessExpiry = now + timedelta(minutes=ACCESS_TOKEN_MINUTES)
    native_session.refreshExpiry = now + timedelta(days=REFRESH_TOKEN_DAYS)
    session.add(native_session)
    await session.commit()
    await session.refresh(native_session)
    return access_token, next_refresh_token, native_session


async def revoke_native_token(session: AsyncSession, refresh_token: str) -> bool:
    native_session = (
        await session.exec(
            select(NativeSession).where(
                NativeSession.refreshTokenHash == hash_token(refresh_token)
            )
        )
    ).one_or_none()
    if not native_session:
        return False
    native_session.revokedAt = datetime.now()
    session.add(native_session)
    await session.commit()
    return True


async def get_native_principal(request: Request) -> AdminLogin | None:
    authorization = request.headers.get("authorization", "")
    if not authorization.lower().startswith("bearer "):
        return None
    access_token = authorization.split(" ", 1)[1].strip()
    if not access_token:
        return None

    async with AsyncSession(engine) as session:
        native_session = (
            await session.exec(
                select(NativeSession).where(
                    NativeSession.accessTokenHash == hash_token(access_token)
                )
            )
        ).one_or_none()
        now = datetime.now()
        if (
            not native_session
            or native_session.revokedAt is not None
            or native_session.accessExpiry <= now
        ):
            return None
        return AdminLogin(
            email=native_session.adminEmail,
            cookie="",
            expiry=native_session.accessExpiry,
        )


async def authenticate_native_credentials(payload: NativeTokenRequest):
    if not verify_turnstile_token(payload.turnstileToken):
        return None
    async with AsyncSession(engine) as session:
        admin = await get_admin_by_email(session, payload.email)
        if not admin or not verify_password(payload.password, admin.password):
            return None
        return admin
