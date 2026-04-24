"""
Parity tests — every rule here must match PiiRedactor.php exactly.

Run locally:
    cd llm-proxy && python -m pytest tests/ -q

These tests double as executable documentation of what the proxy scrubs
and what it deliberately leaves alone (NSN codes, SAP doc nums, etc.).
"""
from __future__ import annotations

import sys
from pathlib import Path

# Make the parent llm-proxy/ importable without a package setup.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from redactor import scrub, scrub_anthropic_body  # noqa: E402


# ── Emails ─────────────────────────────────────────────────────────────────

def test_email_keeps_domain():
    out = scrub("Contacto: bruno.monteiro@partyard.eu para mais info")
    assert out == "Contacto: [EMAIL_REDACTED]@partyard.eu para mais info"


def test_multiple_emails():
    out = scrub("bruno@a.com e daniel@b.pt")
    assert "@a.com" in out and "@b.pt" in out
    assert "bruno@" not in out and "daniel@" not in out


# ── Portuguese NIF ─────────────────────────────────────────────────────────

def test_valid_nif_is_redacted():
    # 508240760 is a valid NIPC (check-digit OK, prefix 5).
    out = scrub("Cliente NIF 508240760 confirmado.")
    assert "[NIF_REDACTED]" in out
    assert "508240760" not in out


def test_invalid_nif_is_preserved():
    # Random 9 digits starting with 4 — invalid prefix → left alone
    # (could be an NSN / SKU / doc number).
    out = scrub("Peça 400123456 encontrada no SAP.")
    assert "400123456" in out


def test_nif_inside_longer_digit_run_is_preserved():
    # 20 digits — the (?<!\d)(\d{9})(?!\d) guard means we don't touch it.
    out = scrub("12345678901234567890")
    assert "12345678901234567890" in out


# ── Credit cards (Luhn) ────────────────────────────────────────────────────

def test_valid_card_is_redacted():
    # 4532015112830366 → valid Luhn
    out = scrub("Pago com 4532 0151 1283 0366 agora.")
    assert "[CARD_REDACTED]" in out
    assert "4532" not in out


def test_random_digits_not_redacted_as_card():
    # 1234 5678 9012 3456 — does NOT pass Luhn (sum mod 10 = 4).
    # This is the case we care about: a legitimate 16-digit reference
    # number in a prompt should survive untouched.
    out = scrub("Referência interna 1234 5678 9012 3456 do contrato")
    assert "1234 5678 9012 3456" in out
    assert "[CARD_REDACTED]" not in out


# ── IBAN ───────────────────────────────────────────────────────────────────

def test_iban_redacted():
    out = scrub("NIB PT50000201231234567890154 para transferência")
    assert "[IBAN_REDACTED]" in out


# ── Phone numbers ──────────────────────────────────────────────────────────

def test_international_phone_keeps_country_code():
    out = scrub("Liga-me para +351 912 345 678 quando chegares")
    assert "+351 [PHONE_REDACTED]" in out
    assert "912 345 678" not in out


def test_short_numbers_not_phone():
    # "123 456" — only 6 digits, under the 8-digit threshold.
    out = scrub("Referência 123 456")
    assert "123 456" in out


# ── Secrets ────────────────────────────────────────────────────────────────

def test_secret_kv_redacted():
    out = scrub("password=hunter2 e api_key: sk-ant-abc123")
    assert "password=[REDACTED]" in out
    assert "api_key=[REDACTED]" in out
    assert "hunter2" not in out
    assert "sk-ant-abc123" not in out


# ── PEM keys ───────────────────────────────────────────────────────────────

def test_private_key_block_redacted():
    pem = """-----BEGIN RSA PRIVATE KEY-----
MIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Q
-----END RSA PRIVATE KEY-----"""
    out = scrub(f"key is: {pem}")
    assert "[PRIVATE_KEY_REDACTED]" in out
    assert "MIIBOg" not in out


# ── Stats counting ─────────────────────────────────────────────────────────

def test_stats_counts_match():
    from redactor import RedactStats
    stats = RedactStats()
    scrub("bruno@a.com, daniel@b.pt, +351 912 345 678", stats)
    assert stats.email == 2
    assert stats.phone == 1
    assert stats.total() == 3


# ── Anthropic payload shape ────────────────────────────────────────────────

def test_scrub_anthropic_body_string_content():
    body = {
        "model": "claude-sonnet-4-5-20250929",
        "system": "Contacta bruno@partyard.eu",
        "messages": [
            {"role": "user", "content": "NIF 508240760 precisa review"},
        ],
    }
    out, stats = scrub_anthropic_body(body)
    assert "[EMAIL_REDACTED]" in out["system"]
    assert "[NIF_REDACTED]" in out["messages"][0]["content"]
    assert stats.email == 1
    assert stats.nif == 1


def test_scrub_anthropic_body_content_blocks():
    body = {
        "model": "claude-sonnet-4-5-20250929",
        "messages": [
            {"role": "user", "content": [
                {"type": "text", "text": "email bruno@x.com"},
                {"type": "image", "source": {"data": "base64..."}},  # untouched
                {"type": "text", "text": "cc daniel@y.pt"},
            ]},
        ],
    }
    out, stats = scrub_anthropic_body(body)
    blocks = out["messages"][0]["content"]
    assert "[EMAIL_REDACTED]" in blocks[0]["text"]
    assert blocks[1]["type"] == "image"  # unchanged
    assert blocks[1]["source"]["data"] == "base64..."
    assert "[EMAIL_REDACTED]" in blocks[2]["text"]
    assert stats.email == 2


def test_binary_blocks_never_touched():
    body = {
        "messages": [{"role": "user", "content": [
            {"type": "document", "source": {"media_type": "application/pdf",
                                            "data": "secret@email.com in b64"}},
        ]}],
    }
    out, stats = scrub_anthropic_body(body)
    # Document blocks are binary → left verbatim; redactor is text-only.
    assert out["messages"][0]["content"][0]["source"]["data"] == "secret@email.com in b64"
    assert stats.total() == 0
