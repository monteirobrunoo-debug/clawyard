"""
PartYard LLM Proxy — FastAPI redacting forwarder to Anthropic.

Architecture
------------
    ClawYard (Laravel)
        │   POST https://llm-proxy.partyard.eu/v1/messages
        │   Host: llm-proxy.partyard.eu
        │   x-api-key: sk-ant-...
        │
        ▼
    nginx :443  (llm-proxy.partyard.eu vhost)
        │   proxy_pass http://127.0.0.1:8787
        │
        ▼
    uvicorn :8787  (this app, localhost only)
        │   1. read body
        │   2. scrub messages[] + system with redactor.py
        │   3. httpx → https://api.anthropic.com (streaming)
        │
        ▼
    Anthropic API

Only what this process forwards leaves the droplet. The original prompt
stays in Laravel's encrypted `messages.content` column; the scrubbed version
goes upstream. Redaction counts are logged but the prompt text itself is
never persisted here.

Environment
-----------
    PROXY_LISTEN_HOST           default 127.0.0.1
    PROXY_LISTEN_PORT           default 8787
    UPSTREAM_BASE_URL           default https://api.anthropic.com
    PROXY_LOG_DIR               default /var/log/partyard-llm-proxy
    PROXY_ALLOW_DIRECT_KEY      if set, Anthropic key may come from request
                                header; otherwise we hard-require it (the
                                proxy never stores the key itself).
    PROXY_MAX_BODY_BYTES        default 25 * 1024 * 1024

Logs
----
Two logs, both JSONL, both rotated by logrotate (see deploy notes):
    - access.jsonl   — one line per upstream call (no prompt content)
    - redact.jsonl   — one line per scrub, with category counts only

Neither log ever contains prompt text, API keys, or response bodies.
"""
from __future__ import annotations

import asyncio
import json
import logging
import os
import time
import uuid
from pathlib import Path
from typing import Any

import httpx
from fastapi import FastAPI, Request, Response
from fastapi.responses import JSONResponse, StreamingResponse

from redactor import scrub_anthropic_body
from auth import auth_enabled, verify_request


# ── Config ─────────────────────────────────────────────────────────────────

UPSTREAM_BASE_URL = os.getenv("UPSTREAM_BASE_URL", "https://api.anthropic.com").rstrip("/")
PROXY_MAX_BODY_BYTES = int(os.getenv("PROXY_MAX_BODY_BYTES", 25 * 1024 * 1024))
PROXY_LOG_DIR = Path(os.getenv("PROXY_LOG_DIR", "/home/forge/llm-proxy/logs"))
PROXY_CONNECT_TIMEOUT = float(os.getenv("PROXY_CONNECT_TIMEOUT", "10"))
PROXY_READ_TIMEOUT = float(os.getenv("PROXY_READ_TIMEOUT", "300"))

# Headers we deliberately DO NOT forward upstream — hop-by-hop or dangerous.
HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
    "host",            # httpx sets Host automatically from base_url
    "content-length",  # httpx recomputes after potential body rewrite
    "x-forwarded-for",
    "x-forwarded-proto",
    "x-forwarded-host",
}

# Headers to strip from the RESPONSE before relaying to the downstream client.
# httpx decompresses gzip/deflate transparently, so forwarding the original
# `Content-Encoding: gzip` header would lie to the downstream client and
# trigger cURL error 61 ("incorrect header check"). We strip the encoding/
# length/transfer headers and let Starlette recompute them from the actual
# bytes we hand it.
RESPONSE_STRIP = {
    "content-encoding",
    "content-length",
    "transfer-encoding",
    "connection",
    "keep-alive",
}


def _filter_response_headers(src: dict[str, str]) -> dict[str, str]:
    """Like _filter_headers but for responses coming back from upstream —
    additionally strips encoding/length that no longer match our body."""
    out: dict[str, str] = {}
    for k, v in src.items():
        if k.lower() in RESPONSE_STRIP:
            continue
        out[k] = v
    return out


# ── Logging — JSONL, explicit, no prompt text ever ─────────────────────────

PROXY_LOG_DIR.mkdir(parents=True, exist_ok=True)

def _jsonl_logger(name: str, filename: str) -> logging.Logger:
    logger = logging.getLogger(name)
    logger.setLevel(logging.INFO)
    logger.propagate = False
    # Only add handler once — FastAPI reloads modules in dev.
    if not logger.handlers:
        handler = logging.FileHandler(PROXY_LOG_DIR / filename)
        handler.setFormatter(logging.Formatter("%(message)s"))
        logger.addHandler(handler)
    return logger

access_log = _jsonl_logger("partyard.access", "access.jsonl")
redact_log = _jsonl_logger("partyard.redact", "redact.jsonl")

# Uvicorn console — surface errors only, not request bodies.
stderr_log = logging.getLogger("partyard.proxy")
stderr_log.setLevel(logging.INFO)
if not stderr_log.handlers:
    h = logging.StreamHandler()
    h.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    stderr_log.addHandler(h)


def _jlog(logger: logging.Logger, **fields: Any) -> None:
    """Emit one JSON line; never let logging raise."""
    try:
        logger.info(json.dumps(fields, ensure_ascii=False, default=str))
    except Exception as e:  # pragma: no cover
        stderr_log.warning("log-emit-failed: %s", e)


# ── HTTP client (shared pool, SSE-safe) ─────────────────────────────────────

# A single long-lived AsyncClient — httpx pools connections so streaming
# SSE requests don't reopen TLS on every turn. Timeout `read=None` lets
# Opus runs stream for minutes without us timing out locally.
_http_client: httpx.AsyncClient | None = None


async def _get_client() -> httpx.AsyncClient:
    global _http_client
    if _http_client is None:
        timeout = httpx.Timeout(
            connect=PROXY_CONNECT_TIMEOUT,
            read=PROXY_READ_TIMEOUT,
            write=PROXY_READ_TIMEOUT,
            pool=PROXY_CONNECT_TIMEOUT,
        )
        limits = httpx.Limits(max_connections=50, max_keepalive_connections=20)
        _http_client = httpx.AsyncClient(
            base_url=UPSTREAM_BASE_URL,
            timeout=timeout,
            limits=limits,
            follow_redirects=False,
            http2=False,  # Anthropic SSE is cleaner over HTTP/1.1
        )
    return _http_client


# ── FastAPI app ────────────────────────────────────────────────────────────

app = FastAPI(
    title="PartYard LLM Proxy",
    version="1.0.0",
    openapi_url=None,      # no /openapi.json
    docs_url=None,         # no /docs
    redoc_url=None,        # no /redoc
)


@app.on_event("shutdown")
async def _shutdown() -> None:  # pragma: no cover
    global _http_client
    if _http_client is not None:
        await _http_client.aclose()
        _http_client = None


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    """Liveness probe for nginx / systemd. Never reaches Anthropic.
    Does NOT require HMAC — monitoring probes have no shared key."""
    return {
        "status": "ok",
        "upstream": UPSTREAM_BASE_URL,
        "auth": "hmac" if auth_enabled() else "disabled",
    }


async def _require_signature(request: Request, body: bytes, req_id: str) -> JSONResponse | None:
    """Return a 401 response if the split-VM HMAC is enabled and the
    request doesn't carry a valid signature. Returns None on success or
    when auth is disabled (loopback topology)."""
    if not auth_enabled():
        return None

    sig_primary = request.headers.get("x-py-signature")
    sig_next = request.headers.get("x-py-signature-next")
    ts = request.headers.get("x-py-timestamp")
    outcome = verify_request(
        timestamp_header=ts,
        signature_headers=[sig_primary or "", sig_next or ""],
        body=body,
    )
    if outcome.ok:
        return None

    # Log the reason server-side; return a deliberately opaque 401.
    _jlog(access_log, id=req_id, path=str(request.url.path),
          auth_reject=outcome.reason,
          peer=request.client.host if request.client else "unknown")
    return JSONResponse(
        {"error": {"type": "authentication_error",
                   "message": "Invalid or missing proxy signature."}},
        status_code=401,
    )


def _filter_headers(src: dict[str, str]) -> dict[str, str]:
    """Drop hop-by-hop headers and normalize casing."""
    out: dict[str, str] = {}
    for k, v in src.items():
        if k.lower() in HOP_BY_HOP:
            continue
        out[k] = v
    return out


async def _forward_non_json(request: Request, path: str, req_id: str) -> Response:
    """Pass-through for endpoints that don't carry JSON message bodies
    (e.g. GET /v1/models). No redaction needed; no streaming tricks."""
    client = await _get_client()
    headers = _filter_headers(dict(request.headers))
    body = await request.body()
    t0 = time.monotonic()
    try:
        upstream = await client.request(
            request.method,
            "/" + path.lstrip("/"),
            headers=headers,
            content=body,
            params=request.query_params,
        )
    except httpx.HTTPError as e:
        _jlog(access_log, id=req_id, path=path, method=request.method,
              error=str(e), ms=int((time.monotonic() - t0) * 1000))
        return JSONResponse(
            {"error": {"type": "proxy_error", "message": "Upstream unavailable"}},
            status_code=502,
        )
    _jlog(access_log, id=req_id, path=path, method=request.method,
          status=upstream.status_code, ms=int((time.monotonic() - t0) * 1000),
          bytes_in=len(body), bytes_out=len(upstream.content))
    return Response(
        content=upstream.content,
        status_code=upstream.status_code,
        headers=_filter_response_headers(dict(upstream.headers)),
        media_type=upstream.headers.get("content-type"),
    )


@app.post("/v1/messages")
async def v1_messages(request: Request) -> Response:
    """Primary entry — redacts then forwards, streaming SSE through."""
    req_id = uuid.uuid4().hex[:12]
    t0 = time.monotonic()

    raw = await request.body()
    if len(raw) > PROXY_MAX_BODY_BYTES:
        _jlog(access_log, id=req_id, path="/v1/messages",
              error="body_too_large", bytes_in=len(raw))
        return JSONResponse(
            {"error": {"type": "payload_too_large",
                       "message": f"Body exceeds {PROXY_MAX_BODY_BYTES} bytes"}},
            status_code=413,
        )

    # Split-VM HMAC: only enforced when PY_PROXY_SHARED_KEY is set on the
    # proxy VM. In the loopback topology this is a no-op.
    auth_reject = await _require_signature(request, raw, req_id)
    if auth_reject is not None:
        return auth_reject

    # Parse JSON. Anything non-JSON is a client bug → 400 (we could forward
    # as-is but then redaction is impossible, and Anthropic requires JSON).
    try:
        body = json.loads(raw) if raw else {}
    except json.JSONDecodeError as e:
        _jlog(access_log, id=req_id, path="/v1/messages",
              error=f"bad_json: {e}", bytes_in=len(raw))
        return JSONResponse(
            {"error": {"type": "invalid_request_error",
                       "message": "Body is not valid JSON"}},
            status_code=400,
        )

    # ── REDACT ─────────────────────────────────────────────────────────
    scrubbed, stats = scrub_anthropic_body(body)
    if stats.total() > 0:
        _jlog(redact_log, id=req_id,
              counts=stats.to_dict(), total=stats.total(),
              model=scrubbed.get("model"), stream=bool(scrubbed.get("stream")))

    upstream_body = json.dumps(scrubbed, ensure_ascii=False).encode("utf-8")
    is_stream = bool(scrubbed.get("stream"))

    # ── FORWARD ────────────────────────────────────────────────────────
    client = await _get_client()
    headers = _filter_headers(dict(request.headers))
    headers["content-length"] = str(len(upstream_body))
    headers.setdefault("content-type", "application/json")
    # Force plain-text upstream responses. Anthropic may gzip SSE streams by
    # default, but httpx's aiter_raw() would then hand us compressed bytes
    # that we'd have to re-tag with Content-Encoding (which we'd strip
    # anyway to avoid decoding mismatches). Asking for `identity` simplifies
    # both paths and costs a few extra bytes on the wire to localhost.
    headers["accept-encoding"] = "identity"

    if not is_stream:
        # Simple request/response
        try:
            upstream = await client.post("/v1/messages", content=upstream_body, headers=headers)
        except httpx.HTTPError as e:
            _jlog(access_log, id=req_id, path="/v1/messages", error=str(e),
                  ms=int((time.monotonic() - t0) * 1000),
                  redacted=stats.total(), model=scrubbed.get("model"))
            return JSONResponse(
                {"error": {"type": "proxy_error", "message": "Upstream unavailable"}},
                status_code=502,
            )
        _jlog(access_log, id=req_id, path="/v1/messages", method="POST",
              status=upstream.status_code,
              ms=int((time.monotonic() - t0) * 1000),
              bytes_in=len(upstream_body), bytes_out=len(upstream.content),
              redacted=stats.total(), model=scrubbed.get("model"), stream=False)
        return Response(
            content=upstream.content,
            status_code=upstream.status_code,
            headers=_filter_response_headers(dict(upstream.headers)),
            media_type=upstream.headers.get("content-type"),
        )

    # Streaming path — SSE. Hold the upstream stream open and pipe chunks.
    async def _sse_pipe() -> Any:
        bytes_out = 0
        status = 0
        try:
            req = client.build_request("POST", "/v1/messages",
                                       content=upstream_body, headers=headers)
            upstream = await client.send(req, stream=True)
            status = upstream.status_code
            # Mirror upstream status/headers via a nested StreamingResponse
            # is awkward; we instead let FastAPI wrap this generator and set
            # status via closure below.
            _sse_pipe.status = upstream.status_code
            _sse_pipe.headers = _filter_response_headers(dict(upstream.headers))
            # aiter_bytes() auto-decodes if upstream ever ignores our
            # Accept-Encoding:identity and sends gzip anyway — safer default.
            async for chunk in upstream.aiter_bytes():
                bytes_out += len(chunk)
                yield chunk
            await upstream.aclose()
        except httpx.HTTPError as e:
            # SSE error event so the client sees *something* — Anthropic's
            # format is `event: error\ndata: {...}\n\n`.
            err_payload = json.dumps({"type": "error",
                                      "error": {"type": "proxy_error",
                                                "message": str(e)}})
            yield f"event: error\ndata: {err_payload}\n\n".encode("utf-8")
            _sse_pipe.status = 502
            _sse_pipe.headers = {"content-type": "text/event-stream"}
        finally:
            _jlog(access_log, id=req_id, path="/v1/messages", method="POST",
                  status=getattr(_sse_pipe, "status", status) or 0,
                  ms=int((time.monotonic() - t0) * 1000),
                  bytes_in=len(upstream_body), bytes_out=bytes_out,
                  redacted=stats.total(), model=scrubbed.get("model"),
                  stream=True)

    # Prime the generator enough to capture the upstream status code.
    # StreamingResponse doesn't give us a clean pre-send hook, so we
    # fall back to a two-phase approach: start a generator, grab the
    # first chunk to force headers, then continue.
    gen = _sse_pipe()
    try:
        first_chunk = await asyncio.wait_for(gen.__anext__(), timeout=PROXY_READ_TIMEOUT)
    except asyncio.TimeoutError:
        return JSONResponse(
            {"error": {"type": "proxy_error", "message": "Upstream timeout"}},
            status_code=504,
        )
    except StopAsyncIteration:
        first_chunk = b""

    async def _wrapped() -> Any:
        if first_chunk:
            yield first_chunk
        async for c in gen:
            yield c

    resp_status = getattr(_sse_pipe, "status", 200) or 200
    resp_headers = getattr(_sse_pipe, "headers", {"content-type": "text/event-stream"})
    # Ensure we don't accidentally forward an upstream content-length
    # (it only describes the first chunk we already drained).
    resp_headers.pop("content-length", None)
    return StreamingResponse(
        _wrapped(),
        status_code=resp_status,
        headers=resp_headers,
        media_type=resp_headers.get("content-type", "text/event-stream"),
    )


# Catch-all for every other Anthropic endpoint (models list, batches, files)
# so the proxy is a true superset of the old passthrough.
@app.api_route("/{full_path:path}",
               methods=["GET", "POST", "PUT", "DELETE", "PATCH", "OPTIONS", "HEAD"])
async def passthrough(full_path: str, request: Request) -> Response:
    req_id = uuid.uuid4().hex[:12]
    # Avoid shadowing the more specific /v1/messages handler: FastAPI tries
    # this route only if the specific one didn't match, so no extra guard is
    # strictly needed — but we keep the `if` for clarity.
    if full_path == "v1/messages" and request.method.upper() == "POST":
        return await v1_messages(request)

    # Apply HMAC to the catch-all too so e.g. /v1/models is not a free
    # oracle to enumerate the proxy from the VPC.
    body_for_auth = await request.body()
    auth_reject = await _require_signature(request, body_for_auth, req_id)
    if auth_reject is not None:
        return auth_reject

    return await _forward_non_json(request, full_path, req_id)
