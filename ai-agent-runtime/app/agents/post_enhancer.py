from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.agents.tool_runtime import OWNER_TOOLS, AgentToolRuntime
from app.middleware.agent_activity import activity
from app.models.schemas import UsagePayload

logger = logging.getLogger(__name__)

ENHANCE_SYSTEM = """You are PostEnhancerAgent — senior Maghreb social copy editor for Wasl campaigns.

Your job: take an EXISTING campaign post the owner rejected (Regenerate) and produce a CLEARLY BETTER version.
Do NOT ignore the previous post — improve it: stronger hook, richer body, clearer CTA, better Darija/French voice.
Ground the rewrite in THIS shop's identity + memories + verified products — never invent.

You SHOULD call tools before writing (especially on regenerate):
- ask_identity_agent — who this shop is, brand voice, niche, how they talk
- knowledge_search — memories (campaign brief / process YES-NO), brand, tone, products, recent post style
- list_recent_posts — match pacing/energy without copying
- search_products / get_product — ONLY to verify real names/prices already in focus or catalog
- get_business_context / get_shop_reply_language — shop profile + reply language

Caption length (feed posts, not stories):
- Aim for a FULL Maghreb feed caption: strong hook line, blank line, then 3–5 short body lines (benefit / offer detail / social proof or urgency), blank line, clear CTA.
- Target roughly 280–520 characters of caption text (not counting hashtags). Do NOT write a one-liner or 2-line stub.
- Stories stay short (under ~80 characters).

Anti-hallucination (hard):
- Stay faithful to campaign focus / brief / process YES-NO decisions and THIS slot offer only.
- Do not invent products, prices, discounts, stock, pack contents, or game mechanics not in verified facts / brief / tool results.
- If a product detail is unknown, sell with name + known price + CTA only — never fill gaps with guesses.
- HARD BUSINESS RULES (Should / Must not), when provided, OVERRIDE style — never violate MUST NOT.
- Language: match shop reply language (usually Algerian Darija Arabic script) unless focus says otherwise.

Return ONLY valid JSON with keys:
  title: string (short owner-facing idea, 4-10 words)
  caption: string (publishable caption, no hashtag dump inside)
  hashtags: string[] (exactly 3, with #)
  image_prompt: string (English visual scene if AI image mode; else short note)
  improvement_notes: string (1-2 sentences: what you improved vs previous)
"""


class PostEnhancerAgent:
    """Enhance a rejected campaign caption using identity + memories + shop tools."""

    agent_id = "post_enhancer"
    description = (
        "Enhances a campaign post the owner asked to regenerate. "
        "Consults identity and Supabase memories/brand knowledge to produce a stronger caption."
    )

    TOOL_ALLOWLIST = [
        "ask_identity_agent",
        "knowledge_search",
        "list_recent_posts",
        "get_business_context",
        "get_shop_reply_language",
        "search_products",
        "get_product",
        "list_channels",
    ]

    def __init__(self, runtime: AgentToolRuntime | None = None) -> None:
        self.runtime = runtime or AgentToolRuntime()

    async def enhance(
        self,
        *,
        tenant: dict[str, Any],
        previous_caption: str,
        previous_title: str = "",
        previous_hashtags: list[str] | None = None,
        focus: str = "",
        platform: str = "facebook",
        kind: str = "post",
        content_mode: str = "product_images",
        model: str | None = None,
        correlation_id: str = "",
        forbidden_hooks: str = "",
        slot_idea: str = "",
        slot_offer: str = "",
        hard_business_rules: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        with activity().turn(
            "PostEnhancerAgent",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "campaign_enhance", "platform": platform, "kind": kind},
        ):
            prev = (previous_caption or "").strip()
            activity().input(
                "enhance start",
                {
                    "prev_chars": len(prev),
                    "title": (previous_title or "")[:120],
                    "focus": (focus or "")[:800],
                    "platform": platform,
                    "kind": kind,
                    "slot_idea": (slot_idea or "")[:120],
                    "has_hard_rules": bool((hard_business_rules or "").strip()),
                },
            )
            if not prev:
                return self._empty("missing_previous_caption")

            tools = self.runtime.filter_tools(
                self.runtime.tools_for_surface("campaign_enhance", OWNER_TOOLS),
                self.TOOL_ALLOWLIST,
            )
            tags = previous_hashtags or []
            rules = (hard_business_rules or "").strip()
            system = ENHANCE_SYSTEM
            if rules:
                system = f"{ENHANCE_SYSTEM}\n\n{rules}\n"

            # Prefetch identity/memories so regenerate is grounded even if the model skips tools.
            brand_context = ""
            try:
                hits = await self.runtime.knowledge.search(
                    business_id=business_id,
                    query=(
                        (slot_offer or slot_idea or previous_title or focus or "shop brand products tone")[:500]
                    ),
                    namespace=None,
                    top_k=8,
                )
                brand_context = "\n".join(
                    f"[{h.get('namespace') or 'brand'}] {h.get('content')}"
                    for h in hits
                    if h.get("content")
                )
                activity().input(
                    "enhance brand RAG",
                    {"chars": len(brand_context), "hits": len(hits)},
                )
            except Exception as exc:
                logger.info("post enhance brand RAG skipped: %s", exc)

            user_payload = {
                "task": (
                    "Enhance this campaign post for regenerate. "
                    "First use identity + memories/tools if needed, then return a FULLER feed caption "
                    "(~280–520 chars: hook + body + CTA), faithful, no hallucinations. Return JSON only."
                ),
                "platform": platform,
                "kind": kind,
                "content_mode": content_mode,
                "campaign_focus": (focus or "")[:6000],
                "slot_idea": slot_idea or previous_title or "",
                "slot_offer": slot_offer or "",
                "forbidden_hooks": forbidden_hooks or "(none)",
                "hard_business_rules": rules or "(none)",
                "shop_identity_and_memories": (brand_context or "")[:5000] or "(call ask_identity_agent + knowledge_search)",
                "previous": {
                    "title": previous_title or "",
                    "caption": prev[:4000],
                    "hashtags": tags[:8],
                },
            }
            messages = [
                {"role": "system", "content": system},
                {"role": "user", "content": json.dumps(user_payload, ensure_ascii=False)},
            ]
            loop = await self.runtime.run(
                messages=messages,
                tools=tools,
                tenant={**tenant, "surface": "campaign_enhance", "llm_model": model},
                model=model or tenant.get("llm_model"),
            )
            raw = str(loop.get("final_text") or loop.get("reply") or loop.get("content") or "")
            parsed = self._parse_json(raw)
            usage = loop.get("usage") or {}
            caption = str(parsed.get("caption") or "").strip() or prev
            title = str(parsed.get("title") or previous_title or "").strip()
            hashtags = self._norm_tags(parsed.get("hashtags") if isinstance(parsed.get("hashtags"), list) else tags)
            image_prompt = str(parsed.get("image_prompt") or "").strip()
            notes = str(parsed.get("improvement_notes") or "").strip()

            activity().output(
                "enhance done",
                {
                    "caption_chars": len(caption),
                    "title": title[:80],
                    "hashtags": hashtags,
                    "notes": notes[:300],
                    "tools": len(loop.get("tool_calls") or loop.get("tool_log") or []),
                },
            )

            return {
                "title": title,
                "caption": caption,
                "hashtags": hashtags,
                "image_prompt": image_prompt,
                "improvement_notes": notes,
                "agents": {
                    "enhancer": "PostEnhancerAgent",
                    "tools_used": [
                        (t.get("name") if isinstance(t, dict) else str(t))
                        for t in (loop.get("tool_log") or [])
                    ][:12],
                },
                "usage": self._usage_payload(usage),
                "runtime": "sk",
                "error": None,
            }

    def _empty(self, error: str) -> dict[str, Any]:
        return {
            "title": "",
            "caption": "",
            "hashtags": [],
            "image_prompt": "",
            "improvement_notes": "",
            "agents": {"enhancer": "PostEnhancerAgent"},
            "usage": UsagePayload().model_dump(),
            "runtime": "sk",
            "error": error,
        }

    def _parse_json(self, raw: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if not text:
            return {}
        fence = re.search(r"```(?:json)?\s*([\s\S]*?)```", text)
        if fence:
            text = fence.group(1).strip()
        try:
            data = json.loads(text)
            return data if isinstance(data, dict) else {}
        except Exception:
            start = text.find("{")
            end = text.rfind("}")
            if start >= 0 and end > start:
                try:
                    data = json.loads(text[start : end + 1])
                    return data if isinstance(data, dict) else {}
                except Exception:
                    return {}
            return {}

    def _norm_tags(self, tags: list[Any]) -> list[str]:
        out: list[str] = []
        for tag in tags:
            t = str(tag or "").strip()
            if not t:
                continue
            if not t.startswith("#"):
                t = "#" + t.lstrip("#")
            if t not in out:
                out.append(t)
            if len(out) >= 3:
                break
        return out

    def _usage_payload(self, usage: dict[str, Any]) -> dict[str, Any]:
        return {
            "prompt_tokens": int(usage.get("prompt_tokens") or 0),
            "completion_tokens": int(usage.get("completion_tokens") or 0),
            "cost_usd": float(usage.get("cost_usd") or 0),
            "fal_calls": int(usage.get("fal_calls") or 0),
            "calls_with_cost": int(usage.get("calls_with_cost") or usage.get("fal_calls") or 0),
        }
