"""
HMAC request auth for the split-VM topology.

When the app tier lives on a different VM from the proxy, the proxy
cannot trust the source IP alone (DO private networking is a shared
VPC in general, even though our current firewall narrows it to one
peer). This module adds a cheap, explicit proof-of-origin:

  X-PY-Timestamp: <unix seconds>
  X-PY-Signature: hex(hmac_sha256(PY_PROXY_SHARED_KEY, timestamp + "\n" + body))

If `PY_PROXY_SHARED_KEY` is unset in the proxy's env, auth is OFF —
preserves the loopback topology where we already trust 127.0.0.1.

Design notes
------------
- The signature covers (timestamp + body), not headers. Headers get
  rewritten by Cloudflare / nginx / Forge all the time; body + time
  is the smallest stable surface.
- 5-second clock skew window. Anthropic's own request signing uses 5
  min, but we control both clocks (same region, NTP) so 5 s is plenty
  and cuts replay attack windows sharply.
- `hmac.compare_digest` is constant-time; don't swap to `==` to save
  a microsecond.
- On auth failure we return a **401 with no detail** — "invalid
  signature" vs. "stale timestamp" vs. "missing header" all look the
  same to an attacker. The proxy's own log line has the reason.

To rotate the shared key without downtime, proxy reads both
`PY_PROXY_SHARED_KEY` and (if set) `PY_PROXY_SHARED_KEY_NEXT`. Accepts
either. App side sends `X-PY-Signature` and `X-PY-Signature-Next`
during the overlap window. Once all app boxes emit `NEXT`, promote it
to primary and drop the old.
"""
from __future__ import annotations

import hashlib
import hmac
import os
import time
from dataclasses import dataclass

# 5-second window. Both VMs run chrony → NTP drift is well under 1 s.
CLOCK_SKEW_SECONDS = int(os.getenv("PY_PROXY_SKEW_SECONDS", "5"))


@dataclass
class AuthOutcome:
    ok: bool
    reason: str  # "missing_signature", "bad_timestamp", "stale", "bad_sig",
                 # "ok", "disabled"


def _load_keys() -> list[bytes]:
    """Return the primary + next shared keys as raw bytes. Empty list
    means auth is disabled."""
    keys: list[bytes] = []
    primary = os.getenv("PY_PROXY_SHARED_KEY", "").strip()
    nxt = os.getenv("PY_PROXY_SHARED_KEY_NEXT", "").strip()
    for k in (primary, nxt):
        if k:
            # The key on the wire is base64, but for HMAC we accept both
            # forms — just encode whatever ASCII we got.
            keys.append(k.encode("ascii"))
    return keys


def auth_enabled() -> bool:
    return bool(_load_keys())


def verify_request(
    timestamp_header: str | None,
    signature_headers: list[str],
    body: bytes,
    now: float | None = None,
) -> AuthOutcome:
    """Validate the X-PY-Timestamp + X-PY-Signature[-Next] headers
    against `body`. `now` is injectable for tests."""
    keys = _load_keys()
    if not keys:
        return AuthOutcome(ok=True, reason="disabled")

    if not timestamp_header or not any(signature_headers):
        return AuthOutcome(ok=False, reason="missing_signature")

    try:
        ts = int(timestamp_header)
    except ValueError:
        return AuthOutcome(ok=False, reason="bad_timestamp")

    now_s = int(now if now is not None else time.time())
    if abs(now_s - ts) > CLOCK_SKEW_SECONDS:
        return AuthOutcome(ok=False, reason="stale")

    payload = (str(ts) + "\n").encode("ascii") + body
    for key in keys:
        expected = hmac.new(key, payload, hashlib.sha256).hexdigest()
        for sig in signature_headers:
            if sig and hmac.compare_digest(expected, sig.strip().lower()):
                return AuthOutcome(ok=True, reason="ok")

    return AuthOutcome(ok=False, reason="bad_sig")


def sign(body: bytes, key: bytes, now: float | None = None) -> tuple[str, str]:
    """Sign helper for tests and for the Python side of the app, if any.
    Returns (timestamp_str, signature_hex)."""
    ts = str(int(now if now is not None else time.time()))
    payload = (ts + "\n").encode("ascii") + body
    sig = hmac.new(key, payload, hashlib.sha256).hexdigest()
    return ts, sig
