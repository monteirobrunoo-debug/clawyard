"""Ingest CLI — turn a folder of PDFs / .txt into rows in the documents
+ chunks tables.

Usage:

    python -m hp_history.ingest /path/to/folder \
        --domain spares \
        --year 2024 \
        --metadata '{"customer":"PT Navy"}'

The folder is scanned recursively. PDF text is extracted with pypdf
(reasonable for digitally-typeset PDFs; for scanned ones add OCR
upstream). Each document is chunked at ~512 tokens with ~64 overlap.

Idempotency: ingest is keyed on the SHA-256 of the absolute path —
re-running on the same file replaces its chunks rather than duplicating.
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import logging
import os
import re
import uuid
from pathlib import Path
from typing import Iterable, List, Tuple

from pypdf import PdfReader

from .db import get_pool, init_schema
from .embed import embed_documents

log = logging.getLogger("hp_history.ingest")


SUPPORTED_EXTENSIONS = {".pdf", ".txt", ".md"}


def _document_uuid(path: Path) -> uuid.UUID:
    # Deterministic UUID5 from the absolute path so re-ingesting the
    # same file replaces (instead of duplicating) it.
    return uuid.uuid5(uuid.NAMESPACE_URL, "file://" + str(path.resolve()))


def _read_text(path: Path) -> str:
    if path.suffix.lower() in {".txt", ".md"}:
        return path.read_text(encoding="utf-8", errors="ignore")
    if path.suffix.lower() == ".pdf":
        try:
            reader = PdfReader(str(path))
        except Exception as e:
            log.warning("pdf-read failed for %s: %s", path, e)
            return ""
        return "\n".join((p.extract_text() or "") for p in reader.pages)
    return ""


def _chunk(text: str, target_chars: int = 1800, overlap: int = 200) -> List[str]:
    """Coarse chunker. Splits on paragraph boundaries when possible
    so embedding context stays semantically coherent."""
    text = re.sub(r"[ \t]+", " ", text).strip()
    if not text:
        return []
    paras = [p.strip() for p in re.split(r"\n\s*\n", text) if p.strip()]

    chunks: List[str] = []
    buf = ""
    for p in paras:
        if len(buf) + len(p) + 2 > target_chars and buf:
            chunks.append(buf)
            # Carry the tail of the previous chunk to keep continuity.
            buf = buf[-overlap:] + "\n\n" + p
        else:
            buf = (buf + "\n\n" + p) if buf else p
    if buf:
        chunks.append(buf)
    return chunks


async def ingest_folder(
    folder: Path,
    *,
    domain: str | None,
    year: int | None,
    extra_metadata: dict,
) -> Tuple[int, int]:
    pool = await get_pool()
    docs_in = chunks_in = 0

    files: Iterable[Path] = (
        p for p in folder.rglob("*") if p.is_file() and p.suffix.lower() in SUPPORTED_EXTENSIONS
    )

    for path in files:
        text = _read_text(path)
        if not text.strip():
            log.info("skip empty: %s", path)
            continue
        pieces = _chunk(text)
        if not pieces:
            continue

        vectors = await embed_documents(pieces)
        if len(vectors) != len(pieces):
            log.warning("vector/chunk count mismatch on %s — skipping", path)
            continue

        doc_id = _document_uuid(path)
        title = path.stem.replace("_", " ")[:200]
        source = "file://" + str(path.resolve())
        meta = dict(extra_metadata)
        meta["filename"] = path.name

        async with pool.acquire() as conn:
            async with conn.transaction():
                # Replace strategy: drop existing chunks for the doc, then
                # upsert the document row, then insert fresh chunks.
                await conn.execute("DELETE FROM chunks WHERE document_id = $1", doc_id)
                await conn.execute(
                    """
                    INSERT INTO documents (id, title, source, domain, year, metadata)
                    VALUES ($1, $2, $3, $4, $5, $6::jsonb)
                    ON CONFLICT (id) DO UPDATE SET
                        title = EXCLUDED.title,
                        source = EXCLUDED.source,
                        domain = EXCLUDED.domain,
                        year = EXCLUDED.year,
                        metadata = EXCLUDED.metadata
                    """,
                    doc_id, title, source, domain, year, json.dumps(meta),
                )
                rows = []
                for i, (txt, vec) in enumerate(zip(pieces, vectors)):
                    rows.append(
                        (uuid.uuid4(), doc_id, i, txt, vec)
                    )
                await conn.executemany(
                    """
                    INSERT INTO chunks (id, document_id, chunk_idx, text, embedding)
                    VALUES ($1, $2, $3, $4, $5)
                    """,
                    rows,
                )
        docs_in += 1
        chunks_in += len(pieces)
        log.info("ingested %d chunks from %s", len(pieces), path.name)

    return docs_in, chunks_in


async def _amain() -> int:
    parser = argparse.ArgumentParser(description="Ingest a folder into hp-history")
    parser.add_argument("folder", type=Path)
    parser.add_argument("--domain", choices=["spares", "repair"], default=None)
    parser.add_argument("--year", type=int, default=None)
    parser.add_argument("--metadata", type=str, default="{}",
                        help="extra JSON metadata applied to every doc in the folder")
    args = parser.parse_args()

    if not args.folder.is_dir():
        print(f"folder not found: {args.folder}")
        return 2

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    await init_schema()
    docs, chunks = await ingest_folder(
        args.folder,
        domain=args.domain,
        year=args.year,
        extra_metadata=json.loads(args.metadata),
    )
    print(f"OK — {docs} document(s) / {chunks} chunk(s)")
    return 0


def main() -> int:
    return asyncio.run(_amain())


if __name__ == "__main__":
    raise SystemExit(main())
