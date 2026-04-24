"""
HMAC auth tests — the split-VM proof-of-origin layer.

These tests import the module with env vars set/unset at import time
(see the `@pytest.fixture` that patches os.environ before `_load_keys`
is called). The intent is to exercise:

  - disabled by default (no key set)
  - valid signature → ok
  - missing signature → reject
  - stale timestamp → reject
  - tampered body → reject
  - rotation: either primary or next key is accepted
"""
from __future__ import annotations

import os
import sys
import time
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from auth import sign, verify_request  # noqa: E402


KEY_PRIMARY = "primary-test-key-32bytes-base64-ish"
KEY_NEXT = "rotation-next-key-32bytes-base64-ish"


@pytest.fixture
def env_primary_only(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PY_PROXY_SHARED_KEY", KEY_PRIMARY)
    monkeypatch.delenv("PY_PROXY_SHARED_KEY_NEXT", raising=False)


@pytest.fixture
def env_both_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PY_PROXY_SHARED_KEY", KEY_PRIMARY)
    monkeypatch.setenv("PY_PROXY_SHARED_KEY_NEXT", KEY_NEXT)


@pytest.fixture
def env_no_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("PY_PROXY_SHARED_KEY", raising=False)
    monkeypatch.delenv("PY_PROXY_SHARED_KEY_NEXT", raising=False)


def test_auth_disabled_when_no_key(env_no_keys: None) -> None:
    out = verify_request(
        timestamp_header=None,
        signature_headers=[],
        body=b"{}",
    )
    assert out.ok
    assert out.reason == "disabled"


def test_valid_signature_accepted(env_primary_only: None) -> None:
    body = b'{"messages":[{"role":"user","content":"hi"}]}'
    ts, sig = sign(body, KEY_PRIMARY.encode())
    out = verify_request(
        timestamp_header=ts,
        signature_headers=[sig],
        body=body,
    )
    assert out.ok, out.reason


def test_missing_signature_rejected(env_primary_only: None) -> None:
    out = verify_request(
        timestamp_header=str(int(time.time())),
        signature_headers=[""],
        body=b"{}",
    )
    assert not out.ok
    assert out.reason == "missing_signature"


def test_stale_timestamp_rejected(env_primary_only: None) -> None:
    body = b"{}"
    # Sign with a timestamp 1 hour in the past.
    ts, sig = sign(body, KEY_PRIMARY.encode(), now=time.time() - 3600)
    out = verify_request(
        timestamp_header=ts,
        signature_headers=[sig],
        body=body,
    )
    assert not out.ok
    assert out.reason == "stale"


def test_tampered_body_rejected(env_primary_only: None) -> None:
    ts, sig = sign(b'{"role":"user"}', KEY_PRIMARY.encode())
    out = verify_request(
        timestamp_header=ts,
        signature_headers=[sig],
        body=b'{"role":"admin"}',  # attacker swaps role
    )
    assert not out.ok
    assert out.reason == "bad_sig"


def test_rotation_next_key_also_accepted(env_both_keys: None) -> None:
    body = b'{"ok":true}'
    ts, sig = sign(body, KEY_NEXT.encode())
    out = verify_request(
        timestamp_header=ts,
        signature_headers=[sig],
        body=body,
    )
    assert out.ok, out.reason


def test_rotation_primary_still_accepted(env_both_keys: None) -> None:
    body = b'{"ok":true}'
    ts, sig = sign(body, KEY_PRIMARY.encode())
    out = verify_request(
        timestamp_header=ts,
        signature_headers=[sig],
        body=body,
    )
    assert out.ok, out.reason


def test_wrong_key_rejected(env_primary_only: None) -> None:
    ts, sig = sign(b"{}", b"wrong-key")
    out = verify_request(
        timestamp_header=ts,
        signature_headers=[sig],
        body=b"{}",
    )
    assert not out.ok
    assert out.reason == "bad_sig"


def test_bad_timestamp_format_rejected(env_primary_only: None) -> None:
    out = verify_request(
        timestamp_header="not-a-number",
        signature_headers=["abc"],
        body=b"{}",
    )
    assert not out.ok
    assert out.reason == "bad_timestamp"
