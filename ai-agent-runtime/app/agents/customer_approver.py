from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.config import get_settings
from app.llm import LlmClient
from app.middleware.agent_activity import activity
from app.middleware.telemetry import get_tracer
from app.rag.store import KnowledgeStore

logger = logging.getLogger(__name__)

CUSTOMER_APPROVER_SYSTEM = """You are CustomerApprover — the metacognition critic for the shop CustomerAgent
(DMs/comments replies to end customers).
Approve or reject the CustomerAgent draft BEFORE it is sent to the client.
Return ONLY valid JSON:
  decision: "approved" | "rejected"
  score: number 0..1
  reasons: string[]
  feedback: string (rewrite instructions when rejected)

Reject when the draft invents prices, stock, delivery fees, discounts, product specs,
order status, or promises not present in evidence/tools/RAG.
Reject rude, off-language, or unsafe replies.
If HARD BUSINESS RULES (Should / Must not) are provided in the user payload, treat MUST NOT
as non-negotiable — reject any draft that violates them; feedback must require a rewrite that obeys them.

Voice (first person as the shop):
- Reject third-person shop talk: "this shop sells", "they offer", "their website",
  "عندهم", "موقعهم", "خدمتهم", or narrating the brand from outside.
- Reject searcher / discovery voice: لقينا، وجدنا، we found, I searched, I looked up —
  feedback: rewrite as عندنا / كاين عندنا / نقدر.
- Feedback: rewrite as we/عندنا/نقدر — you ARE the shop.

Checkout continuity (reason from dialogue — NO confirm-word lists):
- Evidence may include history_assistant / history_user lines. If the prior assistant turn
  offered a product/pack or asked to confirm, and the current customer message accepts
  (any language/phrasing), reject drafts that: restart availability search, deny that offer,
  or skip collecting the next checkout field (phone / fulfillment IDs for digital;
  wilaya/delivery ONLY when the offer is clearly PHYSICAL).
- NEVER approve payment instructions (Flexy/BaridiMob/CCP numbers, "تقدر تخلص", "ادفع")
  unless evidence shows a successful create_order this turn (order_number / payment_methods).
  Collect destination/fulfillment IDs BEFORE payment. Feedback: ask the next missing field,
  recap, create_order, THEN pay.
- NEVER approve re-asking for a field already present in evidence:
  known_from_chat / known_from_prior_order / known_from_profile / history_user / customer_profile
  (phone, size, game/player ID, wilaya, address).
  Feedback: CONFIRM the known value warmly as a QUESTION (show it + ask to reuse) — never blank re-ask
  and never silently reuse an old/profile value in create_order without that confirm.
  Confirm phrasing like "نقدر نستعملو رقمك 0555…؟" / "نفس المقاس 42؟" / "نفس الـ ID؟" is APPROVED
  when it shows the known value. Blank "عطيني رقمك" / "واش الـ ID" is REJECTED.
  Silent reuse of profile/old phone/size/ID without asking is REJECTED.
- NEVER approve claims that payment is verified AND order is already topped-up/shipped in the same reply.
- NEVER approve "رانا شحنالك" / topped-up / shipped / delivered unless evidence from get_order
  shows status shipped or delivered (set by the shop owner on the Orders dashboard).
  Client saying they sent a receipt is NOT proof of fulfillment.
  Feedback: thank them, say we will verify (pending only), call get_order; do NOT invent done.
- If the customer message is a question or clarifying intent (payment method name, price ask,
  "؟", "chhal", etc.), reject drafts that treat it as final order confirmation or imply create_order.
  Feedback: answer the question, then ask for explicit confirmation of the order recap.
- Reject drafts that call the order "confirmed" or create_order when the user has not clearly
  confirmed the recap (item + price + required fields). Payment comes after create_order.
- Feedback must require continuing checkout for the open offer (ask the next missing field).
- Reject denials driven only by create_order / "Product not found" when history or evidence
  shows the prior turn offered that product from posts/Identity/catalog.
- NEVER suggest عنوان التوصيل / wilaya / بلدية for digital game/pass/diamond/top-up checkouts.
- After a successful create_order (evidence has order_number / payment_methods): REJECT drafts that
  only announce the order number with no payment next-step when payment_methods are present.
  Feedback: recommend priority-1 payment details + soft process-ASAP closer; never ask for receipt attachments.

Digital vs physical (critical):
- For digital / game top-up / pass / diamonds / recharge / post-offer digital checkout, REJECT
  any draft that asks for wilaya, بلدية/commune, address, عنوان التوصيل, or delivery_type.
  Feedback: digital needs phone + account/fulfillment IDs first, then recap + create_order,
  THEN payment — never shipping.

Sales closer:
- When the customer shows buy/availability intent (top-up, game, "hab nchhan", "do you have"),
  reject passive answers that only confirm or dump a website link with NO closing ask
  (which pack/offer/diamonds do you want?). Feedback must require a closer question.

Identity / category grounding (critical):
- Reject when the draft claims unavailable / not sold / we don't offer / not available while
  evidence contains "identity: ..." (or brand facts) that say the shop DOES sell that category
  or product. Empty catalog search is NOT proof of unavailability.
- Reject invented denials when the customer asked about availability / a product / offer / pack /
  pass / top-up / game / recharge and evidence skipped Identity, recent posts, or catalog —
  feedback must require ask_identity_agent + knowledge_search + list_recent_posts(limit=5)
  + search_products before any denial.
- Prefer clarifying + closing questions (which pack/SKU/product) over false "not available".
- Unavailable must be LAST RESORT after exhausting Identity, knowledge, live recent posts, AND catalog.
- NEVER treat prior assistant denials in history as proof of unavailability. If the customer asks again,
  the agent must re-check tools this turn — repeating an old "ما عندناش" without fresh search_products /
  Identity / list_recent_posts must be rejected.

Approve grounded, first-person, sales-forward replies that match Identity + catalog evidence.
Never soft-approve a false unavailability claim, searcher voice, or third-person shop narration.
"""

_DENIAL_RE = re.compile(
    r"(not available|unavailable|don't (sell|offer|have)|do not (sell|offer|have)|"
    r"we don't|we do not|ماكاينش|ما كاينش|ما عندناش|غير متوفر|ماشي متوفر|مش متوفر|"
    r"ماكاش|ما كاش|indisponible|n'?est pas disponible|on (n'?a|n'a) pas|"
    r"m3ndk|ma\s*3ndk|makainch|ma\s*kain|mkach|mkch|makach|"
    r"ماشي متوفر|مش كاين|غاير متوفر)",
    re.IGNORECASE,
)
# Availability / buy intent — Arabizi + short product words (SaaS-wide, not one shop).
_CATEGORY_ASK_RE = re.compile(
    r"(available|availab|top\s*up|topup|recharge|game|games|"
    r"do you (have|sell)|عندكم|واش كاين|واش عند|top.?up|شحان|شحال|"
    r"nhws|nchhan|hab\s|khsni|khani|bghit|n7us|"
    r"3ndk|3ndkm|3ndkom|m3ndk|m3ndkm|ma\s*3nd|"
    r"mkach|mkch|makach|ماكاش|ما كاش|"
    r"weekly|monthly|diamond|diamonds|pack|pass|باقة|عرض|شحن)",
    re.IGNORECASE,
)
_THIRD_PERSON_SHOP_RE = re.compile(
    r"(\bthis shop\b|\bthey (sell|offer|have|provide)\b|\btheir (website|site|shop)\b|"
    r"عندهم|موقعهم|خدمتهم|منتجاتهم|يشحنون)",
    re.IGNORECASE,
)
_SEARCHER_VOICE_RE = re.compile(
    r"(لقينا|وجدنا|\bwe found\b|\bi (searched|looked up|found)\b|"
    r"according to my search|\bmy search (found|shows)\b)",
    re.IGNORECASE,
)
_CLOSE_ASK_RE = re.compile(
    r"(\?|؟|which (pack|offer|one)|what (pack|offer|amount)|"
    r"واش|شحال|شحال|أي|اش|تحب|تبد|تفض|تبغي|نبدأ|نشرو|تشري|"
    r"pack|diamonds|باقة|عرض)",
    re.IGNORECASE,
)
_CREATE_ORDER_FAIL_RE = re.compile(
    r"(product not found|create_order|'ok'\s*:\s*false|\"ok\"\s*:\s*false)",
    re.IGNORECASE,
)
_EMPTY_CATALOG_RE = re.compile(
    r'("products"\s*:\s*\[\s*\]|"count"\s*:\s*0|products:\s*\[\])',
    re.IGNORECASE,
)
_SHIPPING_ASK_RE = re.compile(
    r"(ولاية|الولاية|بلدية|البلدية|wilaya|commune|baladia|baladiya|"
    r"عنوان التوصيل|عنوان|delivery_type|stopdesk|stop\s*desk|"
    r"delivery address|shipping address)",
    re.IGNORECASE,
)
_DIGITAL_OFFER_RE = re.compile(
    r"(weekly\s*pass|diamond|diamonds|top-?up|recharge|pass|game_id|server_id|"
    r"player_id|zone_id|digital_fulfillment|mlbb|mobile legends|"
    r"شحن|داياموند|جوهرة|باقة|game\s*id|سيرفر)",
    re.IGNORECASE,
)
_ORDER_NUMBER_RE = re.compile(r"\bORD-\d{4}-[A-Z0-9]+\b", re.IGNORECASE)
_PAYMENT_NEXT_RE = re.compile(
    r"(flexy|baridimob|baridi|ccp|clé|cle|ادفع|خلص|تخلص|payment|دفع|"
    r"أولوية|priority|نكمّل|نكملو|أقرب وقت|asap)",
    re.IGNORECASE,
)
_PAYMENT_INSTRUCTIONS_RE = re.compile(
    r"(flexy|baridimob|baridi\s*mob|ccp|clé|cle|"
    r"تقدر تخلص|تقدر تدفع|خلص على|ادفع على|رقم.*(flexy|baridi|ccp)|"
    r"0079\d{10,})",
    re.IGNORECASE,
)
_ASK_PHONE_RE = re.compile(
    r"(رقم\s*(تاعك|الهاتف|التلفون|التيليفون)|عطيني\s*رقم|واش\s*رقمك|"
    r"رقم\s*الواتساب|phone\s*number|your\s*phone|رقمكم)",
    re.IGNORECASE,
)
_ASK_ID_RE = re.compile(
    r"(ممكن\s+(تعطينا|تبعثلنا|تقولنا).{0,40}(الـ\s*)?id|"
    r"عطيني\s*(الـ)?\s*id|واش\s*(الـ)?\s*id|"
    r"(game\s*id|player_s?id|رقم الحساب|رقم اللاعب)\s*\?|"
    r"\?[^?]{0,40}(id تاعك|الـ\s*id|game\s*id))",
    re.IGNORECASE,
)
_ASK_WILAYA_RE = re.compile(
    r"(واش\s*الولاية|عطيني\s*الولاية|ولاية\s*تاعك|your\s*wilaya|"
    r"وين\s*تسكن|بلدية\s*تاعك)",
    re.IGNORECASE,
)
_CONFIRM_KNOWN_RE = re.compile(
    r"(نقدر\s*نستعمل|نستعملو|نستخدمو|نخدمو\s*ب|نفس\s*(الـ\s*)?(id|الرقم|العنوان|الولاية)|"
    r"can\s+we\s+use|same\s+(phone|id|address|wilaya)|as\s+last\s+time|"
    r"اللي\s*عندنا|المرة\s*اللي\s*فاتت|ولا\s*تبدلو|ولا\s*نبدلو|"
    r"confirm.{0,20}(phone|id|address|wilaya)|"
    r"رقمك\s*0[5-7]\d{8}|id\s*تاعك\s*\d{5,})",
    re.IGNORECASE,
)
_KNOWN_PHONE_RE = re.compile(
    r"(known_from_(?:chat|profile|prior_order):[^\n]*phone=|"
    r"(?:history_user|customer_profile):[^\n]*(?:\+?213|0)[5-7]\d{8})",
    re.IGNORECASE,
)
_KNOWN_ID_RE = re.compile(
    r"(known_from_(?:chat|profile|prior_order):[^\n]*game_or_player_id=|"
    r"known_from_(?:profile|prior_order):[^\n]*(?:player_id|game_id|zone_id)=|"
    r"history_user:\s*\d{5,}|"
    r"digital_fulfillment[^\n]*(player_id|game_id|zone_id))",
    re.IGNORECASE,
)
_KNOWN_WILAYA_RE = re.compile(
    r"(known_from_(?:chat|profile|prior_order):[^\n]*wilaya=|"
    r"customer_profile:[^\n]*wilaya[\"']?\s*[:=]\s*[\"']?[A-Za-z\u0600-\u06FF]|"
    r"history_user:[^\n]*(biskra|alger|oran|constantine|setif|بلدية|ولاية))",
    re.IGNORECASE,
)
_KNOWN_ADDRESS_RE = re.compile(
    r"(known_from_(?:chat|profile|prior_order):[^\n]*address=)",
    re.IGNORECASE,
)
_ASK_ADDRESS_RE = re.compile(
    r"(عطيني\s*(العنوان|عنوان)|واش\s*(العنوان|عنوانك)|your\s*address|"
    r"عنوان\s*التوصيل\s*\?)",
    re.IGNORECASE,
)
_FULFILL_CLAIM_RE = re.compile(
    r"(رانا شحن|تم الشحن|شحنالك|شحنّالك|وصلوك|تم التعبئة|تم التوب.?أب|"
    r"already (shipped|sent|topped|fulfilled)|we (shipped|sent|topped)|"
    r"order (is|was) (shipped|fulfilled|delivered)|"
    r"topped\s*you\s*up|we\s*have\s*topped)",
    re.IGNORECASE,
)
_VERIFY_PENDING_RE = re.compile(
    r"(نتحقق|رانا نتحقق|دوك نتحقق|قيد المراجعة|نستناو التأكيد|"
    r"we('ll| will)?\s*verify|checking\s*(the\s*)?(payment|receipt))",
    re.IGNORECASE,
)
_ORDER_FULFILLED_STATUS_RE = re.compile(
    r"(['\"]status['\"]\s*:\s*['\"](?:shipped|delivered)['\"]|"
    r"status['\"]?\s*[:=]\s*['\"]?(?:shipped|delivered)\b)",
    re.IGNORECASE,
)
_CREATE_ORDER_SUCCESS_RE = re.compile(
    r"('ok'\s*:\s*true|\"ok\"\s*:\s*true).{0,80}order_number|"
    r"order_number.{0,80}('ok'\s*:\s*true|\"ok\"\s*:\s*true)|"
    r"payment_methods|payment_recommended",
    re.IGNORECASE | re.DOTALL,
)
_RECEIPT_ASK_RE = re.compile(
    r"(attach|screenshot|receipt|صورة الدفع|لقطة|التذكرة|ticket|وصل الدفع|"
    r"ابعث.*(صورة|الوصل)|send.*(photo|receipt|screenshot))",
    re.IGNORECASE,
)


class CustomerApprover:
    """Approver agent for CustomerAgent outputs (end-client facing)."""

    def __init__(self, llm: LlmClient | None = None, knowledge: KnowledgeStore | None = None) -> None:
        self.llm = llm or LlmClient()
        self.knowledge = knowledge or KnowledgeStore(llm=self.llm)
        self.settings = get_settings()

    async def review(
        self,
        *,
        draft_reply: str,
        customer_text: str,
        evidence: list[str],
        business_id: int | None = None,
        model: str | None = None,
        hard_business_rules: str = "",
    ) -> dict[str, Any]:
        tracer = get_tracer()
        with tracer.start_as_current_span("metacognition.customer_approver") as span:
            if business_id:
                span.set_attribute("business_id", business_id)

            forced = self._force_reject_identity_denial(
                draft_reply=draft_reply,
                customer_text=customer_text,
                evidence=evidence,
            )
            if not forced:
                forced = self._force_reject_voice_or_close(
                    draft_reply=draft_reply,
                    customer_text=customer_text,
                )
            if not forced:
                forced = self._force_reject_order_fail_denial(
                    draft_reply=draft_reply,
                    evidence=evidence,
                )
            if not forced:
                forced = self._force_reject_digital_shipping_ask(
                    draft_reply=draft_reply,
                    evidence=evidence,
                )
            if not forced:
                forced = self._force_reject_premature_payment(
                    draft_reply=draft_reply,
                    evidence=evidence,
                )
            # Fulfillment truth gates BEFORE reask — wrong feedback once steered the
            # model from "ask ID again" into inventing "رانا شحنالك" with the known ID.
            if not forced:
                forced = self._force_reject_verify_and_fulfill_contradiction(
                    draft_reply=draft_reply,
                )
            if not forced:
                forced = self._force_reject_premature_fulfillment_claim(
                    draft_reply=draft_reply,
                    customer_text=customer_text,
                    evidence=evidence,
                )
            if not forced:
                forced = self._force_reject_reask_known_field(
                    draft_reply=draft_reply,
                    evidence=evidence,
                )
            if not forced:
                forced = self._force_reject_missing_payment_closer(
                    draft_reply=draft_reply,
                    evidence=evidence,
                )
            if forced:
                forced["usage"] = {}
                forced["approver"] = "CustomerApprover"
                span.set_attribute("decision", "rejected")
                span.set_attribute("force_reject", True)
                return forced

            rules = (hard_business_rules or "").strip()
            system = CUSTOMER_APPROVER_SYSTEM
            if rules:
                system = f"{CUSTOMER_APPROVER_SYSTEM}\n\n{rules}\n"
            payload = {
                "customer_text": customer_text,
                "draft_reply": draft_reply,
                "evidence": evidence[:16],
                "hard_business_rules": rules or "(none)",
            }
            response = await self.llm.chat_completion(
                [
                    {"role": "system", "content": system},
                    {"role": "user", "content": json.dumps(payload, ensure_ascii=False)},
                ],
                model=model,
                temperature=0.0,
            )
            parsed = self._parse(response.get("content") or "")
            parsed["usage"] = response.get("usage") or {}
            parsed["approver"] = "CustomerApprover"
            span.set_attribute("decision", parsed.get("decision") or "rejected")
            return parsed

    async def review_until_approved(
        self,
        *,
        draft_reply: str,
        customer_text: str,
        evidence: list[str],
        revise_fn,
        business_id: int | None = None,
        model: str | None = None,
        max_rounds: int | None = None,
        hard_business_rules: str = "",
    ) -> dict[str, Any]:
        """Reject→revise until CustomerApprover approves (bounded)."""
        rounds_limit = max_rounds if max_rounds is not None else max(1, int(self.settings.metacognition_max_retries) + 1)
        usage = {"prompt_tokens": 0, "completion_tokens": 0, "fal_calls": 0}
        rounds: list[dict[str, Any]] = []
        current = draft_reply
        last_review: dict[str, Any] = {}

        for round_idx in range(1, rounds_limit + 1):
            review = await self.review(
                draft_reply=current,
                customer_text=customer_text,
                evidence=evidence,
                business_id=business_id,
                model=model,
                hard_business_rules=hard_business_rules,
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
                f"CustomerApprover round {round_idx} → {decision}",
                {
                    "approver": "CustomerApprover",
                    "decision": review.get("decision"),
                    "score": review.get("score"),
                    "reasons": review.get("reasons"),
                    "feedback": review.get("feedback"),
                    "draft_reviewed": current,
                },
                kind="APPROVER",
            )
            if review.get("decision") == "approved":
                return {
                    "approved": True,
                    "reply": current,
                    "review": review,
                    "rounds": rounds,
                    "usage": usage,
                    "retried": round_idx > 1,
                }

            if round_idx >= rounds_limit:
                break

            revised = await revise_fn(str(review.get("feedback") or ""), current)
            if isinstance(revised, dict):
                current = str(revised.get("reply") or current)
                self._add_usage(usage, revised.get("usage") or {})
                usage["fal_calls"] += 1
            else:
                current = str(revised or current)

        # Soft-fail: NEVER send topped-up/shipped claims without owner status proof.
        safe_reply = self._safe_pending_verify_reply(
            draft=current,
            evidence=evidence,
        )

        return {
            "approved": False,
            "reply": safe_reply,
            "review": last_review,
            "rounds": rounds,
            "usage": usage,
            "retried": True,
            "needs_human_review": True,
        }

    def _force_reject_identity_denial(
        self,
        *,
        draft_reply: str,
        customer_text: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Hard reject false unavailability that fights Identity evidence or skipped Identity."""
        draft = (draft_reply or "").strip()
        if not draft or not _DENIAL_RE.search(draft):
            return None

        identity_bits = [
            str(e) for e in evidence if str(e).lower().startswith("identity:")
        ]
        has_identity = bool(identity_bits)
        category_ask = bool(_CATEGORY_ASK_RE.search(customer_text or ""))
        # Ignore chat history when judging tool use — only this-turn tool payloads count.
        tool_evidence = [
            str(e)
            for e in evidence
            if not str(e).lower().startswith("history_")
            and not str(e).lower().startswith("prior_draft:")
            and str(e).strip() != (customer_text or "").strip()
        ]
        joined_tools = "\n".join(tool_evidence).lower()
        has_posts_check = (
            "list_recent_posts" in joined_tools
            or "recent post" in joined_tools
            or "sample post" in joined_tools
            or '"posts"' in joined_tools
            or "posts_text" in joined_tools
        )
        has_knowledge = "knowledge_search" in joined_tools or (
            "namespace" in joined_tools and "chunk" in joined_tools
        )
        has_catalog_call = (
            '"products"' in joined_tools
            or "search_products" in joined_tools
            or "matched_via" in joined_tools
            or "active_catalog_count" in joined_tools
        )
        empty_catalog = bool(_EMPTY_CATALOG_RE.search(joined_tools))

        if has_identity and self._identity_supports_ask(identity_bits, customer_text or ""):
            return {
                "decision": "rejected",
                "score": 0.15,
                "reasons": ["identity_contradicts_unavailability"],
                "feedback": (
                    "Draft claims unavailable but identity evidence supports related products. "
                    "Call ask_identity_agent + list_recent_posts + search_products; ask which pack/SKU. "
                    "Do NOT say not available when Identity supports the ask."
                ),
            }

        # Any availability ask denied without fresh tools THIS turn (history denials are not evidence).
        if category_ask and (not has_identity or not has_posts_check or not has_catalog_call):
            return {
                "decision": "rejected",
                "score": 0.15,
                "reasons": ["missing_exhaustive_availability_checks"],
                "feedback": (
                    "Customer asked about availability/offer/product. Do NOT deny from chat memory. "
                    "THIS turn call ask_identity_agent, knowledge_search, list_recent_posts(limit=5), "
                    "AND search_products (short keywords or empty query to list SKUs). "
                    "Prior 'unavailable' replies are not proof. Unavailable is last resort after all four."
                ),
            }

        # Empty catalog denial without Identity/posts even if ask regex missed.
        if empty_catalog and not has_identity and not has_posts_check and not has_knowledge:
            return {
                "decision": "rejected",
                "score": 0.15,
                "reasons": ["empty_catalog_denial_without_posts_or_identity"],
                "feedback": (
                    "Empty search_products is not proof we don't offer it. "
                    "Retry search_products with a shorter product keyword or empty query to list "
                    "active SKUs, plus ask_identity_agent and list_recent_posts(limit=5), "
                    "before any denial; then close on what we do have."
                ),
            }
        return None

    def _force_reject_voice_or_close(
        self,
        *,
        draft_reply: str,
        customer_text: str,
    ) -> dict[str, Any] | None:
        """Reject third-person / searcher shop voice or buy-intent replies with no closer."""
        draft = (draft_reply or "").strip()
        if not draft:
            return None

        if _THIRD_PERSON_SHOP_RE.search(draft):
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["third_person_shop_voice"],
                "feedback": (
                    "Rewrite in FIRST PERSON as the shop (we/عندنا/نقدر). "
                    "Never say عندهم/موقعهم/this shop/they offer. You ARE the brand chatting."
                ),
            }

        if _SEARCHER_VOICE_RE.search(draft):
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["searcher_discovery_voice"],
                "feedback": (
                    "Do not narrate discovery (لقينا / we found / I searched). "
                    "Speak as the shop: عندنا / كاين عندنا / نقدر نشحن, then close the sale."
                ),
            }

        buy_intent = bool(_CATEGORY_ASK_RE.search(customer_text or ""))
        if buy_intent and not _CLOSE_ASK_RE.search(draft):
            return {
                "decision": "rejected",
                "score": 0.25,
                "reasons": ["missing_sales_close"],
                "feedback": (
                    "Customer showed buy/availability intent. Confirm we have it in first person, "
                    "then CLOSE: ask which pack/offer/diamonds they want to buy. "
                    "Do not only dump a website link."
                ),
            }
        return None

    def _force_reject_order_fail_denial(
        self,
        *,
        draft_reply: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Reject denying an offered product just because create_order failed."""
        draft = (draft_reply or "").strip()
        if not draft or not _DENIAL_RE.search(draft):
            return None
        joined = "\n".join(str(e) for e in evidence)
        if not _CREATE_ORDER_FAIL_RE.search(joined):
            return None
        offered = any(
            str(e).lower().startswith("history_assistant:")
            or str(e).lower().startswith("prior_draft:")
            or str(e).lower().startswith("identity:")
            for e in evidence
        )
        if not offered:
            return None
        return {
            "decision": "rejected",
            "score": 0.15,
            "reasons": ["order_tool_fail_must_not_deny_offer"],
            "feedback": (
                "create_order failed but the prior turn offered this product. "
                "Do NOT deny availability. Ask for the next missing checkout field "
                "(phone / fulfillment IDs for digital; wilaya only if physical) or retry "
                "create_order with product_name + unit_price for post offers. Keep selling."
            ),
        }

    def _force_reject_digital_shipping_ask(
        self,
        *,
        draft_reply: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Reject asking wilaya/baladia/address when checkout is clearly digital."""
        draft = (draft_reply or "").strip()
        if not draft or not _SHIPPING_ASK_RE.search(draft):
            return None
        joined = "\n".join(str(e) for e in evidence)
        if not _DIGITAL_OFFER_RE.search(joined) and not _DIGITAL_OFFER_RE.search(draft):
            return None
        return {
            "decision": "rejected",
            "score": 0.1,
            "reasons": ["digital_checkout_must_not_ask_shipping"],
            "feedback": (
                "This is a DIGITAL offer (game/pass/diamonds/top-up). "
                "Do NOT ask wilaya, بلدية, address, or delivery. "
                "Collect phone + account/fulfillment IDs first, recap, create_order, "
                "THEN share payment details — never shipping."
            ),
        }

    def _force_reject_premature_payment(
        self,
        *,
        draft_reply: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Reject Flexy/BaridiMob payment instructions before create_order succeeded."""
        draft = (draft_reply or "").strip()
        if not draft or not _PAYMENT_INSTRUCTIONS_RE.search(draft):
            return None
        # Confirming a known customer phone/ID is not payment instructions.
        joined = "\n".join(str(e) for e in evidence)
        if _CONFIRM_KNOWN_RE.search(draft) and self._draft_shows_known_value(draft, joined):
            return None
        if _CREATE_ORDER_SUCCESS_RE.search(joined):
            return None
        # Draft inventing a brand-new ORD without tool success is also premature.
        return {
            "decision": "rejected",
            "score": 0.15,
            "reasons": ["payment_before_order_fields"],
            "feedback": (
                "Do NOT give payment numbers yet. Checkout order is strict: "
                "collect phone + fulfillment IDs (digital) or phone + wilaya/delivery (physical), "
                "recap and get confirmation, call create_order, THEN recommend payment. "
                "Ask the next missing field instead."
            ),
        }

    def _force_reject_reask_known_field(
        self,
        *,
        draft_reply: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Reject blank re-asks when a field is known; allow warm confirm phrasing."""
        draft = (draft_reply or "").strip()
        if not draft:
            return None
        joined = "\n".join(str(e) for e in evidence)

        # Warm confirmation that shows the known value is the desired UX — allow it.
        if _CONFIRM_KNOWN_RE.search(draft) and self._draft_shows_known_value(draft, joined):
            return None

        if _ASK_PHONE_RE.search(draft) and _KNOWN_PHONE_RE.search(joined):
            phone = self._extract_known_value(joined, "phone") or "the saved number"
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["reask_known_phone"],
                "feedback": (
                    f"Phone is already known ({phone}). Do NOT blank-ask. "
                    f"CONFIRM warmly: e.g. نقدر نستعملو رقمك {phone}؟ "
                    "Make them feel remembered, then continue checkout."
                ),
            }
        if _ASK_ID_RE.search(draft) and _KNOWN_ID_RE.search(joined):
            game_id = self._extract_known_value(joined, "game_or_player_id") or "the saved ID"
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["reask_known_game_id"],
                "feedback": (
                    f"Game/player ID is already known ({game_id}). Do NOT blank-ask. "
                    f"CONFIRM warmly: نفس الـ ID تاع المرة اللي فاتت {game_id} ولا تبدلو؟"
                ),
            }
        if _ASK_WILAYA_RE.search(draft) and _KNOWN_WILAYA_RE.search(joined):
            wilaya = self._extract_known_value(joined, "wilaya") or "the saved wilaya"
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["reask_known_wilaya"],
                "feedback": (
                    f"Wilaya/commune is already known ({wilaya}). Do NOT blank-ask. "
                    f"CONFIRM warmly: نفس الولاية {wilaya}؟"
                ),
            }
        if _ASK_ADDRESS_RE.search(draft) and _KNOWN_ADDRESS_RE.search(joined):
            address = self._extract_known_value(joined, "address") or "the saved address"
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["reask_known_address"],
                "feedback": (
                    f"Address is already known ({address}). Do NOT blank-ask. "
                    f"CONFIRM warmly: نقدر نستعملو نفس العنوان؟"
                ),
            }
        return None

    @staticmethod
    def _extract_known_value(joined_evidence: str, key: str) -> str | None:
        match = re.search(
            rf"(?:known_from_(?:chat|profile|prior_order):[^\n]*\b{re.escape(key)}=)([^\s;]+)",
            joined_evidence,
            re.IGNORECASE,
        )
        return match.group(1).strip() if match else None

    @staticmethod
    def _draft_shows_known_value(draft: str, joined_evidence: str) -> bool:
        """True when the draft repeats a known phone/ID/wilaya/address token."""
        values: list[str] = []
        for key in ("phone", "game_or_player_id", "player_id", "game_id", "wilaya", "address"):
            found = CustomerApprover._extract_known_value(joined_evidence, key)
            if found and len(found) >= 3:
                values.append(found)
        for match in re.finditer(
            r"(?:phone|game_or_player_id|player_id|game_id|wilaya|address)=([^\s;]+)",
            joined_evidence,
            re.IGNORECASE,
        ):
            token = match.group(1).strip()
            if len(token) >= 3:
                values.append(token)
        draft_l = draft.lower()
        return any(v.lower() in draft_l for v in values)
    @staticmethod
    def _safe_pending_verify_reply(*, draft: str, evidence: list[str]) -> str:
        """Replace any unverified fulfillment claim with a pending-verify-only reply."""
        text = (draft or "").strip()
        joined = "\n".join(str(e) for e in evidence)
        owner_confirmed = bool(_ORDER_FULFILLED_STATUS_RE.search(joined))
        claims_done = bool(_FULFILL_CLAIM_RE.search(text))
        contradicts = bool(_VERIFY_PENDING_RE.search(text) and claims_done)
        if (claims_done and not owner_confirmed) or contradicts:
            return (
                "صحيت، رانا شفنا التأكيد. الطلب مازال قيد المراجعة عندنا، "
                "كي يولي جاهز نعلمك فورا."
            )
        return text or (
            "صحيت، رانا شفنا التأكيد. الطلب مازال قيد المراجعة عندنا، "
            "كي يولي جاهز نعلمك فورا."
        )

    def _force_reject_premature_fulfillment_claim(
        self,
        *,
        draft_reply: str,
        customer_text: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Reject topped-up/shipped claims unless get_order status is shipped/delivered."""
        draft = (draft_reply or "").strip()
        if not draft or not _FULFILL_CLAIM_RE.search(draft):
            return None
        joined = "\n".join(str(e) for e in evidence)
        if _ORDER_FULFILLED_STATUS_RE.search(joined):
            return None
        return {
            "decision": "rejected",
            "score": 0.05,
            "reasons": ["premature_fulfillment_claim"],
            "feedback": (
                "FORBIDDEN: do not say the order is topped-up/shipped/done. "
                "Only the shop owner marking the order shipped/delivered on the Orders dashboard "
                "makes it done — then get_order must show status shipped or delivered. "
                "If the client sent a payment receipt: thank them and say we will VERIFY (pending only). "
                "Do NOT claim شحنالك / topped-up. Never invent fulfillment."
            ),
        }

    def _force_reject_verify_and_fulfill_contradiction(
        self,
        *,
        draft_reply: str,
    ) -> dict[str, Any] | None:
        """Reject drafts that both 'verify payment' and 'already topped up'."""
        draft = (draft_reply or "").strip()
        if not draft:
            return None
        if _VERIFY_PENDING_RE.search(draft) and _FULFILL_CLAIM_RE.search(draft):
            return {
                "decision": "rejected",
                "score": 0.05,
                "reasons": ["verify_and_fulfill_contradiction"],
                "feedback": (
                    "Contradiction: you cannot say we are still verifying AND that we already topped up. "
                    "Pick ONE: pending verification only (until owner marks order shipped/delivered)."
                ),
            }
        return None

    def _force_reject_missing_payment_closer(
        self,
        *,
        draft_reply: str,
        evidence: list[str],
    ) -> dict[str, Any] | None:
        """Reject order-number drafts that skip payment next-step when methods are configured."""
        draft = (draft_reply or "").strip()
        if not draft:
            return None

        if _RECEIPT_ASK_RE.search(draft):
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["forbidden_receipt_attachment_phrasing"],
                "feedback": (
                    "Do not ask the client to attach a receipt/screenshot or wait on a ticket. "
                    "Share payment details and a soft 'when you pay we process ASAP' closer."
                ),
            }

        joined = "\n".join(str(e) for e in evidence)
        has_order = bool(_ORDER_NUMBER_RE.search(draft)) or (
            "order_number" in joined.lower() and "'ok': true" in joined.lower().replace('"', "'")
        ) or ('"ok": true' in joined.lower() and "order_number" in joined.lower())
        has_payment_methods = (
            "payment_methods" in joined.lower()
            or "payment_recommended" in joined.lower()
            or "list_payment_methods" in joined.lower()
        )
        if not has_order or not has_payment_methods:
            return None
        # Methods present and non-empty in tool evidence
        if re.search(r'"payment_methods"\s*:\s*\[\s*\]', joined) or re.search(
            r"'payment_methods'\s*:\s*\[\s*\]", joined
        ):
            return None
        if "payment_methods" in joined.lower() and "count': 0" in joined.lower().replace('"', "'"):
            return None

        if _PAYMENT_NEXT_RE.search(draft):
            return None

        return {
            "decision": "rejected",
            "score": 0.2,
            "reasons": ["missing_post_order_payment_closer"],
            "feedback": (
                "Order was created and payment methods are configured. "
                "Give the order number, recommend priority-1 payment details "
                "(Flexy/BaridiMob/CCP from tools), then a soft process-ASAP closer. "
                "Do not stop at the order number alone; never ask for receipt attachments."
            ),
        }

    @staticmethod
    def _identity_supports_ask(identity_bits: list[str], customer_text: str) -> bool:
        blob = " ".join(identity_bits).lower()
        if not blob.strip() or _DENIAL_RE.search(blob):
            return False
        support_cues = (
            "top-up",
            "topup",
            "top up",
            "game",
            "games",
            "diamond",
            "recharge",
            "digital",
            "sell",
            "offer",
            "service",
            "catalog",
            "pack",
            "pass",
            "شحن",
            "باقة",
        )
        if any(c in blob for c in support_cues):
            return True
        # Shared content words between customer ask and identity answer
        stop = {"the", "a", "an", "and", "or", "to", "for", "you", "do", "have", "want", "hab", "nchhan"}
        ask_tokens = {
            t for t in re.findall(r"[a-zA-Z\u0600-\u06FF]{3,}", customer_text.lower()) if t not in stop
        }
        return any(t in blob for t in ask_tokens)

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
                    "feedback": "Rewrite without invented prices or stock; stay grounded in evidence.",
                }
            try:
                data = json.loads(match.group(0))
            except json.JSONDecodeError:
                return {
                    "decision": "rejected",
                    "score": 0.0,
                    "reasons": ["invalid_approver_json"],
                    "feedback": "Rewrite without invented prices or stock; stay grounded in evidence.",
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
