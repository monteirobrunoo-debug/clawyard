"""FastAPI entry point — registers routes, applies HMAC middleware,
boots the DB pool on startup."""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request, Response
from pydantic import BaseModel, Field

from .auth import hmac_middleware
from .db import init_schema
from .docs import get_document
from . import metrics
from .search import search_chunks
from .settings import settings

log = logging.getLogger("hp_history")


class SearchRequest(BaseModel):
    query: str = Field(..., min_length=1, max_length=4000)
    max_results: int = Field(default=5, ge=1, le=20)
    filters: dict = Field(default_factory=dict)


@asynccontextmanager
async def lifespan(_: FastAPI):
    await init_schema()
    yield


app = FastAPI(title="hp-history", lifespan=lifespan)
app.middleware("http")(hmac_middleware)


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/metrics")
async def prometheus_metrics() -> Response:
    """Prometheus scrape endpoint. Exempt from HMAC (auth.py whitelists
    it). Restrict at the network layer — see deploy-do.sh nginx config."""
    payload, content_type = metrics.render_text()
    return Response(content=payload, media_type=content_type)


@app.post("/search")
async def search(req: Request) -> dict:
    # Parsing is done manually because the HMAC middleware already
    # consumed/replaced request._receive — this avoids a double-buffer.
    body = await req.body()
    try:
        payload = SearchRequest.model_validate_json(body)
    except Exception:
        metrics.inc_search_request("error", settings.enable_rerank)
        raise

    with metrics.time_search():
        try:
            hits = await search_chunks(
                query=payload.query,
                max_results=payload.max_results,
                filters=payload.filters,
            )
        except Exception:
            metrics.inc_search_request("error", settings.enable_rerank)
            raise

    metrics.inc_search_request(
        "ok" if hits else "empty",
        settings.enable_rerank,
    )
    metrics.inc_search_hits(len(hits))
    return {"hits": hits}


@app.get("/doc/{doc_id}")
async def fetch_document(doc_id: str):
    """Authenticated download of an archived document by its UUID.
    The HMAC middleware enforces auth — by the time we reach here the
    caller has already proven possession of the shared secret."""
    from fastapi.responses import FileResponse, JSONResponse
    response = await get_document(doc_id)
    if isinstance(response, FileResponse):
        metrics.inc_doc_download("served")
    elif isinstance(response, JSONResponse):
        metrics.inc_doc_download("metadata_only")
    return response


@app.post("/ingest/upload")
async def ingest_upload(req: Request) -> dict:
    """HTTP-driven ingestion. Accepts a JSON-over-HMAC payload with
    base64-encoded files so the existing HMAC middleware works without
    changes (multipart/form-data would force us to rewrite the body
    buffering pipeline).

    Request body (already HMAC-validated by the time we reach here):
        {
          "files": [
            { "filename": "proposal_2024_03.pdf", "content_b64": "<base64>" },
            …
          ],
          "domain": "spares" | "marine" | "military" | null,
          "year": 2024 | null,
          "metadata": { "uploader": "monica.pereira", "source": "clawyard-ui" }
        }

    Response:
        {
          "ok": true,
          "docs_ingested": 3,
          "chunks_ingested": 42,
          "skipped": ["empty.pdf"]
        }

    The clawyard side wraps this with a drag-drop UI at /hp-history/upload
    so managers can feed historical proposals in without SSH'ing the droplet.

    Limits:
        • Max 10 files per request (rate-limit at the app layer; nginx
          can also enforce client_max_body_size if you want a harder cap).
        • Each file ≤ 16MB after base64-decode.
    """
    import base64
    import json
    import shutil
    import tempfile
    from pathlib import Path

    body = await req.body()
    try:
        payload = json.loads(body)
    except Exception:
        return {"ok": False, "error": "invalid_json"}

    files = payload.get("files") or []
    if not isinstance(files, list) or not files:
        return {"ok": False, "error": "no_files"}
    if len(files) > 10:
        return {"ok": False, "error": "too_many_files_max_10"}

    domain = payload.get("domain")
    year = payload.get("year")
    extra_metadata = dict(payload.get("metadata") or {})

    skipped: list[str] = []
    with tempfile.TemporaryDirectory(prefix="hph_upload_") as tmp_dir:
        tmp_path = Path(tmp_dir)
        for entry in files:
            name = (entry.get("filename") or "").strip()
            content_b64 = entry.get("content_b64") or ""
            if not name or not content_b64:
                skipped.append(name or "(unnamed)")
                continue
            # Allow only the same extensions the CLI accepts.
            if Path(name).suffix.lower() not in {".pdf", ".txt", ".md"}:
                skipped.append(name)
                continue
            try:
                raw = base64.b64decode(content_b64, validate=True)
            except Exception:
                skipped.append(name)
                continue
            if len(raw) > 16 * 1024 * 1024:
                skipped.append(name + " (>16MB)")
                continue
            (tmp_path / name).write_bytes(raw)

        if not any(tmp_path.iterdir()):
            return {"ok": True, "docs_ingested": 0, "chunks_ingested": 0, "skipped": skipped}

        # Reuse the same ingest_folder() the CLI uses — keeps the
        # idempotency + chunking + embedding pipeline identical.
        from .ingest import ingest_folder
        docs_in, chunks_in = await ingest_folder(
            folder=tmp_path,
            domain=domain,
            year=year,
            extra_metadata=extra_metadata,
        )

    return {
        "ok": True,
        "docs_ingested": docs_in,
        "chunks_ingested": chunks_in,
        "skipped": skipped,
    }
