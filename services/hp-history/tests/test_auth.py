"""Pytest suite for the HMAC verification middleware. Pure unit tests
— no DB, no real FastAPI server. We instantiate the verify_hmac coro
with a hand-built Request and assert it accepts/rejects.

Run:  cd services/hp-history && pytest -q
"""

from __future__ import annotations

import hashlib
import hmac
import time

import pytest
from fastapi import HTTPException, Request

from app import auth
from app.settings import settings


def _make_request(method: str, path: str, headers: dict[str, str]) -> Request:
    scope = {
        "type": "http",
        "method": method,
        "path": path,
        "raw_path": path.encode(),
        "query_string": b"",
        "headers": [(k.lower().encode(), v.encode()) for k, v in headers.items()],
        "scheme": "http",
        "server": ("test", 80),
    }
    return Request(scope)


def _sign(method: str, path: str, body: bytes, *, ts: int | None = None, secret: str = "test-secret"):
    ts = ts or int(time.time())
    body_hash = hashlib.sha256(body).hexdigest()
    canonical = f"{ts}.{method}.{path}.{body_hash}"
    sig = hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    return {
        "X-HP-Timestamp": str(ts),
        "X-HP-Body-SHA256": body_hash,
        "X-HP-Signature": sig,
    }


@pytest.fixture(autouse=True)
def _set_secret(monkeypatch):
    monkeypatch.setattr(settings, "hmac_secret", "test-secret")
    monkeypatch.setattr(settings, "hmac_tolerance_seconds", 300)


@pytest.mark.asyncio
async def test_valid_signature_accepts():
    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body)
    req = _make_request("POST", "/search", headers)
    await auth.verify_hmac(req, body)  # no exception


@pytest.mark.asyncio
async def test_missing_header_rejects():
    body = b'{"query":"foo"}'
    req = _make_request("POST", "/search", {})
    with pytest.raises(HTTPException) as e:
        await auth.verify_hmac(req, body)
    assert e.value.status_code == 401


@pytest.mark.asyncio
async def test_old_timestamp_rejects():
    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body, ts=int(time.time()) - 999)
    req = _make_request("POST", "/search", headers)
    with pytest.raises(HTTPException) as e:
        await auth.verify_hmac(req, body)
    assert e.value.status_code == 401


@pytest.mark.asyncio
async def test_body_tamper_rejects():
    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body)
    tampered_body = b'{"query":"steal"}'
    req = _make_request("POST", "/search", headers)
    with pytest.raises(HTTPException) as e:
        await auth.verify_hmac(req, tampered_body)
    assert e.value.status_code == 401


@pytest.mark.asyncio
async def test_wrong_secret_rejects(monkeypatch):
    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body)
    monkeypatch.setattr(settings, "hmac_secret", "different-secret")
    req = _make_request("POST", "/search", headers)
    with pytest.raises(HTTPException) as e:
        await auth.verify_hmac(req, body)
    assert e.value.status_code == 401


@pytest.mark.asyncio
async def test_empty_secret_returns_503(monkeypatch):
    monkeypatch.setattr(settings, "hmac_secret", "")
    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body)
    req = _make_request("POST", "/search", headers)
    with pytest.raises(HTTPException) as e:
        await auth.verify_hmac(req, body)
    assert e.value.status_code == 503


# ── Dual-secret rotation window ──────────────────────────────────────────────

@pytest.mark.asyncio
async def test_rotation_accepts_request_signed_with_next_secret(monkeypatch):
    # Server in rotation: knows OLD as primary + NEW as `next`.
    monkeypatch.setattr(settings, "hmac_secret", "OLD")
    monkeypatch.setattr(settings, "hmac_secret_next", "NEW")

    # Client has already moved to NEW (step 2 of the rotation flow).
    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body, secret="NEW")
    req = _make_request("POST", "/search", headers)

    await auth.verify_hmac(req, body)   # no exception


@pytest.mark.asyncio
async def test_rotation_still_accepts_old_secret(monkeypatch):
    # In-flight clients still use OLD during the window — must not 401.
    monkeypatch.setattr(settings, "hmac_secret", "OLD")
    monkeypatch.setattr(settings, "hmac_secret_next", "NEW")

    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body, secret="OLD")
    req = _make_request("POST", "/search", headers)

    await auth.verify_hmac(req, body)   # no exception


@pytest.mark.asyncio
async def test_rotation_rejects_unrelated_secret(monkeypatch):
    # A 3rd-party secret must still be rejected even with rotation on.
    monkeypatch.setattr(settings, "hmac_secret", "OLD")
    monkeypatch.setattr(settings, "hmac_secret_next", "NEW")

    body = b'{"query":"foo"}'
    headers = _sign("POST", "/search", body, secret="STOLEN")
    req = _make_request("POST", "/search", headers)

    with pytest.raises(HTTPException) as e:
        await auth.verify_hmac(req, body)
    assert e.value.status_code == 401
