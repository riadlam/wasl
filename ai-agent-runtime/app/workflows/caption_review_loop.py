from __future__ import annotations

import logging
import re
from typing import Any

from app.agents.caption_approver import CaptionApprover
from app.agents.caption_writer import CaptionWriter
from app.config import get_settings
from app.middleware.telemetry import get_tracer

logger = logging.getLogger(__name__)

POST_INTENT_RE = re.compile(
    r"(post|caption|بوست|منشور|نشر|كابشن|légende|legende|publier|create\s+a\s+post|write\s+a\s+caption)",
    re.IGNORECASE,
)


def looks_like_post_intent(text: str) -> bool:
    return bool(POST_INTENT_RE.search(text or ""))


class CaptionReviewLoop:
    """Author–critic cycle: CaptionWriter ↔ CaptionApprover until approved or max rounds."""

    def __init__(
        self,
        writer: CaptionWriter | None = None,
        approver: CaptionApprover | None = None,
    ) -> None:
        self.writer = writer or CaptionWriter()
        self.approver = approver or CaptionApprover(llm=self.writer.llm, knowledge=self.writer.knowledge)
        self.settings = get_settings()

    async def run(
        self,
        *,
        business_id: int,
        owner_brief: str,
        session: dict[str, Any],
        model: str | None = None,
        correlation_id: str = "",
        max_rounds: int | None = None,
        brand_context: str | None = None,
        include_laravel_context: bool = False,
    ) -> dict[str, Any]:
        tracer = get_tracer()
        configured = max(1, int(self.settings.caption_approver_max_rounds))
        max_rounds = max(1, int(max_rounds)) if max_rounds is not None else configured
        state = session.setdefault("state", {})
        state["publish_step"] = "drafting"

        usage = {"prompt_tokens": 0, "completion_tokens": 0, "fal_calls": 0, "calls_with_cost": 0}
        rounds: list[dict[str, Any]] = []
        caption = ""
        last_review: dict[str, Any] = {}
        feedback: str | None = None
        cached_brand = (brand_context or "").strip() or None

        with tracer.start_as_current_span("metacognition.caption_review") as span:
            span.set_attribute("business_id", business_id)
            span.set_attribute("max_rounds", max_rounds)

            for round_idx in range(1, max_rounds + 1):
                state["publish_step"] = "drafting" if round_idx == 1 else "reviewing"
                draft = await self.writer.draft(
                    business_id=business_id,
                    owner_brief=owner_brief,
                    feedback=feedback,
                    previous_caption=caption or None,
                    model=model,
                    brand_context=cached_brand,
                )
                caption = str(draft.get("caption") or "").strip()
                if not cached_brand and draft.get("brand_context"):
                    cached_brand = str(draft.get("brand_context") or "")
                self._add_usage(usage, draft.get("usage") or {})

                state["publish_step"] = "reviewing"
                review = await self.approver.review(
                    caption=caption,
                    business_id=business_id,
                    owner_brief=owner_brief,
                    model=model,
                    correlation_id=correlation_id,
                    brand_context=cached_brand,
                    include_laravel_context=include_laravel_context and round_idx == 1 and not cached_brand,
                )
                self._add_usage(usage, review.get("usage") or {})
                last_review = review
                rounds.append(
                    {
                        "round": round_idx,
                        "caption": caption,
                        "decision": review.get("decision"),
                        "score": review.get("score"),
                        "reasons": review.get("reasons"),
                        "feedback": review.get("feedback"),
                    }
                )

                if review.get("decision") == "approved" or float(review.get("score") or 0) >= 0.8:
                    state["publish_step"] = "caption_approved"
                    state["approved_caption"] = caption
                    state["caption_review"] = {
                        "approved": True,
                        "needs_owner_edit": False,
                        "rounds": rounds,
                        "score": review.get("score"),
                    }
                    span.set_attribute("approved", True)
                    span.set_attribute("rounds", round_idx)
                    return {
                        "caption": caption,
                        "approved": True,
                        "needs_owner_edit": False,
                        "rounds": rounds,
                        "review": review,
                        "usage": usage,
                        "owner_message": self._owner_message(caption, approved=True, needs_edit=False),
                    }

                feedback = str(review.get("feedback") or "Improve brand fit; remove invented claims.")

            approved = (last_review.get("decision") == "approved") or float(last_review.get("score") or 0) >= 0.75
            state["publish_step"] = "caption_approved"
            state["approved_caption"] = caption
            state["caption_review"] = {
                "approved": approved,
                "needs_owner_edit": not approved,
                "rounds": rounds,
                "score": last_review.get("score"),
            }
            span.set_attribute("approved", approved)
            span.set_attribute("needs_owner_edit", not approved)
            span.set_attribute("rounds", len(rounds))
            return {
                "caption": caption,
                "approved": approved,
                "needs_owner_edit": not approved,
                "rounds": rounds,
                "review": last_review,
                "usage": usage,
                "owner_message": self._owner_message(caption, approved=approved, needs_edit=not approved),
            }

    def _owner_message(self, caption: str, *, approved: bool, needs_edit: bool) -> str:
        if needs_edit:
            return (
                "هاذا مسودة الكابشن بعد مراجعة داخلية (مازال يحتاج تعديل منك):\n\n"
                f"{caption}\n\n"
                "قدر تعدّل النص، ولا قول نعم باش نكمّلو (صورة ولا نص فقط)."
            )
        if approved:
            return (
                "الكابشن واجد بعد مراجعة البراند:\n\n"
                f"{caption}\n\n"
                "تقبلو؟ إلا نعم، تحب نزيدو صورة ولا نص فقط؟"
            )
        return caption

    @staticmethod
    def _add_usage(total: dict[str, Any], part: dict[str, Any]) -> None:
        total["prompt_tokens"] = int(total.get("prompt_tokens") or 0) + int(part.get("prompt_tokens") or 0)
        total["completion_tokens"] = int(total.get("completion_tokens") or 0) + int(
            part.get("completion_tokens") or 0
        )
        total["fal_calls"] = int(total.get("fal_calls") or 0) + 1
        total["calls_with_cost"] = int(total.get("calls_with_cost") or 0) + 1
