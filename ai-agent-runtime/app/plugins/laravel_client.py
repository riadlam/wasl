from __future__ import annotations

import logging
from typing import Any

import httpx

from app.config import Settings, get_settings

logger = logging.getLogger(__name__)


class LaravelClient:
    """Calls Laravel internal OpenAPI-style tool endpoints (system of record)."""

    def __init__(self, settings: Settings | None = None) -> None:
        self.settings = settings or get_settings()
        self.base = self.settings.laravel_base_url.rstrip("/")

    def _headers(self, business_id: int, user_id: int | None = None, correlation_id: str = "") -> dict[str, str]:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-Internal-Key": self.settings.laravel_internal_key,
            "X-Business-Id": str(business_id),
        }
        if user_id is not None:
            headers["X-User-Id"] = str(user_id)
        if correlation_id:
            headers["X-Correlation-Id"] = correlation_id
        return headers

    async def invoke_tool(
        self,
        *,
        business_id: int,
        surface: str,
        tool: str,
        arguments: dict[str, Any],
        user_id: int | None = None,
        correlation_id: str = "",
        context: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        payload = {
            "surface": surface,
            "tool": tool,
            "arguments": arguments,
            "context": context or {},
        }
        # SocialAPI list_posts and heavy owner context can hang; fail fast.
        slow_tools = {
            "list_recent_posts": 20.0,
            "list_posts": 20.0,
            "sapi_list_posts": 20.0,
            "get_business_context": 8.0,
        }
        timeout = float(slow_tools.get(tool, 60.0))
        try:
            async with httpx.AsyncClient(timeout=timeout) as client:
                response = await client.post(
                    f"{self.base}/api/internal/ai/tools/invoke",
                    headers=self._headers(business_id, user_id, correlation_id),
                    json=payload,
                )
        except httpx.TimeoutException as exc:
            logger.warning("Laravel tool invoke timeout tool=%s timeout=%s err=%s", tool, timeout, exc)
            return {
                "ok": False,
                "error": "tool_timeout",
                "tool": tool,
                "message": f"{tool} timed out after {int(timeout)}s — continue without it.",
            }
        if response.status_code >= 400:
            logger.warning("Laravel tool invoke failed: %s %s", response.status_code, response.text[:500])
            return {
                "ok": False,
                "error": f"laravel_tool_http_{response.status_code}",
                "detail": response.text[:500],
            }
        data = response.json()
        return data if isinstance(data, dict) else {"ok": True, "result": data}

    async def create_pending_action(
        self,
        *,
        business_id: int,
        user_id: int | None,
        payload: dict[str, Any],
        correlation_id: str = "",
    ) -> dict[str, Any]:
        async with httpx.AsyncClient(timeout=60.0) as client:
            response = await client.post(
                f"{self.base}/api/internal/ai/pending-actions",
                headers=self._headers(business_id, user_id, correlation_id),
                json=payload,
            )
            if response.status_code >= 400:
                return {"ok": False, "error": response.text[:500]}
            data = response.json()
            return data if isinstance(data, dict) else {"ok": True, "data": data}

    async def get_business_context(self, business_id: int, correlation_id: str = "") -> dict[str, Any]:
        return await self.invoke_tool(
            business_id=business_id,
            surface="owner",
            tool="get_business_context",
            arguments={},
            correlation_id=correlation_id,
        )
