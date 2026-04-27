#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# import-partners.sh — load the curated port-workshop xlsx into prod
#
# Paste in the DigitalOcean web console (or any SSH session) on
# clawyard.partyard.eu after `git pull origin main` has brought in the
# new migration + command + service + tests.
#
# What this does:
#   1. cd to Forge app dir
#   2. ensure migrations are up to date (creates partner_workshops if new)
#   3. show what would be imported (dry-run)  ← no writes yet
#   4. ask for confirmation
#   5. real import with --prune (deactivates rows that disappear from
#      the xlsx in future re-imports)
#   6. quick sanity check: print row count + 3 high-priority partners
#
# Idempotent: re-running creates 0 / updates N. The --prune flag only
# deactivates rows missing from THIS xlsx — it never deletes.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_DIR="/home/forge/clawyard.partyard.eu/current"
APP_USER="forge"

# Drop to forge if running as root (DO console default).
if [[ "$(id -un)" != "$APP_USER" ]]; then
    if [[ "$EUID" -ne 0 ]]; then
        echo "✗ Run as $APP_USER or as root (current: $(id -un))." >&2
        exit 1
    fi
    exec sudo -u "$APP_USER" -i bash "$0" "$@"
fi

PHP="${FORGE_PHP:-php}"
command -v "$PHP" >/dev/null 2>&1 || PHP="$(command -v php)"

color() { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }

cd "$APP_DIR"

section "1/5  Migrate (creates partner_workshops if absent)"
"$PHP" artisan migrate --force

section "2/5  Locate xlsx"
# Source-of-truth fixture lives in database/seed-data/marco/ (committed).
# The artisan command reads from storage/app/marco/sources/ by default;
# we copy the committed fixture in if it's not already there, so the
# operator doesn't have to scp anything.
SEED_DIR="database/seed-data/marco"
RUNTIME_DIR="storage/app/marco/sources"
mkdir -p "$RUNTIME_DIR"
SEED_LATEST="$(ls -t "$SEED_DIR"/*.xlsx 2>/dev/null | head -1 || true)"
if [[ -z "$SEED_LATEST" ]]; then
    color "31" "✗ No xlsx found in $SEED_DIR. The repo should ship the fixture here."
    exit 1
fi
RUNTIME_FILE="$RUNTIME_DIR/$(basename "$SEED_LATEST")"
if [[ ! -f "$RUNTIME_FILE" || "$SEED_LATEST" -nt "$RUNTIME_FILE" ]]; then
    cp "$SEED_LATEST" "$RUNTIME_FILE"
    echo "Copied seed → runtime: $RUNTIME_FILE"
fi
XLSX="$RUNTIME_FILE"
echo "Will import: $XLSX"

section "3/5  Dry-run (no DB writes)"
"$PHP" artisan marco:import-partners "$XLSX" --dry-run

section "4/5  Confirm and apply"
echo
read -r -p "Type 'yes' to import + prune (deactivates removed rows): " ANSWER
if [[ "$ANSWER" != "yes" ]]; then
    color "33" "Skipped — nothing written."
    exit 0
fi

"$PHP" artisan marco:import-partners "$XLSX" --prune

section "5/5  Sanity check"
"$PHP" artisan tinker --execute="
\$total = \App\Models\PartnerWorkshop::active()->count();
echo \"Active partners: {\$total}\" . PHP_EOL;
echo PHP_EOL . 'High-priority sample:' . PHP_EOL;
foreach (\App\Models\PartnerWorkshop::active()->where('priority', 'high_priority')->take(3)->get() as \$p) {
    echo '  · ' . \$p->company_name . ' @ ' . \$p->port . ' (domains=' . json_encode(\$p->domains) . ')' . PHP_EOL;
}
"

section "Done"
echo "Marco (sales) and Capitão Vasco (vessel) now have access to the partner network."
echo "Test in the chat:"
echo "  Marco: 'preciso de overhaul Wärtsilä em Singapura — quem temos?'"
echo "  Vasco: 'drydock alternative em Genova for a 200m vessel?'"
