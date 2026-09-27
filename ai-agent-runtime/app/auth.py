from __future__ import annotations

from typing import Any

from fastapi import Header, HTTPException, status

from app.config import get_settings


def require_service_key(x_runtime_key: str | None = Header(default=None, alias="X-Runtime-Key")) -> None:
    settings = get_settings()
    if not x_runtime_key or x_runtime_key != settings.runtime_service_key:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid runtime service key")


def tenant_headers(
    x_business_id: str | None = Header(default=None, alias="X-Business-Id"),
    x_user_id: str | None = Header(default=None, alias="X-User-Id"),
    x_correlation_id: str | None = Header(default=None, alias="X-Correlation-Id"),
    x_surface: str | None = Header(default=None, alias="X-Surface"),
) -> dict[str, Any]:
    if not x_business_id:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="X-Business-Id required")
    try:
        business_id = int(x_business_id)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid X-Business-Id") from exc

    user_id = None
    if x_user_id:
        try:
            user_id = int(x_user_id)
        except ValueError as exc:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid X-User-Id") from exc

    return {
        "business_id": business_id,
        "user_id": user_id,
        "correlation_id": x_correlation_id or "",
        "surface": (x_surface or "owner").lower(),
    }
