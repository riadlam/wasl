from __future__ import annotations

import json
import threading
from contextlib import contextmanager
from contextvars import ContextVar
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator
from uuid import uuid4

from app.config import get_settings

_turn_id: ContextVar[str] = ContextVar("agent_activity_turn", default="")
_agent_name: ContextVar[str] = ContextVar("agent_activity_agent", default="")
_step_n: ContextVar[int] = ContextVar("agent_activity_step", default=0)
_lock = threading.Lock()

# Friendly display names for walkthrough logs
AGENT_DISPLAY: dict[str, str] = {
    "customer": "CustomerAgent",
    "CustomerAgent": "CustomerAgent",
    "identity": "BusinessIdentityAgent",
    "BusinessIdentityAgent": "BusinessIdentityAgent",
    "owner": "OwnerAgent",
    "OwnerAgent": "OwnerAgent",
    "owner_approver": "OwnerApprover",
    "OwnerApprover": "OwnerApprover",
    "customer_approver": "CustomerApprover",
    "CustomerApprover": "CustomerApprover",
    "caption": "CaptionWriter",
    "CaptionWriter": "CaptionWriter",
    "caption_approver": "CaptionApprover",
    "CaptionApprover": "CaptionApprover",
    "campaign_brief": "CampaignBriefAgent",
    "CampaignBriefAgent": "CampaignBriefAgent",
    "campaign_tease": "CampaignTeaseAgent",
    "CampaignTeaseAgent": "CampaignTeaseAgent",
    "post_crafter": "PostCrafterAgent",
    "PostCrafterAgent": "PostCrafterAgent",
    "post_crafter_approver": "PostCrafterApprover",
    "PostCrafterApprover": "PostCrafterApprover",
    "translation": "TranslationAgent",
    "TranslationAgent": "TranslationAgent",
    "CampaignSlotPlanner": "CampaignSlotPlanner",
    "CampaignSlotDrafter": "CampaignSlotDrafter",
    "PostEnhancerAgent": "PostEnhancerAgent",
}


def _now() -> str:
    return datetime.now(timezone.utc).astimezone().strftime("%Y-%m-%d %H:%M:%S")


def _display_agent(name: str | None) -> str:
    raw = (name or "").strip() or "-"
    return AGENT_DISPLAY.get(raw, raw)


def _clip(value: Any, limit: int = 12000) -> str:
    if value is None:
        return ""
    if isinstance(value, (dict, list)):
        try:
            text = json.dumps(value, ensure_ascii=False, indent=2, default=str)
        except Exception:
            text = str(value)
    else:
        text = str(value)
    if len(text) <= limit:
        return text
    return text[: limit - 40] + "\n…[truncated {} chars]".format(len(text) - limit + 40)


def _one_line(value: Any, limit: int = 160) -> str:
    """Single-line clip for the brief log — no multiline dumps."""
    if value is None:
        return ""
    if isinstance(value, (dict, list)):
        try:
            text = json.dumps(value, ensure_ascii=False, default=str)
        except Exception:
            text = str(value)
    else:
        text = str(value)
    text = " ".join(text.split())
    if len(text) <= limit:
        return text
    return text[: limit - 1] + "…"


def _content_text(content: Any) -> str:
    if content is None:
        return ""
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        bits: list[str] = []
        for part in content:
            if isinstance(part, dict):
                if part.get("type") == "text":
                    bits.append(str(part.get("text") or ""))
                elif part.get("type") == "image_url":
                    bits.append("[image]")
                else:
                    bits.append(str(part.get("type") or "part"))
            else:
                bits.append(str(part))
        return " ".join(bits)
    return str(content)


def _messages_brief(messages: list[dict[str, Any]] | None) -> str:
    if not messages:
        return "(none)"
    lines: list[str] = []
    for i, msg in enumerate(messages):
        role = msg.get("role") or "?"
        content = msg.get("content")
        tools = msg.get("tool_calls")
        tool_id = msg.get("tool_call_id")
        head = f"[{i}] {role}"
        if tool_id:
            head += f" tool_call_id={tool_id}"
        body = _clip(content, 4000) if content not in (None, "") else ""
        if tools:
            names = []
            for tc in tools:
                if isinstance(tc, dict):
                    names.append((tc.get("function") or {}).get("name") or "?")
            body = (body + "\n" if body else "") + "tool_calls: " + ", ".join(names)
        lines.append(f"{head}\n{body}".rstrip())
    return "\n\n".join(lines)


def _messages_quiet(messages: list[dict[str, Any]] | None) -> str:
    """Brief log: skip system prompts; keep short user/assistant/tool hints."""
    if not messages:
        return "msgs=0"
    parts: list[str] = []
    skipped_system = 0
    for msg in messages:
        role = str(msg.get("role") or "?")
        if role == "system":
            skipped_system += 1
            continue
        text = _one_line(_content_text(msg.get("content")), 120)
        tools = msg.get("tool_calls") or []
        tool_names = []
        for tc in tools:
            if isinstance(tc, dict):
                tool_names.append((tc.get("function") or {}).get("name") or "?")
        bit = f"{role}"
        if tool_names:
            bit += f"→{','.join(tool_names)}"
        if text:
            bit += f" «{text}»"
        parts.append(bit)
    head = f"msgs={len(messages)}"
    if skipped_system:
        head += f" (system×{skipped_system} omitted)"
    if not parts:
        return head
    return head + " | " + " · ".join(parts[:4])


def _payload_quiet(payload: Any) -> str:
    """Summarize INPUT/OUTPUT without dumping long prompts."""
    if payload in ("", None):
        return ""
    if isinstance(payload, dict):
        keys = list(payload.keys())[:8]
        interesting: list[str] = []
        for k in ("focus", "mode", "content_mode", "platform", "kind", "surface", "chars", "hits", "approved", "caption_len"):
            if k in payload and payload[k] not in (None, ""):
                interesting.append(f"{k}={_one_line(payload[k], 60)}")
        if interesting:
            return " ".join(interesting)
        return "keys=" + ",".join(str(k) for k in keys)
    return _one_line(payload, 180)


def _next_step() -> int:
    n = int(_step_n.get() or 0) + 1
    _step_n.set(n)
    return n


class AgentActivityLog:
    """Dedicated walkthrough log for all AI agent activity (numbered steps).

    Writes two files:
    - full (`agent-activity.log`) — detailed payloads for deep debug
    - brief (`agent-activity-brief.log`) — one-line steps, no system prompts
    """

    def __init__(self, path: Path | None = None, brief_path: Path | None = None) -> None:
        settings = get_settings()
        configured = getattr(settings, "agent_activity_log_path", "") or "logs/agent-activity.log"
        brief_configured = (
            getattr(settings, "agent_activity_brief_log_path", "") or "logs/agent-activity-brief.log"
        )
        root = Path(__file__).resolve().parents[2]
        self.path = path or Path(configured)
        if not self.path.is_absolute():
            self.path = root / self.path
        self.brief_path = brief_path or Path(brief_configured)
        if not self.brief_path.is_absolute():
            self.brief_path = root / self.brief_path
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.brief_path.parent.mkdir(parents=True, exist_ok=True)
        self.enabled = bool(getattr(settings, "agent_activity_log_enabled", True))

    def _write(self, block: str) -> None:
        if not self.enabled:
            return
        with _lock:
            with self.path.open("a", encoding="utf-8") as fh:
                fh.write(block)
                if not block.endswith("\n"):
                    fh.write("\n")

    def _write_brief(self, text: str) -> None:
        if not self.enabled:
            return
        line = text if text.endswith("\n") else text + "\n"
        with _lock:
            with self.brief_path.open("a", encoding="utf-8") as fh:
                fh.write(line)

    def _brief_line(self, kind: str, title: str, detail: str = "", *, step: int | None = None) -> None:
        tid = _turn_id.get() or "-"
        agent = _display_agent(_agent_name.get())
        n = int(_step_n.get() or 0) if step is None else step
        prefix = f"step={n:02d}" if n else "step=--"
        bits = [f"{_now()}", prefix, kind, f"[{agent}]", f"turn={tid}", title]
        detail = detail.strip()
        if detail:
            bits.append(detail)
        self._write_brief(" | ".join(bits))

    def line(self, text: str) -> None:
        tid = _turn_id.get() or "-"
        agent = _display_agent(_agent_name.get())
        step = int(_step_n.get() or 0)
        prefix = f"step={step:02d}" if step else "step=--"
        self._write(f"{_now()} | {prefix} | [{agent}] | turn={tid} | {text}\n")
        self._brief_line("NOTE", text)

    def section(
        self,
        title: str,
        body: Any = "",
        *,
        kind: str = "STEP",
        number: bool = True,
        brief_detail: str | None = None,
        write_brief: bool = True,
    ) -> None:
        tid = _turn_id.get() or "-"
        agent = _display_agent(_agent_name.get())
        step = _next_step() if number else int(_step_n.get() or 0)
        sep = "-" * 78
        step_label = f"Step {step:02d}" if step else "Step --"
        parts = [
            sep,
            f"{_now()} | {step_label} | {kind} | agent=[{agent}] | turn={tid}",
            f"        {title}",
            sep,
        ]
        clipped = _clip(body) if body not in ("", None) else ""
        if clipped:
            parts.append(clipped)
        parts.append("")
        self._write("\n".join(parts))
        if write_brief:
            if brief_detail is not None:
                detail = brief_detail
            elif kind in ("INPUT", "OUTPUT"):
                detail = _payload_quiet(body)
            else:
                detail = _one_line(body, 180)
            self._brief_line(kind, title, detail, step=step)

    def input(self, title: str, payload: Any) -> None:
        self.section(title, payload, kind="INPUT")

    def output(self, title: str, payload: Any) -> None:
        self.section(title, payload, kind="OUTPUT")

    def agent_answer(self, agent: str, answer: Any, *, title: str | None = None) -> None:
        """Clear 'who said what' block for walkthrough reading."""
        display = _display_agent(agent)
        prev = _agent_name.set(display)
        try:
            label = title or f"{display} ANSWER"
            text = answer if isinstance(answer, str) else _clip(answer)
            self.section(
                label,
                f"WHO:  {display}\nSAID:\n{text}",
                kind="ANSWER",
                brief_detail=_one_line(text, 160),
            )
        finally:
            _agent_name.reset(prev)

    def llm(
        self,
        *,
        model: str | None,
        messages: list[dict[str, Any]],
        tools: list[dict[str, Any]] | None,
        response: dict[str, Any],
        label: str = "LLM",
    ) -> None:
        tool_names = []
        for t in tools or []:
            if isinstance(t, dict):
                tool_names.append((t.get("function") or {}).get("name") or "?")
        in_body = {
            "model": model,
            "tools_available": tool_names,
            "messages": "SEE BELOW",
        }
        content = response.get("content")
        tool_calls = response.get("tool_calls") or []
        decide = []
        for tc in tool_calls:
            if isinstance(tc, dict):
                decide.append((tc.get("function") or {}).get("name") or "?")
        out_body = {
            "finish_reason": response.get("finish_reason"),
            "usage": response.get("usage"),
            "agent_decided_tools": decide,
            "content": content,
            "tool_calls": tool_calls,
        }
        self.section(
            f"{label} request → [{_display_agent(_agent_name.get())}]",
            f"{_clip(in_body, 2000)}\n\n--- messages ---\n{_messages_brief(messages)}",
            kind="LLM-IN",
            brief_detail=(
                f"model={model or '-'} tools=[{','.join(tool_names[:8])}] {_messages_quiet(messages)}"
            ),
        )

        summary = (
            f"tools={decide}" if decide else f"text={_clip(content, 500) or '(empty)'}"
        )
        out_detail = (
            f"calls={','.join(decide)}"
            if decide
            else f"text={_one_line(_content_text(content), 140) or '(empty)'}"
        )
        usage = response.get("usage") if isinstance(response.get("usage"), dict) else {}
        if usage:
            out_detail += f" tok={usage.get('prompt_tokens', '?')}/{usage.get('completion_tokens', '?')}"
        self.section(
            f"{label} response ← [{_display_agent(_agent_name.get())}] · {summary}",
            out_body,
            kind="LLM-OUT",
            brief_detail=out_detail,
        )

    def tool(self, name: str, arguments: Any, result: Any) -> None:
        agent = _display_agent(_agent_name.get())
        # Highlight A2A-style answers when present
        answer_hint = ""
        if isinstance(result, dict) and result.get("answer") not in (None, ""):
            answer_hint = f"\n\n<<< AGENT ANSWER (from tool)\n{_clip(result.get('answer'), 2000)}"
        ok = True
        if isinstance(result, dict) and result.get("ok") is False:
            ok = False
        err = ""
        if isinstance(result, dict) and result.get("error"):
            err = f" err={_one_line(result.get('error'), 80)}"
        arg_hint = ""
        if isinstance(arguments, dict):
            q = arguments.get("query") or arguments.get("question") or arguments.get("prompt")
            if q:
                arg_hint = f" q={_one_line(q, 80)}"
        self.section(
            f"TOOL {name}  (called by [{agent}])",
            f">>> ARGUMENTS\n{_clip(arguments)}\n\n<<< RESULT\n{_clip(result)}{answer_hint}",
            kind="TOOL",
            brief_detail=f"{'ok' if ok else 'FAIL'}{arg_hint}{err}",
        )

    def a2a(self, from_agent: str, to_agent: str, question: str, result: Any) -> None:
        src = _display_agent(from_agent)
        dst = _display_agent(to_agent)
        answer = ""
        if isinstance(result, dict):
            answer = str(result.get("answer") or result.get("reply") or "")
        body = (
            f"FROM: [{src}]\n"
            f"TO:   [{dst}]\n"
            f">>> QUESTION\n{_clip(question)}\n\n"
            f"<<< [{dst}] ANSWER\n{_clip(answer or result)}"
        )
        self.section(
            f"A2A [{src}] → [{dst}]",
            body,
            kind="A2A",
            brief_detail=f"q={_one_line(question, 100)} · a={_one_line(answer or result, 120)}",
        )

    def banner(self, title: str, meta: dict[str, Any] | None = None) -> None:
        bar = "=" * 78
        lines = [bar, f"{_now()} | {title}"]
        if meta:
            lines.append(_clip(meta, 4000))
        lines.extend([bar, ""])
        self._write("\n".join(lines))
        meta_bits = ""
        if meta:
            parts = []
            for k in ("agent", "business_id", "surface", "content_mode", "platform", "kind", "steps", "correlation_id"):
                if k in meta and meta[k] not in (None, ""):
                    parts.append(f"{k}={meta[k]}")
            meta_bits = " ".join(parts)
        kind = "TURN"
        if "START" in title.upper():
            kind = "TURN-START"
        elif "END" in title.upper():
            kind = "TURN-END"
        self._brief_line(kind, title, meta_bits, step=0 if "START" in title.upper() else int(_step_n.get() or 0))

    @contextmanager
    def turn(
        self,
        agent: str,
        *,
        business_id: Any = None,
        correlation_id: str | None = None,
        extra: dict[str, Any] | None = None,
    ) -> Iterator[str]:
        turn = (correlation_id or "")[:36] or uuid4().hex[:12]
        display = _display_agent(agent)
        t_agent = _agent_name.set(display)
        t_turn = _turn_id.set(turn)
        t_step = _step_n.set(0)
        meta = {
            "agent": display,
            "business_id": business_id,
            "correlation_id": turn,
            **(extra or {}),
        }
        self.banner(f"TURN START · [{display}]  (steps numbered below)", meta)
        try:
            yield turn
        except Exception as exc:
            self.section("TURN ERROR", f"{type(exc).__name__}: {exc}", kind="ERROR")
            raise
        finally:
            total = int(_step_n.get() or 0)
            self.banner(
                f"TURN END · [{display}]  ({total} steps)",
                {"agent": display, "correlation_id": turn, "steps": total},
            )
            _agent_name.reset(t_agent)
            _turn_id.reset(t_turn)
            _step_n.reset(t_step)


_activity: AgentActivityLog | None = None


def activity() -> AgentActivityLog:
    global _activity
    if _activity is None:
        _activity = AgentActivityLog()
    return _activity


def reset_activity_log_for_tests(path: Path | None = None, brief_path: Path | None = None) -> AgentActivityLog:
    global _activity
    _activity = AgentActivityLog(path=path, brief_path=brief_path)
    return _activity
