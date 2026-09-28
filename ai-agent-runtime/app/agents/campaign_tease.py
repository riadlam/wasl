from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.agents.caption_writer import CaptionWriter
from app.agents.owner_approver import OwnerApprover
from app.agents.tool_runtime import AgentToolRuntime
from app.middleware.agent_activity import activity
from app.models.schemas import UsagePayload
from app.workflows.caption_review_loop import CaptionReviewLoop

logger = logging.getLogger(__name__)


class CampaignTeaseAgent:
    """Tease generation: identity consult optional + CaptionWriter ↔ CaptionApprover."""

    agent_id = "campaign_tease"

    def __init__(
        self,
        runtime: AgentToolRuntime | None = None,
        owner_approver: OwnerApprover | None = None,
    ) -> None:
        self.runtime = runtime or AgentToolRuntime()
        self.owner_approver = owner_approver or OwnerApprover(
            llm=self.runtime.llm,
            knowledge=self.runtime.knowledge,
            laravel=self.runtime.laravel,
        )
        self.caption_loop = CaptionReviewLoop(
            writer=CaptionWriter(llm=self.runtime.llm, knowledge=self.runtime.knowledge),
            approver=self.owner_approver.caption_approver,
        )

    async def run(
        self,
        *,
        tenant: dict[str, Any],
        focus: str,
        platform: str = "facebook",
        kind: str = "post",
        model: str | None = None,
        correlation_id: str = "",
        hard_business_rules: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        with activity().turn(
            "CampaignTeaseAgent",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "campaign_tease", "platform": platform, "kind": kind},
        ):
            activity().input(
                "campaign tease start",
                {
                    "focus": focus[:1200],
                    "platform": platform,
                    "kind": kind,
                    "has_hard_rules": bool((hard_business_rules or "").strip()),
                },
            )

            # Fast path: one RAG brand fetch, then Writer→Approver (max 2) with cached brand.
            # Never call Laravel get_business_context here — it was hanging ~90s per round.
            brand_context = ""
            try:
                hits = await self.runtime.knowledge.search(
                    business_id=business_id,
                    query=(focus or "shop brand tone posts")[:500],
                    namespace=None,
                    top_k=8,
                )
                brand_context = "\n".join(
                    f"[{h.get('namespace') or 'brand'}] {h.get('content')}"
                    for h in hits
                    if h.get("content")
                )
                activity().input("tease brand RAG", {"chars": len(brand_context), "hits": len(hits)})
            except Exception as exc:
                logger.info("campaign tease brand RAG skipped: %s", exc)

            rules = (hard_business_rules or "").strip()
            if rules:
                brand_context = (brand_context + "\n\n" + rules).strip() if brand_context else rules

            brief = (
                "AI CAMPAIGN TEASE — produce ONE sample caption showing what scheduled posts "
                "will look like when this campaign runs. Owner text below is INTENT/DIRECTION only "
                "(topic, style, games). NEVER print briefing meta or 'make new posts / same concept' "
                "as the caption. Match THIS shop's real category from brand RAG.\n"
            )
            if rules:
                brief += (
                    "HARD BUSINESS RULES (Should / Must not) OVERRIDE style preferences — "
                    "never violate MUST NOT.\n\n"
                )
            brief += f"{focus}"

            session: dict[str, Any] = {"state": {}}
            loop_result = await self.caption_loop.run(
                business_id=business_id,
                owner_brief=brief,
                session=session,
                model=model,
                correlation_id=correlation_id,
                max_rounds=2,
                brand_context=brand_context or None,
                include_laravel_context=False,
            )

            caption = str(loop_result.get("caption") or "").strip()
            structured = self._structure_local(caption, focus, platform, kind, brand_context)

            usage = {
                "prompt_tokens": 0,
                "completion_tokens": 0,
                "cost_usd": 0.0,
                "fal_calls": 0,
                "calls_with_cost": 0,
            }
            self._merge_usage(usage, loop_result.get("usage") or {})

            out = {
                "title": structured.get("title") or "",
                "caption": structured.get("caption") or caption,
                "hashtags": structured.get("hashtags") or [],
                "image_prompt": structured.get("image_prompt") or "",
                "approved": bool(loop_result.get("approved")),
                "needs_owner_edit": bool(loop_result.get("needs_owner_edit")),
                "rounds": loop_result.get("rounds") or [],
                "agents": {
                    "brief": "CampaignBriefAgent",
                    "identity": "BusinessIdentityAgent",
                    "writer": "CaptionWriter",
                    "approver": "CaptionApprover",
                },
                "approver": {
                    "agent": "CaptionApprover",
                    "approved": bool(loop_result.get("approved")),
                    "needs_owner_edit": bool(loop_result.get("needs_owner_edit")),
                    "rounds": len(loop_result.get("rounds") or []),
                },
                "usage": self._usage_payload(usage).model_dump(),
                "runtime": "sk",
            }
            activity().output("campaign tease FINAL", out)
            return out

    def _structure_local(
        self,
        caption: str,
        focus: str,
        platform: str,
        kind: str,
        brand_context: str = "",
    ) -> dict[str, Any]:
        """Build title/hashtags/image_prompt without an extra LLM round."""
        lines = [ln.strip() for ln in (caption or "").splitlines() if ln.strip()]
        title = ""
        if lines:
            title = re.sub(r"^#+\s*", "", lines[0])
            title = re.sub(r"^[\W_]+|[\W_]+$", "", title, flags=re.UNICODE)
            title = title[:80]
        tags = re.findall(r"#[\w\u0600-\u06FF]+", caption or "")
        clean_tags: list[str] = []
        for t in tags:
            if t not in clean_tags:
                clean_tags.append(t)
            if len(clean_tags) >= 5:
                break

        category_hint = self._category_hint(brand_context, caption)
        image_prompt = (
            f"Photoreal shop {kind} creative for {platform}. "
            f"Subject from THIS shop's brand identity: {category_hint}. "
            "Match the approved caption mood (energy only — do not paint owner briefing). "
            "Show only products/services evidenced in brand context — never invent an unrelated category. "
            "FORBIDDEN on-image text: owner instructions, 'make new posts', 'same concept', briefing chat. "
            "Optional short marketing slogan matching the caption theme and shop language. "
            "No invented prices or logos."
        )
        return {
            "title": title or "Campaign tease",
            "caption": caption,
            "hashtags": clean_tags,
            "image_prompt": image_prompt,
        }

    @staticmethod
    def _category_hint(brand_context: str, caption: str) -> str:
        """Derive visual subject only from this shop's brand RAG / caption — no hardcoded niches."""
        for line in (brand_context or "").splitlines():
            line = line.strip()
            if not line:
                continue
            low = line.lower()
            if low.startswith("[brand]") or low.startswith("[posts]") or "brand:" in low or "audience:" in low:
                return line[:220]
        for line in (brand_context or "").splitlines():
            line = line.strip()
            if len(line) >= 40:
                return line[:220]
        cap = (caption or "").strip().splitlines()
        if cap:
            return f"visual matching this caption theme: {cap[0][:160]}"
        return "this shop's real products/services from brand identity — never invent unrelated categories"

    async def _structure(
        self,
        caption: str,
        focus: str,
        platform: str,
        kind: str,
        model: str | None,
    ) -> dict[str, Any]:
        # Kept for optional richer structuring; generate-example uses _structure_local.
        response = await self.runtime.llm.chat_completion(
            [
                {
                    "role": "system",
                    "content": (
                        "Turn an approved campaign tease caption into JSON only: "
                        '{"title":"4-10 words","caption":"...","hashtags":["#a","#b","#c"],'
                        '"image_prompt":"English visual scene"}. Keep caption script as given. '
                        "Do not invent prices."
                    ),
                },
                {
                    "role": "user",
                    "content": json.dumps(
                        {"platform": platform, "kind": kind, "focus": focus[:800], "caption": caption},
                        ensure_ascii=False,
                    ),
                },
            ],
            model=model,
            temperature=0.2,
        )
        raw = str(response.get("content") or "")
        data = self._parse_json(raw)
        tags = data.get("hashtags") if isinstance(data.get("hashtags"), list) else []
        clean_tags = []
        for t in tags[:5]:
            s = str(t).strip()
            if s and not s.startswith("#"):
                s = "#" + s
            if s:
                clean_tags.append(s)
        return {
            "title": str(data.get("title") or "")[:180],
            "caption": str(data.get("caption") or caption).strip(),
            "hashtags": clean_tags,
            "image_prompt": str(data.get("image_prompt") or "").strip(),
            "usage": response.get("usage") or {},
        }

    @staticmethod
    def _parse_json(raw: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
            return data if isinstance(data, dict) else {}
        except Exception:
            m = re.search(r"\{[\s\S]*\}", text)
            if not m:
                return {}
            try:
                data = json.loads(m.group(0))
                return data if isinstance(data, dict) else {}
            except Exception:
                return {}

    @staticmethod
    def _merge_usage(total: dict[str, Any], part: dict[str, Any]) -> None:
        for key in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
            total[key] = int(total.get(key) or 0) + int(part.get(key) or 0)
        total["cost_usd"] = float(total.get("cost_usd") or 0) + float(part.get("cost_usd") or 0)

    @staticmethod
    def _usage_payload(usage: dict[str, Any]) -> UsagePayload:
        return UsagePayload(
            prompt_tokens=int(usage.get("prompt_tokens") or 0),
            completion_tokens=int(usage.get("completion_tokens") or 0),
            cost_usd=float(usage.get("cost_usd") or 0),
            fal_calls=int(usage.get("fal_calls") or 0),
            calls_with_cost=int(usage.get("calls_with_cost") or 0),
        )
