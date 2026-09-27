from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.agents.caption_approver import CaptionApprover
from app.llm import LlmClient
from app.middleware.agent_activity import activity
from app.middleware.telemetry import get_tracer
from app.plugins.laravel_client import LaravelClient
from app.rag.store import KnowledgeStore

logger = logging.getLogger(__name__)

OWNER_APPROVER_SYSTEM = """You are OwnerApprover — the metacognition critic for the shop OwnerAgent.
You approve or reject the OwnerAgent's draft RESULT before it is shown to the shop owner.
Return ONLY valid JSON:
  decision: "approved" | "rejected"
  score: number 0..1
  reasons: string[]
  feedback: string (how OwnerAgent must revise when rejected)

Reject when: invented prices/claims, unsafe publish claims (says posted without confirm),
off-brand tone vs shop_brand_context, missing required next question, or hallucinated channels/ids.
Approve helpful drafts that are honest and brand-safe.

SaaS / multi-business rules (CRITICAL — never hardcode a category):
- Ground every judgment in shop_brand_context + evidence (identity vectors, catalog, prior posts).
- Owner briefs may mix Latin Arabizi, Darija Arabic, French, or English. Interpret intent using
  THIS shop's identity and samples — do not invent a product category the brand does not sell.
- If dialect is ambiguous, prefer readings that fit shop_brand_context (e.g. "previous posts /
  same style") over inventing an unrelated catalog mismatch.
- Never assume the shop sells food, fashion, games, or anything else unless evidence says so.

For task_type=campaign_brief:
- Prefer APPROVE when score would be >= 0.75.
- A short greeting + one clarifying question that echoes identity style cues is enough.
- Campaign "make posts" = sample of scheduled posts, not literal instruction text on the creative.
"""


class OwnerApprover:
    """Approver agent for OwnerAgent outputs (shop-owner facing)."""

    def __init__(
        self,
        llm: LlmClient | None = None,
        knowledge: KnowledgeStore | None = None,
        laravel: LaravelClient | None = None,
        caption_approver: CaptionApprover | None = None,
    ) -> None:
        self.llm = llm or LlmClient()
        self.knowledge = knowledge or KnowledgeStore(llm=self.llm)
        self.laravel = laravel or LaravelClient()
        self.caption_approver = caption_approver or CaptionApprover(
            llm=self.llm, knowledge=self.knowledge, laravel=self.laravel
        )

    async def review(
        self,
        *,
        draft: str,
        business_id: int,
        owner_brief: str = "",
        task_type: str = "general",
        evidence: list[str] | None = None,
        model: str | None = None,
        correlation_id: str = "",
    ) -> dict[str, Any]:
        """Approve/reject an OwnerAgent draft. Captions use CaptionApprover specialist."""
        tracer = get_tracer()
        with tracer.start_as_current_span("metacognition.owner_approver") as span:
            span.set_attribute("business_id", business_id)
            span.set_attribute("task_type", task_type)

            if task_type == "caption":
                result = await self.caption_approver.review(
                    caption=draft,
                    business_id=business_id,
                    owner_brief=owner_brief,
                    model=model,
                    correlation_id=correlation_id,
                )
                result["approver"] = "OwnerApprover/CaptionApprover"
                return result

            brand = self._brand_from_evidence(evidence)
            if not brand:
                brand = await self._brand_bits(business_id, owner_brief)
            payload = {
                "task_type": task_type,
                "owner_brief": owner_brief,
                "draft_result": draft,
                "evidence": (evidence or [])[:12],
                "shop_brand_context": brand or "(limited — ask identity / do not invent category)",
                "instruction": (
                    "Interpret owner dialect using shop_brand_context. "
                    "Do not invent a category mismatch this brand does not sell."
                ),
            }
            response = await self.llm.chat_completion(
                [
                    {"role": "system", "content": OWNER_APPROVER_SYSTEM},
                    {"role": "user", "content": json.dumps(payload, ensure_ascii=False)},
                ],
                model=model,
                temperature=0.1,
            )
            parsed = self._parse(response.get("content") or "")
            parsed = self._soft_correct(
                parsed,
                draft=draft,
                owner_brief=owner_brief,
                task_type=task_type,
                brand=brand,
                evidence=evidence or [],
            )
            parsed["usage"] = response.get("usage") or {}
            parsed["approver"] = "OwnerApprover"
            span.set_attribute("decision", parsed.get("decision") or "rejected")
            return parsed

    async def review_until_approved(
        self,
        *,
        draft: str,
        business_id: int,
        owner_brief: str,
        revise_fn,
        task_type: str = "general",
        evidence: list[str] | None = None,
        model: str | None = None,
        correlation_id: str = "",
        max_rounds: int = 2,
    ) -> dict[str, Any]:
        """Reject→revise loop. revise_fn(feedback, previous_draft) -> new draft str or dict with reply/usage."""
        usage = {"prompt_tokens": 0, "completion_tokens": 0, "fal_calls": 0}
        rounds: list[dict[str, Any]] = []
        current = draft
        last_review: dict[str, Any] = {}

        for round_idx in range(1, max(1, max_rounds) + 1):
            review = await self.review(
                draft=current,
                business_id=business_id,
                owner_brief=owner_brief,
                task_type=task_type,
                evidence=evidence,
                model=model,
                correlation_id=correlation_id,
            )
            self._add_usage(usage, review.get("usage") or {})
            usage["fal_calls"] += 1
            last_review = review
            rounds.append(
                {
                    "round": round_idx,
                    "draft": current,
                    "decision": review.get("decision"),
                    "score": review.get("score"),
                    "feedback": review.get("feedback"),
                }
            )
            decision = str(review.get("decision") or "rejected").upper()
            activity().section(
                f"OwnerApprover round {round_idx} ({task_type}) → {decision}",
                {
                    "approver": "OwnerApprover",
                    "decision": review.get("decision"),
                    "score": review.get("score"),
                    "reasons": review.get("reasons"),
                    "feedback": review.get("feedback"),
                    "draft_reviewed": current,
                    "soft_corrected": bool(review.get("soft_corrected")),
                },
                kind="APPROVER",
            )
            if review.get("decision") == "approved":
                return {
                    "approved": True,
                    "draft": current,
                    "review": review,
                    "rounds": rounds,
                    "usage": usage,
                }

            if round_idx >= max_rounds:
                break

            # Do not revise when Approver invented a category mismatch against identity —
            # revision would push the draft off-brand.
            brand = self._brand_from_evidence(evidence)
            if self._is_identity_contradiction_reject(review, brand=brand, evidence=evidence or []):
                last_review = {
                    **review,
                    "decision": "approved",
                    "score": max(float(review.get("score") or 0), 0.8),
                    "soft_corrected": True,
                    "reasons": list(review.get("reasons") or [])
                    + ["soft_approved_identity_over_dialect_guess"],
                    "feedback": "",
                }
                rounds[-1]["decision"] = "approved"
                return {
                    "approved": True,
                    "draft": current,
                    "review": last_review,
                    "rounds": rounds,
                    "usage": usage,
                }

            revised = await revise_fn(str(review.get("feedback") or ""), current)
            if isinstance(revised, dict):
                current = str(revised.get("reply") or revised.get("draft") or current)
                self._add_usage(usage, revised.get("usage") or {})
                usage["fal_calls"] += 1
            else:
                current = str(revised or current)

        # Campaign brief: still serve the best draft (usually the original) without PHP fallback.
        if task_type == "campaign_brief" and current.strip():
            return {
                "approved": True,
                "draft": current,
                "review": {
                    **last_review,
                    "decision": "approved",
                    "soft_corrected": True,
                    "reasons": list(last_review.get("reasons") or []) + ["served_best_draft_after_gate"],
                },
                "rounds": rounds,
                "usage": usage,
                "needs_owner_edit": False,
            }

        return {
            "approved": False,
            "draft": current,
            "review": last_review,
            "rounds": rounds,
            "usage": usage,
            "needs_owner_edit": True,
        }

    def _soft_correct(
        self,
        parsed: dict[str, Any],
        *,
        draft: str,
        owner_brief: str,
        task_type: str,
        brand: str = "",
        evidence: list[str] | None = None,
    ) -> dict[str, Any]:
        score = float(parsed.get("score") or 0)
        decision = str(parsed.get("decision") or "rejected")
        evidence = evidence or []

        if task_type == "campaign_brief" and score >= 0.75 and decision != "approved":
            return {
                **parsed,
                "decision": "approved",
                "soft_corrected": True,
                "feedback": "",
                "reasons": list(parsed.get("reasons") or []) + ["soft_approve_score"],
            }

        if draft.strip() and self._is_identity_contradiction_reject(parsed, brand=brand, evidence=evidence):
            return {
                **parsed,
                "decision": "approved",
                "score": max(score, 0.8),
                "soft_corrected": True,
                "feedback": "",
                "reasons": list(parsed.get("reasons") or []) + ["soft_approve_identity_over_dialect_guess"],
            }

        return parsed

    def _is_identity_contradiction_reject(
        self,
        review: dict[str, Any],
        *,
        brand: str,
        evidence: list[str],
    ) -> bool:
        """True when Approver invents a category mismatch that fights identity evidence."""
        identity = f"{brand}\n" + "\n".join(evidence)
        if len(identity.strip()) < 40:
            return False

        blob = " ".join(
            [
                str(review.get("feedback") or ""),
                " ".join(str(r) for r in (review.get("reasons") or [])),
            ]
        ).lower()

        mismatch = any(
            w in blob
            for w in (
                "mismatch",
                "unusual for",
                "doesn't match",
                "does not match",
                "contradiction",
                "wrong product",
                "not in the catalog",
                "catalog",
                "food post",
                "restaurant",
            )
        )
        if not mismatch:
            return False

        # Real safety rejects must still stick.
        if any(w in blob for w in ("invented price", "invented discount", "unsafe publish", "hallucinated channel")):
            return False

        return True

    @staticmethod
    def _brand_from_evidence(evidence: list[str] | None) -> str:
        if not evidence:
            return ""
        parts = [str(e).strip() for e in evidence if str(e).strip()]
        joined = "\n".join(parts)
        # Identity tool result alone is enough — skip extra RAG embeds.
        if len(joined) >= 400 or "identity:" in joined.lower() or "sample post" in joined.lower():
            return joined[:3500]
        return ""

    async def _brand_bits(self, business_id: int, brief: str) -> str:
        parts: list[str] = []
        search_multi = getattr(self.knowledge, "search_namespaces", None)
        try:
            if callable(search_multi):
                hits = await search_multi(
                    business_id=business_id,
                    query=brief or "brand tone posts",
                    namespaces=("brand", "tone", "posts"),
                    top_k_per_ns=3,
                )
                for h in hits:
                    parts.append(str(h.get("content") or ""))
            else:
                for ns in ("brand", "tone", "posts"):
                    hits = await self.knowledge.search(
                        business_id=business_id, query=brief or "brand", namespace=ns, top_k=3
                    )
                    for h in hits:
                        parts.append(str(h.get("content") or ""))
        except Exception as exc:
            logger.warning("OwnerApprover RAG: %s", exc)
        return "\n".join(p for p in parts if p)

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
                    "feedback": "Revise the answer to be brand-safe and factual.",
                }
            try:
                data = json.loads(match.group(0))
            except json.JSONDecodeError:
                return {
                    "decision": "rejected",
                    "score": 0.0,
                    "reasons": ["invalid_approver_json"],
                    "feedback": "Revise the answer to be brand-safe and factual.",
                }
        decision = str(data.get("decision") or "rejected").lower().strip()
        if decision not in ("approved", "rejected"):
            decision = "rejected"
        try:
            score = float(data.get("score", 0))
        except (TypeError, ValueError):
            score = 0.0
        reasons = data.get("reasons") if isinstance(data.get("reasons"), list) else []
        return {
            "decision": decision,
            "score": max(0.0, min(1.0, score)),
            "reasons": [str(r) for r in reasons][:12],
            "feedback": str(data.get("feedback") or ""),
        }

    @staticmethod
    def _add_usage(total: dict[str, Any], part: dict[str, Any]) -> None:
        total["prompt_tokens"] = int(total.get("prompt_tokens") or 0) + int(part.get("prompt_tokens") or 0)
        total["completion_tokens"] = int(total.get("completion_tokens") or 0) + int(
            part.get("completion_tokens") or 0
        )
