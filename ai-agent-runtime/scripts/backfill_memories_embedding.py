"""Backfill business_memories.embedding for rows that still have NULL."""
from __future__ import annotations

import asyncio
import os
import sys

import asyncpg
from dotenv import load_dotenv

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ROOT)
load_dotenv(os.path.join(ROOT, ".env"))

from app.config import get_settings
from app.llm import LlmClient


async def main() -> int:
    url = os.getenv("SUPABASE_DB_URL") or ""
    if not url:
        print("SUPABASE_DB_URL missing", file=sys.stderr)
        return 1
    kwargs: dict = {"dsn": url}
    if "supabase" in url:
        kwargs["ssl"] = "require"

    llm = LlmClient(get_settings())
    conn = await asyncpg.connect(**kwargs)
    try:
        rows = await conn.fetch(
            """
            SELECT id::text, content
            FROM business_memories
            WHERE embedding IS NULL AND content IS NOT NULL AND length(trim(content)) > 0
            ORDER BY updated_at DESC
            LIMIT 100
            """
        )
        print(f"rows_to_backfill={len(rows)}")
        for row in rows:
            vectors = await llm.embed_texts([row["content"]])
            if not vectors:
                print(f"skip embed fail id={row['id']}")
                continue
            vector_literal = "[" + ",".join(str(float(x)) for x in vectors[0]) + "]"
            await conn.execute(
                """
                UPDATE business_memories
                SET embedding = $2::vector, updated_at = NOW()
                WHERE id = $1::uuid
                """,
                row["id"],
                vector_literal,
            )
            print(f"backed id={row['id']}")
        remaining = await conn.fetchval(
            "SELECT count(*) FROM business_memories WHERE embedding IS NULL"
        )
        print(f"remaining_null={remaining}")
        return 0
    finally:
        await conn.close()


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
