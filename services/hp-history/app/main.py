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
