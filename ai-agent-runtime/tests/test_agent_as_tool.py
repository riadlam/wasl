from __future__ import annotations

from typing import Any
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.agents.business_identity import BusinessIdentityAgent
from app.agents.registry import AgentRegistry, MAX_A2A_DEPTH, _a2a_depth
from app.agents.tool_runtime import CUSTOMER_TOOLS, AgentToolRuntime


class FakeIdentity:
    agent_id = "identity"
    description = "fake identity"

    def __init__(self, answer: str = "We ship COD to 58 wilayas.") -> None:
        self.answer = answer
        self.calls: list[dict[str, Any]] = []

    async def consult(
        self,
        question: str,
        *,
        business_id: int,
        context: dict[str, Any] | None = None,
        model: str | None = None,
    ) -> dict[str, Any]:
        self.calls.append(
            {"question": question, "business_id": business_id, "context": context or {}, "model": model}
        )
        return {
            "ok": True,
            "answer": self.answer,
            "citations": [{"namespace": "policies", "content": "COD 58 wilayas"}],
            "namespaces_used": ["policies"],
        }


class NestedCaller:
    """Specialist that tries to call another agent (should hit depth guard)."""

    agent_id = "nested"
    description = "nested"

    def __init__(self, registry: AgentRegistry) -> None:
        self.registry = registry

    async def consult(
        self,
        question: str,
        *,
        business_id: int,
        context: dict[str, Any] | None = None,
        model: str | None = None,
    ) -> dict[str, Any]:
        inner = await self.registry.invoke(
            "ask_identity_agent",
            {"question": "inner"},
            {"business_id": business_id, "surface": "nested"},
        )
        return {"ok": True, "answer": f"outer:{inner.get('error') or inner.get('answer')}", "citations": []}


@pytest.mark.asyncio
async def test_registry_as_tool_schema_and_invoke():
    reg = AgentRegistry()
    agent = FakeIdentity()
    binding = reg.register(agent, tool_name="ask_identity_agent", arg_name="question")
    assert binding.name == "ask_identity_agent"
    assert binding.schema["function"]["name"] == "ask_identity_agent"

    tools = reg.tools_for(caller_agent_id="customer")
    assert any(t["function"]["name"] == "ask_identity_agent" for t in tools)
    # Self excluded
    assert reg.tools_for(caller_agent_id="identity") == []

    result = await reg.invoke(
        "ask_identity_agent",
        {"question": "How do you deliver?"},
        {"business_id": 7, "surface": "customer", "social_account_id": 9},
    )
    assert result["ok"] is True
    assert "COD" in result["answer"] or "wilayas" in result["answer"]
    assert agent.calls[0]["business_id"] == 7
    assert agent.calls[0]["context"]["social_account_id"] == 9


@pytest.mark.asyncio
async def test_registry_depth_guard_blocks_nested_a2a():
    reg = AgentRegistry()
    reg.register(FakeIdentity(), tool_name="ask_identity_agent")
    nested = NestedCaller(reg)
    reg.register(nested, tool_name="ask_nested_agent")

    # Outer invoke sets depth=1; nested consult tries ask_identity -> depth exceeded
    result = await reg.invoke(
        "ask_nested_agent",
        {"question": "go"},
        {"business_id": 1, "surface": "customer"},
    )
    assert result["ok"] is True
    assert "a2a_depth_exceeded" in result["answer"]
    assert MAX_A2A_DEPTH == 1
    assert _a2a_depth.get() == 0


@pytest.mark.asyncio
async def test_identity_consult_grounded_from_rag():
    knowledge = MagicMock()

    async def search_side(**kwargs):
        ns = kwargs.get("namespace")
        if ns == "policies" or ns is None:
            return [
                {
                    "namespace": "policies",
                    "content": "Delivery: 58 wilayas COD",
                    "score": 0.9,
                    "source_id": "1:policies",
                }
            ]
        return []

    knowledge.search = AsyncMock(side_effect=search_side)
    llm = MagicMock()
    llm.chat_completion = AsyncMock(
        return_value={"content": "We deliver COD across 58 wilayas.", "usage": {}}
    )
    agent = BusinessIdentityAgent(llm=llm, knowledge=knowledge)
    out = await agent.consult("do you deliver?", business_id=3, context={"from_agent": "customer"})
    assert out["ok"] is True
    assert out["unknown"] is False
    assert "58" in out["answer"]
    assert out["citations"]
    llm.chat_completion.assert_awaited()


@pytest.mark.asyncio
async def test_identity_consult_unknown_when_no_hits():
    knowledge = MagicMock()
    knowledge.search = AsyncMock(return_value=[])
    llm = MagicMock()
    llm.chat_completion = AsyncMock()
    agent = BusinessIdentityAgent(llm=llm, knowledge=knowledge)
    out = await agent.consult("warranty?", business_id=3)
    assert out["ok"] is True
    assert out["unknown"] is True
    assert "do not have enough" in out["answer"].lower() or "owner" in out["answer"].lower()
    llm.chat_completion.assert_not_awaited()


@pytest.mark.asyncio
async def test_customer_tool_loop_asks_identity_agent():
    reg = AgentRegistry()
    fake = FakeIdentity(answer="Tone is friendly Darija.")
    reg.register(fake, tool_name="ask_identity_agent")

    llm = MagicMock()
    # First turn: tool call; second: final reply
    llm.chat_completion = AsyncMock(
        side_effect=[
            {
                "content": "",
                "tool_calls": [
                    {
                        "id": "tc1",
                        "type": "function",
                        "function": {
                            "name": "ask_identity_agent",
                            "arguments": '{"question": "What is our tone?"}',
                        },
                    }
                ],
                "usage": {"prompt_tokens": 10, "completion_tokens": 5},
            },
            {
                "content": "We reply in friendly Darija.",
                "tool_calls": [],
                "usage": {"prompt_tokens": 12, "completion_tokens": 8},
            },
        ]
    )

    runtime = AgentToolRuntime(llm=llm, registry=reg)
    tools = runtime.tools_for_surface("customer", CUSTOMER_TOOLS)
    assert any(t["function"]["name"] == "ask_identity_agent" for t in tools)

    result = await runtime.run(
        messages=[{"role": "user", "content": "how do you talk to customers?"}],
        tools=tools,
        tenant={"business_id": 5, "surface": "customer"},
        context={"social_account_id": 11},
    )
    assert "Darija" in result["reply"]
    assert any(c.get("tool") == "ask_identity_agent" for c in result["tool_calls"])
    assert fake.calls
    assert any(
        isinstance(c, dict) and "Darija" in str(c.get("content") or "")
        for c in result["citations"]
    )
