from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.config import get_settings
from app.llm import LlmClient
from app.middleware.telemetry import get_tracer

logger = logging.getLogger(__name__)

MUTATING_OWNER_TOOLS = frozenset(
    {
        "prepare_social_post",
        "confirm_pending_action",
        "create_ai_campaign",
        "create_order",
    }
)

GROUNDING_JUDGE = """You are a metacognition grounding judge for a shop assistant reply.
Given the customer message, tool/RAG evidence, and the draft reply, decide if the reply invents
prices, stock, delivery fees, or product facts not present in evidence.
Return ONLY JSON: {"ok": true|false, "feedback": "..."}.
If ok=false, feedback must tell how to rewrite without invented numbers/facts.
"""


class MetacognitionGate:
    """Shared metacognition gates (AF AI-judge semantics) for Owner/Customer agents."""

    def __init__(self, llm: LlmClient | None = None) -> None:
        self.llm = llm or LlmClient()
        self.settings = get_settings()

    @property
    def enabled(self) -> bool:
        return bool(self.settings.metacognition_enabled)

    def check_prepare_social_post(self, args: dict[str, Any], session_state: dict[str, Any] | None) -> dict[str, Any] | None:
        """Return an error dict if prepare_social_post should be blocked; else None."""
        if not self.enabled:
            return None
        state = session_state or {}
        approved = str(state.get("approved_caption") or "").strip()
        caption = str(args.get("caption") or "").strip()
        if not approved:
            return {
                "ok": False,
                "error": "caption_not_approved",
                "detail": "Caption must pass OwnerApprover/CaptionApprover before prepare_social_post.",
            }
        if caption and self._normalize(caption) != self._normalize(approved):
            return {
                "ok": False,
                "error": "caption_not_approved",
                "detail": "prepare_social_post caption must match the approved_caption from review.",
                "approved_caption": approved,
            }
        return None

    async def ground_customer_reply(
        self,
        *,
        customer_text: str,
        draft_reply: str,
        evidence: list[str],
        model: str | None = None,
    ) -> dict[str, Any]:
        if not self.enabled or not draft_reply.strip():
            return {"ok": True, "reply": draft_reply, "retried": False, "usage": {}}

        tracer = get_tracer()
        with tracer.start_as_current_span("metacognition.customer_grounding"):
            judge = await self._judge(customer_text, draft_reply, evidence, model=model)
            usage = judge.get("usage") or {}
            if judge.get("ok"):
                return {"ok": True, "reply": draft_reply, "retried": False, "usage": usage}

            retries = max(0, int(self.settings.metacognition_max_retries))
            if retries < 1:
                return {
                    "ok": False,
                    "reply": draft_reply,
                    "retried": False,
                    "usage": usage,
                    "feedback": judge.get("feedback"),
                }

            rewrite = await self.llm.chat_completion(
                [
                    {
                        "role": "system",
                        "content": (
                            "Rewrite the shop assistant reply. Remove any invented prices/stock/fees. "
                            "Use only evidence. Keep the same language. Return reply text only."
                        ),
                    },
                    {
                        "role": "user",
                        "content": json.dumps(
                            {
                                "customer_text": customer_text,
                                "draft_reply": draft_reply,
                                "feedback": judge.get("feedback"),
                                "evidence": evidence[:12],
                            },
                            ensure_ascii=False,
                        ),
                    },
                ],
                model=model,
                temperature=0.2,
            )
            self._merge_usage(usage, rewrite.get("usage") or {})
            new_reply = (rewrite.get("content") or draft_reply).strip()
            return {"ok": True, "reply": new_reply, "retried": True, "usage": usage, "feedback": judge.get("feedback")}

    async def _judge(
        self,
        customer_text: str,
        draft_reply: str,
        evidence: list[str],
        model: str | None = None,
    ) -> dict[str, Any]:
        result = await self.llm.chat_completion(
            [
                {"role": "system", "content": GROUNDING_JUDGE},
                {
                    "role": "user",
                    "content": json.dumps(
                        {
                            "customer_text": customer_text,
                            "draft_reply": draft_reply,
                            "evidence": evidence[:12],
                        },
                        ensure_ascii=False,
                    ),
                },
            ],
            model=model,
            temperature=0.0,
        )
        parsed = self._parse_judge(result.get("content") or "")
        parsed["usage"] = result.get("usage") or {}
        return parsed

    def _parse_judge(self, raw: str) -> dict[str, Any]:
        text = raw.strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            match = re.search(r"\{[\s\S]*\}", text)
            if not match:
                return {"ok": True, "feedback": ""}
            try:
                data = json.loads(match.group(0))
            except json.JSONDecodeError:
                return {"ok": True, "feedback": ""}
        return {"ok": bool(data.get("ok", True)), "feedback": str(data.get("feedback") or "")}

    @staticmethod
    def _normalize(text: str) -> str:
        return re.sub(r"\s+", " ", text).strip()

    @staticmethod
    def _merge_usage(total: dict[str, Any], part: dict[str, Any]) -> None:
        total["prompt_tokens"] = int(total.get("prompt_tokens") or 0) + int(part.get("prompt_tokens") or 0)
        total["completion_tokens"] = int(total.get("completion_tokens") or 0) + int(
            part.get("completion_tokens") or 0
        )
