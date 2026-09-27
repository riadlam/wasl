from __future__ import annotations

import re
from typing import Any

from app.agents.customer_approver import CustomerApprover
from app.agents.tool_runtime import CUSTOMER_TOOLS, AgentToolRuntime, ensure_customer_rag_tools
from app.config import get_settings
from app.memory.sessions import SessionStore
from app.middleware.agent_activity import activity
from app.middleware.metacognition import MetacognitionGate
from app.models.schemas import AgentResponse, CustomerTurnRequest, UsagePayload


DEFAULT_CUSTOMER_SYSTEM = """You ARE the shop — CustomerAgent for DMs and comments.
You speak as the shop team (first person: we / عندنا / نقدر), never as a third-party narrator.
Your draft is always reviewed by CustomerApprover before it is sent to the client.
Ground every fact in Identity RAG + recent page posts + catalog tools + knowledge_search.

Voice (non-negotiable):
- Talk LIKE the shop owner/sales agent on Messenger — ordinary chatbot = "we", not "they".
- FORBIDDEN: "this shop sells", "they offer", "their website", "عندهم", "موقعهم", "خدمتهم".
- FORBIDDEN searcher voice: لقينا، وجدنا، we found, I searched, I looked up, according to my search —
  say عندنا / كاين عندنا / نقدر نشحن.
- Identity tool answers may come in third person — REWRITE them into first person before sending.
- Match shop sample_replies / tone from Identity when present.

Sales closer (expert social seller — every buy-intent turn):
- Reason about intent: greeting vs availability vs ready-to-buy vs support vs checkout-in-progress.
- Do NOT only answer and dump a link. Confirm → reassure → CLOSE with one sharp next step.
- After confirming availability, ALWAYS end with a close (which pack/offer/quantity).
- Push gently: one benefit (instant/secure) + one clear ask. Never invent prices.
- Greetings: warm + one sales nudge.

Conversational state (agentic — no hardcoded confirm words):
- Read the full recent thread, not only the last line. Reuse facts the client already gave.
- Read the last assistant message. If it offered something or asked to confirm, interpret the user
  reply with normal language understanding (accept / refuse / new topic / supply data / question)
  in ANY language.
- If the user asks a question or clarifying intent (payment name, price, ID format, etc.): ANSWER it.
  Do NOT treat a question as buy/order confirmation.
- On clear accept of that open offer: continue checkout for THAT offer. Do NOT restart availability
  search, re-pitch, or deny it.
- On refuse or new topic: follow the new intent.

Reuse known facts (human memory — never blank re-ask):
- Before asking phone / wilaya / address / game ID / player_id / zone / quantity: scan recent
  user messages + Known checkout details in the system prompt + call get_customer / get_order
  (latest) if the profile or a prior purchase might already hold the field.
- If a value is already known: CONFIRM it warmly — show the value and ask permission to reuse.
  Examples: "نقدر نستعملو رقمك 0555…؟" / "نفس الـ ID تاع المرة اللي فاتت … ولا تبدلو؟" /
  "نفس العنوان / الولاية؟". Make them feel remembered.
- After they accept, reuse it in recap / create_order / digital_fulfillment / ai_notes.
  If they give a new value, use the new one.
- Forbidden: blank "عطيني رقمك" / "واش الـ ID" / "ولاية" when we already have that field.

Checkout ladder (STRICT — one question at a time):
1. Quantity / which pack clear (skip if already chosen in recent chat).
2. Decide kind first (get_product.type when catalog; else game/pass/diamonds/top-up/post offer = digital):
   - DIGITAL: NEVER ask wilaya / بلدية / address / delivery_type.
     Collect ONLY missing phone + fulfillment IDs (digital.fulfillment_fields / metadata when set;
     else Identity + judgment: player_id, zone_id, game ID, etc.).
     If phone/IDs known from prior top-up: confirm them first.
   - PHYSICAL only: phone → wilaya → delivery_type (home|stopdesk) → address if home —
     CONFIRM steps already known from profile/prior order instead of blank ask.
     NEVER ask for payment before phone + delivery destination are collected.
3. Confirm price only. Do NOT send Flexy/BaridiMob/CCP payment numbers yet.
4. Recap order using known values (item, price, phone, IDs or delivery) and get explicit confirmation.
5. Only then create_order (catalog: product_id; post offer: product_name + unit_price + digital_fulfillment).
   Pass ai_notes with a short owner-facing summary (game ID, zone, address — whatever the shop needs to fulfill).
6. ONLY AFTER create_order succeeds: order number → payment_methods from result or list_payment_methods →
   recommend priority-1 with details → soft "pay then we process ASAP" closer.
   If client prefers another enabled method, share that; never invent disabled methods.
   Forbidden: payment instructions before create_order; claiming shipped/fulfilled/topped-up
   unless get_order status is shipped or delivered (owner marks that on the Orders dashboard);
   attach receipt / send screenshot / waiting for photo / open ticket.
- Payment receipt / "ani rsltlk receipt": thank the client + say we will VERIFY (pending only).
  Call get_order. NEVER say رانا شحنالك / topped up / done until status is shipped or delivered.
- Never create_order on bare acceptance, questions, or while fields are missing.
- If create_order returns missing/error, ask for the field — never deny the prior offer; never invent wilaya for digital.

Exhaustive availability plan (NEVER say unavailable first — unavailable is LAST RESORT):
For any product/pack/offer/pass/game/top-up/"3ndkm"/"do you have" question, check ALL sources before denying:
1. ask_identity_agent — ask about THAT item/offer by name.
2. knowledge_search with the customer's keywords.
3. list_recent_posts with limit=5 — READ captions; live page promos count as available offers.
4. search_products + list_categories — catalog IS a full availability source.
   Prefer short product keywords. If count=0: retry shorter query or empty query to list SKUs
   (follow tool hint). One empty long query ≠ unavailable.
   Empty catalog alone is NOT enough to deny if Identity or recent posts mention it.
Only after all four give no signal may you say you will check / ask a human —
still prefer a clarifying close over a hard "ما عندناش".

Critical:
- If Identity OR recent posts OR catalog mention the product/offer/category, NEVER say unavailable — sell/close.
- Never invent prices or stock; use get_product / get_product_stock when the catalog hit needs detail.
- Comments: short. DMs: closer question OK.
- Human / angry / refund → handoff_to_human.
"""


class CustomerAgentService:
    def __init__(
        self,
        runtime: AgentToolRuntime | None = None,
        sessions: SessionStore | None = None,
        customer_approver: CustomerApprover | None = None,
        metacognition: MetacognitionGate | None = None,
    ) -> None:
        self.runtime = runtime or AgentToolRuntime()
        self.sessions = sessions or SessionStore()
        self.settings = get_settings()
        self.customer_approver = customer_approver or CustomerApprover(
            llm=self.runtime.llm, knowledge=self.runtime.knowledge
        )
        self.metacognition = metacognition or MetacognitionGate(llm=self.runtime.llm)

    async def turn(self, request: CustomerTurnRequest, tenant: dict[str, Any]) -> AgentResponse:
        if not request.allow_reply:
            return AgentResponse(reply="", runtime="sk")

        with activity().turn(
            "CustomerAgent",
            business_id=tenant.get("business_id"),
            correlation_id=str(tenant.get("correlation_id") or ""),
            extra={
                "conversation_id": request.conversation_id,
                "message_id": request.message_id,
                "social_account_id": request.social_account_id,
                "message_type": request.message_type,
            },
        ):
            return await self._turn_inner(request, tenant)

    async def _turn_inner(self, request: CustomerTurnRequest, tenant: dict[str, Any]) -> AgentResponse:
        session_ref = str(request.conversation_id)
        session = self.sessions.get_or_create(tenant["business_id"], "customer", session_ref)

        media_note = await preprocess_media_safe(request, self.runtime.llm)

        system = request.system_prompt or DEFAULT_CUSTOMER_SYSTEM
        messages: list[dict[str, Any]] = [{"role": "system", "content": system}]
        for row in request.history[-24:]:
            messages.append({"role": row.role, "content": row.content})

        user_text = (request.text or "").strip()
        if media_note:
            user_text = f"{user_text}\n\n[media understanding]\n{media_note}".strip()
        if not user_text:
            user_text = "[empty inbound message]"
        messages.append({"role": "user", "content": user_text})
        self.sessions.append_message(session, "user", user_text)

        catalog = self.runtime.tools_for_surface("customer", CUSTOMER_TOOLS)
        tools = ensure_customer_rag_tools(
            self.runtime.filter_tools(catalog, request.tool_allowlist or None),
            catalog,
        )
        activity().input(
            "1) CustomerAgent receives inbound message",
            {
                "text": request.text,
                "media_note": media_note,
                "history_tail": [{"role": r.role, "content": r.content} for r in request.history[-10:]],
                "tools_catalog": [(t.get("function") or {}).get("name") for t in catalog],
                "tools_available": [(t.get("function") or {}).get("name") for t in tools],
            },
        )

        run_tenant = {**tenant, "surface": "customer"}
        run_context = {
            "message_id": request.message_id,
            "conversation_id": request.conversation_id,
            "customer_id": request.customer_id,
            "social_account_id": request.social_account_id,
            "message_type": request.message_type,
        }
        result = await self.runtime.run(
            messages=messages,
            tools=tools,
            tenant=run_tenant,
            model=request.llm_model,
            context=run_context,
        )

        evidence = self._build_evidence(
            user_text,
            result,
            history=request.history,
            known_checkout=list(request.known_checkout or []),
        )
        draft = str(result.get("reply") or "")
        usage_raw = dict(result.get("usage") or {})
        tool_calls = list(result.get("tool_calls") or [])
        citations = list(result.get("citations") or [])
        reply = draft
        activity().agent_answer("CustomerAgent", draft, title="2) CustomerAgent DRAFT (before CustomerApprover)")

        if self.settings.metacognition_enabled and draft.strip():
            async def revise(feedback: str, previous: str) -> dict[str, Any]:
                """Re-run the full tool loop so Identity/catalog can fix grounding failures."""
                activity().input(
                    "3b) CustomerAgent RE-TOOL after CustomerApprover reject",
                    {"feedback": feedback, "previous_draft": previous},
                )
                revise_messages = list(messages) + [
                    {
                        "role": "assistant",
                        "content": previous,
                    },
                    {
                        "role": "user",
                        "content": (
                            "CustomerApprover rejected your draft. Fix it with tools — do NOT invent.\n"
                            f"Feedback: {feedback}\n"
                            "Speak as the shop in FIRST PERSON (we/عندنا). Never عندهم/this shop/they/"
                            "لقينا/we found.\n"
                            "If the user asked a question / clarifying intent: answer it; do not treat "
                            "it as order confirmation.\n"
                            "Digital offers (pass/diamonds/top-up): NEVER ask wilaya/بلدية/address.\n"
                            "If phone / game ID / wilaya / address is already in known_from_prior_order "
                            "or known_from_chat: CONFIRM it warmly (show the value + ask to reuse) — "
                            "never blank re-ask as if you forgot them.\n"
                            "Before create_order: recap the order and get explicit confirmation.\n"
                            "If the prior turn offered something and the user clearly accepted: "
                            "continue checkout — ask the next missing field; do not deny or re-search.\n"
                            "If buy/availability intent: confirm + CLOSE with which pack/offer they want.\n"
                            "Unavailable is LAST RESORT. Before denying check ALL of: "
                            "ask_identity_agent (item/offer by name), knowledge_search, "
                            "list_recent_posts(limit=5), AND search_products/list_categories — "
                            "catalog is a full availability source too, not only price.\n"
                            "Empty catalog alone ≠ we don't sell it if posts/Identity mention it.\n"
                            "Return only the customer-facing reply after tools."
                        ),
                    },
                ]
                re_run = await self.runtime.run(
                    messages=revise_messages,
                    tools=tools,
                    tenant=run_tenant,
                    model=request.llm_model,
                    context={**run_context, "customer_revise": True},
                )
                new_reply = str(re_run.get("reply") or previous)
                # Merge tool evidence for the next Approver round
                evidence.clear()
                evidence.extend(
                    self._build_evidence(
                        user_text,
                        re_run,
                        prior_draft=previous,
                        history=request.history,
                        known_checkout=list(request.known_checkout or []),
                    )
                )
                for call in re_run.get("tool_calls") or []:
                    tool_calls.append(call)
                for hit in re_run.get("citations") or []:
                    if isinstance(hit, dict):
                        citations.append(hit)
                return {
                    "reply": new_reply,
                    "usage": re_run.get("usage") or {},
                    "tool_calls": list(re_run.get("tool_calls") or []),
                }

            gated = await self.customer_approver.review_until_approved(
                draft_reply=draft,
                customer_text=user_text,
                evidence=evidence,
                revise_fn=revise,
                business_id=int(tenant["business_id"]),
                model=request.llm_model,
            )
            # Belt-and-suspenders: never leak a fulfillment claim without owner status proof.
            reply = self.customer_approver._safe_pending_verify_reply(
                draft=str(gated.get("reply") or draft),
                evidence=evidence,
            )
            activity().section(
                "3) CustomerApprover VERDICT",
                {
                    "approver": "CustomerApprover",
                    "approved": gated.get("approved"),
                    "retried": gated.get("retried"),
                    "needs_human_review": gated.get("needs_human_review"),
                    "rounds": gated.get("rounds"),
                    "review": gated.get("review"),
                },
                kind="APPROVER",
            )
            g_usage = gated.get("usage") or {}
            usage_raw["prompt_tokens"] = int(usage_raw.get("prompt_tokens") or 0) + int(
                g_usage.get("prompt_tokens") or 0
            )
            usage_raw["completion_tokens"] = int(usage_raw.get("completion_tokens") or 0) + int(
                g_usage.get("completion_tokens") or 0
            )
            usage_raw["fal_calls"] = int(usage_raw.get("fal_calls") or 0) + int(g_usage.get("fal_calls") or 0)
            usage_raw["calls_with_cost"] = int(usage_raw.get("calls_with_cost") or 0) + int(
                g_usage.get("fal_calls") or 0
            )
            tool_calls.append(
                {
                    "tool": "customer_approver",
                    "arguments": {},
                    "result": {
                        "approver": "CustomerApprover",
                        "approved": gated.get("approved"),
                        "retried": gated.get("retried"),
                        "needs_human_review": gated.get("needs_human_review"),
                        "rounds": gated.get("rounds"),
                    },
                }
            )

        self.sessions.append_message(session, "assistant", reply)
        self.sessions.save(session)
        activity().agent_answer(
            "CustomerAgent",
            reply,
            title="4) CustomerAgent FINAL ANSWER → client",
        )

        return AgentResponse(
            reply=reply,
            tool_calls=tool_calls,
            usage=UsagePayload(
                prompt_tokens=int(usage_raw.get("prompt_tokens") or 0),
                completion_tokens=int(usage_raw.get("completion_tokens") or 0),
                cost_usd=float(usage_raw.get("cost_usd") or 0),
                fal_calls=int(usage_raw.get("fal_calls") or 0),
                calls_with_cost=int(usage_raw.get("calls_with_cost") or 0),
            ),
            session_id=str(session["id"]),
            citations=citations,
            runtime="sk",
        )

    @staticmethod
    def _build_evidence(
        user_text: str,
        result: dict[str, Any],
        *,
        prior_draft: str | None = None,
        history: list[Any] | None = None,
        known_checkout: list[str] | None = None,
    ) -> list[str]:
        evidence: list[str] = [user_text]
        if known_checkout:
            cleaned = [str(f).strip() for f in known_checkout if str(f).strip()]
            if cleaned:
                evidence.append("known_from_prior_order: " + "; ".join(cleaned))
        if history:
            known = CustomerAgentService._known_facts_from_history(history)
            if known:
                evidence.append("known_from_chat: " + "; ".join(known))
            for row in list(history)[-12:]:
                role = getattr(row, "role", None) or (row.get("role") if isinstance(row, dict) else "")
                content = getattr(row, "content", None) or (
                    row.get("content") if isinstance(row, dict) else ""
                )
                if role and content:
                    evidence.append(f"history_{role}: {str(content)[:600]}")
        if prior_draft:
            evidence.append(f"prior_draft: {prior_draft[:800]}")
        for hit in result.get("citations") or []:
            if isinstance(hit, dict) and hit.get("content"):
                evidence.append(str(hit["content"])[:1500])
        for call in result.get("tool_calls") or []:
            tool = str(call.get("tool") or call.get("name") or "")
            raw_res = call.get("result")
            if isinstance(raw_res, dict):
                ans = str(raw_res.get("answer") or "")
                if tool == "ask_identity_agent" and ans:
                    evidence.append(f"identity: {ans[:1600]}")
                    continue
                if tool == "get_customer":
                    evidence.append(f"customer_profile: {str(raw_res)[:1200]}")
                    known = CustomerAgentService._known_facts_from_customer_payload(raw_res)
                    if known:
                        evidence.append("known_from_profile: " + "; ".join(known))
                    continue
                if tool == "get_order":
                    evidence.append(str(raw_res)[:1500])
                    known = CustomerAgentService._known_facts_from_order_payload(raw_res)
                    if known:
                        evidence.append("known_from_prior_order: " + "; ".join(known))
                    continue
                evidence.append(str(raw_res)[:1500])
            else:
                evidence.append(str(raw_res or "")[:1500])
        return evidence

    @staticmethod
    def _known_facts_from_customer_payload(payload: dict[str, Any]) -> list[str]:
        facts: list[str] = []
        rows = payload.get("customers") if isinstance(payload.get("customers"), list) else [payload]
        for row in rows:
            if not isinstance(row, dict):
                continue
            known = row.get("known_checkout") if isinstance(row.get("known_checkout"), dict) else row
            phone = known.get("phone") or row.get("phone")
            wilaya = known.get("wilaya") or row.get("wilaya")
            commune = known.get("commune") or row.get("commune")
            address = known.get("address")
            if phone:
                facts.append(f"phone={phone}")
            if wilaya:
                facts.append(f"wilaya={wilaya}")
            if commune:
                facts.append(f"commune={commune}")
            if address:
                facts.append(f"address={address}")
            digital = known.get("digital_fulfillment") if isinstance(known.get("digital_fulfillment"), dict) else {}
            for key, value in digital.items():
                if value:
                    facts.append(f"{key}={value}")
                    if re.search(r"(player|game|account|user)?_?id|zone", str(key), re.I):
                        facts.append(f"game_or_player_id={value}")
        return facts

    @staticmethod
    def _known_facts_from_order_payload(payload: dict[str, Any]) -> list[str]:
        order = payload.get("order") if isinstance(payload.get("order"), dict) else payload
        if not isinstance(order, dict):
            return []
        facts: list[str] = []
        for key in ("phone", "wilaya", "commune", "address"):
            if order.get(key):
                facts.append(f"{key}={order[key]}")
        meta = order.get("metadata") if isinstance(order.get("metadata"), dict) else {}
        digital = meta.get("digital_fulfillment") if isinstance(meta.get("digital_fulfillment"), dict) else {}
        for key, value in digital.items():
            if value:
                facts.append(f"{key}={value}")
                if re.search(r"(player|game|account|user)?_?id|zone", str(key), re.I):
                    facts.append(f"game_or_player_id={value}")
        if order.get("order_number"):
            facts.append(f"prior_order_number={order['order_number']}")
        return facts

    @staticmethod
    def _known_facts_from_history(history: list[Any]) -> list[str]:
        """Extract checkout fields the client already supplied so Approver/agent reuse them."""
        facts: list[str] = []
        phones: list[str] = []
        game_ids: list[str] = []
        pending_id_ask = False
        phone_re = re.compile(r"(?:\+?213|0)[5-7]\d{8}")
        id_ask_re = re.compile(
            r"(id تاعك|الـ\s*id|game\s*id|player_s?id|zone_id|رقم الحساب|رقم اللاعب)",
            re.IGNORECASE,
        )

        for row in list(history)[-24:]:
            role = getattr(row, "role", None) or (row.get("role") if isinstance(row, dict) else "")
            content = str(
                getattr(row, "content", None) or (row.get("content") if isinstance(row, dict) else "")
            ).strip()
            if not content:
                continue
            role_l = str(role).lower()
            if role_l == "assistant":
                pending_id_ask = bool(id_ask_re.search(content))
                continue
            if role_l != "user":
                continue
            for match in phone_re.findall(content):
                phones.append(match)
            digits = re.fullmatch(r"\d{5,}", content.replace(" ", ""))
            if digits and pending_id_ask:
                game_ids.append(digits.group(0))
                pending_id_ask = False
            elif digits and not phone_re.search(content) and len(digits.group(0)) >= 6:
                # Bare numeric reply often is game/player ID in digital shops.
                game_ids.append(digits.group(0))

        if phones:
            facts.append("phone=" + phones[-1])
        if game_ids:
            facts.append("game_or_player_id=" + game_ids[-1])
        return facts


async def preprocess_media_safe(request: CustomerTurnRequest, llm: Any) -> str | None:
    from app.agents.multimodal import preprocess_media

    return await preprocess_media(
        media_url=request.media_url,
        media_type=request.media_type,
        llm=llm,
    )
