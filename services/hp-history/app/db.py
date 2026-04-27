"""asyncpg pool + schema bootstrap for the hp-history service.

Schema is intentionally tiny:

    documents
        id          uuid pk
        title       text
        source      text          where it came from (qnap path, sharepoint url…)
        domain      text          'spares' | 'repair' | NULL
        year        int           extracted-once metadata for filters
        metadata    jsonb         everything else (vessel, customer, deal_value, …)
        created_at  timestamptz

    chunks
        id          uuid pk
        document_id uuid fk → documents
        chunk_idx   int           position in the document
        text        text          ~512 token chunks
        embedding   vector(1024)  pgvector
        created_at  timestamptz

    chunks_embedding_idx   ivfflat USING vector_cosine_ops
                           with lists ~ sqrt(rows)

The `vector` extension MUST exist; the migration creates it if absent.
"""

from __future__ import annotations

import asyncpg

from .settings import settings


_POOL: asyncpg.Pool | None = None


async def get_pool() -> asyncpg.Pool:
    global _POOL
    if _POOL is None:
        _POOL = await asyncpg.create_pool(settings.pg_dsn, min_size=1, max_size=8)
    return _POOL


async def init_schema() -> None:
    pool = await get_pool()
    async with pool.acquire() as conn:
        # The pgvector extension is required. Don't silently continue
        # without it — search would error at query time anyway.
        await conn.execute("CREATE EXTENSION IF NOT EXISTS vector;")
        await conn.execute(
            """
            CREATE TABLE IF NOT EXISTS documents (
                id          UUID PRIMARY KEY,
                title       TEXT NOT NULL,
                source      TEXT NOT NULL,        -- original origin (qnap path, sharepoint url, file://…)
                local_path  TEXT,                  -- our managed copy under /data/library (set by ingest)
                mime_type   TEXT,                  -- application/pdf, text/plain, …
                domain      TEXT,
                year        INT,
                metadata    JSONB DEFAULT '{}'::jsonb,
                created_at  TIMESTAMPTZ DEFAULT NOW()
            );
            -- Add columns idempotently if upgrading from an older schema.
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS local_path TEXT;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS mime_type  TEXT;

            CREATE INDEX IF NOT EXISTS documents_domain_year_idx
                ON documents (domain, year);
            CREATE INDEX IF NOT EXISTS documents_metadata_gin
                ON documents USING GIN (metadata);
            """
        )
        await conn.execute(
            f"""
            CREATE TABLE IF NOT EXISTS chunks (
                id          UUID PRIMARY KEY,
                document_id UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                chunk_idx   INT NOT NULL,
                text        TEXT NOT NULL,
                embedding   vector({settings.embedding_dim}) NOT NULL,
                created_at  TIMESTAMPTZ DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS chunks_doc_idx ON chunks (document_id, chunk_idx);
            -- ivfflat parameters need tuning per row-count. Start with
            -- lists=100 (good for ~10k–250k chunks). Re-create with a
            -- bigger value if `EXPLAIN ANALYZE` shows poor recall.
            CREATE INDEX IF NOT EXISTS chunks_embedding_idx
                ON chunks USING ivfflat (embedding vector_cosine_ops)
                WITH (lists = 100);
            """
        )
