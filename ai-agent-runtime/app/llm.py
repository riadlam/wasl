from __future__ import annotations

import logging
from typing import Any

from openai import AsyncOpenAI

from app.config import Settings, get_settings
from app.middleware.agent_activity import activity

logger = logging.getLogger(__name__)


def _fal_headers(api_key: str) -> dict[str, str]:
    """Fal OpenRouter expects Authorization: Key <FAL_KEY>."""
    key = (api_key or "").strip()
    if not key:
        return {}
    if key.lower().startswith("key "):
        return {"Authorization": key}
    return {"Authorization": f"Key {key}"}


def _is_fal_url(url: str) -> bool:
    return "fal.run" in (url or "").lower() or "fal.ai" in (url or "").lower()


class LlmClient:
    """OpenAI-compatible chat + embeddings (Fal/OpenRouter)."""

    def __init__(self, settings: Settings | None = None) -> None:
        self.settings = settings or get_settings()
        chat_key = self.settings.llm_api_key or "missing"
        embed_key = self.settings.embedding_api_key or self.settings.llm_api_key or "missing"

        chat_kwargs: dict[str, Any] = {
            "api_key": "not-needed" if _is_fal_url(self.settings.llm_base_url) else chat_key,
            "base_url": self.settings.llm_base_url,
            "timeout": 180.0,
        }
        if _is_fal_url(self.settings.llm_base_url):
            chat_kwargs["default_headers"] = _fal_headers(chat_key)

        embed_kwargs: dict[str, Any] = {
            "api_key": "not-needed" if _is_fal_url(self.settings.embedding_base_url) else embed_key,
            "base_url": self.settings.embedding_base_url,
            "timeout": 60.0,
        }
        if _is_fal_url(self.settings.embedding_base_url):
            embed_kwargs["default_headers"] = _fal_headers(embed_key)

        self.chat = AsyncOpenAI(**chat_kwargs)
        self.embed = AsyncOpenAI(**embed_kwargs)

    async def chat_completion(
        self,
        messages: list[dict[str, Any]],
        *,
        model: str | None = None,
        tools: list[dict[str, Any]] | None = None,
        tool_choice: str | dict[str, Any] | None = "auto",
        temperature: float = 0.4,
    ) -> dict[str, Any]:
        kwargs: dict[str, Any] = {
            "model": model or self.settings.llm_model,
            "messages": messages,
            "temperature": temperature,
        }
        if tools:
            kwargs["tools"] = tools
            kwargs["tool_choice"] = tool_choice or "auto"

        response = await self.chat.chat.completions.create(**kwargs)
        choice = response.choices[0]
        message = choice.message
        usage = response.usage
        tool_calls = []
        if message.tool_calls:
            for tc in message.tool_calls:
                tool_calls.append(
                    {
                        "id": tc.id,
                        "type": "function",
                        "function": {
                            "name": tc.function.name,
                            "arguments": tc.function.arguments,
                        },
                    }
                )
        result = {
            "content": message.content or "",
            "tool_calls": tool_calls,
            "finish_reason": choice.finish_reason,
            "usage": {
                "prompt_tokens": int(getattr(usage, "prompt_tokens", 0) or 0),
                "completion_tokens": int(getattr(usage, "completion_tokens", 0) or 0),
            },
        }
        try:
            activity().llm(
                model=str(kwargs.get("model") or ""),
                messages=messages,
                tools=tools,
                response=result,
            )
        except Exception:
            pass
        return result

    async def embed_texts(self, texts: list[str]) -> list[list[float]]:
        if not texts:
            return []
        model = self.settings.embedding_model
        dims = int(self.settings.embedding_dims or 0)
        kwargs: dict[str, Any] = {
            "model": model,
            "input": texts,
        }
        # OpenRouter/Fal: request dims so vectors match Supabase VECTOR(n)
        if dims > 0 and not model.startswith("thenlper/"):
            kwargs["dimensions"] = dims
        try:
            response = await self.embed.embeddings.create(**kwargs)
        except Exception as exc:
            try:
                activity().section(
                    "embeddings FAILED",
                    {"model": model, "dims": dims, "batch": len(texts), "error": str(exc)[:800]},
                    kind="ERROR",
                )
            except Exception:
                pass
            raise
        vectors = [list(item.embedding) for item in response.data]
        try:
            activity().section(
                "embeddings OK",
                {"model": model, "dims_requested": dims, "dims_actual": len(vectors[0]) if vectors else 0, "batch": len(texts)},
                kind="RAG-EMBED",
            )
        except Exception:
            pass
        return vectors
