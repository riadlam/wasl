from __future__ import annotations

from pathlib import Path

from app.middleware.agent_activity import reset_activity_log_for_tests


def test_activity_log_writes_walkthrough(tmp_path: Path):
    path = tmp_path / "agent-activity.log"
    brief = tmp_path / "agent-activity-brief.log"
    log = reset_activity_log_for_tests(path, brief_path=brief)
    with log.turn("CustomerAgent", business_id=1, extra={"conversation_id": 9}):
        log.input("1) CustomerAgent receives inbound message", {"text": "prix?"})
        log.llm(
            model="test-model",
            messages=[
                {"role": "system", "content": "YOU ARE A LONG SYSTEM PROMPT " * 40},
                {"role": "user", "content": "prix?"},
            ],
            tools=[{"type": "function", "function": {"name": "ask_identity_agent"}}],
            response={
                "content": "",
                "tool_calls": [
                    {
                        "id": "1",
                        "function": {
                            "name": "ask_identity_agent",
                            "arguments": '{"question":"price?"}',
                        },
                    }
                ],
                "finish_reason": "tool_calls",
                "usage": {"prompt_tokens": 1, "completion_tokens": 1},
            },
        )
        log.tool("ask_identity_agent", {"question": "price?"}, {"answer": "unknown"})
        log.a2a("customer", "identity", "price?", {"answer": "COD 58 wilayas"})
        log.agent_answer(
            "CustomerAgent",
            "Je vérifie avec le shop.",
            title="4) FINAL ANSWER → client",
        )

    text = path.read_text(encoding="utf-8")
    assert "TURN START · [CustomerAgent]" in text
    assert "Step 01" in text
    assert "Step 02" in text
    assert "INPUT" in text
    assert "LLM-IN" in text
    assert "LLM-OUT" in text
    assert "TOOL ask_identity_agent" in text
    assert "A2A [CustomerAgent] → [BusinessIdentityAgent]" in text
    assert "[BusinessIdentityAgent] ANSWER" in text
    assert "ANSWER" in text
    assert "WHO:  CustomerAgent" in text
    assert "SAID:" in text
    assert "TURN END · [CustomerAgent]" in text
    assert "steps" in text
    # No uvicorn noise markers
    assert "INFO:     Uvicorn" not in text

    brief_text = brief.read_text(encoding="utf-8")
    assert "TURN-START" in brief_text
    assert "INPUT" in brief_text
    assert "LLM-IN" in brief_text
    assert "TOOL" in brief_text
    assert "A2A" in brief_text
    assert "TURN-END" in brief_text
    # Brief log must not dump the long system prompt
    assert "YOU ARE A LONG SYSTEM PROMPT" not in brief_text
    assert "system×1 omitted" in brief_text
    assert "ask_identity_agent" in brief_text
