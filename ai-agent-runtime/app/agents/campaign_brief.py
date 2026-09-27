from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.agents.owner_approver import OwnerApprover
from app.agents.tool_runtime import OWNER_TOOLS, AgentToolRuntime
from app.middleware.agent_activity import activity
from app.models.schemas import UsagePayload

logger = logging.getLogger(__name__)

CAMPAIGN_BRIEF_SYSTEM = """You are OwnerAgent running in CAMPAIGN BRIEF mode for Wasl (multi-business Maghreb SaaS).
You are NOT writing the post yet. You confirm what the campaign will feature so PostCrafter never invents products.

You MUST call ask_identity_agent at least once early (first turn if history is empty) to learn THIS shop's
category and tone — then ask ONE follow-up that builds on that identity answer.
Never invent what the shop sells; never assume a niche unless identity/evidence or VISION UNDERSTANDING says so.
Do NOT call list_recent_posts on the first briefing turn (it is slow).

product_images mode (critical):
- If context includes VISION UNDERSTANDING / image analyses: that is AUTHORITATIVE for products.
- Echo only products/text/vibe seen in vision (or corrected by the owner). NEVER invent SKUs from a catalog list.
- Catalog lines in context (if any) are background only — do not promote them unless the owner or vision names them.
- ready=true means: you restated the vision understanding and the owner can Accept to generate a SAMPLE tease.

ai_recent mode:
- Ground on the owner focus prompt + identity tone. Do not invent catalog pack names the focus never mentioned.

Owner-facing language: ALWAYS draft message/question in clear English.
TranslationAgent localizes to the shop language after you finish — do not write Darija/French yourself.

Quality bar for message (critical):
- Confirm intent + echo concrete cues from VISION (product_images) or owner focus (ai_recent) + one identity tone cue.
- Bad: picking random catalog products the images never showed.
- Good: short confirmation of what was seen/understood, then one sharp question OR ready=true.

Script / readability:
- English only for JSON message/question fields.
- Keep messages short (1–3 sentences). One question max when ready=false.

Ready rules:
- ready=true ONLY when understanding of images/focus is clear enough for a sample tease (owner will Accept next).
- Max 3 questions total.
- When ready=true, question must be "" and message must restate the understanding and say you can prepare a SAMPLE
  scheduled-post preview after they confirm — not that anything is published.

AI CAMPAIGNS intent:
- Owner "make posts" / focus text = DIRECTION for a scheduled-post preview, NOT literal caption or on-image text.

Reply with JSON ONLY (no markdown):
{"ready":false,"message":"...","question":"..."}
or
{"ready":true,"message":"...","question":""}
"""


class CampaignBriefAgent:
    """OwnerAgent surface for campaign tease briefing — identity A2A + OwnerApprover gate."""

    agent_id = "campaign_brief"

    def __init__(
        self,
        runtime: AgentToolRuntime | None = None,
        owner_approver: OwnerApprover | None = None,
    ) -> None:
        self.runtime = runtime or AgentToolRuntime()
        self.owner_approver = owner_approver or OwnerApprover(
            llm=self.runtime.llm,
            knowledge=self.runtime.knowledge,
            laravel=self.runtime.laravel,
        )

    async def turn(
        self,
        *,
        tenant: dict[str, Any],
        context_block: str,
        language_hint: str,
        history: list[dict[str, str]],
        model: str | None = None,
        correlation_id: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        with activity().turn(
            "CampaignBriefAgent",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "campaign_brief"},
        ):
            activity().input(
                "campaign brief turn",
                {
                    "history_turns": len(history),
                    "language_hint": language_hint,
                    "context_preview": (context_block or "")[:800],
                },
            )

            system = (
                CAMPAIGN_BRIEF_SYSTEM
                + "\nDraft all owner-facing message/question fields in English. "
                "Shop language localization happens later via TranslationAgent.\n"
                + f"\nCampaign context:\n{context_block}\n"
            )
            messages: list[dict[str, Any]] = [{"role": "system", "content": system}]
            if not history:
                messages.append(
                    {
                        "role": "user",
                        "content": (
                            "Start the briefing. Call ask_identity_agent about this shop's typical posts/"
                            "tone/concepts first (do not call list_recent_posts). Then greet briefly and "
                            "ask the most important missing question — or set ready=true if the focus "
                            "already has enough. JSON only."
                        ),
                    }
                )
            else:
                for row in history[-12:]:
                    role = row.get("role") if row.get("role") in ("user", "assistant") else "user"
                    content = (row.get("content") or "").strip()
                    if content:
                        messages.append({"role": role, "content": content})
                messages.append(
                    {
                        "role": "user",
                        "content": (
                            "Continue the briefing. Use ask_identity_agent if you still need brand/post-style "
                            "facts. Ask the next missing question, or set ready=true. JSON only."
                        ),
                    }
                )

            tools = self.runtime.filter_tools(
                self.runtime.tools_for_surface("owner", OWNER_TOOLS),
                # First-pass briefing: identity + catalog only. list_recent_posts is available to OwnerAgent
                # chat; calling it here routinely hangs SocialAPI and blows the PHP request budget.
                ["ask_identity_agent", "knowledge_search", "search_products", "get_product", "list_channels"],
            )
            result = await self.runtime.run(
                messages=messages,
                tools=tools,
                tenant={**tenant, "surface": "campaign_brief"},
                model=model,
                context={"campaign_brief": True},
            )

            usage = {
                "prompt_tokens": 0,
                "completion_tokens": 0,
                "cost_usd": 0.0,
                "fal_calls": 0,
                "calls_with_cost": 0,
            }
            self._merge_usage(usage, result.get("usage") or {})

            parsed = self._parse(str(result.get("reply") or ""))
            draft_face = self._face(parsed)
            evidence = [context_block[:1500]]
            for call in result.get("tool_calls") or []:
                tool = str(call.get("tool") or call.get("name") or "")
                raw_res = call.get("result")
                if isinstance(raw_res, dict):
                    # Prefer identity answer text for Approver (not huge citation dump).
                    ans = str(raw_res.get("answer") or "")
                    if tool == "ask_identity_agent" and ans:
                        evidence.append(f"identity: {ans[:1600]}")
                        continue
                    evidence.append(json.dumps(raw_res, ensure_ascii=False)[:1200])
                else:
                    evidence.append(str(raw_res or "")[:1200])

            async def revise(feedback: str, previous: str) -> dict[str, Any]:
                rewrite = await self.runtime.llm.chat_completion(
                    [
                        {
                            "role": "system",
                            "content": (
                                "Revise the campaign brief JSON using OwnerApprover feedback. "
                                "Return JSON only: {\"ready\":bool,\"message\":\"...\",\"question\":\"...\"}. "
                                "Keep one primary script (Arabic OR Latin), do not mix scripts in one sentence."
                            ),
                        },
                        {
                            "role": "user",
                            "content": f"Feedback: {feedback}\nDraft JSON/text:\n{previous}",
                        },
                    ],
                    model=model,
                    temperature=0.25,
                )
                return {"reply": rewrite.get("content") or previous, "usage": rewrite.get("usage") or {}}

            gated = await self.owner_approver.review_until_approved(
                draft=draft_face or json.dumps(parsed, ensure_ascii=False),
                business_id=business_id,
                owner_brief=context_block[:2000],
                revise_fn=revise,
                task_type="campaign_brief",
                evidence=evidence,
                model=model,
                correlation_id=correlation_id,
                max_rounds=1,
            )
            self._merge_usage(usage, gated.get("usage") or {})

            # If approver rewrote free text, re-parse; else keep structured fields.
            gated_text = str(gated.get("draft") or "")
            if gated_text.strip().startswith("{"):
                parsed = self._parse(gated_text)
            elif gated_text.strip() and not parsed.get("message"):
                parsed["message"] = gated_text.strip()

            # Soft script cleanup for UI readability
            parsed["message"] = self._soften_script_mix(str(parsed.get("message") or ""))
            parsed["question"] = self._soften_script_mix(str(parsed.get("question") or ""))

            out = {
                "ready": bool(parsed.get("ready")),
                "message": str(parsed.get("message") or ""),
                "question": str(parsed.get("question") or ""),
                "tool_calls": list(result.get("tool_calls") or []),
                "approver": {
                    "agent": "OwnerApprover",
                    "approved": bool(gated.get("approved")),
                    "needs_owner_edit": bool(gated.get("needs_owner_edit")),
                    "rounds": gated.get("rounds") or [],
                },
                "agents": {
                    "brief": "CampaignBriefAgent",
                    "identity": "BusinessIdentityAgent",
                    "approver": "OwnerApprover",
                    "writer": "CampaignCreativeAgent",
                    "caption_approver": "CaptionApprover",
                },
                "usage": self._usage_payload(usage).model_dump(),
                "runtime": "sk",
            }
            activity().output("campaign brief FINAL", out)
            return out

    def _face(self, parsed: dict[str, Any]) -> str:
        msg = str(parsed.get("message") or "").strip()
        q = str(parsed.get("question") or "").strip()
        if msg and q:
            return f"{msg}\n\n{q}"
        return msg or q

    def _parse(self, raw: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        data = None
        try:
            data = json.loads(text)
        except Exception:
            m = re.search(r"\{[\s\S]*\}", text)
            if m:
                try:
                    data = json.loads(m.group(0))
                except Exception:
                    data = None
        if not isinstance(data, dict):
            return {"ready": False, "message": text, "question": ""}
        return {
            "ready": bool(data.get("ready")),
            "message": str(data.get("message") or "").strip(),
            "question": str(data.get("question") or "").strip(),
        }

    def _soften_script_mix(self, text: str) -> str:
        """Break ugly Latin-inside-Arabic runs onto clearer segments when both scripts appear."""
        if not text:
            return text
        has_ar = bool(re.search(r"[\u0600-\u06FF]", text))
        has_lat = bool(re.search(r"[A-Za-z]{2,}", text))
        if not (has_ar and has_lat):
            return text
        # Put spaces around Latin tokens stuck to Arabic letters
        text = re.sub(r"([\u0600-\u06FF])([A-Za-z])", r"\1 \2", text)
        text = re.sub(r"([A-Za-z])([\u0600-\u06FF])", r"\1 \2", text)
        return text

    @staticmethod
    def _merge_usage(total: dict[str, Any], part: dict[str, Any]) -> None:
        for key in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
            total[key] = int(total.get(key) or 0) + int(part.get(key) or 0)
        total["cost_usd"] = float(total.get("cost_usd") or 0) + float(part.get("cost_usd") or 0)

    @staticmethod
    def _usage_payload(usage: dict[str, Any]) -> UsagePayload:
        return UsagePayload(
            prompt_tokens=int(usage.get("prompt_tokens") or 0),
            completion_tokens=int(usage.get("completion_tokens") or 0),
            cost_usd=float(usage.get("cost_usd") or 0),
            fal_calls=int(usage.get("fal_calls") or 0),
            calls_with_cost=int(usage.get("calls_with_cost") or 0),
        )
