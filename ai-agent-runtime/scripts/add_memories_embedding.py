"""Add embedding column to business_memories via Supabase Postgres API (asyncpg)."""
from __future__ import annotations

import asyncio
import os
import sys

import asyncpg
from dotenv import load_dotenv

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
load_dotenv(os.path.join(ROOT, ".env"))


async def main() -> int:
    url = os.getenv("SUPABASE_DB_URL") or ""
    if not url:
        print("SUPABASE_DB_URL missing", file=sys.stderr)
        return 1

    kwargs: dict = {"dsn": url}
    if "supabase" in url:
        kwargs["ssl"] = "require"

    conn = await asyncpg.connect(**kwargs)
    try:
        before = await conn.fetch(
            """
            SELECT column_name, data_type, udt_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'business_memories'
            ORDER BY ordinal_position
            """
        )
        print("before:")
        for row in before:
            print(f"  {row['column_name']}: {row['udt_name']}")

        await conn.execute("CREATE EXTENSION IF NOT EXISTS vector")
        await conn.execute(
            """
            ALTER TABLE business_memories
            ADD COLUMN IF NOT EXISTS embedding VECTOR(1536)
            """
        )
        await conn.execute(
            """
            CREATE INDEX IF NOT EXISTS business_memories_embedding_hnsw_idx
            ON business_memories
            USING hnsw (embedding vector_cosine_ops)
            WHERE embedding IS NOT NULL
            """
        )

        after = await conn.fetch(
            """
            SELECT column_name, data_type, udt_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'business_memories'
            ORDER BY ordinal_position
            """
        )
        print("after:")
        for row in after:
            print(f"  {row['column_name']}: {row['udt_name']}")
        print("OK: business_memories.embedding VECTOR(1536) ready")
        return 0
    finally:
        await conn.close()


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
