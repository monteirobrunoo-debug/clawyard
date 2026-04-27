"""Unit tests for the optional re-ranker.

The Voyage SDK is replaced by a stub via monkeypatch so the tests run
offline. We only need to exercise the wiring:
  • disabled → return hits[:top_k] unchanged
  • empty hits → []
  • API error → fall back to original order, never raise
  • happy path → reorder by relevance_score, copy score onto hits
"""

from __future__ import annotations

import pytest

from app import rerank
from app.settings import settings


HITS = [
    {"id": "a", "title": "A", "snippet": "alpha alpha", "score": 0.55, "source": "s"},
    {"id": "b", "title": "B", "snippet": "beta beta",  "score": 0.50, "source": "s"},
    {"id": "c", "title": "C", "snippet": "gamma gamma","score": 0.45, "source": "s"},
]


@pytest.mark.asyncio
async def test_disabled_returns_unchanged(monkeypatch):
    monkeypatch.setattr(settings, "enable_rerank", False)
    out = await rerank.maybe_rerank("q", HITS, top_k=2)
    assert [h["id"] for h in out] == ["a", "b"]


@pytest.mark.asyncio
async def test_empty_hits_returns_empty(monkeypatch):
    monkeypatch.setattr(settings, "enable_rerank", True)
    out = await rerank.maybe_rerank("q", [], top_k=5)
    assert out == []


@pytest.mark.asyncio
async def test_api_error_falls_back_to_original_order(monkeypatch):
    monkeypatch.setattr(settings, "enable_rerank", True)

    async def boom(query, hits):
        raise RuntimeError("voyage down")

    monkeypatch.setattr(rerank, "_voyage_rerank", boom)
    out = await rerank.maybe_rerank("q", HITS, top_k=2)
    # Falls back silently — first two of the original order.
    assert [h["id"] for h in out] == ["a", "b"]


@pytest.mark.asyncio
async def test_happy_path_reorders_by_relevance(monkeypatch):
    monkeypatch.setattr(settings, "enable_rerank", True)

    async def fake(query, hits):
        # Return scores that put "c" first, then "a", then "b".
        scores_by_id = {"a": 0.7, "b": 0.4, "c": 0.95}
        out = []
        for h in hits:
            new = dict(h)
            new["rerank_score"] = scores_by_id[h["id"]]
            new["score"] = new["rerank_score"]
            out.append(new)
        return out

    monkeypatch.setattr(rerank, "_voyage_rerank", fake)
    out = await rerank.maybe_rerank("q", HITS, top_k=2)
    assert [h["id"] for h in out] == ["c", "a"]
    # Score replaced with rerank score for the agent prompt.
    assert out[0]["score"] == pytest.approx(0.95)
    assert out[1]["score"] == pytest.approx(0.7)
