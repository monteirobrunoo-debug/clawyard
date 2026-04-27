"""FastAPI entry point — registers routes, applies HMAC middleware,
boots the DB pool on startup."""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from pydantic import BaseModel, Field

from .auth import hmac_middleware
from .db import init_schema
from .search import search_chunks

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


@app.post("/search")
async def search(req: Request) -> dict:
    # Parsing is done manually because the HMAC middleware already
    # consumed/replaced request._receive — this avoids a double-buffer.
    body = await req.body()
    payload = SearchRequest.model_validate_json(body)
    hits = await search_chunks(
        query=payload.query,
        max_results=payload.max_results,
        filters=payload.filters,
    )
    return {"hits": hits}
