"""Pure unit tests for the metrics module — no FastAPI server, no
Prometheus scrape endpoint. The module's job is to expose typed
counters/histograms and to expose a `render_text()` that produces a
Prometheus-compatible payload.

We don't snapshot the full output (that's brittle); instead we assert
that incrementing through the public helpers shows up in the rendered
text under the expected metric name.
"""

from __future__ import annotations

import pytest

from app import metrics


@pytest.fixture(autouse=True)
def _isolate_metrics():
    """Each test sees a fresh registry. We can't `del REGISTRY` (it's
    module-level state) so for the unit tests below we reset the
    counters by re-importing the module — pytest's import cache makes
    that fragile, so we just rely on relative deltas instead."""
    yield


def _scrape() -> str:
    payload, _ = metrics.render_text()
    return payload.decode("utf-8")


def test_render_text_returns_prometheus_payload():
    text = _scrape()
    # Either prometheus_client is available and we see HELP lines, or
    # the module degraded to no-op shims and we see the disabled
    # banner. Either is acceptable on a CI box without the lib.
    assert "hph_search_requests_total" in text or "prometheus_client not installed" in text


def test_inc_search_request_records_outcome_and_rerank_label():
    if not getattr(metrics, "_AVAILABLE", False):
        pytest.skip("prometheus_client not installed in this env")

    metrics.inc_search_request("ok", rerank_on=True)
    metrics.inc_search_request("empty", rerank_on=False)
    metrics.inc_search_request("ok", rerank_on=False)

    text = _scrape()
    # Rows are { outcome, rerank } pairs — ensure both labels surface.
    assert 'outcome="ok"' in text
    assert 'outcome="empty"' in text
    assert 'rerank="on"' in text
    assert 'rerank="off"' in text


def test_inc_search_hits_increments_total():
    if not getattr(metrics, "_AVAILABLE", False):
        pytest.skip("prometheus_client not installed in this env")

    before = _scrape()
    metrics.inc_search_hits(3)
    after = _scrape()
    # Total increased by at least 3.
    def _val(text):
        for line in text.splitlines():
            if line.startswith("hph_search_hits_total ") and not line.startswith("# "):
                return float(line.split()[-1])
        return 0.0
    assert _val(after) >= _val(before) + 3


def test_inc_doc_download_records_each_outcome():
    if not getattr(metrics, "_AVAILABLE", False):
        pytest.skip("prometheus_client not installed in this env")

    for outcome in ("served", "not_found", "gone", "forbidden", "metadata_only"):
        metrics.inc_doc_download(outcome)
    text = _scrape()
    for outcome in ("served", "not_found", "gone", "forbidden", "metadata_only"):
        assert f'outcome="{outcome}"' in text


def test_time_search_observes_latency():
    if not getattr(metrics, "_AVAILABLE", False):
        pytest.skip("prometheus_client not installed in this env")

    with metrics.time_search():
        pass    # trivial — we only need the histogram to record _something_

    text = _scrape()
    # Histogram lines have _bucket / _count / _sum suffixes.
    assert "hph_search_latency_seconds_count" in text
    assert "hph_search_latency_seconds_bucket" in text


def test_safe_helper_swallows_when_metric_is_none():
    # When prometheus_client is absent the inc_* helpers should be
    # no-ops, never raise. We force the disabled path by handing a None
    # metric to the internal helper.
    metrics._safe(None, "x")        # must not raise
    metrics._safe(None, "x", "y", kind="observe", value=1.5)
