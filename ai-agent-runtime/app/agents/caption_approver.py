from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.llm import LlmClient
from app.plugins.laravel_client import LaravelClient
from app.rag.store import KnowledgeStore

logger = logging.getLogger(__name__)

APPROVER_SYSTEM = """You are CaptionApprover for a Maghreb shop SaaS (Wasl).
Judge ONE social-post caption against the shop brand context.
Return ONLY valid JSON with keys:
  decision: "approved" | "rejected"
  score: number 0..1
  reasons: string[]
  feedback: string (rewrite instructions when rejected; empty when approved)

Approve when: tone/language matches shop, no invented numeric prices/discounts/stock,
brand voice OK, CTA to known website OK, no harmful content.
ALLOW brand-true claims already in shop context (e.g. payment methods, delivery speed,
competitive prices, official website URL when those appear in brand context). Do not reject for those.
Reject only clear inventions or unsafe claims. Prefer approve when score >= 0.75.
If brand context is empty, REJECT with insufficient_shop_knowledge.
"""


class CaptionApprover:
    def __init__(
        self,
        llm: LlmClient | None = None,
        knowledge: KnowledgeStore | None = None,
        laravel: LaravelClient | None = None,
    ) -> None:
        self.llm = llm or LlmClient()
        self.knowledge = knowledge or KnowledgeStore(llm=self.llm)
        self.laravel = laravel or LaravelClient()

    async def review(
        self,
        *,
        caption: str,
        business_id: int,
        owner_brief: str = "",
        model: str | None = None,
        correlation_id: str = "",
        brand_context: str | None = None,
        include_laravel_context: bool = False,
    ) -> dict[str, Any]:
        brand_bits = (brand_context or "").strip()
        if not brand_bits:
            brand_bits = await self._brand_context(
                business_id,
                owner_brief,
                correlation_id,
                include_laravel=include_laravel_context,
            )
        insufficient = not brand_bits.strip()

        user = {
            "caption": caption,
            "owner_brief": owner_brief,
            "shop_brand_context": brand_bits or "(empty)",
            "insufficient_shop_knowledge": insufficient,
        }
        result = await self.llm.chat_completion(
            [
                {"role": "system", "content": APPROVER_SYSTEM},
                {"role": "user", "content": json.dumps(user, ensure_ascii=False)},
            ],
            model=model,
            temperature=0.1,
        )
        parsed = self._parse(result.get("content") or "")
        if insufficient and parsed.get("decision") == "approved":
            parsed = {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["insufficient_shop_knowledge"],
                "feedback": (
                    "Shop brand knowledge is empty. Rewrite with a safe generic caption: "
                    "no invented prices, discounts, stock, or product claims. Keep tone friendly."
                ),
            }
        parsed["usage"] = result.get("usage") or {}
        parsed["brand_context_chars"] = len(brand_bits)
        return parsed

    async def _brand_context(
        self,
        business_id: int,
        owner_brief: str,
        correlation_id: str,
        *,
        include_laravel: bool = False,
    ) -> str:
        parts: list[str] = []
        # One embedding search across namespaces — much faster than N sequential embeds.
        try:
            hits = await self.knowledge.search(
                business_id=business_id,
                query=owner_brief or "shop brand voice tone posts",
                namespace=None,
                top_k=8,
            )
            for hit in hits:
                ns = hit.get("namespace") or "brand"
                parts.append(f"[{ns}] {hit.get('content')}")
        except Exception as exc:
            logger.warning("CaptionApprover RAG failed: %s", exc)

        # Laravel get_business_context is optional — it often hangs and is redundant with RAG.
        if include_laravel:
            try:
                ctx = await self.laravel.get_business_context(business_id, correlation_id)
                if isinstance(ctx, dict) and ctx.get("ok") is not False:
                    parts.append("business_context: " + json.dumps(ctx, ensure_ascii=False)[:2000])
            except Exception as exc:
                logger.warning("CaptionApprover business context failed: %s", exc)

        return "\n".join(parts).strip()

    def _parse(self, raw: str) -> dict[str, Any]:
        text = raw.strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            match = re.search(r"\{[\s\S]*\}", text)
            if not match:
                return {
                    "decision": "rejected",
                    "score": 0.0,
                    "reasons": ["invalid_approver_json"],
                    "feedback": "Rewrite the caption more clearly for the shop brand.",
                }
            try:
                data = json.loads(match.group(0))
            except json.JSONDecodeError:
                return {
                    "decision": "rejected",
                    "score": 0.0,
                    "reasons": ["invalid_approver_json"],
                    "feedback": "Rewrite the caption more clearly for the shop brand.",
                }

        decision = str(data.get("decision") or "rejected").lower().strip()
        if decision not in ("approved", "rejected"):
            decision = "rejected"
        score = data.get("score", 0.0)
        try:
            score_f = float(score)
        except (TypeError, ValueError):
            score_f = 0.0
        reasons = data.get("reasons") if isinstance(data.get("reasons"), list) else []
        feedback = str(data.get("feedback") or "")
        return {
            "decision": decision,
            "score": max(0.0, min(1.0, score_f)),
            "reasons": [str(r) for r in reasons][:12],
            "feedback": feedback,
        }
