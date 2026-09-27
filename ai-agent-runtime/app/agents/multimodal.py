from __future__ import annotations

from typing import Any

from app.llm import LlmClient


async def preprocess_media(
    *,
    media_url: str | None,
    media_type: str | None,
    llm: LlmClient,
) -> str | None:
    """STT/vision preprocess for inbound customer media.

    Uses multimodal chat when the URL looks like an image; for audio returns a
    placeholder instructing the model that transcription should arrive from Laravel
    when available (Laravel can pass transcribed text in `text`).
    """
    if not media_url:
        return None
    kind = (media_type or "").lower()
    if any(x in kind for x in ("audio", "voice", "ogg", "mpeg", "wav")):
        return (
            f"Inbound voice/audio attachment at {media_url}. "
            "If transcript text was not provided, ask the customer briefly to type their request."
        )

    if any(x in kind for x in ("image", "jpeg", "png", "webp", "gif")) or media_url.lower().endswith(
        (".jpg", ".jpeg", ".png", ".webp", ".gif")
    ):
        try:
            result = await llm.chat_completion(
                [
                    {
                        "role": "user",
                        "content": [
                            {
                                "type": "text",
                                "text": "Describe this customer-sent image for a shop assistant in 2 short sentences. Note products, text, or intent if visible.",
                            },
                            {"type": "image_url", "image_url": {"url": media_url}},
                        ],
                    }
                ],
                temperature=0.2,
            )
            desc = (result.get("content") or "").strip()
            return desc or f"Customer sent an image: {media_url}"
        except Exception:
            return f"Customer sent an image (vision unavailable): {media_url}"

    return f"Customer sent media ({media_type or 'unknown'}): {media_url}"
