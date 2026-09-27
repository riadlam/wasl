from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.llm import LlmClient

logger = logging.getLogger(__name__)

APPROVER_SYSTEM = """You are PostCrafterApprover for Wasl Maghreb shops.
Judge a campaign tease/plan. Return ONLY JSON:
  decision: "approved" | "rejected"
  score: 0..1
  reasons: string[]
  feedback: string

Rules:
- Reject invented products, prices, discounts, or stock claims not grounded in vision/identity notes.
- Approve when claims about looks/vibe cite vision analysis or identity context.
- Allow Maghreb Darija/French/Arabic brand voice.
- Prefer approve when score >= 0.7 and no clear invention.
"""

PROCESS_CARD_FILTER_SYSTEM = """You are PostCrafterApprover filtering owner decision cards before a campaign sample.
Shop reply language is ALREADY fixed in business settings — never keep language/Darija/French/tone-of-script cards.
Vision understanding is trusted — never keep "confirm what is in the image" / product-name / price-on-image cards.

Keep ONLY cards that are real post-structure choices the owner must decide, e.g.:
- one image/SKU per post vs combine creatives in one narrative
- which offer to lead with when several appear
- series vs single post when that changes the plan

Drop naive/redundant cards: CTA reminders, "use only prices from images", energy/tone fluff, anything already decided by settings or vision.

Return ONLY JSON:
{"keep":[{"id":"...","text":"...","default_on":true|false}],"drop_ids":["..."],"reasons":["..."]}
If nothing worth asking, return keep:[].
"""


class PostCrafterApprover:
    def __init__(self, llm: LlmClient | None = None) -> None:
        self.llm = llm or LlmClient()

    async def review(
        self,
        *,
        caption: str,
        plan_notes: str,
        vision_notes: str,
        identity_notes: str,
        model: str | None = None,
    ) -> dict[str, Any]:
        user = {
            "tease_caption": caption,
            "plan_notes": plan_notes,
            "vision_notes": vision_notes or "(none)",
            "identity_notes": identity_notes or "(none)",
        }
        try:
            result = await self.llm.chat_completion(
                [
                    {"role": "system", "content": APPROVER_SYSTEM},
                    {"role": "user", "content": json.dumps(user, ensure_ascii=False)},
                ],
                model=model,
                temperature=0.1,
            )
            return self._parse(result.get("content") or "")
        except Exception as exc:
            logger.warning("post_crafter_approver failed: %s", exc)
            return {
                "decision": "approved",
                "score": 0.75,
                "reasons": ["approver_unavailable_soft_pass"],
                "feedback": "",
                "approved": True,
            }

    async def filter_process_cards(
        self,
        *,
        cards: list[dict[str, Any]],
        summary: str,
        reply_language: str = "",
        image_count: int = 0,
        model: str | None = None,
    ) -> list[dict[str, Any]]:
        """Drop naive/redundant process cards; keep only useful owner decisions."""
        if not cards:
            return []
        # Hard filter: language / tone-script / vision-confirm fluff
        blocked = re.compile(
            r"(darija|fran[cç]ais|french|arabic script|reply language|لغة|دارجة|"
            r"shop energy|use only prices|prices?/text visible|cta\b|call to action|"
            r"confirm (what|the product|the price)|what (is|was) in the image)",
            re.I,
        )
        local = []
        for c in cards:
            if not isinstance(c, dict):
                continue
            text = str(c.get("text") or "").strip()
            if not text or blocked.search(text):
                continue
            local.append({
                "id": str(c.get("id") or "")[:64],
                "text": text[:180],
                "group": "process",
                "required": bool(c.get("required", True)),
                "default_on": bool(c.get("default_on", False)),
            })
        if not local:
            return []
        try:
            result = await self.llm.chat_completion(
                [
                    {"role": "system", "content": PROCESS_CARD_FILTER_SYSTEM},
                    {
                        "role": "user",
                        "content": json.dumps(
                            {
                                "reply_language_setting": reply_language or "(shop settings)",
                                "vision_summary": (summary or "")[:800],
                                "image_count": image_count,
                                "proposed_cards": local,
                            },
                            ensure_ascii=False,
                        ),
                    },
                ],
                model=model,
                temperature=0.1,
            )
            parsed = self._parse_keep_list(result.get("content") or "")
            keep = parsed.get("keep") if isinstance(parsed.get("keep"), list) else []
            out: list[dict[str, Any]] = []
            by_id = {c["id"]: c for c in local if c.get("id")}
            for row in keep[:4]:
                if not isinstance(row, dict):
                    continue
                cid = str(row.get("id") or "").strip()
                text = str(row.get("text") or "").strip()
                base = by_id.get(cid) or {}
                if not text:
                    text = str(base.get("text") or "").strip()
                if not text:
                    continue
                if blocked.search(text):
                    continue
                out.append({
                    "id": (cid or f"process_{len(out)+1}")[:64],
                    "text": text[:180],
                    "group": "process",
                    "required": True,
                    "default_on": bool(row.get("default_on", base.get("default_on", False))),
                })
            return out
        except Exception as exc:
            logger.info("post_crafter_approver.filter_process_cards skipped: %s", exc)
            return local[:3]

    def _parse_keep_list(self, raw: str) -> dict[str, Any]:
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

    def _parse(self, raw: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            m = re.search(r"\{[\s\S]*\}", text)
            data = json.loads(m.group(0)) if m else {}
        decision = str(data.get("decision") or "rejected").lower()
        if decision not in ("approved", "rejected"):
            decision = "rejected"
        score = float(data.get("score") or 0)
        reasons = data.get("reasons") if isinstance(data.get("reasons"), list) else []
        return {
            "decision": decision,
            "score": score,
            "reasons": [str(r) for r in reasons][:8],
            "feedback": str(data.get("feedback") or ""),
            "approved": decision == "approved",
        }
