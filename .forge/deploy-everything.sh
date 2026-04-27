#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy-everything.sh — full deploy of every change pushed since 2026-04-23
#
# Paste this whole script into the DigitalOcean web console (or any SSH
# session) on clawyard.partyard.eu. Runs as either `forge` or `root` —
# auto-elevates to forge if started as root.
#
# Sequence:
#   1. git pull origin main
#   2. composer install --no-dev (in case deps changed)
#   3. artisan migrate --force          (3 new migrations)
#   4. artisan optimize                 (config + route + view cache)
#   5. (optional) tenders:audit-collaborator-emails    — read-only
#   6. (optional) marco:import-partners                — port workshop DB
#   7. (optional) tenders:audit-all-users              — health check
#   8. service php-fpm reload           (clear opcache for the live SAPI)
#
# Each optional step is gated by a `read -p` so a botched migration or
# import doesn't cascade into the next thing. Idempotent — safe to
# re-run after fixing whatever stopped you.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_DIR="/home/forge/clawyard.partyard.eu"
APP_USER="forge"

# Auto-escalate from root → forge so file ownership / opcache stay
# correct for the FPM pool.
if [[ "$(id -un)" != "$APP_USER" ]]; then
    if [[ "$EUID" -ne 0 ]]; then
        echo "✗ Run as $APP_USER or as root (current: $(id -un))." >&2
        exit 1
    fi
    echo "→ Currently root, re-running as $APP_USER…"
    exec sudo -u "$APP_USER" -i bash "$0" "$@"
fi

# Atomic-deploy paths: Forge's `current` symlink → releases/<id>.
# We act on whichever shape exists.
if [[ -L "$APP_DIR/current" && -d "$APP_DIR/current" ]]; then
    WORK="$APP_DIR/current"
elif [[ -d "$APP_DIR/.git" ]]; then
    WORK="$APP_DIR"
else
    echo "✗ Don't recognise the layout at $APP_DIR — neither current symlink nor .git" >&2
    exit 1
fi

PHP="${FORGE_PHP:-php}"
command -v "$PHP" >/dev/null 2>&1 || PHP="$(command -v php)"

color()   { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }
ask()     { read -r -p "$1 [y/N] " a; [[ "$a" =~ ^[Yy]$ ]]; }

cd "$WORK"

section "1/8  Confirm where we are"
echo "Working dir: $(pwd)"
echo "PHP binary:  $PHP"
echo "Branch:      $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '(no .git here — atomic deploy)')"

section "2/8  git pull origin main"
# In atomic-deploy mode `current` is a release snapshot, not a working
# tree — Forge uses its own deploy.sh for those. Detect and route:
if [[ -d ".git" ]]; then
    git fetch origin main
    LOCAL=$(git rev-parse HEAD)
    REMOTE=$(git rev-parse origin/main)
    if [[ "$LOCAL" == "$REMOTE" ]]; then
        color "33" "Already at $(git rev-parse --short HEAD)"
    else
        git pull origin main
        color "32" "Pulled to $(git rev-parse --short HEAD)"
    fi
else
    color "33" "Atomic-deploy site detected — go to Forge dashboard and click 'Deploy Now'."
    color "33" "Once that finishes, re-run this script and it will skip the pull step."
    if ! ask "Continue assuming the deploy already ran via Forge?"; then
        exit 0
    fi
fi

section "3/8  composer install"
if [[ -f composer.json ]]; then
    if ask "Run composer install --no-dev --optimize-autoloader?"; then
        "$PHP" "$(command -v composer)" install --no-dev --prefer-dist --optimize-autoloader --no-interaction || \
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    else
        color "33" "Skipped composer."
    fi
fi

section "4/8  artisan migrate --force"
echo "Pending migrations:"
"$PHP" artisan migrate:status 2>&1 | tail -20 || true
if ask "Run migrations?"; then
    "$PHP" artisan migrate --force
else
    color "33" "Skipped migrate."
fi

section "5/8  artisan optimize"
"$PHP" artisan config:clear
"$PHP" artisan cache:clear
"$PHP" artisan view:clear
"$PHP" artisan route:clear
"$PHP" artisan optimize

section "6/8  Audit collaborator emails (read-only)"
if ask "Run tenders:audit-collaborator-emails?"; then
    "$PHP" artisan tenders:audit-collaborator-emails || true
    if ask "  …apply --fix to repair phantom rows?"; then
        "$PHP" artisan tenders:audit-collaborator-emails --fix
    fi
fi

section "7/8  Import port-workshop partners"
SEED="$WORK/database/seed-data/marco/2026-04-25_port-workshop-mapping_v1.xlsx"
if [[ -f "$SEED" ]]; then
    if ask "Import 49 port-workshop partners for Marco/Vasco?"; then
        bash "$WORK/.forge/import-partners.sh" || true
    fi
else
    color "33" "Seed xlsx not found at $SEED — skipping. Pull the latest commit if missing."
fi

section "8/8  Health check"
"$PHP" artisan tenders:audit-all-users || true

# Restart FPM so OPcache picks up edited classes (Laravel's optimize
# alone doesn't touch the live PHP process).
section "Bonus  reload php-fpm"
if command -v sudo >/dev/null 2>&1; then
    if ask "Reload php-fpm? (recommended after a deploy)"; then
        FPM_SERVICE="$(systemctl list-units --type=service --no-pager 2>/dev/null | awk '/php.*fpm/ {print $1}' | head -1)"
        if [[ -n "$FPM_SERVICE" ]]; then
            sudo -S service "$FPM_SERVICE" reload < /dev/null
            color "32" "Reloaded $FPM_SERVICE"
        else
            color "33" "php-fpm service not detected — reload manually if needed"
        fi
    fi
fi

color "32" "✓ Deploy complete. Visit https://clawyard.partyard.eu and smoke-test the chat."
echo
echo "Marca CRM bug:  Marta should now snap past dates forward."
echo "Marco/Vasco:    will cite port partners (49 in the DB after the import)."
echo "hp-history:     OFF until you set HP_HISTORY_ENABLED=true and the second droplet is up."
