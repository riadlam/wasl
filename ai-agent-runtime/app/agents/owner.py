from __future__ import annotations

from typing import Any

from app.agents.owner_approver import OwnerApprover
from app.agents.tool_runtime import OWNER_TOOLS, AgentToolRuntime
from app.config import get_settings
from app.memory.sessions import SessionStore
from app.middleware.agent_activity import activity
from app.models.schemas import AgentResponse, OwnerChatRequest, UsagePayload
from app.workflows.caption_review_loop import CaptionReviewLoop, looks_like_post_intent
from app.workflows.publish import PublishWorkflow


DEFAULT_OWNER_SYSTEM = """You are the shop OwnerAgent for a Maghreb commerce SaaS (Wasl).
Help the owner create captions, images, and social posts. Prefer Darija/French when the shop language implies it.
For brand voice, policies, or shop identity facts, call ask_identity_agent (BusinessIdentityAgent) before inventing.
To see what this shop recently published (tone/topics/memes), call list_recent_posts (limit 1–20 via SocialAPI).
All results are reviewed by OwnerApprover (metacognition critic) before the shop owner sees them.
Captions use CaptionWriter→CaptionApprover inside OwnerApprover.
Follow: approved caption → image vs text-only → prepare_social_post (confirm card).
Never claim published until confirm. Never invent product facts.
"""


class OwnerAgentService:
    def __init__(
        self,
        runtime: AgentToolRuntime | None = None,
        sessions: SessionStore | None = None,
        workflow: PublishWorkflow | None = None,
        caption_loop: CaptionReviewLoop | None = None,
        owner_approver: OwnerApprover | None = None,
    ) -> None:
        self.runtime = runtime or AgentToolRuntime()
        self.sessions = sessions or SessionStore()
        self.workflow = workflow or PublishWorkflow()
        self.settings = get_settings()
        self.owner_approver = owner_approver or OwnerApprover(
            llm=self.runtime.llm,
            knowledge=self.runtime.knowledge,
            laravel=self.runtime.laravel,
        )
        self.caption_loop = caption_loop or CaptionReviewLoop(
            writer=__import__("app.agents.caption_writer", fromlist=["CaptionWriter"]).CaptionWriter(
                llm=self.runtime.llm,
                knowledge=self.runtime.knowledge,
            ),
            approver=self.owner_approver.caption_approver,
        )

    async def chat(self, request: OwnerChatRequest, tenant: dict[str, Any]) -> AgentResponse:
        with activity().turn(
            "OwnerAgent",
            business_id=tenant.get("business_id"),
            correlation_id=str(tenant.get("correlation_id") or ""),
            extra={"agent_chat_id": request.agent_chat_id},
        ):
            return await self._chat_inner(request, tenant)

    async def _chat_inner(self, request: OwnerChatRequest, tenant: dict[str, Any]) -> AgentResponse:
        session_ref = str(request.agent_chat_id or "default")
        session = self.sessions.get_or_create(tenant["business_id"], "owner", session_ref)

        user_parts = [request.text or ""]
        for att in request.attachments:
            if att.url:
                user_parts.append(f"[attachment id={att.id} mime={att.mime} url={att.url}]")
        user_text = "\n".join(p for p in user_parts if p).strip() or "(empty)"
        self.sessions.append_message(session, "user", user_text)
        activity().input("owner inbound", {"text": user_text, "attachments": len(request.attachments)})

        usage = {
            "prompt_tokens": 0,
            "completion_tokens": 0,
            "cost_usd": 0.0,
            "fal_calls": 0,
            "calls_with_cost": 0,
        }
        citations: list[dict[str, Any]] = []
        tool_calls: list[dict[str, Any]] = []

        # Caption path: CaptionWriter ↔ CaptionApprover (via OwnerApprover specialist)
        state = session.get("state") or {}
        should_review = looks_like_post_intent(user_text) and not state.get("approved_caption")
        if should_review and (state.get("publish_step") or "idle") in ("idle", "done", "drafting", "reviewing"):
            loop_result = await self.caption_loop.run(
                business_id=int(tenant["business_id"]),
                owner_brief=user_text,
                session=session,
                model=request.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
            )
            self._merge_usage(usage, loop_result.get("usage") or {})
            reply = str(loop_result.get("owner_message") or loop_result.get("caption") or "")
            self.sessions.append_message(session, "assistant", reply)
            self.sessions.save(session)
            tool_calls.append(
                {
                    "tool": "owner_approver_caption_loop",
                    "arguments": {"brief": user_text},
                    "result": {
                        "approver": "OwnerApprover/CaptionApprover",
                        "approved": loop_result.get("approved"),
                        "needs_owner_edit": loop_result.get("needs_owner_edit"),
                        "rounds": loop_result.get("rounds"),
                        "caption": loop_result.get("caption"),
                    },
                }
            )
            activity().output("owner caption-loop result", loop_result)
            return AgentResponse(
                reply=reply,
                tool_calls=tool_calls,
                usage=self._usage_payload(usage),
                session_id=str(session["id"]),
                citations=citations,
                runtime="sk",
            )

        system = request.system_prompt or DEFAULT_OWNER_SYSTEM
        workflow_hint = self.workflow.instruction_for_state(session.get("state") or {})
        messages: list[dict[str, Any]] = [
            {"role": "system", "content": system + "\n\n" + workflow_hint},
        ]
        for row in request.history[-30:]:
            messages.append({"role": row.role, "content": row.content})
        messages.append({"role": "user", "content": user_text})

        tools = self.runtime.filter_tools(
            self.runtime.tools_for_surface("owner", OWNER_TOOLS),
            request.tool_allowlist or None,
        )
        result = await self.runtime.run(
            messages=messages,
            tools=tools,
            tenant={**tenant, "surface": "owner"},
            model=request.llm_model,
            context={
                "agent_chat_id": request.agent_chat_id,
                "voice_lang": request.voice_lang,
                "attached_asset_ids": [a.id for a in request.attachments if a.id],
                "session_state": session.get("state") or {},
            },
        )

        self.workflow.observe_tools(session, result.get("tool_calls") or [])
        lower = user_text.lower()
        if (session.get("state") or {}).get("publish_step") == "caption_approved" and any(
            x in lower for x in ("نعم", "yes", "ok", "oui", "text", "image", "صورة", "نص")
        ):
            self.workflow.mark_media_choice(session)

        draft_reply = str(result.get("reply") or "")
        evidence = [user_text]
        for call in result.get("tool_calls") or []:
            evidence.append(str(call.get("result") or "")[:1500])
        for hit in result.get("citations") or []:
            if isinstance(hit, dict) and hit.get("content"):
                evidence.append(str(hit["content"]))

        # OwnerApprover gate on general OwnerAgent results
        final_reply = draft_reply
        approver_meta: dict[str, Any] | None = None
        if self.settings.metacognition_enabled and draft_reply.strip() and not result.get("pending_action"):
            async def revise(feedback: str, previous: str) -> dict[str, Any]:
                rewrite = await self.runtime.llm.chat_completion(
                    [
                        {
                            "role": "system",
                            "content": (
                                "Revise the OwnerAgent reply using reviewer feedback. "
                                "Return reply text only. Stay factual and brand-safe."
                            ),
                        },
                        {
                            "role": "user",
                            "content": (
                                f"Owner brief: {user_text}\nFeedback: {feedback}\nDraft:\n{previous}"
                            ),
                        },
                    ],
                    model=request.llm_model,
                    temperature=0.3,
                )
                return {"reply": rewrite.get("content") or previous, "usage": rewrite.get("usage") or {}}

            gated = await self.owner_approver.review_until_approved(
                draft=draft_reply,
                business_id=int(tenant["business_id"]),
                owner_brief=user_text,
                revise_fn=revise,
                task_type="general",
                evidence=evidence,
                model=request.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
                max_rounds=max(1, int(self.settings.metacognition_max_retries) + 1),
            )
            final_reply = str(gated.get("draft") or draft_reply)
            self._merge_usage(usage, gated.get("usage") or {})
            approver_meta = {
                "approver": "OwnerApprover",
                "approved": gated.get("approved"),
                "rounds": gated.get("rounds"),
                "needs_owner_edit": gated.get("needs_owner_edit"),
            }

        self.sessions.append_message(session, "assistant", final_reply)
        self.sessions.save(session)

        usage_raw = result.get("usage") or {}
        self._merge_usage(usage, usage_raw)
        pending = result.get("pending_action")
        out_tools = list(result.get("tool_calls") or [])
        if approver_meta:
            out_tools.append({"tool": "owner_approver", "arguments": {}, "result": approver_meta})

        activity().output("owner FINAL reply", {"reply": final_reply, "approver": approver_meta})
        return AgentResponse(
            reply=final_reply,
            pending_action=pending if isinstance(pending, dict) else None,
            approval_required=bool(pending),
            approval_id=str(pending.get("id")) if isinstance(pending, dict) and pending.get("id") else None,
            tool_calls=out_tools,
            generated_assets=list(result.get("generated_assets") or []),
            pending_image_jobs=list(result.get("pending_image_jobs") or []),
            usage=self._usage_payload(usage),
            session_id=str(session["id"]),
            citations=list(result.get("citations") or []),
            runtime="sk",
        )

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
