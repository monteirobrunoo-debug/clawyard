# Grafana dashboards

Drop-in dashboards for the `hp-history` Prometheus exporter.

## How to import

In Grafana: **Dashboards → New → Import → Upload JSON file →
hp-history.json**, then pick the Prometheus data source that scrapes
`http://hp-history.partyard.eu/metrics` (whitelist the Prom server's
IP in `nginx` first — see `services/hp-history/scripts/deploy-do.sh`).

The dashboard is `__inputs`-based, so the data source UID gets
substituted at import time. No manual JSON editing required.

## Panels

| Row | Panel | What it tells you |
|---|---|---|
| 1 | Search latency p50/p95/p99 | Health of pgvector + rerank. p95 climbing → ANN is paging or rerank is slow. |
| 1 | Request rate by outcome | Throughput. `error` spikes → check auth + DB. |
| 2 | Hits / request | Avg useful results. Consistently 0 → search isn't finding matches; check ingest. |
| 2 | Rerank usage % | Confirms `HPH_ENABLE_RERANK=true` actually took effect. |
| 2 | Embedding chars / min | Throughput on the cost-driver. |
| 3 | Daily cost estimate (USD) | Voyage `voyage-3-large` @ $0.18/M tokens, 4 chars/token. Tweak the constant if you switch provider. |
| 3 | Doc downloads by outcome | `gone` / `not_found` ↑ → ingest broken or library volume detached. |
| 4 | Watcher passes / hour | Should equal `3600 / HPH_WATCHER_INTERVAL_SECONDS`. Drops to 0 → watcher container down. |
| 4 | Docs / chunks ingested 24h | Did anything new actually arrive? |
| 4 | Auth failures / min | Spike → rotation drift, clock skew, or HMAC probing — cross-reference with `auth.py` warning logs. |

## Alerts (suggested, not bundled)

You can pin these as Grafana alerts off the same queries:

```promql
# p95 search latency over 1.5s for 10 minutes
histogram_quantile(0.95, sum by (le) (rate(hph_search_latency_seconds_bucket[5m]))) > 1.5

# Watcher hasn't fired for an hour
increase(hph_watcher_passes_total[1h]) == 0

# Auth failures > 10/min for 5 minutes (rotation went wrong, OR attacker)
sum(rate(hph_search_requests_total{outcome="error"}[1m])) > 10

# Daily Voyage spend > $5
(sum(increase(hph_embedding_chars_total[24h])) / 4) * (0.18 / 1000000) > 5
```
