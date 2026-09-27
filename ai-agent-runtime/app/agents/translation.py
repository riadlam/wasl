from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.agents.tool_runtime import AgentToolRuntime
from app.config import get_settings
from app.llm import LlmClient
from app.middleware.agent_activity import activity

logger = logging.getLogger(__name__)

TRANSLATE_SYSTEM = """You are TranslationAgent for Wasl Maghreb SaaS.
Other agents work in English. Your only job is to localize owner-facing text
to THIS shop's reply language (from get_shop_reply_language / tenant hint).

Rules:
- Preserve meaning, product names, prices, SKUs, URLs, and brand names.
- Do not invent offers or change facts.
- Keep short UI labels short.
- If target is Algerian Darija: natural Maghreb phrasing (Arabic script or Latin Arabizi as fits the draft).
- If target is French: clear Maghreb-shop French.
- If text is already in the target language, return it unchanged.
- Return ONLY JSON: {"texts":{"<id>":"<translated>"},"language":"<darija|french>","label":"..."}
"""


class TranslationAgent:
    """Final-mile localization: English agent drafts → shop language via Gemini."""

    agent_id = "translation"
    description = (
        "Translate owner-facing text into this shop's reply language (Darija or French). "
        "Uses get_shop_reply_language for settings. Prefer for final UI messages only."
    )

    def __init__(
        self,
        runtime: AgentToolRuntime | None = None,
        llm: LlmClient | None = None,
    ) -> None:
        self.runtime = runtime or AgentToolRuntime()
        self.llm = llm or self.runtime.llm

    async def consult(
        self,
        question: str,
        *,
        business_id: int,
        context: dict[str, Any] | None = None,
        model: str | None = None,
    ) -> dict[str, Any]:
        """A2A: translate a single English string for the shop."""
        ctx = context or {}
        tenant = {
            "business_id": business_id,
            "user_id": ctx.get("user_id"),
            "correlation_id": ctx.get("correlation_id") or "",
        }
        out = await self.translate(
            tenant=tenant,
            texts={"q": (question or "").strip()},
            language_hint=str(ctx.get("language_hint") or ctx.get("reply_language") or ""),
            model=model,
            correlation_id=str(ctx.get("correlation_id") or ""),
        )
        translated = str((out.get("texts") or {}).get("q") or question or "")
        return {
            "ok": not bool(out.get("error")),
            "answer": translated,
            "language": out.get("language"),
            "label": out.get("label"),
            "error": out.get("error"),
        }

    async def translate(
        self,
        *,
        tenant: dict[str, Any],
        texts: dict[str, str],
        correlation_id: str = "",
        model: str | None = None,
        language_hint: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        user_id = tenant.get("user_id")
        usage = {
            "prompt_tokens": 0,
            "completion_tokens": 0,
            "cost_usd": 0.0,
            "fal_calls": 0,
            "calls_with_cost": 0,
        }
        clean = {
            str(k): str(v).strip()
            for k, v in (texts or {}).items()
            if str(v or "").strip()
        }
        if not clean:
            return {
                "texts": {},
                "language": language_hint or "",
                "label": "",
                "usage": usage,
                "runtime": "sk",
                "agents": {"translator": "TranslationAgent"},
                "error": None,
            }

        with activity().turn(
            "TranslationAgent",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "translation", "keys": list(clean.keys())},
        ):
            activity().input(
                "translate to shop language",
                {"keys": list(clean.keys()), "chars": sum(len(v) for v in clean.values())},
            )
            lang_meta = await self._resolve_language(
                business_id=business_id,
                user_id=int(user_id) if user_id else None,
                correlation_id=correlation_id,
                language_hint=language_hint,
            )
            target = str(lang_meta.get("language") or "darija")
            label = str(lang_meta.get("label") or target)
            script = str(lang_meta.get("script_hint") or label)

            settings = get_settings()
            model_name = model or settings.translation_model or settings.llm_model

            try:
                result = await self.llm.chat_completion(
                    [
                        {"role": "system", "content": TRANSLATE_SYSTEM},
                        {
                            "role": "user",
                            "content": json.dumps(
                                {
                                    "target_language": target,
                                    "target_label": label,
                                    "script_hint": script,
                                    "texts": clean,
                                },
                                ensure_ascii=False,
                            ),
                        },
                    ],
                    model=model_name,
                    temperature=0.2,
                )
                self._merge_usage(usage, result.get("usage") or {})
                parsed = self._parse(result.get("content") or "")
                translated = parsed.get("texts") if isinstance(parsed.get("texts"), dict) else {}
                out_texts: dict[str, str] = {}
                for key, original in clean.items():
                    val = translated.get(key)
                    out_texts[key] = str(val).strip() if val is not None and str(val).strip() else original
                out = {
                    "texts": out_texts,
                    "language": str(parsed.get("language") or target),
                    "label": str(parsed.get("label") or label),
                    "usage": usage,
                    "runtime": "sk",
                    "agents": {"translator": "TranslationAgent", "model": model_name},
                    "error": None,
                }
                activity().output(
                    "translation done",
                    {"language": out["language"], "keys": len(out_texts)},
                )
                return out
            except Exception as exc:
                logger.warning("translation.failed: %s", exc)
                activity().output("translation failed", {"error": str(exc)[:200]})
                return {
                    "texts": clean,
                    "language": target,
                    "label": label,
                    "usage": usage,
                    "runtime": "sk",
                    "agents": {"translator": "TranslationAgent"},
                    "error": str(exc),
                }

    async def _resolve_language(
        self,
        *,
        business_id: int,
        user_id: int | None,
        correlation_id: str,
        language_hint: str,
    ) -> dict[str, Any]:
        hint = (language_hint or "").strip().lower()
        if hint in ("french", "fr", "français", "francais"):
            return {
                "language": "french",
                "label": "French",
                "script_hint": "French (Latin script)",
                "source": "hint",
            }
        if hint in ("darija", "ar", "arabic", "dz"):
            return {
                "language": "darija",
                "label": "Algerian Darija",
                "script_hint": "Algerian Darija",
                "source": "hint",
            }
        try:
            raw = await self.runtime.laravel.invoke_tool(
                business_id=business_id,
                surface="owner",
                tool="get_shop_reply_language",
                arguments={},
                user_id=user_id,
                correlation_id=correlation_id,
            )
            if isinstance(raw, dict) and raw.get("ok"):
                return raw
            if isinstance(raw, dict) and isinstance(raw.get("result"), dict):
                inner = raw["result"]
                if inner.get("ok"):
                    return inner
        except Exception as exc:
            logger.info("translation.language_tool skipped: %s", exc)
        return {
            "language": "darija",
            "label": "Algerian Darija",
            "script_hint": "Algerian Darija",
            "source": "default",
        }

    @staticmethod
    def _merge_usage(into: dict[str, Any], extra: dict[str, Any]) -> None:
        for key in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
            into[key] = int(into.get(key) or 0) + int(extra.get(key) or 0)
        into["cost_usd"] = float(into.get("cost_usd") or 0) + float(extra.get("cost_usd") or 0)

    @staticmethod
    def _parse(raw: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            m = re.search(r"\{[\s\S]*\}", text)
            data = json.loads(m.group(0)) if m else {}
        return data if isinstance(data, dict) else {}
