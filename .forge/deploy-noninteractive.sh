#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy-noninteractive.sh — full deploy, no prompts
#
# Runs every post-Forge-deploy step without asking. Idempotent (safe
# to re-run). Errors fail loud (set -e) so the operator sees exactly
# where it stopped.
#
# Assumes Forge's Quick Deploy webhook already did:
#   • git pull
#   • composer install
#   • artisan migrate (Forge's deploy.sh runs migrate too)
#
# What this script adds on top:
#   • artisan optimize (cache rebuild)
#   • marco:import-partners (the 49 port workshops fixture)
#   • tenders:audit-all-users (health check, exit code is informational
#                              only — non-zero is fine here)
#   • php-fpm reload (OPcache flush so the new code actually executes)
#
# Cola no console DO:
#   curl -fsSL https://raw.githubusercontent.com/monteirobrunoo-debug/clawyard/main/.forge/deploy-noninteractive.sh | bash
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_DIR="/home/forge/clawyard.partyard.eu"
APP_USER="forge"

# Auto-elevate root → forge.
if [[ "$(id -un)" != "$APP_USER" ]]; then
    if [[ "$EUID" -ne 0 ]]; then
        echo "✗ Run as $APP_USER or as root (current: $(id -un))." >&2
        exit 1
    fi
    exec sudo -u "$APP_USER" -i bash -s <<EOF
$(cat "$0" 2>/dev/null || curl -fsSL https://raw.githubusercontent.com/monteirobrunoo-debug/clawyard/main/.forge/deploy-noninteractive.sh)
EOF
fi

# Pick the right working dir (atomic-deploy `current` symlink).
if [[ -L "$APP_DIR/current" ]]; then
    WORK="$APP_DIR/current"
else
    WORK="$APP_DIR"
fi
PHP="${FORGE_PHP:-php}"
command -v "$PHP" >/dev/null 2>&1 || PHP="$(command -v php)"

color()   { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }

cd "$WORK"

section "Working dir"
echo "  $(pwd)"
echo "  PHP: $PHP"

section "1/5  artisan migrate"
"$PHP" artisan migrate --force

section "2/5  artisan optimize"
"$PHP" artisan config:clear  >/dev/null
"$PHP" artisan cache:clear   >/dev/null
"$PHP" artisan view:clear    >/dev/null
"$PHP" artisan route:clear   >/dev/null
"$PHP" artisan optimize      >/dev/null
echo "  done"

section "3/5  marco:import-partners"
SEED="$WORK/database/seed-data/marco/2026-04-25_port-workshop-mapping_v1.xlsx"
RUNTIME="$WORK/storage/app/marco/sources/$(basename "$SEED")"
mkdir -p "$(dirname "$RUNTIME")"
if [[ -f "$SEED" ]]; then
    cp "$SEED" "$RUNTIME"
    "$PHP" artisan marco:import-partners "$RUNTIME"
else
    color "33" "  Seed xlsx not present (skipping). Pull the latest commit if missing."
fi

section "4/5  Audit (read-only)"
"$PHP" artisan tenders:audit-collaborator-emails || true

section "5/5  Cross-user health"
# Health-check exit code is non-zero when anomalies exist — that's
# informational, not a deploy failure. Capture so set -e doesn't bail.
"$PHP" artisan tenders:audit-all-users || true

section "OPcache reload"
FPM_SERVICE="$(systemctl list-units --type=service --no-pager 2>/dev/null | awk '/php.*fpm/ {print $1}' | head -1 || true)"
if [[ -n "$FPM_SERVICE" ]]; then
    sudo -n service "$FPM_SERVICE" reload 2>&1 && \
        color "32" "  reloaded $FPM_SERVICE" || \
        color "33" "  could not reload $FPM_SERVICE (no sudo password) — Forge will reload on next deploy"
else
    color "33" "  php-fpm service not found — manual reload may be needed"
fi

echo
color "32" "✓ Deploy complete."
echo
echo "Visible changes:"
echo "  • Marta CRM: past closing dates auto-snapped forward"
echo "  • Marco/Vasco: cite 49 port workshop contacts"
echo "  • /tenders: source-restriction banner for restricted users"
echo "  • /tenders/collaborators: bulk source/status panel"
echo "  • user_admin_events table: every admin action audited"
echo
echo "hp-history (Phase 2): code is in place but DORMANT (HP_HISTORY_ENABLED=false)."
echo "Activates when you create the second droplet and flip the env flag."
