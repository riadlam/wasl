from __future__ import annotations

import pytest

from app.agents.translation import TranslationAgent


@pytest.mark.asyncio
async def test_translation_agent_uses_shop_language_and_gemini(monkeypatch):
    agent = TranslationAgent()

    async def fake_lang(**kwargs):
        return {
            "ok": True,
            "language": "darija",
            "label": "Algerian Darija",
            "script_hint": "Algerian Darija",
            "source": "agent_settings",
        }

    async def fake_chat(messages, **kwargs):
        assert kwargs.get("model") == "google/gemini-2.5-flash" or "gemini" in str(kwargs.get("model") or "")
        return {
            "content": '{"texts":{"message":"شفت العرض","understanding":"Weekly Pass"},"language":"darija","label":"Algerian Darija"}',
            "usage": {"prompt_tokens": 2, "completion_tokens": 4},
        }

    monkeypatch.setattr(agent, "_resolve_language", fake_lang)
    monkeypatch.setattr(agent.llm, "chat_completion", fake_chat)

    out = await agent.translate(
        tenant={"business_id": 11, "user_id": 13},
        texts={
            "message": "I understand this for the tease",
            "understanding": "Weekly Pass flash sale",
        },
        model="google/gemini-2.5-flash",
    )
    assert out["texts"]["message"] == "شفت العرض"
    assert out["language"] == "darija"
    assert out["error"] is None
    assert out["agents"]["translator"] == "TranslationAgent"


@pytest.mark.asyncio
async def test_translation_consult_protocol(monkeypatch):
    agent = TranslationAgent()

    async def fake_translate(**kwargs):
        return {
            "texts": {"q": "مرحبا"},
            "language": "darija",
            "label": "Algerian Darija",
            "error": None,
        }

    monkeypatch.setattr(agent, "translate", fake_translate)
    out = await agent.consult("Hello", business_id=11, context={"user_id": 1})
    assert out["ok"] is True
    assert out["answer"] == "مرحبا"
