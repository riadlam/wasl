from __future__ import annotations

from typing import Any


class PublishWorkflow:
    """Deterministic Owner publish pipeline hints (AF workflow substitute).

    States: idle -> drafting -> reviewing -> caption_approved -> media_choice
            -> image_ready|text_only -> pending_approval -> done
    """

    def instruction_for_state(self, state: dict[str, Any]) -> str:
        step = state.get("publish_step") or "idle"
        approved = state.get("approved_caption") or ""
        mapping = {
            "idle": (
                "Publish workflow step=idle: If the owner wants a post/caption, the system runs "
                "CaptionWriter→CaptionApprover internally. Do not invent a caption in freeform if "
                "the review loop will run. Do not generate_image or prepare_social_post yet."
            ),
            "drafting": (
                "Publish workflow step=drafting: CaptionWriter is drafting. Wait for review."
            ),
            "reviewing": (
                "Publish workflow step=reviewing: CaptionApprover is judging brand fit."
            ),
            "caption_approved": (
                "Publish workflow step=caption_approved: An approved caption is ready"
                + (f": <<<{approved}>>>" if approved else "")
                + ". Show it to the owner and ask image vs text-only. "
                "Do not prepare_social_post until they accept and choose media."
            ),
            "caption_drafted": (
                "Publish workflow step=caption_drafted: Ask whether they want an image or text-only post."
            ),
            "media_choice": (
                "Publish workflow step=media_choice: If image requested, call generate_image; "
                "if text-only, proceed to prepare_social_post when channel is known. "
                "prepare_social_post caption MUST equal the approved_caption in session."
            ),
            "image_ready": (
                "Publish workflow step=image_ready: Confirm the image, then prepare_social_post "
                "with the approved caption + asset ids."
            ),
            "text_only": (
                "Publish workflow step=text_only: Call prepare_social_post with the approved caption."
            ),
            "pending_approval": (
                "Publish workflow step=pending_approval: A confirm card is waiting. "
                "Tell the owner to confirm in the UI (or call confirm_pending_action if they ask)."
            ),
            "done": "Publish workflow step=done: Ready for the next request.",
        }
        return mapping.get(str(step), mapping["idle"])

    def observe_tools(self, session: dict[str, Any], tool_calls: list[dict[str, Any]]) -> None:
        state = session.setdefault("state", {})
        names = [str(t.get("tool") or "") for t in tool_calls]
        if "prepare_social_post" in names:
            for t in tool_calls:
                if t.get("tool") == "prepare_social_post":
                    result = t.get("result") or {}
                    if result.get("error") == "caption_not_approved":
                        continue
                    if isinstance(result.get("pending_action"), dict):
                        state["publish_step"] = "pending_approval"
                        state["pending_action_id"] = result["pending_action"].get("id")
        elif "generate_image" in names:
            state["publish_step"] = "image_ready"
        elif "confirm_pending_action" in names:
            state["publish_step"] = "done"
        elif "cancel_pending_action" in names:
            state["publish_step"] = "idle"
            state.pop("approved_caption", None)
        session["state"] = state

    def mark_media_choice(self, session: dict[str, Any]) -> None:
        state = session.setdefault("state", {})
        if state.get("publish_step") == "caption_approved":
            state["publish_step"] = "media_choice"
            session["state"] = state
