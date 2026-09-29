from __future__ import annotations

import json
import logging
import uuid
from typing import Any

from app.rag.store import KnowledgeStore

logger = logging.getLogger(__name__)


class LongTermMemory:
    """Durable per-business memories stored alongside vectors (business_memories + chunks)."""

    def __init__(self, store: KnowledgeStore) -> None:
        self.store = store

    async def remember(
        self,
        business_id: int,
        key: str,
        content: str,
        metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        empty = {
            "ok": False,
            "usage": {
                "prompt_tokens": 0,
                "completion_tokens": 0,
                "cost_usd": 0.0,
                "fal_calls": 0,
                "calls_with_cost": 0,
            },
        }
        await self.store.connect()
        meta = metadata or {}
        text = (content or "").strip()
        if not text:
            return empty

        # Embed once for both business_memories.embedding and memories chunks.
        vectors = await self.store.llm.embed_texts([text])
        if not vectors:
            logger.warning("LongTermMemory.remember: embedding failed for key=%s", key)
            return empty
        usage = {
            "prompt_tokens": 0,
            "completion_tokens": 0,
            "cost_usd": 0.0,
            "fal_calls": 1,
            "calls_with_cost": 0,
        }
        embedding = vectors[0]
        vector_literal = "[" + ",".join(str(float(x)) for x in embedding) + "]"

        if not self.store._pool:
            # Still index into vector namespace for retrieval even without memories table writes.
            _, upsert_usage = await self.store.upsert_document(
                business_id=business_id,
                namespace="memories",
                source_type="memory",
                source_id=key,
                content=text,
                metadata=meta,
            )
            # First embed already counted; upsert may embed again — merge if so.
            for k in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
                usage[k] = int(usage.get(k) or 0) + int((upsert_usage or {}).get(k) or 0)
            usage["cost_usd"] = float(usage.get("cost_usd") or 0) + float((upsert_usage or {}).get("cost_usd") or 0)
            return {"ok": True, "usage": usage}

        async with self.store._pool.acquire() as conn:
            await conn.execute(
                """
                INSERT INTO business_memories (id, business_id, memory_key, content, metadata, embedding, updated_at)
                VALUES ($1::uuid, $2, $3, $4, $5::jsonb, $6::vector, NOW())
                ON CONFLICT (business_id, memory_key) DO UPDATE SET
                    content = EXCLUDED.content,
                    metadata = EXCLUDED.metadata,
                    embedding = EXCLUDED.embedding,
                    updated_at = NOW()
                """,
                str(uuid.uuid5(uuid.NAMESPACE_URL, f"memory:{business_id}:{key}")),
                business_id,
                key,
                text,
                json.dumps(meta),
                vector_literal,
            )
        _, upsert_usage = await self.store.upsert_document(
            business_id=business_id,
            namespace="memories",
            source_type="memory",
            source_id=key,
            content=text,
            metadata=meta,
        )
        for k in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
            usage[k] = int(usage.get(k) or 0) + int((upsert_usage or {}).get(k) or 0)
        usage["cost_usd"] = float(usage.get("cost_usd") or 0) + float((upsert_usage or {}).get("cost_usd") or 0)
        return {"ok": True, "usage": usage}
