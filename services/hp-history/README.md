# hp-history

Company-memory service for PartYard / H&P-Group. A FastAPI app backed
by Postgres + pgvector that stores embeddings of historical PDFs,
emails, proposals and contracts so the Marco (sales) and Capitão Vasco
(vessel) agents in `clawyard` can cite precedents instead of
hallucinating them.

This is a **separate deployable** intended for its own DigitalOcean
droplet (`hp-history.partyard.eu`). It lives inside the `clawyard`
repo as a sibling so the Laravel client and the server move together
on the same commit, but nothing in `clawyard` Laravel code imports
from here.

## Surface

```
POST /search          (HMAC-authenticated)
{ "query": "MTU Series 4000 PT Navy", "max_results": 5,
  "filters": { "domain": "spares", "year": ">=2022" } }
→ { "hits": [
      { "id": "...", "title": "...", "source": "qnap://archive/...",
        "snippet": "...", "score": 0.91,
        "metadata": { "year": 2024, "domain": "spares" },
        "citation_url": "https://hp-history.partyard.eu/doc/..." }
    ]}

GET  /healthz         (no auth — load-balancer probe)
GET  /doc/{id}        (HMAC-authenticated)  → original document download
```

## HMAC contract (must match the Laravel client)

Every authenticated request carries:

| Header | Value |
|---|---|
| `X-HP-Timestamp`   | unix seconds, server tolerance ±300s |
| `X-HP-Body-SHA256` | hex sha256 of the raw request body  |
| `X-HP-Signature`   | hex `hmac_sha256(secret, "{ts}.{method}.{path}.{body_hash}")` |

Replay protection: refuse requests with timestamp drift > 300s.

## Local dev

```bash
cd services/hp-history
docker compose up -d        # postgres+pgvector + the app
python -m hp_history.ingest /path/to/folder   # index PDFs/txt
curl -X POST http://localhost:8088/search ...
```

## Deploy on DigitalOcean

Two flavours depending on whether you want a separate droplet or
to share the existing clawyard droplet:

  • `scripts/deploy-do.sh` — fresh, dedicated `hp-history.partyard.eu`
    droplet. Installs Docker + nginx + certbot + ufw, configures the
    full stack from scratch.
  • `scripts/cohost-clawyard.sh` — co-host on the EXISTING clawyard
    droplet alongside Forge. Skips package installs (Forge already
    has nginx/certbot/ufw), drops the postgres host port mapping
    (avoids colliding with Forge's database), adds a sidecar nginx
    vhost. Use this when you don't want a second droplet for cost
    or operational reasons.

## What lives where

```
services/hp-history/
├── app/               FastAPI application
│   ├── main.py        startup, route registration, healthz
│   ├── auth.py        HMAC verification middleware
│   ├── search.py      pgvector retrieval logic
│   ├── ingest.py      PDF/email/text → chunks → embeddings → DB
│   ├── db.py          asyncpg pool + schema migrations
│   └── settings.py    Pydantic settings from env
├── scripts/
│   ├── deploy-do.sh   first-time droplet bootstrap (apt, docker, certs)
│   └── ingest.sh      cron-friendly wrapper around app/ingest.py
├── docker-compose.yml postgres+pgvector + app
├── Dockerfile         FastAPI + uvicorn
├── requirements.txt   pinned dependencies
└── tests/             pytest (auth, search, ingest)
```
