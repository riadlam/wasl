from __future__ import annotations

import json
import logging
from typing import Any

from app.llm import LlmClient
from app.rag.store import KnowledgeStore

logger = logging.getLogger(__name__)

WRITER_SYSTEM = """You are CaptionWriter for Wasl (multi-business Maghreb SaaS).
Write ONE social post caption only. No preamble, no markdown fences.
Use shop_brand_context for THIS shop only — adapt category, tone, and language from that evidence.
Prefer Darija/French when the brief or brand implies it.
Never invent prices, discounts, stock, or product claims not present in context.
Hashtags only if the brief asks for tags.
If revising, strictly follow the reviewer feedback.

AI CAMPAIGN TEASE (when the brief mentions campaign / scheduled / Owner briefing):
- The owner focus is DIRECTION for a SAMPLE of what scheduled posts will look like — not literal text to publish.
- Do NOT quote or paraphrase meta instructions ("make new posts", "same concept").
- Write a real marketing caption for THIS shop's products/category from shop_brand_context only.
"""


class CaptionWriter:
    def __init__(self, llm: LlmClient | None = None, knowledge: KnowledgeStore | None = None) -> None:
        self.llm = llm or LlmClient()
        self.knowledge = knowledge or KnowledgeStore(llm=self.llm)

    async def draft(
        self,
        *,
        business_id: int,
        owner_brief: str,
        feedback: str | None = None,
        previous_caption: str | None = None,
        model: str | None = None,
        brand_context: str | None = None,
    ) -> dict[str, Any]:
        brand = (brand_context or "").strip()
        if not brand:
            brand = await self._brand_bits(business_id, owner_brief)
        payload = {
            "owner_brief": owner_brief,
            "shop_brand_context": brand or "(limited — avoid invented facts)",
            "previous_caption": previous_caption or "",
            "reviewer_feedback": feedback or "",
        }
        result = await self.llm.chat_completion(
            [
                {"role": "system", "content": WRITER_SYSTEM},
                {"role": "user", "content": json.dumps(payload, ensure_ascii=False)},
            ],
            model=model,
            temperature=0.5,
        )
        caption = (result.get("content") or "").strip()
        if caption.startswith("```"):
            caption = caption.strip("`").removeprefix("json").strip()
        return {"caption": caption, "usage": result.get("usage") or {}, "brand_context_chars": len(brand), "brand_context": brand}

    async def _brand_bits(self, business_id: int, owner_brief: str) -> str:
        try:
            hits = await self.knowledge.search(
                business_id=business_id,
                query=owner_brief or "brand voice posts tone",
                namespace=None,
                top_k=8,
            )
            return "\n".join(str(hit.get("content") or "") for hit in hits if hit.get("content")).strip()
        except Exception as exc:
            logger.warning("CaptionWriter RAG failed: %s", exc)
            return ""
