"""Search logic — embed the query, run a pgvector similarity search,
join back to documents, return shaped hits."""

from __future__ import annotations

from typing import Any, Dict, List, Optional

from .db import get_pool
from .embed import embed_query
from .settings import settings


async def search_chunks(
    query: str, max_results: int, filters: Optional[Dict[str, Any]] = None
) -> List[Dict[str, Any]]:
    if not query.strip():
        return []
    n = max(1, min(int(max_results or settings.max_results_default), settings.max_results_cap))
    filters = filters or {}

    qvec = await embed_query(query)

    where_sql: List[str] = ["1=1"]
    params: List[Any] = []

    # Domain filter ('spares' | 'repair'). NULL on the document means
    # "any domain" — include those rows even when a domain filter is set.
    if (dom := filters.get("domain")) and isinstance(dom, str):
        where_sql.append(f"(d.domain IS NULL OR d.domain = ${len(params)+1})")
        params.append(dom)

    # Year comparison filter (e.g. `>=2022`, `<2020`). We accept this as
    # a string with an operator prefix to keep the wire format small.
    yr = filters.get("year")
    if isinstance(yr, str) and len(yr) >= 3:
        op = "="
        for prefix in (">=", "<=", ">", "<"):
            if yr.startswith(prefix):
                op = prefix
                yr = yr[len(prefix):]
                break
        try:
            yr_int = int(yr)
            where_sql.append(f"d.year {op} ${len(params)+1}")
            params.append(yr_int)
        except ValueError:
            pass  # silently drop malformed year filter

    where = " AND ".join(where_sql)

    # pgvector cosine similarity. `<=>` returns distance (smaller is
    # closer); we convert to a bounded similarity score for the UI.
    pool = await get_pool()
    async with pool.acquire() as conn:
        rows = await conn.fetch(
            f"""
            SELECT
                c.id              AS chunk_id,
                c.text            AS snippet,
                d.id              AS doc_id,
                d.title           AS title,
                d.source          AS source,
                d.domain          AS domain,
                d.year            AS year,
                d.metadata        AS metadata,
                1 - (c.embedding <=> ${len(params)+1}::vector) AS score
            FROM chunks c
            JOIN documents d ON d.id = c.document_id
            WHERE {where}
            ORDER BY c.embedding <=> ${len(params)+1}::vector
            LIMIT {n}
            """,
            *params,
            qvec,
        )

    hits: List[Dict[str, Any]] = []
    for r in rows:
        hits.append(
            {
                "id": str(r["chunk_id"]),
                "title": r["title"],
                "source": r["source"],
                "snippet": r["snippet"],
                "score": float(r["score"]),
                "metadata": dict(r["metadata"] or {}) | {
                    "domain": r["domain"],
                    "year": r["year"],
                },
                "citation_url": f"/doc/{r['doc_id']}",
            }
        )
    return hits
