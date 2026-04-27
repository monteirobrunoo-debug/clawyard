"""HMAC verification for /search and /doc endpoints.

Mirrors the Laravel HpHistoryClient::signHeaders contract:

    canonical = "{ts}.{method}.{path}.{sha256(body)}"
    sig       = HMAC_SHA256(secret, canonical)

Headers expected:
    X-HP-Timestamp   unix seconds (string)
    X-HP-Body-SHA256 hex(sha256(body))
    X-HP-Signature   hex(hmac)

Reasons to reject (each emits a 401 with a generic body — never leak
the precise reason to the caller, but DO log it server-side so we can
debug rotation drift / clock skew):
    • missing header
    • timestamp drift > tolerance
    • body hash header doesn't match recomputed body hash
    • signature mismatch (constant-time compare)
"""
from __future__ import annotations

import hashlib
import hmac
import logging
import time
from typing import Awaitable, Callable

from fastapi import HTTPException, Request, Response

from .settings import settings

log = logging.getLogger("hp_history.auth")


async def verify_hmac(request: Request, body: bytes) -> None:
    if not settings.hmac_secret:
        # If the secret is empty, refuse outright. This prevents
        # accidentally running the service in "open" mode in prod.
        log.error("hmac_secret is empty — refusing all authenticated requests")
        raise HTTPException(status_code=503, detail="server misconfigured")

    ts = request.headers.get("X-HP-Timestamp", "")
    sig = request.headers.get("X-HP-Signature", "")
    body_hash_hdr = request.headers.get("X-HP-Body-SHA256", "")

    if not ts or not sig or not body_hash_hdr:
        log.warning("auth fail — missing header(s)")
        raise HTTPException(status_code=401, detail="unauthorised")

    # Timestamp must parse and be within the tolerance window. Without
    # this an attacker could replay a captured signed request forever.
    try:
        ts_int = int(ts)
    except ValueError:
        log.warning("auth fail — non-numeric timestamp")
        raise HTTPException(status_code=401, detail="unauthorised")
    drift = abs(int(time.time()) - ts_int)
    if drift > settings.hmac_tolerance_seconds:
        log.warning("auth fail — timestamp drift %ss exceeds tolerance %ss", drift, settings.hmac_tolerance_seconds)
        raise HTTPException(status_code=401, detail="unauthorised")

    # Body hash header must equal recomputed sha256 of body, otherwise
    # the request body was tampered with after signing.
    body_hash = hashlib.sha256(body).hexdigest()
    if not hmac.compare_digest(body_hash_hdr, body_hash):
        log.warning("auth fail — body hash mismatch")
        raise HTTPException(status_code=401, detail="unauthorised")

    canonical = f"{ts}.{request.method}.{request.url.path}.{body_hash}"
    expected = hmac.new(
        settings.hmac_secret.encode(), canonical.encode(), hashlib.sha256
    ).hexdigest()
    if not hmac.compare_digest(expected, sig):
        log.warning("auth fail — signature mismatch (canonical=%s)", canonical)
        raise HTTPException(status_code=401, detail="unauthorised")


async def hmac_middleware(
    request: Request, call_next: Callable[[Request], Awaitable[Response]]
) -> Response:
    """Apply HMAC to every authenticated path. /healthz is exempt."""
    if request.url.path in ("/healthz", "/"):
        return await call_next(request)

    body = await request.body()
    await verify_hmac(request, body)

    # Cache the body so downstream handlers can re-read it via
    # `await request.body()` without consuming-twice issues.
    async def receive():
        return {"type": "http.request", "body": body, "more_body": False}

    request._receive = receive  # type: ignore[attr-defined]
    return await call_next(request)
