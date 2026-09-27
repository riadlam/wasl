from __future__ import annotations

import logging
import time
from contextvars import ContextVar
from typing import Any, Awaitable, Callable, Protocol

from app.middleware.agent_activity import activity

logger = logging.getLogger(__name__)

# Nested ask_* depth — specialists must not re-enter other agents.
_a2a_depth: ContextVar[int] = ContextVar("a2a_depth", default=0)

MAX_A2A_DEPTH = 1
MAX_ANSWER_CHARS = 4000


class SpecialistAgent(Protocol):
    """AF-style specialist that can be wrapped as a tool for other agents."""

    agent_id: str
    description: str

    async def consult(
        self,
        question: str,
        *,
        business_id: int,
        context: dict[str, Any] | None = None,
        model: str | None = None,
    ) -> dict[str, Any]:
        ...


class AgentToolBinding:
    """OpenAI function schema + invoker — mirrors AF agent.as_tool()."""

    def __init__(
        self,
        *,
        name: str,
        description: str,
        arg_name: str,
        agent_id: str,
        invoker: Callable[[dict[str, Any], dict[str, Any]], Awaitable[dict[str, Any]]],
        schema: dict[str, Any],
    ) -> None:
        self.name = name
        self.description = description
        self.arg_name = arg_name
        self.agent_id = agent_id
        self.invoker = invoker
        self.schema = schema


class AgentRegistry:
    """Registers specialists and exposes them as tools to conversational agents."""

    def __init__(self) -> None:
        self._agents: dict[str, SpecialistAgent] = {}
        self._bindings: dict[str, AgentToolBinding] = {}

    def register(
        self,
        agent: SpecialistAgent,
        *,
        tool_name: str | None = None,
        tool_description: str | None = None,
        arg_name: str = "question",
    ) -> AgentToolBinding:
        agent_id = str(getattr(agent, "agent_id", "") or "").strip()
        if not agent_id:
            raise ValueError("SpecialistAgent must define agent_id")
        name = tool_name or f"ask_{agent_id}_agent"
        description = tool_description or str(getattr(agent, "description", "") or f"Ask {agent_id} agent.")
        schema = {
            "type": "function",
            "function": {
                "name": name,
                "description": description,
                "parameters": {
                    "type": "object",
                    "properties": {
                        arg_name: {
                            "type": "string",
                            "description": "Question or task for the specialist agent.",
                        },
                    },
                    "required": [arg_name],
                },
            },
        }

        async def invoker(args: dict[str, Any], tenant: dict[str, Any]) -> dict[str, Any]:
            return await self.invoke(name, args, tenant)

        binding = AgentToolBinding(
            name=name,
            description=description,
            arg_name=arg_name,
            agent_id=agent_id,
            invoker=invoker,
            schema=schema,
        )
        self._agents[agent_id] = agent
        self._bindings[name] = binding
        logger.info("agent_registry.registered", extra={"agent_id": agent_id, "tool": name})
        return binding

    def get(self, agent_id: str) -> SpecialistAgent | None:
        return self._agents.get(agent_id)

    def has_tool(self, name: str) -> bool:
        return name in self._bindings

    def tools_for(self, *, caller_agent_id: str | None = None) -> list[dict[str, Any]]:
        """Return OpenAI tool schemas, excluding the caller's own specialist tool."""
        out: list[dict[str, Any]] = []
        for binding in self._bindings.values():
            if caller_agent_id and binding.agent_id == caller_agent_id:
                continue
            out.append(binding.schema)
        return out

    def merge_tools(
        self,
        base: list[dict[str, Any]],
        *,
        caller_agent_id: str | None = None,
    ) -> list[dict[str, Any]]:
        known = {
            t.get("function", {}).get("name")
            for t in base
            if isinstance(t, dict)
        }
        merged = list(base)
        for schema in self.tools_for(caller_agent_id=caller_agent_id):
            name = schema.get("function", {}).get("name")
            if name and name not in known:
                merged.append(schema)
        return merged

    async def invoke(
        self,
        tool_name: str,
        args: dict[str, Any],
        tenant: dict[str, Any],
    ) -> dict[str, Any]:
        binding = self._bindings.get(tool_name)
        if not binding:
            return {"ok": False, "error": f"unknown_agent_tool:{tool_name}"}

        depth = _a2a_depth.get()
        if depth >= MAX_A2A_DEPTH:
            return {
                "ok": False,
                "error": "a2a_depth_exceeded",
                "message": "Specialist agents cannot call other agents in this turn.",
                "agent_id": binding.agent_id,
            }

        agent = self._agents.get(binding.agent_id)
        if not agent:
            return {"ok": False, "error": "agent_not_registered", "agent_id": binding.agent_id}

        question = str(args.get(binding.arg_name) or args.get("question") or "").strip()
        if not question:
            return {"ok": False, "error": "empty_question", "agent_id": binding.agent_id}

        business_id = int(tenant.get("business_id") or 0)
        if business_id < 1:
            return {"ok": False, "error": "missing_business_id", "agent_id": binding.agent_id}

        from_agent = str(tenant.get("surface") or tenant.get("from_agent") or "unknown")
        token = _a2a_depth.set(depth + 1)
        started = time.perf_counter()
        try:
            result = await agent.consult(
                question,
                business_id=business_id,
                context={
                    "social_account_id": tenant.get("social_account_id")
                    or (tenant.get("context") or {}).get("social_account_id"),
                    "from_agent": from_agent,
                    "correlation_id": tenant.get("correlation_id"),
                    "surface": tenant.get("surface"),
                },
                model=tenant.get("llm_model"),
            )
            answer = str(result.get("answer") or "")
            if len(answer) > MAX_ANSWER_CHARS:
                answer = answer[: MAX_ANSWER_CHARS - 20] + "\n…[truncated]"
                result = {**result, "answer": answer}

            latency_ms = int((time.perf_counter() - started) * 1000)
            logger.info(
                "a2a.hop",
                extra={
                    "from_agent": from_agent,
                    "to_agent": binding.agent_id,
                    "tool": tool_name,
                    "business_id": business_id,
                    "latency_ms": latency_ms,
                    "ok": bool(result.get("ok", True)),
                },
            )
            payload = {
                "ok": bool(result.get("ok", True)),
                "agent_id": binding.agent_id,
                "tool": tool_name,
                "answer": answer,
                "citations": result.get("citations") or [],
                "namespaces_used": result.get("namespaces_used") or [],
                "unknown": bool(result.get("unknown")),
                "latency_ms": latency_ms,
                "error": result.get("error"),
            }
            try:
                activity().a2a(from_agent, binding.agent_id, question, payload)
            except Exception:
                pass
            return payload
        except Exception as exc:
            logger.exception("a2a.hop_failed tool=%s agent=%s", tool_name, binding.agent_id)
            return {
                "ok": False,
                "agent_id": binding.agent_id,
                "tool": tool_name,
                "error": str(exc),
            }
        finally:
            _a2a_depth.reset(token)


# Process-wide registry used by tool runtime and main startup.
default_registry = AgentRegistry()
