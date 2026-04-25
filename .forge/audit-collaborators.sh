#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# audit-collaborators.sh — fix the catarina/mónica leak on production
#
# Paste this whole script into the DigitalOcean web console (or any SSH
# session) on clawyard.partyard.eu. It will:
#
#   1. cd into the Forge app directory
#   2. git pull origin main         (so the new artisan commands exist)
#   3. php artisan optimize         (cache clear + reload)
#   4. tenders:audit-collaborator-emails        (read-only audit)
#   5. ask before applying tenders:audit-collaborator-emails --fix
#
# Safe by default: nothing destructive runs without you typing "yes".
# Re-runnable: idempotent — running twice produces the same outcome.
#
# If the console drops you in as root, the script will sudo to forge
# automatically so file ownership / opcache stays correct.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_DIR="/home/forge/clawyard.partyard.eu"
APP_USER="forge"

# Re-exec as forge if we're root (DO console default).
if [[ "$(id -un)" != "$APP_USER" ]]; then
    if [[ "$EUID" -ne 0 ]]; then
        echo "✗ Run as $APP_USER or as root (current: $(id -un))." >&2
        exit 1
    fi
    echo "→ Currently root, re-running as $APP_USER…"
    exec sudo -u "$APP_USER" -i bash "$0" "$@"
fi

# Pick the PHP binary Forge uses. $FORGE_PHP is set in Forge's deploy
# environment but not in interactive shells, so fall back gracefully.
PHP="${FORGE_PHP:-php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    PHP="$(command -v php)"
fi

color() { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }

cd "$APP_DIR"

section "1/4  Pulling latest main"
git fetch origin main
LOCAL_HEAD=$(git rev-parse HEAD)
REMOTE_HEAD=$(git rev-parse origin/main)
if [[ "$LOCAL_HEAD" == "$REMOTE_HEAD" ]]; then
    color "33" "Already up to date ($(git rev-parse --short HEAD))."
else
    git pull origin main
    color "32" "Pulled to $(git rev-parse --short HEAD)."
fi

section "2/4  Cache + optimize"
"$PHP" artisan config:clear
"$PHP" artisan cache:clear
"$PHP" artisan view:clear
"$PHP" artisan route:clear
"$PHP" artisan optimize

section "3/4  Read-only audit"
# Show every collaborator row whose email belongs to a different User than
# its user_id link. Nothing is changed yet.
"$PHP" artisan tenders:audit-collaborator-emails || true

section "4/4  Apply repair?"
echo
echo "If the audit above listed any mismatches, --fix will:"
echo "  • CLEAR the email column on each mismatched row"
echo "  • KEEP the user_id link (the row's true owner)"
echo "  • PRESERVE every assigned tender (digest_email falls back to User.email)"
echo
echo "If the audit said 'OK — no mismatches found', nothing will change."
echo
read -r -p "Type 'yes' to apply --fix, anything else to skip: " ANSWER
if [[ "$ANSWER" == "yes" ]]; then
    "$PHP" artisan tenders:audit-collaborator-emails --fix
    color "32" "✓ Repair applied. Logged to storage/logs/laravel.log (level: warning)."
else
    color "33" "Skipped — repair NOT applied. Re-run this script when you're ready."
fi

section "Done"
echo "Useful follow-ups:"
echo "  $PHP artisan tenders:whoami catarina.sequeira@hp-group.org"
echo "  $PHP artisan tenders:whoami monica.pereira@hp-group.org"
echo
echo "Logs:"
echo "  tail -n 50 storage/logs/laravel.log | grep -A2 AuditCollaboratorEmails"
