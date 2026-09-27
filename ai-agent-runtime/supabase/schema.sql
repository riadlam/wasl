-- Wasl business knowledge + vectors (Supabase / Postgres + pgvector)
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS business_chunks (
    id UUID PRIMARY KEY,
    business_id BIGINT NOT NULL,
    namespace TEXT NOT NULL CHECK (namespace IN (
        'brand', 'products', 'faqs', 'policies', 'tone', 'posts', 'memories'
    )),
    source_type TEXT NOT NULL,
    source_id TEXT NOT NULL,
    chunk_index INT NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    embedding VECTOR(1536) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS business_chunks_source_chunk_uidx
    ON business_chunks (business_id, source_type, source_id, chunk_index);

CREATE INDEX IF NOT EXISTS business_chunks_tenant_ns_idx
    ON business_chunks (business_id, namespace);

CREATE INDEX IF NOT EXISTS business_chunks_embedding_hnsw_idx
    ON business_chunks
    USING hnsw (embedding vector_cosine_ops);

CREATE TABLE IF NOT EXISTS business_memories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    business_id BIGINT NOT NULL,
    memory_key TEXT NOT NULL,
    content TEXT NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    embedding VECTOR(1536),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (business_id, memory_key)
);

-- Existing databases: add embedding if the table was created without it.
ALTER TABLE business_memories
    ADD COLUMN IF NOT EXISTS embedding VECTOR(1536);

CREATE INDEX IF NOT EXISTS business_memories_embedding_hnsw_idx
    ON business_memories
    USING hnsw (embedding vector_cosine_ops)
    WHERE embedding IS NOT NULL;

-- Service role only: enable RLS and deny anon/authenticated by default.
ALTER TABLE business_chunks ENABLE ROW LEVEL SECURITY;
ALTER TABLE business_memories ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS deny_all_chunks ON business_chunks;
CREATE POLICY deny_all_chunks ON business_chunks
    FOR ALL TO anon, authenticated USING (false) WITH CHECK (false);

DROP POLICY IF EXISTS deny_all_memories ON business_memories;
CREATE POLICY deny_all_memories ON business_memories
    FOR ALL TO anon, authenticated USING (false) WITH CHECK (false);

-- Optional hybrid keyword assist (not used by SK Postgres connector; available for SQL).
ALTER TABLE business_chunks
    ADD COLUMN IF NOT EXISTS content_tsv tsvector
    GENERATED ALWAYS AS (to_tsvector('simple', coalesce(content, ''))) STORED;

CREATE INDEX IF NOT EXISTS business_chunks_tsv_idx ON business_chunks USING gin (content_tsv);
