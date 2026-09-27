from __future__ import annotations

import json
import logging
import time
import uuid
from typing import Any

logger = logging.getLogger(__name__)


class SessionStore:
    """In-memory short-term agent sessions (keyed by chat/conversation)."""

    def __init__(self, ttl_seconds: int = 86400) -> None:
        self.ttl = ttl_seconds
        self._sessions: dict[str, dict[str, Any]] = {}

    def _key(self, business_id: int, surface: str, session_ref: str) -> str:
        return f"{business_id}:{surface}:{session_ref}"

    def get_or_create(self, business_id: int, surface: str, session_ref: str) -> dict[str, Any]:
        key = self._key(business_id, surface, session_ref)
        now = time.time()
        existing = self._sessions.get(key)
        if existing and now - existing["updated_at"] < self.ttl:
            existing["updated_at"] = now
            return existing
        session = {
            "id": str(uuid.uuid4()),
            "business_id": business_id,
            "surface": surface,
            "session_ref": session_ref,
            "state": {},
            "messages": [],
            "created_at": now,
            "updated_at": now,
        }
        self._sessions[key] = session
        return session

    def save(self, session: dict[str, Any]) -> None:
        key = self._key(session["business_id"], session["surface"], session["session_ref"])
        session["updated_at"] = time.time()
        self._sessions[key] = session

    def set_state(self, session: dict[str, Any], **kwargs: Any) -> None:
        session["state"].update(kwargs)
        self.save(session)

    def append_message(self, session: dict[str, Any], role: str, content: str) -> None:
        session["messages"].append({"role": role, "content": content})
        # Cap short-term history
        if len(session["messages"]) > 40:
            session["messages"] = session["messages"][-40:]
        self.save(session)

    def dump(self, session: dict[str, Any]) -> str:
        return json.dumps(session.get("state") or {}, ensure_ascii=False)
