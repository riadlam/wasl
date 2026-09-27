from __future__ import annotations

import hashlib
import json
import logging
import re
import uuid
from typing import Any

import asyncpg

from app.config import Settings, get_settings
from app.llm import LlmClient

logger = logging.getLogger(__name__)


def chunk_text(text: str, chunk_size: int = 800, overlap: int = 120) -> list[str]:
    cleaned = re.sub(r"\s+", " ", text or "").strip()
    if not cleaned:
        return []
    if len(cleaned) <= chunk_size:
        return [cleaned]
    chunks: list[str] = []
    start = 0
    while start < len(cleaned):
        end = min(len(cleaned), start + chunk_size)
        chunks.append(cleaned[start:end].strip())
        if end >= len(cleaned):
            break
        start = max(0, end - overlap)
    return [c for c in chunks if c]


class KnowledgeStore:
    """Tenant-scoped pgvector store backed by Supabase/Postgres."""

    def __init__(self, settings: Settings | None = None, llm: LlmClient | None = None) -> None:
        self.settings = settings or get_settings()
        self.llm = llm or LlmClient(self.settings)
        self._pool: asyncpg.Pool | None = None

    async def connect(self) -> None:
        if self._pool or not self.settings.supabase_db_url:
            return
        # Supabase pooler needs TLS. Use the same ssl mode that works with asyncpg.connect.
        url = self.settings.supabase_db_url
        kwargs: dict[str, Any] = {"dsn": url, "min_size": 1, "max_size": 5}
        if "supabase" in url:
            kwargs["ssl"] = "require"
        self._pool = await asyncpg.create_pool(**kwargs)

    async def close(self) -> None:
        if self._pool:
            await self._pool.close()
            self._pool = None

    @property
    def available(self) -> bool:
        return bool(self.settings.supabase_db_url)

    async def upsert_document(
        self,
        *,
        business_id: int,
        namespace: str,
        source_type: str,
        source_id: str,
        content: str,
        metadata: dict[str, Any] | None = None,
        chunk_size: int = 800,
        chunk_overlap: int = 120,
    ) -> int:
        await self.connect()
        pieces = chunk_text(content, chunk_size, chunk_overlap)
        if not pieces:
            return 0
        if not self._pool:
            logger.warning("KnowledgeStore: no DB pool; dry-run ingest of %s chunks", len(pieces))
            return len(pieces)

        embeddings = await self.llm.embed_texts(pieces)
        meta = metadata or {}
        async with self._pool.acquire() as conn:
            await conn.execute(
                """
                DELETE FROM business_chunks
                WHERE business_id = $1 AND source_type = $2 AND source_id = $3
                """,
                business_id,
                source_type,
                source_id,
            )
            for index, (piece, embedding) in enumerate(zip(pieces, embeddings, strict=True)):
                chunk_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"{business_id}:{source_type}:{source_id}:{index}"))
                content_hash = hashlib.sha256(piece.encode("utf-8")).hexdigest()
                vector_literal = "[" + ",".join(str(float(x)) for x in embedding) + "]"
                await conn.execute(
                    """
                    INSERT INTO business_chunks (
                        id, business_id, namespace, source_type, source_id, chunk_index,
                        content, content_hash, metadata, embedding, updated_at
                    ) VALUES (
                        $1::uuid, $2, $3, $4, $5, $6,
                        $7, $8, $9::jsonb, $10::vector, NOW()
                    )
                    ON CONFLICT (id) DO UPDATE SET
                        content = EXCLUDED.content,
                        content_hash = EXCLUDED.content_hash,
                        metadata = EXCLUDED.metadata,
                        embedding = EXCLUDED.embedding,
                        updated_at = NOW()
                    """,
                    chunk_id,
                    business_id,
                    namespace,
                    source_type,
                    source_id,
                    index,
                    piece,
                    content_hash,
                    __import__("json").dumps(meta),
                    vector_literal,
                )
        return len(pieces)

    async def search(
        self,
        *,
        business_id: int,
        query: str,
        namespace: str | None = None,
        top_k: int = 6,
        query_embedding: list[float] | None = None,
    ) -> list[dict[str, Any]]:
        await self.connect()
        if not query.strip() and query_embedding is None:
            return []
        if not self._pool:
            return []

        if query_embedding is not None:
            vectors = [query_embedding]
        else:
            vectors = await self.llm.embed_texts([query])
        if not vectors:
            return []
        vector_literal = "[" + ",".join(str(float(x)) for x in vectors[0]) + "]"

        if namespace:
            sql = """
                SELECT id::text, content, namespace, source_type, source_id, metadata,
                       1 - (embedding <=> $1::vector) AS score
                FROM business_chunks
                WHERE business_id = $2 AND namespace = $3
                ORDER BY embedding <=> $1::vector
                LIMIT $4
            """
            args = (vector_literal, business_id, namespace, top_k)
        else:
            sql = """
                SELECT id::text, content, namespace, source_type, source_id, metadata,
                       1 - (embedding <=> $1::vector) AS score
                FROM business_chunks
                WHERE business_id = $2
                ORDER BY embedding <=> $1::vector
                LIMIT $3
            """
            args = (vector_literal, business_id, top_k)

        async with self._pool.acquire() as conn:
            rows = await conn.fetch(sql, *args)

        return self._rows_to_hits(rows)

    async def search_namespaces(
        self,
        *,
        business_id: int,
        query: str,
        namespaces: list[str] | tuple[str, ...],
        top_k_per_ns: int = 3,
    ) -> list[dict[str, Any]]:
        """One embed, then nearest chunks per namespace (avoids N× embedding latency)."""
        await self.connect()
        if not query.strip() or not namespaces:
            return []
        if not self._pool:
            return []

        vectors = await self.llm.embed_texts([query])
        if not vectors:
            return []
        emb = vectors[0]
        hits: list[dict[str, Any]] = []
        for ns in namespaces:
            part = await self.search(
                business_id=business_id,
                query=query,
                namespace=ns,
                top_k=top_k_per_ns,
                query_embedding=emb,
            )
            hits.extend(part)
        return hits

    @staticmethod
    def _rows_to_hits(rows: Any) -> list[dict[str, Any]]:
        hits: list[dict[str, Any]] = []
        for row in rows:
            meta = row["metadata"]
            if isinstance(meta, str):
                try:
                    meta = json.loads(meta)
                except Exception:
                    meta = {}
            hits.append(
                {
                    "id": row["id"],
                    "content": row["content"],
                    "namespace": row["namespace"],
                    "source_type": row["source_type"],
                    "source_id": row["source_id"],
                    "score": float(row["score"] or 0),
                    "metadata": meta or {},
                }
            )
        return hits
