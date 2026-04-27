"""Background ingest watcher.

Polls `settings.watcher_path` every `watcher_interval_seconds` for new
files. Each poll runs `ingest_folder` against the directory, which is
already idempotent (UUID5 from the absolute path means the same file
re-ingested simply replaces its chunks). The watcher is therefore a
trivial re-run loop — we don't need a journal of already-seen files.

Two run modes:

    python -m app.watcher                  # daemon — loops forever
    python -m app.watcher --once           # single pass, exits

Per-file overrides (optional). When a sidecar JSON file lives next to
a document with the same stem and `.meta.json` extension, its content
is merged into the document's metadata. Recognised top-level keys:
    domain   "spares" | "repair"
    year     int
    customer / vessel / partner / …  (free-form, stored as metadata)

Example layout:
    /data/incoming/RFQ-2024-0241.pdf
    /data/incoming/RFQ-2024-0241.meta.json   →  {"domain":"spares","year":2024,"customer":"PT Navy"}

The watcher uses the same Python process as the API in development;
in production we run it as a separate docker-compose service so the
API isn't blocked by long ingestion runs.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import signal
from pathlib import Path
from typing import Any, Dict

from . import metrics
from .db import get_pool, init_schema
from .ingest import SUPPORTED_EXTENSIONS, ingest_folder
from .settings import settings

log = logging.getLogger("hp_history.watcher")


def _read_sidecar(file_path: Path) -> Dict[str, Any]:
    """If a `.meta.json` sidecar exists for this file, parse it into a
    dict; otherwise return {}. Sidecars are advisory — malformed JSON
    is logged and ignored rather than failing the watcher run."""
    sidecar = file_path.with_suffix(file_path.suffix + ".meta.json")
    if not sidecar.is_file():
        # Also accept naked `.meta.json` next to the file (without the
        # original extension), for older pipelines.
        sidecar = file_path.with_suffix(".meta.json")
        if not sidecar.is_file():
            return {}
    try:
        return json.loads(sidecar.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        log.warning("sidecar parse failed for %s — %s", sidecar, e)
        return {}


async def run_once() -> tuple[int, int]:
    """Single pass: walk the watcher_path, ingest every supported file
    using its sidecar (if any) for domain/year/metadata overrides. The
    underlying ingest layer's UUID5 idempotency makes this safe to
    repeat — already-ingested files just update their row in place."""
    if not settings.watcher_path:
        log.info("watcher_path empty — nothing to do")
        return 0, 0

    folder = Path(settings.watcher_path)
    if not folder.is_dir():
        log.info("watcher_path %s not a directory — nothing to do", folder)
        return 0, 0

    # Walk file-by-file so the per-file sidecar can override defaults.
    docs_in = chunks_in = 0
    for path in sorted(folder.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in SUPPORTED_EXTENSIONS:
            continue
        if path.name.endswith(".meta.json"):
            continue
        sidecar = _read_sidecar(path)

        domain = sidecar.get("domain") or (settings.watcher_default_domain or None) or None
        year = sidecar.get("year")
        if year is None and settings.watcher_default_year:
            year = settings.watcher_default_year
        meta = {k: v for k, v in sidecar.items() if k not in ("domain", "year")}

        d, c = await ingest_folder(
            path.parent,                  # ingest_folder rglob's, but this single-file dir is fine
            domain=domain,
            year=year,
            extra_metadata=meta,
        ) if False else (0, 0)  # we ingest file-by-file below, not folder-by-folder

        # Reuse ingest_folder's logic by calling _ingest_file equivalent —
        # but for clarity (and to keep the codebase small) we simply call
        # ingest_folder against a temp directory containing only this
        # file. That's slow for large trees; instead we directly use the
        # internal helper at module scope:
        d, c = await _ingest_single(path, domain=domain, year=year, extra_metadata=meta)
        docs_in += d
        chunks_in += c

    metrics.inc_watcher_pass()
    metrics.inc_ingest("watcher", docs_in, chunks_in)
    log.info("watcher pass complete — %d doc(s) / %d chunk(s)", docs_in, chunks_in)
    return docs_in, chunks_in


async def _ingest_single(path: Path, *, domain: str | None, year: int | None, extra_metadata: dict) -> tuple[int, int]:
    """Bypass ingest_folder's rglob to ingest exactly one file with its
    own metadata. Reuses the same chunk + embed + DB-write internals.

    We re-import the helpers locally to avoid a circular import at module
    load time and to keep the call surface in one obvious place."""
    from .ingest import _read_text, _chunk, _document_uuid, _copy_into_library, _MIME_BY_EXT
    from .embed import embed_documents
    import uuid as uuid_lib

    text = _read_text(path)
    if not text.strip():
        return 0, 0
    pieces = _chunk(text)
    if not pieces:
        return 0, 0

    vectors = await embed_documents(pieces)
    if len(vectors) != len(pieces):
        return 0, 0

    doc_id = _document_uuid(path)
    title = path.stem.replace("_", " ")[:200]
    source = "file://" + str(path.resolve())
    meta = dict(extra_metadata)
    meta["filename"] = path.name

    try:
        local_path_obj, mime = _copy_into_library(path, doc_id)
        local_path = str(local_path_obj)
    except Exception as e:
        log.warning("library-copy failed for %s — %s", path, e)
        local_path, mime = None, _MIME_BY_EXT.get(path.suffix.lower())

    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.transaction():
            await conn.execute("DELETE FROM chunks WHERE document_id = $1", doc_id)
            await conn.execute(
                """
                INSERT INTO documents (id, title, source, local_path, mime_type, domain, year, metadata)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb)
                ON CONFLICT (id) DO UPDATE SET
                    title = EXCLUDED.title,
                    source = EXCLUDED.source,
                    local_path = EXCLUDED.local_path,
                    mime_type = EXCLUDED.mime_type,
                    domain = EXCLUDED.domain,
                    year = EXCLUDED.year,
                    metadata = EXCLUDED.metadata
                """,
                doc_id, title, source, local_path, mime, domain, year, json.dumps(meta),
            )
            rows = [(uuid_lib.uuid4(), doc_id, i, txt, vec) for i, (txt, vec) in enumerate(zip(pieces, vectors))]
            await conn.executemany(
                """
                INSERT INTO chunks (id, document_id, chunk_idx, text, embedding)
                VALUES ($1, $2, $3, $4, $5)
                """,
                rows,
            )
    return 1, len(pieces)


async def run_forever() -> None:
    interval = max(60, int(settings.watcher_interval_seconds))
    log.info("watcher loop started — path=%s interval=%ss", settings.watcher_path, interval)
    stop = asyncio.Event()

    def _on_signal(*_: object) -> None:
        log.info("watcher received stop signal — finishing current pass…")
        stop.set()

    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            asyncio.get_running_loop().add_signal_handler(sig, _on_signal)
        except NotImplementedError:
            # Windows / non-asyncio-supported signal — not our deploy target.
            pass

    while not stop.is_set():
        try:
            await run_once()
        except Exception as e:
            log.exception("watcher pass crashed (will retry next interval): %s", e)
        try:
            await asyncio.wait_for(stop.wait(), timeout=interval)
        except asyncio.TimeoutError:
            pass


async def _amain() -> int:
    parser = argparse.ArgumentParser(description="Background ingest watcher")
    parser.add_argument("--once", action="store_true", help="single pass, then exit")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    await init_schema()
    if args.once:
        d, c = await run_once()
        print(f"OK — {d} document(s) / {c} chunk(s)")
        return 0
    await run_forever()
    return 0


def main() -> int:
    return asyncio.run(_amain())


if __name__ == "__main__":
    raise SystemExit(main())
