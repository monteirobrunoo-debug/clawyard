"""Pure logic tests for /doc/{id} — no FastAPI server, no DB.

We monkey-patch `docs.get_pool` to return a fake pool whose `fetchrow`
returns a dict-like row. This validates:
  • Invalid UUID → 400
  • Missing row → 404
  • Row exists but local_path NULL → JSON metadata (200, downloadable=False)
  • Row exists, local_path missing on disk → 410
  • Row exists, local_path escapes library_root → 403 (path-traversal guard)
  • Happy path → FileResponse with correct media_type and headers
"""

from __future__ import annotations

import os
import uuid
from pathlib import Path

import pytest
from fastapi import HTTPException
from fastapi.responses import FileResponse, JSONResponse

from app import docs as docs_module


class FakeRow(dict):
    """asyncpg Records support both __getitem__ and attribute access;
    we only use __getitem__ in the handler so a dict suffices."""


class _FakeConn:
    def __init__(self, row):
        self._row = row

    async def fetchrow(self, *args, **kwargs):
        return self._row


class _AcquireCtx:
    def __init__(self, conn):
        self._conn = conn

    async def __aenter__(self):
        return self._conn

    async def __aexit__(self, *exc):
        return False


class FakePool:
    """Mimics asyncpg.Pool — only the `acquire() → context manager →
    connection` path is exercised by the handler."""
    def __init__(self, row):
        self._row = row

    def acquire(self):
        return _AcquireCtx(_FakeConn(self._row))


@pytest.fixture
def with_pool(monkeypatch):
    def _set(row):
        async def _get_pool():
            return FakePool(row)
        monkeypatch.setattr(docs_module, "get_pool", _get_pool)
    return _set


@pytest.mark.asyncio
async def test_invalid_uuid_returns_400(with_pool):
    with_pool(None)
    with pytest.raises(HTTPException) as e:
        await docs_module.get_document("not-a-uuid")
    assert e.value.status_code == 400


@pytest.mark.asyncio
async def test_missing_row_returns_404(with_pool):
    with_pool(None)
    with pytest.raises(HTTPException) as e:
        await docs_module.get_document(str(uuid.uuid4()))
    assert e.value.status_code == 404


@pytest.mark.asyncio
async def test_row_without_local_path_returns_metadata_json(with_pool):
    doc_id = uuid.uuid4()
    with_pool(FakeRow(
        id=doc_id, title="No file", source="qnap://x", local_path=None,
        mime_type=None, domain="spares", year=2024, metadata={"customer": "PT"},
        created_at="2024-01-01",
    ))
    resp = await docs_module.get_document(str(doc_id))
    assert isinstance(resp, JSONResponse)


@pytest.mark.asyncio
async def test_missing_file_returns_410(monkeypatch, with_pool, tmp_path):
    monkeypatch.setenv("HPH_LIBRARY_PATH", str(tmp_path))
    doc_id = uuid.uuid4()
    bogus_path = str(tmp_path / "nope.pdf")    # never created
    with_pool(FakeRow(
        id=doc_id, title="Gone", source="x", local_path=bogus_path,
        mime_type="application/pdf", domain=None, year=None, metadata={},
        created_at="2024",
    ))
    with pytest.raises(HTTPException) as e:
        await docs_module.get_document(str(doc_id))
    assert e.value.status_code == 410


@pytest.mark.asyncio
async def test_path_traversal_blocked_with_403(monkeypatch, with_pool, tmp_path):
    monkeypatch.setenv("HPH_LIBRARY_PATH", str(tmp_path / "library"))
    (tmp_path / "library").mkdir()
    outside = tmp_path / "escape.pdf"
    outside.write_bytes(b"%PDF-1.4")
    doc_id = uuid.uuid4()
    with_pool(FakeRow(
        id=doc_id, title="Escape", source="x", local_path=str(outside),
        mime_type="application/pdf", domain=None, year=None, metadata={},
        created_at="2024",
    ))
    with pytest.raises(HTTPException) as e:
        await docs_module.get_document(str(doc_id))
    assert e.value.status_code == 403


@pytest.mark.asyncio
async def test_happy_path_returns_file_response(monkeypatch, with_pool, tmp_path):
    library = tmp_path / "library"
    library.mkdir()
    pdf = library / "deadbeef.pdf"
    pdf.write_bytes(b"%PDF-1.4 hello")
    monkeypatch.setenv("HPH_LIBRARY_PATH", str(library))

    doc_id = uuid.uuid4()
    with_pool(FakeRow(
        id=doc_id, title='RFQ "Quoted" Title', source="qnap://archive/x",
        local_path=str(pdf), mime_type="application/pdf",
        domain="spares", year=2024, metadata={}, created_at="2024",
    ))

    resp = await docs_module.get_document(str(doc_id))
    assert isinstance(resp, FileResponse)
    assert resp.media_type == "application/pdf"
    # Quotes stripped from filename so Content-Disposition stays valid.
    assert '"' not in (resp.filename or "")
    assert resp.headers["X-HP-Doc-Source"] == "qnap://archive/x"
