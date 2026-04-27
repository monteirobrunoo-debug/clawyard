#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# bulk-ingest-qnap.sh — pull the H&P Group QNAP archive into hp-history.
#
# Usage on the hp-history droplet:
#
#   QNAP_HOST=qnap.partyard.local \
#   QNAP_SHARE=Public/Archive \
#   QNAP_USER=hphistory-readonly \
#   QNAP_PASS='…' \
#   DOMAIN=spares \
#   YEAR=2024 \
#   ./scripts/bulk-ingest-qnap.sh /Archive/RFQs/2024
#
# What it does:
#   1. Mounts the QNAP CIFS share read-only at /mnt/qnap-hp
#      (re-uses existing mount if already there).
#   2. Walks the requested subpath, copies every supported file
#      (.pdf .txt .md) into /data/incoming/<batch-id>/ on the droplet.
#   3. Writes per-file `<basename>.meta.json` sidecars for the watcher,
#      carrying the DOMAIN / YEAR / customer / vessel hints derived from
#      the QNAP folder structure (parent-dir name → customer; year from
#      path).
#   4. Triggers an immediate watcher pass via `python -m app.watcher
#      --once` and reports the count of docs ingested.
#   5. Validates: SELECTs documents with source_file LIKE the batch id
#      and asserts row count == file count.
#   6. Unmounts the share.
#
# Idempotency:
#   • UUID5 of the source path makes re-running safe — same file just
#     replaces its chunks.
#   • The batch-id is a timestamp so re-runs don't collide in
#     /data/incoming.
#
# Safety:
#   • Always mounts read-only.
#   • Refuses to run unless DOMAIN is in {spares,repair} (typos break
#     the agent routing later).
#   • Aborts on any single ingest failure (set -e) so we don't end up
#     with a partial batch silently masquerading as complete.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

QNAP_HOST="${QNAP_HOST:?QNAP_HOST required (e.g. qnap.partyard.local)}"
QNAP_SHARE="${QNAP_SHARE:?QNAP_SHARE required (e.g. Public/Archive)}"
QNAP_USER="${QNAP_USER:?QNAP_USER required}"
QNAP_PASS="${QNAP_PASS:?QNAP_PASS required}"
DOMAIN="${DOMAIN:?DOMAIN required (spares|repair)}"
YEAR="${YEAR:-}"
SUBPATH="${1:?Provide the subpath under the QNAP share, e.g. /Archive/RFQs/2024}"

case "$DOMAIN" in
    spares|repair) : ;;
    *) echo "✗ DOMAIN must be 'spares' or 'repair' (got '$DOMAIN')"; exit 2 ;;
esac

MOUNT="${MOUNT:-/mnt/qnap-hp}"
INCOMING_ROOT="/data/incoming"
BATCH_ID="qnap-$(date -u +%Y%m%dT%H%M%SZ)"
BATCH_DIR="${INCOMING_ROOT}/${BATCH_ID}"

color() { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }

[[ "$EUID" -eq 0 ]] || { echo "✗ Run as root — needs to mount/unmount."; exit 1; }

section "1/6  Mount QNAP read-only"
mkdir -p "$MOUNT"
if mountpoint -q "$MOUNT"; then
    color "33" "$MOUNT already mounted — re-using"
else
    # ro,nobrl,vers=3.0 — modern SMB3, no byte-range locks (the
    # archive doesn't need them and they can hang on QNAP firmwares).
    mount -t cifs "//${QNAP_HOST}/${QNAP_SHARE}" "$MOUNT" \
        -o "ro,nobrl,vers=3.0,username=${QNAP_USER},password=${QNAP_PASS},uid=0,gid=0,iocharset=utf8"
fi
trap 'echo "Unmounting…"; umount "$MOUNT" 2>/dev/null || true' EXIT

section "2/6  Walk + copy"
SRC="${MOUNT}${SUBPATH}"
if [[ ! -d "$SRC" ]]; then
    echo "✗ Source not found inside mount: $SRC"
    exit 3
fi
mkdir -p "$BATCH_DIR"
COUNT=0
while IFS= read -r -d '' file; do
    # Preserve the relative path so two files with the same name in
    # different sub-folders don't collide.
    rel="${file#$SRC/}"
    safe="$(echo "$rel" | tr '/' '_' | tr -cd '[:alnum:]._-')"
    dest="${BATCH_DIR}/${safe}"
    cp "$file" "$dest"

    # Per-file sidecar — extract a customer hint from the parent
    # folder name (best-effort), pin domain + year.
    parent="$(basename "$(dirname "$file")")"
    cat > "${dest}.meta.json" <<META
{
  "domain": "${DOMAIN}",
  ${YEAR:+\"year\": ${YEAR}, }
  "customer_hint": $(printf '%s' "$parent" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read().strip()))'),
  "qnap_source": $(printf '%s' "$rel" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read().strip()))'),
  "batch_id": "${BATCH_ID}"
}
META
    COUNT=$((COUNT+1))
done < <(find "$SRC" -type f \( -iname "*.pdf" -o -iname "*.txt" -o -iname "*.md" \) -print0)

color "32" "Copied ${COUNT} file(s) into ${BATCH_DIR}"

section "3/6  Run watcher pass"
docker compose -f /opt/hp-history/services/hp-history/docker-compose.yml \
    run --rm watcher python -m app.watcher --once

section "4/6  Validate row count in DB"
DB_COUNT=$(docker compose -f /opt/hp-history/services/hp-history/docker-compose.yml exec -T db \
    psql -U hphistory -d hphistory -tAc \
    "SELECT count(*) FROM documents WHERE metadata->>'batch_id' = '${BATCH_ID}';")
DB_COUNT=$(echo "$DB_COUNT" | tr -d '[:space:]')

if [[ "$DB_COUNT" != "$COUNT" ]]; then
    color "31" "✗ Validation failed — copied=${COUNT} ingested=${DB_COUNT}"
    color "33" "  Inspect /data/incoming/${BATCH_ID} for files that didn't ingest"
    exit 4
fi
color "32" "✓ ${DB_COUNT} document(s) confirmed in DB for batch ${BATCH_ID}"

section "5/6  Spot-check a hit"
SAMPLE_TITLE=$(docker compose -f /opt/hp-history/services/hp-history/docker-compose.yml exec -T db \
    psql -U hphistory -d hphistory -tAc \
    "SELECT title FROM documents WHERE metadata->>'batch_id' = '${BATCH_ID}' LIMIT 1;")
echo "Sample doc: ${SAMPLE_TITLE}"

section "6/6  Done"
echo "Batch ID:       ${BATCH_ID}"
echo "Copy location:  ${BATCH_DIR}"
echo "Domain:         ${DOMAIN}"
[[ -n "$YEAR" ]] && echo "Year:           ${YEAR}"
echo
echo "Next bulk:  re-run with a different SUBPATH or DOMAIN."
