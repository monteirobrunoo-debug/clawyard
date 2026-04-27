"""Prometheus metrics for hp-history.

Exposed unauthenticated at GET /metrics — same convention as every
other Prom exporter. The droplet's nginx config should restrict it
to the monitoring server's IP (or to localhost) — see deploy-do.sh.

Why these specific metrics:

  • search_latency_seconds (histogram) — visualise p50/p95/p99 search
    times. Buckets chosen for typical pgvector + rerank latencies:
    50ms / 200ms / 500ms / 1s / 2s / 5s.
  • search_hits (counter) — total hits returned across all searches.
    Combined with search_requests_total it gives mean hits/request.
  • search_requests_total (counter, labelled) — broken down by
    `outcome` (ok | empty | error) and `rerank` (on | off) so we can
    answer "is rerank actually changing anything?" without looking
    at code.
  • embedding_calls_total / embedding_chars_total — proxy for cost.
    Voyage and OpenAI both bill per token; chars / 4 is a reasonable
    estimate for the dashboard (no need to bring the real tokeniser
    onto the hot path).
  • ingest_documents_total / ingest_chunks_total — what came in.
  • watcher_passes_total / watcher_files_seen_total — does the
    background watcher fire as expected.

Metrics module is import-safe: failure to import prometheus_client
should never break the request path. We swallow the ImportError and
hand back no-op shims so the rest of the codebase can call them
unconditionally.
"""

from __future__ import annotations

import logging
import time
from contextlib import contextmanager
from typing import Iterator

log = logging.getLogger("hp_history.metrics")


try:
    from prometheus_client import (
        CollectorRegistry,
        Counter,
        Histogram,
        generate_latest,
        CONTENT_TYPE_LATEST,
    )
    _AVAILABLE = True
except Exception as e:                # pragma: no cover — only triggers on broken installs
    log.warning("prometheus_client unavailable, metrics disabled: %s", e)
    _AVAILABLE = False


if _AVAILABLE:
    REGISTRY = CollectorRegistry()

    SEARCH_LATENCY = Histogram(
        "hph_search_latency_seconds",
        "End-to-end /search request latency (embedding + pgvector + optional rerank).",
        buckets=(0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10),
        registry=REGISTRY,
    )
    SEARCH_REQUESTS = Counter(
        "hph_search_requests_total",
        "Number of /search requests served, labelled by outcome.",
        ("outcome", "rerank"),
        registry=REGISTRY,
    )
    SEARCH_HITS = Counter(
        "hph_search_hits_total",
        "Total hit rows returned across all /search requests.",
        registry=REGISTRY,
    )
    EMBEDDING_CALLS = Counter(
        "hph_embedding_calls_total",
        "Number of embedding API calls.",
        ("kind",),    # 'document' | 'query'
        registry=REGISTRY,
    )
    EMBEDDING_CHARS = Counter(
        "hph_embedding_chars_total",
        "Total characters submitted for embedding (cost proxy).",
        ("kind",),
        registry=REGISTRY,
    )
    INGEST_DOCS = Counter(
        "hph_ingest_documents_total",
        "Documents ingested via CLI / watcher.",
        ("source",),  # 'cli' | 'watcher'
        registry=REGISTRY,
    )
    INGEST_CHUNKS = Counter(
        "hph_ingest_chunks_total",
        "Chunks written via CLI / watcher.",
        ("source",),
        registry=REGISTRY,
    )
    WATCHER_PASSES = Counter(
        "hph_watcher_passes_total",
        "Number of completed watcher polling passes.",
        registry=REGISTRY,
    )
    DOC_DOWNLOADS = Counter(
        "hph_doc_downloads_total",
        "Number of /doc/{id} responses, labelled by outcome.",
        ("outcome",), # 'served' | 'metadata_only' | 'not_found' | 'gone' | 'forbidden'
        registry=REGISTRY,
    )
else:
    SEARCH_LATENCY = None
    SEARCH_REQUESTS = None
    SEARCH_HITS = None
    EMBEDDING_CALLS = None
    EMBEDDING_CHARS = None
    INGEST_DOCS = None
    INGEST_CHUNKS = None
    WATCHER_PASSES = None
    DOC_DOWNLOADS = None


def _safe(metric, *labels, kind: str = "counter", value: float | int = 1) -> None:
    """No-op shim when prometheus_client failed to import. Keeps the
    call sites in the rest of the codebase unconditional."""
    if metric is None:
        return
    try:
        if labels:
            metric = metric.labels(*labels)
        if kind == "counter":
            metric.inc(value)
        elif kind == "observe":
            metric.observe(value)
    except Exception as e:    # pragma: no cover
        log.debug("metric write failed (ignored): %s", e)


def inc_search_request(outcome: str, rerank_on: bool) -> None:
    _safe(SEARCH_REQUESTS, outcome, "on" if rerank_on else "off")


def inc_search_hits(n: int) -> None:
    if n > 0:
        _safe(SEARCH_HITS, value=n)


def inc_embedding(kind: str, char_count: int) -> None:
    _safe(EMBEDDING_CALLS, kind)
    _safe(EMBEDDING_CHARS, kind, value=char_count)


def inc_ingest(source: str, docs: int, chunks: int) -> None:
    if docs > 0:
        _safe(INGEST_DOCS, source, value=docs)
    if chunks > 0:
        _safe(INGEST_CHUNKS, source, value=chunks)


def inc_watcher_pass() -> None:
    _safe(WATCHER_PASSES)


def inc_doc_download(outcome: str) -> None:
    _safe(DOC_DOWNLOADS, outcome)


@contextmanager
def time_search() -> Iterator[None]:
    if SEARCH_LATENCY is None:
        yield
        return
    start = time.perf_counter()
    try:
        yield
    finally:
        SEARCH_LATENCY.observe(time.perf_counter() - start)


def render_text() -> tuple[bytes, str]:
    """Return (payload, content_type) for the /metrics endpoint."""
    if not _AVAILABLE:
        return b"# prometheus_client not installed\n", "text/plain; charset=utf-8"
    return generate_latest(REGISTRY), CONTENT_TYPE_LATEST
