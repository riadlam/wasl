"""Per-slot campaign planning + drafting with RAG (identity + memories)."""

from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.agents.tool_runtime import OWNER_TOOLS, AgentToolRuntime
from app.middleware.agent_activity import activity
from app.models.schemas import UsagePayload

logger = logging.getLogger(__name__)

PLAN_SYSTEM = """You are CampaignSlotPlanner for Wasl Maghreb campaigns.

Given a campaign schedule of slots and per-image vision analyses (or focus prompt), build a CONTENT MATRIX
with a DISTINCT idea and content strategy for EACH slot. The PRIMARY GOAL: no duplicate ideas, angles, hooks,
or core messages across the entire campaign.

Rules:
- Different product images = different offers. Never give two posts the same idea/offer/angle.
- Stories must NOT clone the feed post idea — use a story beat (urgency, tip, behind-the-scenes) for the SAME offer.
- Vary content_pillar across posts: Education, Entertainment, Promotion, Connection, Social Proof, FAQ, Behind-scenes.
- Vary hook_type per slot: Question, Statement, Statistic, Story, Command, Curiosity.
- Vary cta_type per slot: Comment, DM, Link, Save, Share, Visit.
- Images are visual anchors, not the strategy — each image gets a different narrative angle (intro / problem / benefit / how-to / FAQ / story / comparison / offer).
- Stay faithful to vision descriptions / focus prompt; do not invent products.
- HARD BUSINESS RULES (Should / Must not) OVERRIDE your choices — never plan a slot that violates MUST NOT.

Return ONLY JSON:
{"slots":[{
  "slot_id":123,
  "idea":"4–10 word title",
  "offer":"product/benefit featured",
  "content_pillar":"Education|Promotion|...",
  "content_angle":"specific angle for this post",
  "objective":"Reach|Trust|Consideration|Conversion",
  "hook_type":"Question|Statement|...",
  "cta_type":"Comment|DM|...",
  "tone":"Helpful|Urgent|Friendly|...",
  "story_type":"Poll|Countdown|Swipe|Text" (only for stories),
  "avoid_topics":["angles already assigned to other slots"]
}]}
"""

DRAFT_SYSTEM = """You are CampaignSlotDrafter — Maghreb social caption writer for ONE Wasl campaign slot.

You draft ONLY this slot's assigned offer/idea. Never feature other campaign offers.

You SHOULD call tools before writing:
- ask_identity_agent — brand voice, niche, how this shop talks
- knowledge_search — memories (campaign brief), brand, tone, posts, products
- list_recent_posts — avoid copying live posts; match energy
- get_business_context / get_shop_reply_language
- search_products / get_product — verify real names/prices only (never invent)

Rules:
- ONLY the SLOT IDEA / THIS OFFER / THIS IMAGE. Forbidden hooks must not be reused.
- Language: shop reply language (usually Algerian Darija Arabic script) unless focus says otherwise.
- Feed posts: FULL caption — hook + blank line + 3–5 short body lines (benefit / offer detail / urgency) + blank line + CTA.
  Target ~280–520 characters. Not a one-liner. Exactly 3 niche hashtags in hashtags array (not inside caption). 1–3 emojis.
- Stories: under 80 characters, at most 2 hashtags.
- Anti-hallucination: never invent prices, discounts, stock, pack contents, or mechanics not in verified facts / brief / tools.
  If unknown, sell name + known price + CTA only.
- HARD BUSINESS RULES from the shop (Should / Must not cards), when provided, OVERRIDE style preferences — never violate MUST NOT.
- Return ONLY JSON:
  {"title":"...","caption":"...","hashtags":["#a","#b","#c"],"image_prompt":"English scene or short note"}
"""


class CampaignSlotAgent:
    agent_id = "campaign_slot"
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

    async def plan_slots(
        self,
        *,
        tenant: dict[str, Any],
        slots: list[dict[str, Any]],
        image_analyses: list[dict[str, Any]] | None = None,
        understanding: str = "",
        product_focus: str = "",
        model: str | None = None,
        correlation_id: str = "",
        hard_business_rules: str = "",
        process_decisions: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        with activity().turn(
            "CampaignSlotPlanner",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "campaign_plan_slots", "slots": len(slots)},
        ):
            activity().input(
                "plan slots",
                {
                    "slots": len(slots),
                    "analyses": len(image_analyses or []),
                    "product_focus": (product_focus or "")[:200],
                    "has_hard_rules": bool((hard_business_rules or "").strip()),
                },
            )
            if not slots:
                return {"slots": [], "usage": UsagePayload().model_dump(), "runtime": "sk", "error": None, "agents": {"planner": "CampaignSlotPlanner"}}

            # Deterministic seeds first — LLM only refines wording.
            seeded = self._seed_plans(slots, image_analyses or [], product_focus)

            # Build system prompt with hard rules if present
            rules = (hard_business_rules or "").strip()
            system = PLAN_SYSTEM
            if rules:
                system = f"{PLAN_SYSTEM}\n\n{rules}\n"

            user_payload = {
                "task": "Build content matrix with distinct idea/pillar/angle/hook/CTA per slot. Keep slot_ids. Return JSON only.",
                "understanding": (understanding or "")[:4000],
                "product_focus": product_focus or "",
                "image_analyses": (image_analyses or [])[:12],
                "seeded_slots": seeded,
                "total_slots": len(slots),
                "hard_business_rules": rules or "(none)",
                "process_decisions": (process_decisions or "").strip() or "(none)",
            }
            messages = [
                {"role": "system", "content": system},
                {"role": "user", "content": json.dumps(user_payload, ensure_ascii=False)},
            ]
            # Planning is a single completion (no tools) — fast + logged.
            loop = await self.runtime.run(
                messages=messages,
                tools=[],
                tenant={**tenant, "surface": "campaign_plan_slots"},
                model=model,
            )
            usage = loop.get("usage") or {}
            parsed = self._parse_json(str(loop.get("final_text") or ""))
            refined = parsed.get("slots") if isinstance(parsed.get("slots"), list) else []
            by_id = {int(s.get("slot_id") or 0): s for s in refined if isinstance(s, dict) and s.get("slot_id")}

            # Matrix fields to extract from LLM response
            matrix_fields = [
                "content_pillar", "content_angle", "objective",
                "hook_type", "cta_type", "tone", "story_type", "avoid_topics"
            ]

            out_slots: list[dict[str, Any]] = []
            for seed in seeded:
                sid = int(seed["slot_id"])
                hit = by_id.get(sid) or {}
                idea = str(hit.get("idea") or seed.get("idea") or "").strip() or str(seed.get("idea") or "")
                offer = str(hit.get("offer") or seed.get("offer") or "").strip() or str(seed.get("offer") or "")
                angle = str(hit.get("angle") or seed.get("angle") or "").strip() or str(seed.get("angle") or "")

                slot_out: dict[str, Any] = {
                    "slot_id": sid,
                    "idea": idea[:160],
                    "offer": offer[:400],
                    "angle": angle[:200],
                    "asset_id": seed.get("asset_id"),
                    "kind": seed.get("kind"),
                    "day_index": seed.get("day_index"),
                }

                # Add matrix fields from LLM or defaults
                for field in matrix_fields:
                    val = hit.get(field)
                    if field == "avoid_topics":
                        slot_out[field] = val if isinstance(val, list) else []
                    else:
                        slot_out[field] = str(val or "").strip()[:100] if val else ""

                # Default content_angle to angle if not provided
                if not slot_out.get("content_angle") and angle:
                    slot_out["content_angle"] = angle[:100]

                out_slots.append(slot_out)

            activity().output("plan slots done", {"count": len(out_slots)})
            return {
                "slots": out_slots,
                "usage": self._usage(usage),
                "runtime": "sk",
                "error": None,
                "agents": {"planner": "CampaignSlotPlanner"},
            }

    async def draft_slot(
        self,
        *,
        tenant: dict[str, Any],
        focus: str,
        platform: str = "facebook",
        kind: str = "post",
        content_mode: str = "product_images",
        slot_idea: str = "",
        slot_offer: str = "",
        slot_angle: str = "",
        forbidden_hooks: str = "",
        image_data_url: str | None = None,
        image_description: str = "",
        model: str | None = None,
        correlation_id: str = "",
        hard_business_rules: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        with activity().turn(
            "CampaignSlotDrafter",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "campaign_draft_slot", "platform": platform, "kind": kind},
        ):
            activity().input(
                "draft slot",
                {
                    "idea": (slot_idea or "")[:160],
                    "offer": (slot_offer or "")[:200],
                    "kind": kind,
                    "has_image": bool(image_data_url),
                    "focus": (focus or "")[:600],
                    "has_hard_rules": bool((hard_business_rules or "").strip()),
                },
            )
            tools = self.runtime.filter_tools(
                self.runtime.tools_for_surface("campaign_draft_slot", OWNER_TOOLS),
                self.TOOL_ALLOWLIST,
            )
            rules = (hard_business_rules or "").strip()
            system = DRAFT_SYSTEM
            if rules:
                system = f"{DRAFT_SYSTEM}\n\n{rules}\n"

            brand_context = ""
            try:
                hits = await self.runtime.knowledge.search(
                    business_id=business_id,
                    query=(slot_offer or slot_idea or focus or "shop brand products tone")[:500],
                    namespace=None,
                    top_k=8,
                )
                brand_context = "\n".join(
                    f"[{h.get('namespace') or 'brand'}] {h.get('content')}"
                    for h in hits
                    if h.get("content")
                )
                activity().input(
                    "draft brand RAG",
                    {"chars": len(brand_context), "hits": len(hits)},
                )
            except Exception as exc:
                logger.info("draft slot brand RAG skipped: %s", exc)

            user_payload: dict[str, Any] = {
                "task": (
                    "Draft THIS campaign slot only. Use shop identity/memories. "
                    "Write a FULL feed caption (~280–520 chars) when kind=post. "
                    "No invented prices/products. Return JSON only."
                ),
                "platform": platform,
                "kind": kind,
                "content_mode": content_mode,
                "slot_idea": slot_idea,
                "slot_offer": slot_offer,
                "slot_angle": slot_angle,
                "forbidden_hooks": forbidden_hooks or "(none)",
                "hard_business_rules": rules or "(none)",
                "campaign_focus": (focus or "")[:6000],
                "this_image_description": (image_description or "")[:2000],
                "shop_identity_and_memories": (brand_context or "")[:5000]
                or "(call ask_identity_agent + knowledge_search)",
            }
            user_content: Any
            if image_data_url:
                user_content = [
                    {"type": "text", "text": json.dumps(user_payload, ensure_ascii=False)},
                    {"type": "image_url", "image_url": {"url": image_data_url}},
                ]
            else:
                user_content = json.dumps(user_payload, ensure_ascii=False)

            messages = [
                {"role": "system", "content": system},
                {"role": "user", "content": user_content},
            ]
            loop = await self.runtime.run(
                messages=messages,
                tools=tools,
                tenant={**tenant, "surface": "campaign_draft_slot"},
                model=model,
            )
            usage = loop.get("usage") or {}
            parsed = self._parse_json(str(loop.get("final_text") or ""))
            title = str(parsed.get("title") or slot_idea or "").strip()
            caption = str(parsed.get("caption") or "").strip()
            tags = self._norm_tags(parsed.get("hashtags") if isinstance(parsed.get("hashtags"), list) else [])
            image_prompt = str(parsed.get("image_prompt") or "").strip()
            if not caption:
                activity().output("draft slot empty", {"error": "empty_caption"})
                return self._empty_draft("empty_caption")
            activity().output(
                "draft slot done",
                {"title": title[:80], "caption_chars": len(caption), "tags": tags},
            )
            return {
                "title": title[:160],
                "caption": caption,
                "hashtags": tags,
                "image_prompt": image_prompt,
                "agents": {
                    "drafter": "CampaignSlotDrafter",
                    "tools_used": [
                        (t.get("name") if isinstance(t, dict) else str(t))
                        for t in (loop.get("tool_log") or [])
                    ][:12],
                },
                "usage": self._usage(usage),
                "runtime": "sk",
                "error": None,
            }

    def _seed_plans(
        self,
        slots: list[dict[str, Any]],
        analyses: list[dict[str, Any]],
        product_focus: str,
    ) -> list[dict[str, Any]]:
        by_label: dict[str, dict[str, Any]] = {}
        for a in analyses:
            if not isinstance(a, dict):
                continue
            label = str(a.get("label") or "").strip()
            if label:
                by_label[label] = a
        out: list[dict[str, Any]] = []
        post_offer_by_day: dict[int, str] = {}
        for i, slot in enumerate(slots):
            if not isinstance(slot, dict):
                continue
            sid = int(slot.get("slot_id") or 0)
            kind = str(slot.get("kind") or "post")
            day = int(slot.get("day_index") or 1)
            asset_id = slot.get("asset_id")
            label = f"asset_{asset_id}" if asset_id else ""
            analysis = by_label.get(label) or (analyses[i % len(analyses)] if analyses else {})
            desc = str((analysis or {}).get("description") or "").strip()
            offer = desc.split(".")[0].strip()[:120] if desc else (product_focus or f"Offer {i + 1}")
            if kind == "story":
                if asset_id and label in by_label and desc:
                    base = offer
                else:
                    base = post_offer_by_day.get(day) or offer
                idea = f"Story beat: {base}"[:160]
                angle = "story urgency / tip for same offer"
                offer = base
            else:
                idea = (offer[:80] or f"Day {day} post {i + 1}").strip()
                angle = "feed hero for this image's offer"
                post_offer_by_day[day] = offer
            out.append(
                {
                    "slot_id": sid,
                    "idea": idea,
                    "offer": offer,
                    "angle": angle,
                    "asset_id": asset_id,
                    "kind": kind,
                    "day_index": day,
                }
            )
        return out

    def _empty_draft(self, error: str) -> dict[str, Any]:
        return {
            "title": "",
            "caption": "",
            "hashtags": [],
            "image_prompt": "",
            "agents": {"drafter": "CampaignSlotDrafter"},
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

    def _usage(self, usage: dict[str, Any]) -> dict[str, Any]:
        return {
            "prompt_tokens": int(usage.get("prompt_tokens") or 0),
            "completion_tokens": int(usage.get("completion_tokens") or 0),
            "cost_usd": float(usage.get("cost_usd") or 0),
            "fal_calls": int(usage.get("fal_calls") or 0),
            "calls_with_cost": int(usage.get("calls_with_cost") or usage.get("fal_calls") or 0),
        }
