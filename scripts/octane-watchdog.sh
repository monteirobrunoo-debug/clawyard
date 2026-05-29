#!/bin/bash
# ────────────────────────────────────────────────────────────────────────────
# octane-watchdog.sh v2 — agressivo, auto-escalation Bruno fix 2026-05-29
#
# Bruno reportou "temos de melhorar e muito". Análise dos logs do watchdog
# mostrou que ele FUNCIONA mas o threshold era demasiado lento:
#   v1: 3 fails (90s) → reload, 5 fails (150s) → restart
#   v2: 2 fails (60s) → reload, 3 fails (90s) → restart, e se reload já
#                                              foi tentado uma vez → restart imediato
#
# Aceleração porque:
#   - Bruno detecta 500 em 60-90s como user (tabs abertas)
#   - Reload às vezes falha silenciosamente (hoje) → escalar para restart
#   - 90s downtime é o máximo aceitável
# ────────────────────────────────────────────────────────────────────────────

set -u
HEALTH_URL="http://127.0.0.1:8000/health"
STATE_FILE="/tmp/clawyard-watchdog-state"
RELOAD_FLAG="/tmp/clawyard-watchdog-reloaded"
ADMIN_EMAIL="${ADMIN_EMAIL:-bruno.monteiro@hp-group.org}"
LARAVEL_LOG="/home/forge/clawyard.partyard.eu/current/storage/logs/laravel.log"

RELOAD_AFTER=2       # v2: 2 fails (60s) — era 3
RESTART_AFTER=3      # v2: 3 fails (90s) — era 5

ts() { date +'%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*" >> /var/log/clawyard-watchdog.log; }

# Também grava no laravel.log para Sentry capturar
laravel_warn() {
    if [[ -w "$LARAVEL_LOG" ]] 2>/dev/null; then
        echo "[$(ts)] production.WARNING: octane-watchdog: $* " >> "$LARAVEL_LOG"
    fi
}

# Lê state actual
fails=0
[[ -f "$STATE_FILE" ]] && fails=$(cat "$STATE_FILE" 2>/dev/null || echo "0")
[[ "$fails" =~ ^[0-9]+$ ]] || fails=0

# Already attempted reload?
already_reloaded=0
[[ -f "$RELOAD_FLAG" ]] && already_reloaded=1

# Check health
code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

if [[ "$code" == "200" ]]; then
    if [[ "$fails" -gt 0 ]]; then
        log "✓ recovered after $fails consecutive failures (reloaded=$already_reloaded)"
        echo 0 > "$STATE_FILE"
        rm -f "$RELOAD_FLAG"
    fi
    exit 0
fi

# Failure
fails=$((fails + 1))
echo "$fails" > "$STATE_FILE"
log "✗ health=$code (consecutive failures: $fails, reload_attempted=$already_reloaded)"

# Smart escalation
if [[ "$fails" -ge "$RESTART_AFTER" ]] || ([[ "$fails" -ge "$RELOAD_AFTER" ]] && [[ "$already_reloaded" -eq 1 ]]); then
    # HARD RESTART — reload já tentado OU 3+ fails
    log "🚨 hard restart Octane (fails=$fails, reload_attempted=$already_reloaded)"
    laravel_warn "Octane hard-restarted by watchdog after $fails fails"
    systemctl restart clawyard-octane.service 2>&1 | tee -a /var/log/clawyard-watchdog.log
    rm -f "$RELOAD_FLAG"  # reset reload tracker

    if command -v mail >/dev/null 2>&1; then
        echo "ClawYard Octane hard-restarted by watchdog at $(ts). HTTP $code for $fails consecutive checks. Check: journalctl -u clawyard-octane.service --since '10 min ago'" | \
            mail -s "🚨 ClawYard: Octane auto-restarted" "$ADMIN_EMAIL"
    fi
elif [[ "$fails" -eq "$RELOAD_AFTER" ]]; then
    # SOFT RELOAD primeiro (graceful)
    log "⚠ $RELOAD_AFTER fails consecutive → octane:reload (soft, graceful)"
    laravel_warn "Octane reload triggered by watchdog after $fails fails"
    sudo -u forge php8.4 /home/forge/clawyard.partyard.eu/current/artisan octane:reload 2>&1 | tee -a /var/log/clawyard-watchdog.log
    touch "$RELOAD_FLAG"  # marker para escalar se próximo tick ainda 500
fi

exit 0
