from __future__ import annotations

import json

import pytest

from app.middleware.metacognition import MetacognitionGate
from app.workflows.caption_review_loop import CaptionReviewLoop, looks_like_post_intent
from app.workflows.publish import PublishWorkflow


def test_looks_like_post_intent():
    assert looks_like_post_intent("create a post about ramadan")
    assert looks_like_post_intent("كتبلي كابشن للمنشور")
    assert not looks_like_post_intent("what is my wallet balance?")


@pytest.mark.asyncio
async def test_caption_loop_approves_first_pass(monkeypatch):
    loop = CaptionReviewLoop()

    async def fake_draft(**kwargs):
        return {"caption": "مرحبا بكم في المحل", "usage": {"prompt_tokens": 1, "completion_tokens": 1}}

    async def fake_review(**kwargs):
        return {
            "decision": "approved",
            "score": 0.9,
            "reasons": ["tone_ok"],
            "feedback": "",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(loop.writer, "draft", fake_draft)
    monkeypatch.setattr(loop.approver, "review", fake_review)

    session = {"state": {}}
    result = await loop.run(business_id=1, owner_brief="make a post", session=session)
    assert result["approved"] is True
    assert result["needs_owner_edit"] is False
    assert session["state"]["approved_caption"] == "مرحبا بكم في المحل"
    assert session["state"]["publish_step"] == "caption_approved"
    assert len(result["rounds"]) == 1


@pytest.mark.asyncio
async def test_caption_loop_reject_then_approve(monkeypatch):
    loop = CaptionReviewLoop()
    calls = {"n": 0}

    async def fake_draft(**kwargs):
        calls["n"] += 1
        return {
            "caption": f"draft-{calls['n']}",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    async def fake_review(**kwargs):
        if kwargs["caption"] == "draft-1":
            return {
                "decision": "rejected",
                "score": 0.3,
                "reasons": ["weak_tone"],
                "feedback": "Make it warmer",
                "usage": {"prompt_tokens": 1, "completion_tokens": 1},
            }
        return {
            "decision": "approved",
            "score": 0.85,
            "reasons": ["ok"],
            "feedback": "",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(loop.writer, "draft", fake_draft)
    monkeypatch.setattr(loop.approver, "review", fake_review)

    session = {"state": {}}
    result = await loop.run(business_id=1, owner_brief="post please", session=session)
    assert result["approved"] is True
    assert result["caption"] == "draft-2"
    assert len(result["rounds"]) == 2


@pytest.mark.asyncio
async def test_caption_loop_max_rounds(monkeypatch):
    loop = CaptionReviewLoop()
    loop.settings.caption_approver_max_rounds = 2

    async def fake_draft(**kwargs):
        return {"caption": "x", "usage": {"prompt_tokens": 1, "completion_tokens": 1}}

    async def fake_review(**kwargs):
        return {
            "decision": "rejected",
            "score": 0.1,
            "reasons": ["no"],
            "feedback": "try again",
            "usage": {"prompt_tokens": 1, "completion_tokens": 1},
        }

    monkeypatch.setattr(loop.writer, "draft", fake_draft)
    monkeypatch.setattr(loop.approver, "review", fake_review)

    session = {"state": {}}
    result = await loop.run(business_id=1, owner_brief="caption", session=session)
    assert result["approved"] is False
    assert result["needs_owner_edit"] is True
    assert len(result["rounds"]) == 2
    assert session["state"]["approved_caption"] == "x"


def test_prepare_social_post_blocked_without_approval():
    gate = MetacognitionGate()
    gate.settings.metacognition_enabled = True
    err = gate.check_prepare_social_post({"caption": "hi"}, {})
    assert err is not None
    assert err["error"] == "caption_not_approved"


def test_prepare_social_post_allows_matching_approved():
    gate = MetacognitionGate()
    gate.settings.metacognition_enabled = True
    err = gate.check_prepare_social_post(
        {"caption": "Hello shop"},
        {"approved_caption": "Hello shop"},
    )
    assert err is None


def test_publish_workflow_instructions_include_review_states():
    wf = PublishWorkflow()
    text = wf.instruction_for_state({"publish_step": "caption_approved", "approved_caption": "ABC"})
    assert "caption_approved" in text
    assert "ABC" in text
