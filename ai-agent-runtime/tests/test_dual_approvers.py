from __future__ import annotations

import json

import pytest

from app.agents.customer_approver import CustomerApprover
from app.agents.owner_approver import OwnerApprover


@pytest.mark.asyncio
async def test_owner_approver_approve(monkeypatch):
    approver = OwnerApprover()

    async def fake_review(**kwargs):
        return {
            "decision": "approved",
            "score": 0.9,
            "reasons": ["ok"],
            "feedback": "",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(approver, "review", fake_review)

    async def revise(feedback, previous):
        return previous

    result = await approver.review_until_approved(
        draft="Here is your schedule.",
        business_id=1,
        owner_brief="list posts",
        revise_fn=revise,
        max_rounds=2,
    )
    assert result["approved"] is True
    assert result["draft"] == "Here is your schedule."


@pytest.mark.asyncio
async def test_owner_approver_reject_then_approve(monkeypatch):
    approver = OwnerApprover()
    calls = {"n": 0}

    async def fake_review(**kwargs):
        calls["n"] += 1
        if calls["n"] == 1:
            return {
                "decision": "rejected",
                "score": 0.2,
                "reasons": ["claim"],
                "feedback": "remove invented price",
                "usage": {"prompt_tokens": 1, "completion_tokens": 1},
            }
        return {
            "decision": "approved",
            "score": 0.8,
            "reasons": ["ok"],
            "feedback": "",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(approver, "review", fake_review)

    async def revise(feedback, previous):
        return {"reply": "Safe reply", "usage": {"prompt_tokens": 1, "completion_tokens": 1}}

    result = await approver.review_until_approved(
        draft="Buy now for 999DA invented",
        business_id=1,
        owner_brief="help",
        revise_fn=revise,
        max_rounds=2,
    )
    assert result["approved"] is True
    assert result["draft"] == "Safe reply"
    assert len(result["rounds"]) == 2


@pytest.mark.asyncio
async def test_owner_approver_soft_approves_when_identity_contradicts_dialect_guess(monkeypatch):
    """Approver must not invent a category mismatch that fights identity evidence."""
    approver = OwnerApprover()

    async def fake_llm_chat(messages, **kwargs):
        return {
            "content": json.dumps(
                {
                    "decision": "rejected",
                    "score": 0.65,
                    "reasons": ["Draft ignores food context — unusual mismatch for this shop"],
                    "feedback": "Ask about food posts; this doesn't match the catalog.",
                }
            ),
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    async def _async_empty(*a, **k):
        return ""

    monkeypatch.setattr(approver.llm, "chat_completion", fake_llm_chat)
    monkeypatch.setattr(approver, "_brand_bits", _async_empty)

    draft = "فهمت، بوستات جداد بنفس الستايل تاع قبل. واش نركزو على نفس العرض؟"
    brief = "ndiro posts jdod 3la wch drna m9bl fles post t3na lgdom nfs lconcept"

    result = await approver.review(
        draft=draft,
        business_id=20,
        owner_brief=brief,
        task_type="campaign_brief",
        evidence=[
            "identity: Online digital services shop. Tone friendly Darija. Sample posts about "
            "instant delivery of digital products — not restaurants or food."
        ],
    )
    assert result["decision"] == "approved"
    assert result.get("soft_corrected") is True


@pytest.mark.asyncio
async def test_owner_approver_brief_serves_draft_when_gate_fails(monkeypatch):
    approver = OwnerApprover()

    async def fake_review(**kwargs):
        return {
            "decision": "rejected",
            "score": 0.4,
            "reasons": ["strict"],
            "feedback": "rewrite",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(approver, "review", fake_review)

    async def revise(feedback, previous):
        return previous

    result = await approver.review_until_approved(
        draft="مرحبا! فهمت الستايل.",
        business_id=1,
        owner_brief="make posts",
        revise_fn=revise,
        task_type="campaign_brief",
        max_rounds=1,
    )
    assert result["approved"] is True
    assert "فهمت" in result["draft"]


@pytest.mark.asyncio
async def test_customer_approver_loop(monkeypatch):
    approver = CustomerApprover()

    async def fake_review(**kwargs):
        if "999" in kwargs["draft_reply"]:
            return {
                "decision": "rejected",
                "score": 0.1,
                "reasons": ["invented_price"],
                "feedback": "remove price",
                "usage": {"prompt_tokens": 1, "completion_tokens": 1},
            }
        return {
            "decision": "approved",
            "score": 0.9,
            "reasons": ["grounded"],
            "feedback": "",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(approver, "review", fake_review)

    async def revise(feedback, previous):
        return {"reply": "سعر يتحدد حسب المنتج", "usage": {"prompt_tokens": 1, "completion_tokens": 1}}

    result = await approver.review_until_approved(
        draft_reply="السعر 999 دج",
        customer_text="شحال الثمن؟",
        evidence=["no price in catalog"],
        revise_fn=revise,
        business_id=1,
        max_rounds=2,
    )
    assert result["approved"] is True
    assert "999" not in result["reply"]
    assert result["retried"] is True
