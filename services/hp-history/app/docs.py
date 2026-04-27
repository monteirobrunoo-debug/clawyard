"""GET /doc/{id} — serve the original file PartYard archived.

Authenticated (HMAC like /search). Returns:
    • 200 + file bytes when the document has a local_path under the
      managed library and that file still exists.
    • 404 when the doc id is unknown or the file is missing on disk.

Why we don't redirect to `documents.source`:
    • `source` is the origin (qnap://, sharepoint://, file:///mnt/…) —
      not always reachable from the agent's process or the user's
      browser. The library path is OUR copy and always reachable.
    • Origin URLs may carry credentials or temporary tokens that
      we shouldn't surface to a downstream agent prompt.
"""

from __future__ import annotations

import logging
import os
import uuid
from pathlib import Path
from typing import Any

from fastapi import HTTPException
from fastapi.responses import FileResponse, JSONResponse

from . import metrics
from .db import get_pool

log = logging.getLogger("hp_history.docs")


async def get_document(doc_id: str) -> Any:
    # Validate the UUID before hitting the DB to avoid leaking
    # planner errors and to give a clean 400 path.
    try:
        normalised = str(uuid.UUID(doc_id))
    except (ValueError, TypeError):
        raise HTTPException(status_code=400, detail="invalid document id")

    pool = await get_pool()
    async with pool.acquire() as conn:
        row = await conn.fetchrow(
            """
            SELECT id, title, source, local_path, mime_type, domain, year, metadata, created_at
            FROM documents
            WHERE id = $1
            """,
            uuid.UUID(normalised),
        )

    if not row:
        metrics.inc_doc_download("not_found")
        raise HTTPException(status_code=404, detail="document not found")

    local_path = row["local_path"]
    if not local_path:
        # Indexed but never copied to library — degrade gracefully:
        # return the metadata so the agent can still cite something
        # textual instead of breaking the conversation with a 404.
        return JSONResponse(
            {
                "id":         str(row["id"]),
                "title":      row["title"],
                "source":     row["source"],
                "domain":     row["domain"],
                "year":       row["year"],
                "metadata":   dict(row["metadata"] or {}),
                "downloadable": False,
                "reason":     "not in managed library",
            },
            status_code=200,
        )

    p = Path(local_path)
    # Defence-in-depth: never follow symlinks out of the library, and
    # never serve anything outside the configured root. Even if the DB
    # row was tampered with via a manual SQL update, this caps blast
    # radius.
    library_root = Path(os.environ.get("HPH_LIBRARY_PATH", "/data/library")).resolve()
    try:
        resolved = p.resolve(strict=True)
    except FileNotFoundError:
        log.warning("doc %s: local_path %s missing on disk", row["id"], local_path)
        metrics.inc_doc_download("gone")
        raise HTTPException(status_code=410, detail="file gone")
    if not str(resolved).startswith(str(library_root) + os.sep):
        log.error("doc %s: local_path %s escapes library root %s", row["id"], resolved, library_root)
        metrics.inc_doc_download("forbidden")
        raise HTTPException(status_code=403, detail="forbidden")

    media = row["mime_type"] or "application/octet-stream"
    # Filename hint that's safe for Content-Disposition.
    safe_title = (row["title"] or str(row["id"])).replace('"', "").replace("\n", " ")
    return FileResponse(
        path=str(resolved),
        media_type=media,
        filename=f"{safe_title}{resolved.suffix}",
        headers={"X-HP-Doc-Source": str(row["source"])},
    )
