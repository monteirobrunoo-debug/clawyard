"""Optional re-ranker on top of the pgvector recall step.

pgvector ANN gives us recall (find anything plausibly relevant);
a cross-encoder rerank gives us precision (sort the top-N so the most
relevant is first). Voyage's `rerank-2.5` is multilingual and handles
PT + EN well, which matches our archive.

When `settings.enable_rerank` is True, search.py:
  1. Pulls `pool_size = min(max_results * pool_factor, pool_cap)`
     candidates from pgvector.
  2. Calls this module to score each candidate against the original
     query.
  3. Reorders by rerank score and truncates to max_results.

When the rerank API call fails or the module is disabled, search
falls back to the pgvector ordering — defensive, never raises to the
caller.
"""

from __future__ import annotations

import logging
from typing import Any, Dict, List

from .settings import settings

log = logging.getLogger("hp_history.rerank")


async def maybe_rerank(query: str, hits: List[Dict[str, Any]], top_k: int) -> List[Dict[str, Any]]:
    """Idempotent wrapper:
    • Off via settings → return hits[:top_k] unchanged.
    • Empty hits → return [].
    • Rerank API failure → log + return hits[:top_k] unchanged.
    Never raises."""
    if not settings.enable_rerank or not hits:
        return hits[:top_k]
    try:
        scored = await _voyage_rerank(query, hits)
    except Exception as e:
        log.warning("rerank failed (falling back to pgvector order): %s", e)
        return hits[:top_k]

    # Each scored item is the original hit dict, augmented with
    # `rerank_score`. Sort descending; tie-breaker = original order
    # (which was already the pgvector best-match ordering).
    indexed = list(enumerate(scored))
    indexed.sort(key=lambda t: (-t[1]["rerank_score"], t[0]))
    return [h for _, h in indexed][:top_k]


async def _voyage_rerank(query: str, hits: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    import asyncio
    import voyageai

    if not settings.voyage_api_key:
        raise RuntimeError("HPH_VOYAGE_API_KEY is empty — cannot rerank")

    client = voyageai.Client(api_key=settings.voyage_api_key)
    documents = [h.get("snippet") or "" for h in hits]

    def _call() -> Any:
        return client.rerank(
            query=query,
            documents=documents,
            model=settings.rerank_model,
            top_k=len(documents),
        )

    result = await asyncio.to_thread(_call)
    # Voyage returns objects with `.index` and `.relevance_score`. Map
    # back onto the original hits, copying score onto each dict.
    out: List[Dict[str, Any]] = []
    for r in result.results:  # type: ignore[attr-defined]
        h = dict(hits[r.index])
        h["rerank_score"] = float(r.relevance_score)
        # Replace the cosine score with the rerank score in the rendered
        # output — agents care about the most precise number we have.
        h["score"] = h["rerank_score"]
        out.append(h)
    return out
