"""
PII redactor — Python port of app/Support/PiiRedactor.php.

This is the *second* pass of redaction that runs on the Digital Ocean proxy
host, after Laravel has already scrubbed the prompt. Two passes protect
against two failure modes:

  1. A developer forgets to set ANTHROPIC_REDACT_PII=true in the app .env.
  2. A new agent bypasses PiiRedactor::scrubMessages() by accident.

The rules must stay in sync with the PHP side — if you tweak one,
mirror the change in PiiRedactor.php (and add a test below).

Every substitution increments a counter in the returned stats dict so
the proxy can emit a structured log line (`{"nif": 2, "email": 1, ...}`)
without ever writing the prompt itself to disk.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any


# ── Patterns ────────────────────────────────────────────────────────────────

# 13–19 digit clusters with optional spaces/dashes (card candidates).
_CARD_RE = re.compile(r"\b(?:\d[ \-]?){12,18}\d\b")

# IBAN: country (2 letters) + 2 check digits + 11–30 alphanumeric.
_IBAN_RE = re.compile(r"\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b")

# Portuguese NIF/NIPC: exactly 9 digits, not embedded in a larger digit run.
_NIF_RE = re.compile(r"(?<!\d)(\d{9})(?!\d)")

# Email addresses — keep domain, mask local part.
_EMAIL_RE = re.compile(
    r"([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})"
)

# International phone: +XX / 00XX followed by 7-14 digits (with separators).
_PHONE_RE = re.compile(
    r"(?:\+|00)\s*(\d{1,3})[\s\-().]*((?:\d[\s\-().]*){7,14})"
)

# Portuguese Cartão de Cidadão: 8 digits + 1 digit + 2 letters + 1 digit.
_CC_RE = re.compile(r"\b\d{8}\s*\d\s*[A-Z]{2}\d\b")

# Secrets in key=value / key: value pairs.
_SECRET_RE = re.compile(
    r"\b(password|passwd|pwd|secret|token|apikey|api_key|bearer)\s*[:=]\s*[^\s,;}]+",
    re.IGNORECASE,
)

# PEM private key blocks.
_PEM_RE = re.compile(
    r"-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----"
)


# ── Stats container ────────────────────────────────────────────────────────

@dataclass
class RedactStats:
    card: int = 0
    iban: int = 0
    nif: int = 0
    email: int = 0
    phone: int = 0
    cc: int = 0
    secret: int = 0
    pem: int = 0
    # List of category labels that fired, in order — handy for error logs
    # without revealing positions.
    fields_hit: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, int]:
        """JSON-serialisable counts for log lines."""
        return {
            "card": self.card,
            "iban": self.iban,
            "nif": self.nif,
            "email": self.email,
            "phone": self.phone,
            "cc": self.cc,
            "secret": self.secret,
            "pem": self.pem,
        }

    def total(self) -> int:
        return sum(self.to_dict().values())

    def merge(self, other: "RedactStats") -> None:
        for k, v in other.to_dict().items():
            setattr(self, k, getattr(self, k) + v)
        self.fields_hit.extend(other.fields_hit)


# ── Luhn / NIF validators ──────────────────────────────────────────────────

def _luhn_ok(raw: str) -> bool:
    """Luhn check — distinguishes real card numbers from random digit runs."""
    digits = re.sub(r"\D", "", raw)
    if not 13 <= len(digits) <= 19:
        return False
    total = 0
    alt = False
    for ch in reversed(digits):
        n = int(ch)
        if alt:
            n *= 2
            if n > 9:
                n -= 9
        total += n
        alt = not alt
    return total % 10 == 0


def _is_valid_nif(nif: str) -> bool:
    """Portuguese NIF check-digit (modulo 11 weighted).

    Mirrors PiiRedactor::isValidPortugueseNif() exactly — the weight
    vector and valid-prefix list must match so random 9-digit codes
    (NSN, SKU, SAP doc numbers) are preserved.
    """
    if not re.fullmatch(r"\d{9}", nif):
        return False
    if nif[0] not in "1235689":
        return False
    total = sum(int(nif[i]) * (9 - i) for i in range(8))
    check = 11 - (total % 11)
    if check >= 10:
        check = 0
    return check == int(nif[8])


# ── Scrub primitives ────────────────────────────────────────────────────────

def scrub(text: str, stats: RedactStats | None = None) -> str:
    """Apply all redaction rules to a free-form string.

    Returns the scrubbed text; mutates `stats` in place if provided so the
    caller can aggregate counts across a whole message payload.
    """
    if not text:
        return text
    if stats is None:
        stats = RedactStats()

    # PEM private keys first — they may contain patterns that match other
    # rules (base64 digits → fake NIF etc.), and we want them gone whole.
    def _pem_sub(_m: re.Match[str]) -> str:
        stats.pem += 1
        stats.fields_hit.append("pem")
        return "[PRIVATE_KEY_REDACTED]"

    text = _PEM_RE.sub(_pem_sub, text)

    def _card_sub(m: re.Match[str]) -> str:
        if _luhn_ok(m.group(0)):
            stats.card += 1
            stats.fields_hit.append("card")
            return "[CARD_REDACTED]"
        return m.group(0)

    text = _CARD_RE.sub(_card_sub, text)

    def _iban_sub(m: re.Match[str]) -> str:
        stats.iban += 1
        stats.fields_hit.append("iban")
        return "[IBAN_REDACTED]"

    text = _IBAN_RE.sub(_iban_sub, text)

    def _nif_sub(m: re.Match[str]) -> str:
        val = m.group(1)
        if _is_valid_nif(val):
            stats.nif += 1
            stats.fields_hit.append("nif")
            return "[NIF_REDACTED]"
        return val

    text = _NIF_RE.sub(_nif_sub, text)

    def _email_sub(m: re.Match[str]) -> str:
        stats.email += 1
        stats.fields_hit.append("email")
        return f"[EMAIL_REDACTED]@{m.group(2)}"

    text = _EMAIL_RE.sub(_email_sub, text)

    def _phone_sub(m: re.Match[str]) -> str:
        stats.phone += 1
        stats.fields_hit.append("phone")
        return f"+{m.group(1)} [PHONE_REDACTED]"

    text = _PHONE_RE.sub(_phone_sub, text)

    def _cc_sub(m: re.Match[str]) -> str:
        stats.cc += 1
        stats.fields_hit.append("cc")
        return "[CC_REDACTED]"

    text = _CC_RE.sub(_cc_sub, text)

    def _secret_sub(m: re.Match[str]) -> str:
        stats.secret += 1
        stats.fields_hit.append("secret")
        return f"{m.group(1)}=[REDACTED]"

    text = _SECRET_RE.sub(_secret_sub, text)

    return text


# ── Payload-shape aware scrub (Anthropic /v1/messages format) ──────────────

def scrub_anthropic_body(body: dict[str, Any]) -> tuple[dict[str, Any], RedactStats]:
    """Scrub an Anthropic /v1/messages payload in-place (shallow copy).

    Walks both shapes of `messages[].content`:
      - string
      - list of content blocks: {type:"text"|"document"|"image", ...}

    Only text blocks are touched. Binary (document/image/tool_*) blocks are
    left verbatim — the redactor is string-only. If you need PDF scrubbing,
    parse the PDF server-side first and send extracted text.

    Also scrubs the `system` field (string or list-of-blocks).
    """
    stats = RedactStats()
    out = dict(body)  # shallow copy — we only rewrite the keys we own

    # system prompt
    sysval = out.get("system")
    if isinstance(sysval, str):
        out["system"] = scrub(sysval, stats)
    elif isinstance(sysval, list):
        new_sys = []
        for block in sysval:
            if isinstance(block, dict) and block.get("type") == "text" and "text" in block:
                block = {**block, "text": scrub(block["text"], stats)}
            new_sys.append(block)
        out["system"] = new_sys

    # messages
    msgs_in = out.get("messages") or []
    msgs_out = []
    for msg in msgs_in:
        if not isinstance(msg, dict):
            msgs_out.append(msg)
            continue
        new_msg = dict(msg)
        content = new_msg.get("content")
        if isinstance(content, str):
            new_msg["content"] = scrub(content, stats)
        elif isinstance(content, list):
            new_content = []
            for block in content:
                if (
                    isinstance(block, dict)
                    and block.get("type") == "text"
                    and "text" in block
                ):
                    block = {**block, "text": scrub(block["text"], stats)}
                new_content.append(block)
            new_msg["content"] = new_content
        msgs_out.append(new_msg)
    out["messages"] = msgs_out

    return out, stats
