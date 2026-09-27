from __future__ import annotations

from typing import Any
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.agents.customer import CustomerAgentService
from app.agents.customer_approver import CustomerApprover
from app.agents.registry import AgentRegistry
from app.agents.tool_runtime import (
    CUSTOMER_TOOLS,
    AgentToolRuntime,
    ensure_customer_rag_tools,
)
from app.models.schemas import CustomerTurnRequest
from tests.test_agent_as_tool import FakeIdentity


def test_ensure_customer_rag_tools_survives_mcp_allowlist():
    """Laravel allowlist strips A2A tools; merge must put them back."""
    catalog = list(CUSTOMER_TOOLS) + [
        {
            "type": "function",
            "function": {
                "name": "ask_identity_agent",
                "description": "Ask BusinessIdentityAgent",
                "parameters": {
                    "type": "object",
                    "properties": {"question": {"type": "string"}},
                    "required": ["question"],
                },
            },
        }
    ]
    # Simulate Laravel MCP allowlist (no identity / knowledge)
    filtered = AgentToolRuntime().filter_tools(
        catalog,
        ["search_products", "get_product", "list_categories", "handoff_to_human"],
    )
    names = {(t.get("function") or {}).get("name") for t in filtered}
    assert "ask_identity_agent" not in names
    assert "knowledge_search" not in names

    merged = ensure_customer_rag_tools(filtered, catalog)
    merged_names = {(t.get("function") or {}).get("name") for t in merged}
    assert "ask_identity_agent" in merged_names
    assert "knowledge_search" in merged_names
    assert "list_recent_posts" in merged_names
    assert "search_products" in merged_names
    assert "list_categories" in merged_names


def test_customer_tools_include_list_categories():
    names = {(t.get("function") or {}).get("name") for t in CUSTOMER_TOOLS}
    assert "list_categories" in names
    assert "list_wilayas" in names
    assert "get_product_stock" in names


@pytest.mark.asyncio
async def test_customer_approver_rejects_third_person_shop_voice():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="أهلاً! Dias Zone تشحن MLBB وعندهم شحن فوري. شوف موقعهم.",
        customer_text="nhws nchhan mobile legends",
        evidence=["identity: We sell MLBB top-ups."],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "third_person_shop_voice" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_searcher_voice():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="لقينا بلي كاين Weekly Pass عندنا. واش حاب؟",
        customer_text="khsni weekly pass",
        evidence=["identity: We promote weekly pass."],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "searcher_discovery_voice" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_denial_after_create_order_fail():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="للأسف العرض ما عندناش حاليا.",
        customer_text="yes please",
        evidence=[
            "history_assistant: عندنا Weekly Pass. واش نكملو الطلب؟",
            '{"ok": false, "error": "Product not found."}',
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "order_tool_fail_must_not_deny_offer" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_arabizi_ask_without_recent_posts():
    """Arabizi 'm3ndkmch' must force exhaustive availability checks including list_recent_posts."""
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="للأسف خويا، ما عندناش باقة 55 داياموند بالضبط.",
        customer_text="m3ndkmch 55 diamond?",
        evidence=['{"products": [], "count": 0}'],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "missing_exhaustive_availability_checks" in result["reasons"]
    assert "list_recent_posts" in result["feedback"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_wilaya_ask_on_digital_checkout():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="دروك نحتاج عنوان التوصيل (الولاية والبلدية) باش نكملو الطلب.",
        customer_text="بالذهبية يخي",
        evidence=[
            "history_assistant: دروك نأكدلك الطلب: 2 Weekly Diamond Pass بسعر 802 دج.",
            "history_user: 62828627",
            "digital_fulfillment game_id server_id",
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "digital_checkout_must_not_ask_shipping" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_denial_from_history_without_tools():
    """Repeating 'not available' from prior turns without searching catalog must be rejected."""
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="للأسف خويا، الـ Weekly Pass ماشي متوفرة حاليا. كاش حاجة أخرى؟",
        customer_text="weekly mkach?",
        evidence=[
            "weekly mkach?",
            "history_assistant: للأسف خويا، الـ Weekly Pass تاني ماشي متوفرة عندنا دروك.",
            "history_user: ew l weekly 3ndkm?",
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "missing_exhaustive_availability_checks" in result["reasons"]
    assert "search_products" in result["feedback"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_order_without_payment_closer():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="تم الطلب خويا! رقم الطلب ORD-2026-ABCDE. شكراً!",
        customer_text="oui",
        evidence=[
            "{'ok': True, 'order': {'order_number': 'ORD-2026-ABCDE'}, "
            "'payment_methods': [{'method': 'flexy', 'priority': 1, 'phone': '0555', 'recommended': True}], "
            "'payment_recommended': {'method': 'flexy'}}"
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "missing_post_order_payment_closer" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_receipt_attachment_phrasing():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="بعثلي screenshot تاع الـ receipt باش نأكدو الدفع.",
        customer_text="خلصت",
        evidence=["payment_methods"],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "forbidden_receipt_attachment_phrasing" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_payment_before_create_order():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="تقدر تخلص عن طريق BaridiMob على الرقم 00799999002588580909.",
        customer_text="واخا",
        evidence=["history_assistant: واش الـ ID تاعك؟", "history_user: واخا"],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "payment_before_order_fields" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_premature_fulfillment_on_id():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="شكرا! رانا شحنالك الـ Weekly Elite لـ ID تاعك 36728629.",
        customer_text="36728629",
        evidence=["history_assistant: ممكن تعطينا الـ ID تاعك في اللعبة؟"],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "premature_fulfillment_claim" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_verify_and_fulfill_contradiction():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    draft = (
        "صحيت! رانا استلمنا إيصال الدفع. دوك نتحققو منو.\n\n"
        "رانا شحنالك 2 Weekly Elite Bundle لـ ID تاعك 36728629."
    )
    result = await approver.review(
        draft_reply=draft,
        customer_text="[image receipt]",
        evidence=[
            "known_from_chat: game_or_player_id=36728629",
            "history_user: 36728629",
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "verify_and_fulfill_contradiction" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_soft_fail_strips_fulfillment_claim():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )

    async def revise(_feedback, _draft):
        return {
            "reply": (
                "صحيت! رانا استلمنا إيصال الدفع. دوك نتحققو منو.\n\n"
                "رانا شحنالك 2 Weekly Elite Bundle لـ ID تاعك 36728629."
            ),
            "usage": {},
        }

    gated = await approver.review_until_approved(
        draft_reply="رانا شحنالك Weekly Elite.",
        customer_text="[image receipt]",
        evidence=["history_user: paid"],
        revise_fn=revise,
        business_id=1,
        max_rounds=2,
    )
    assert gated["approved"] is False
    assert "شحنالك" not in gated["reply"]
    assert "قيد المراجعة" in gated["reply"]


@pytest.mark.asyncio
async def test_customer_approver_allows_fulfillment_when_get_order_shipped():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="رانا شحنالك الـ Weekly Elite. كي يوصلوك علمنا!",
        customer_text="واش صرا؟",
        evidence=['get_order: {"ok": true, "status": "shipped", "order_number": "ORD-2026-ABC"}'],
        business_id=1,
    )
    assert result["decision"] == "approved"


@pytest.mark.asyncio
async def test_customer_approver_rejects_reask_known_game_id():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="ممكن تعطينا الـ ID تاعك في اللعبة؟",
        customer_text="واخا نكملو",
        evidence=[
            "known_from_chat: game_or_player_id=36728629",
            "history_assistant: ممكن تعطينا الـ ID تاعك في اللعبة؟",
            "history_user: 36728629",
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "reask_known_game_id" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_allows_confirm_known_phone():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="نقدر نستعملو رقمك 0555123456 ولا تبدلو؟",
        customer_text="واخا نكملو",
        evidence=[
            "known_from_prior_order: phone=0555123456; game_or_player_id=36728629",
        ],
        business_id=1,
    )
    assert result["decision"] == "approved"


@pytest.mark.asyncio
async def test_customer_approver_rejects_blank_phone_when_prior_order_known():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="عطيني رقمك باش نكملو الطلب",
        customer_text="hab nchhan",
        evidence=[
            "known_from_prior_order: phone=0555123456; wilaya=Biskra",
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "reask_known_phone" in result["reasons"]
    assert "CONFIRM" in result["feedback"] or "نقدر نستعملو" in result["feedback"]


@pytest.mark.asyncio
async def test_customer_approver_allows_confirm_known_game_id_from_prior_order():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":1,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="نفس الـ ID تاع المرة اللي فاتت 36728629 ولا تبدلو؟",
        customer_text="weekly elite",
        evidence=[
            "known_from_prior_order: game_or_player_id=36728629; player_id=36728629",
        ],
        business_id=1,
    )
    assert result["decision"] == "approved"


def test_known_facts_from_history_extracts_phone_and_id():
    from types import SimpleNamespace

    history = [
        SimpleNamespace(role="assistant", content="عطيني رقمك"),
        SimpleNamespace(role="user", content="0555123456"),
        SimpleNamespace(role="assistant", content="واش الـ ID تاعك؟"),
        SimpleNamespace(role="user", content="36728629"),
    ]
    facts = CustomerAgentService._known_facts_from_history(history)
    assert "phone=0555123456" in facts
    assert "game_or_player_id=36728629" in facts


def test_create_order_tool_schema_supports_digital_and_post_offer():
    tool = next(t for t in CUSTOMER_TOOLS if (t.get("function") or {}).get("name") == "create_order")
    props = ((tool.get("function") or {}).get("parameters") or {}).get("properties") or {}
    assert "product_id" in props
    assert "product_name" in props
    assert "unit_price" in props
    assert "digital_fulfillment" in props
    assert "delivery_type" in props
    assert "ai_notes" in props


@pytest.mark.asyncio
async def test_customer_approver_rejects_missing_sales_close():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )
    result = await approver.review(
        draft_reply="نعم عندنا شحن Mobile Legends فوري وآمن. www.diaszone.com",
        customer_text="hab nchhan mobile legends",
        evidence=["identity: We sell MLBB top-ups."],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "missing_sales_close" in result["reasons"]


@pytest.mark.asyncio
async def test_customer_approver_rejects_mlbb_unavailable_against_identity():
    approver = CustomerApprover()
    # LLM would rubber-stamp; force-reject must fire first
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )

    result = await approver.review(
        draft_reply="Sorry, Mobile Legends top-up is not available in our shop.",
        customer_text="hab nchhan mobile legends top up",
        evidence=[
            "identity: Online game top-up shop. We sell Mobile Legends (MLBB) diamonds and "
            "other game recharges. Tone friendly Darija."
        ],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "identity_contradicts_unavailability" in result["reasons"]
    approver.llm.chat_completion.assert_not_awaited()


@pytest.mark.asyncio
async def test_customer_approver_rejects_denial_without_identity_consult():
    approver = CustomerApprover()
    approver.llm = MagicMock()
    approver.llm.chat_completion = AsyncMock(
        return_value={
            "content": '{"decision":"approved","score":0.9,"reasons":["ok"],"feedback":""}',
            "usage": {},
        }
    )

    result = await approver.review(
        draft_reply="This product is not available.",
        customer_text="do you have mobile legends top up?",
        evidence=['{"products":[]}'],
        business_id=1,
    )
    assert result["decision"] == "rejected"
    assert "missing_exhaustive_availability_checks" in result["reasons"] or (
        "missing_identity_before_denial" in result["reasons"]
    )


@pytest.mark.asyncio
async def test_customer_turn_retools_after_approver_reject(monkeypatch):
    """Reject path must re-run the tool loop (Identity), not text-only rewrite."""
    reg = AgentRegistry()
    fake = FakeIdentity(
        answer="We sell Mobile Legends (MLBB) game top-ups and diamond packs."
    )
    reg.register(fake, tool_name="ask_identity_agent")

    llm = MagicMock()
    # Pass 1: search_products only → false denial
    # Pass 2 (re-tool): ask_identity then good reply
    llm.chat_completion = AsyncMock(
        side_effect=[
            {
                "content": "",
                "tool_calls": [
                    {
                        "id": "tc_sp",
                        "type": "function",
                        "function": {
                            "name": "search_products",
                            "arguments": '{"query":"mobile legends"}',
                        },
                    }
                ],
                "usage": {"prompt_tokens": 5, "completion_tokens": 2},
            },
            {
                "content": "Sorry, Mobile Legends is not available.",
                "tool_calls": [],
                "usage": {"prompt_tokens": 8, "completion_tokens": 6},
            },
            # revise re-tool pass
            {
                "content": "",
                "tool_calls": [
                    {
                        "id": "tc_id",
                        "type": "function",
                        "function": {
                            "name": "ask_identity_agent",
                            "arguments": '{"question":"Do we sell Mobile Legends top-ups?"}',
                        },
                    }
                ],
                "usage": {"prompt_tokens": 10, "completion_tokens": 3},
            },
            {
                "content": "Oui! On fait top-up Mobile Legends. Wesh pack / diamonds bghiti?",
                "tool_calls": [],
                "usage": {"prompt_tokens": 12, "completion_tokens": 10},
            },
        ]
    )

    laravel = MagicMock()
    laravel.invoke_tool = AsyncMock(return_value={"ok": True, "products": []})

    runtime = AgentToolRuntime(llm=llm, registry=reg, laravel=laravel)
    service = CustomerAgentService(runtime=runtime)
    service.settings.metacognition_enabled = True
    service.settings.metacognition_max_retries = 1

    # Approver: reject first denial (force), approve second grounded reply
    review_n = {"n": 0}

    async def fake_review(**kwargs):
        review_n["n"] += 1
        draft = kwargs.get("draft_reply") or ""
        if "not available" in draft.lower():
            return {
                "decision": "rejected",
                "score": 0.1,
                "reasons": ["identity_contradicts_unavailability"],
                "feedback": "Call ask_identity_agent; do not deny game top-ups.",
                "usage": {"prompt_tokens": 1, "completion_tokens": 1},
                "approver": "CustomerApprover",
            }
        return {
            "decision": "approved",
            "score": 0.9,
            "reasons": ["grounded"],
            "feedback": "",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
            "approver": "CustomerApprover",
        }

    monkeypatch.setattr(service.customer_approver, "review", fake_review)

    req = CustomerTurnRequest(
        message_id=1,
        conversation_id=100,
        text="hab nchhan mobile legends",
        tool_allowlist=["search_products", "get_product", "list_categories"],
    )
    out = await service.turn(req, {"business_id": 9, "correlation_id": "test"})

    assert "not available" not in out.reply.lower()
    assert "Mobile Legends" in out.reply or "top-up" in out.reply.lower() or "pack" in out.reply.lower()
    assert any(c.get("tool") == "ask_identity_agent" for c in out.tool_calls)
    assert fake.calls
    assert review_n["n"] >= 2
