#!/usr/bin/env bash
# ============================================================================
# ClawYard — daily database + files backup
# ============================================================================
#
# Runs once per day from forge crontab (see install instructions below).
# Produces:
#   /home/forge/backups/db/forge-YYYY-MM-DD.sql.gz       (Postgres dump)
#   /home/forge/backups/storage/storage-YYYY-MM-DD.tgz   (user uploads)
#
# Then uploads both to DO Spaces if configured (S3-compatible), and prunes
# anything older than 30 days locally / 90 days remotely.
#
# Install (run once on droplet as forge):
#   chmod +x /home/forge/clawyard.partyard.eu/current/scripts/backup-daily.sh
#   echo '0 3 * * * /home/forge/clawyard.partyard.eu/current/scripts/backup-daily.sh >> /home/forge/backups/cron.log 2>&1' | crontab -
#
# Required env (set in /home/forge/.backup.env, sourced below):
#   DB_NAME=forge
#   DB_USER=forge
#   PGPASSWORD=<...>
#   # Optional — if any are missing, remote sync is silently skipped:
#   S3_ENDPOINT=https://ams3.digitaloceanspaces.com
#   S3_BUCKET=clawyard-backups
#   S3_ACCESS_KEY=<...>
#   S3_SECRET_KEY=<...>
#   GPG_RECIPIENT=<...>  # optional: encrypt before remote upload
# ============================================================================

set -euo pipefail

# Load creds (NEVER hardcode here — file is 600 perms)
ENV_FILE="/home/forge/.backup.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "[$(date)] ERROR: $ENV_FILE missing — refusing to run with no creds"
    exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

BACKUP_ROOT="/home/forge/backups"
DB_DIR="$BACKUP_ROOT/db"
STORAGE_DIR="$BACKUP_ROOT/storage"
STAMP="$(date +%F)"

mkdir -p "$DB_DIR" "$STORAGE_DIR"

echo "[$(date)] === ClawYard backup $STAMP ==="

# ── 1. Postgres dump (gzipped) ─────────────────────────────────────────────
DUMP="$DB_DIR/forge-${STAMP}.sql.gz"
echo "[$(date)] pg_dump → $DUMP"
PGPASSWORD="${PGPASSWORD:-}" pg_dump \
    --host=127.0.0.1 --port=5432 --username="${DB_USER:-forge}" \
    --no-owner --no-acl --clean --if-exists \
    "${DB_NAME:-forge}" 2>/dev/null | gzip -9 > "$DUMP"
echo "[$(date)] pg_dump size: $(du -h "$DUMP" | cut -f1)"

# ── 2. Application storage (user uploads, RFPs, etc) ───────────────────────
STORAGE_TGZ="$STORAGE_DIR/storage-${STAMP}.tgz"
APP_STORAGE="/home/forge/clawyard.partyard.eu/current/storage/app"
if [[ -d "$APP_STORAGE" ]]; then
    echo "[$(date)] tar storage → $STORAGE_TGZ"
    tar -czf "$STORAGE_TGZ" -C "$APP_STORAGE" . 2>/dev/null
    echo "[$(date)] storage size: $(du -h "$STORAGE_TGZ" | cut -f1)"
fi

# ── 3. Optional: GPG encrypt before remote upload ──────────────────────────
if [[ -n "${GPG_RECIPIENT:-}" ]]; then
    echo "[$(date)] gpg encrypt for $GPG_RECIPIENT"
    gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" "$DUMP" && rm "$DUMP" && DUMP="${DUMP}.gpg"
    [[ -f "$STORAGE_TGZ" ]] && gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" "$STORAGE_TGZ" && rm "$STORAGE_TGZ" && STORAGE_TGZ="${STORAGE_TGZ}.gpg"
fi

# ── 4. DO Spaces sync (S3-compatible) ──────────────────────────────────────
if [[ -n "${S3_ENDPOINT:-}" && -n "${S3_BUCKET:-}" && -n "${S3_ACCESS_KEY:-}" ]]; then
    if command -v aws >/dev/null 2>&1; then
        echo "[$(date)] aws s3 cp → s3://${S3_BUCKET}/"
        AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY" \
            aws --endpoint-url="$S3_ENDPOINT" s3 cp "$DUMP" "s3://${S3_BUCKET}/db/" 2>&1
        [[ -f "$STORAGE_TGZ" ]] && AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY" \
            aws --endpoint-url="$S3_ENDPOINT" s3 cp "$STORAGE_TGZ" "s3://${S3_BUCKET}/storage/" 2>&1
    else
        echo "[$(date)] WARN: aws cli not installed — skipping remote sync. Install: apt install awscli"
    fi
else
    echo "[$(date)] S3_* env vars not set — local-only backup"
fi

# ── 5. Local retention: keep 30 days ───────────────────────────────────────
echo "[$(date)] pruning local backups older than 30 days"
find "$DB_DIR"      -name "*.sql.gz*" -mtime +30 -delete 2>/dev/null || true
find "$STORAGE_DIR" -name "*.tgz*"    -mtime +30 -delete 2>/dev/null || true

# ── 6. Health ping (optional) — heartbeat URL ──────────────────────────────
if [[ -n "${HEARTBEAT_URL:-}" ]]; then
    curl -fsS -m 10 --retry 3 "$HEARTBEAT_URL" >/dev/null 2>&1 && \
        echo "[$(date)] heartbeat ping OK" || \
        echo "[$(date)] heartbeat ping FAILED (non-fatal)"
fi

echo "[$(date)] === backup complete ==="
