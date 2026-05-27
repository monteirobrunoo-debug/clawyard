#!/bin/bash
# ────────────────────────────────────────────────────────────────────────────
# octane-watchdog.sh — auto-recovery do Octane se health falhar
#
# Corre a cada 30s via cron. Se /health falhar 3× seguidas → reload Octane.
# Se 5× seguidas → hard restart + email Bruno.
#
# Instalação no droplet (cron como root):
#   * * * * * /home/forge/clawyard.partyard.eu/current/scripts/octane-watchdog.sh
#   * * * * * sleep 30; /home/forge/clawyard.partyard.eu/current/scripts/octane-watchdog.sh
# (executa duas vezes por minuto para um intervalo efectivo de 30s)
#
# Pedido directo Bruno 2026-05-27: "estou farto que octane de tantos erros".
# ────────────────────────────────────────────────────────────────────────────

set -u
HEALTH_URL="http://127.0.0.1:8000/health"
STATE_FILE="/tmp/clawyard-watchdog-state"
ADMIN_EMAIL="${ADMIN_EMAIL:-bruno.monteiro@hp-group.org}"
RELOAD_AFTER=3   # consecutive failures → reload
RESTART_AFTER=5  # consecutive failures → hard restart

ts() { date +'%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*" >> /var/log/clawyard-watchdog.log; }

# Lê estado actual (counter de falhas consecutivas)
fails=0
if [[ -f "$STATE_FILE" ]]; then
    fails=$(cat "$STATE_FILE" 2>/dev/null || echo "0")
fi
[[ "$fails" =~ ^[0-9]+$ ]] || fails=0

# Check health
code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

if [[ "$code" == "200" ]]; then
    # Sucesso — reset counter (sem log para evitar verbose)
    if [[ "$fails" -gt 0 ]]; then
        log "✓ recovered after $fails consecutive failures"
        echo 0 > "$STATE_FILE"
    fi
    exit 0
fi

# Falha — incrementa counter
fails=$((fails + 1))
echo "$fails" > "$STATE_FILE"
log "✗ health=$code (consecutive failures: $fails)"

if [[ "$fails" -eq "$RELOAD_AFTER" ]]; then
    log "⚠ $RELOAD_AFTER failures consecutive → octane:reload"
    sudo -u forge php /home/forge/clawyard.partyard.eu/current/artisan octane:reload 2>&1 | log
fi

if [[ "$fails" -ge "$RESTART_AFTER" ]]; then
    log "🚨 $RESTART_AFTER+ failures consecutive → hard restart Octane"
    systemctl restart clawyard-octane.service 2>&1 | log

    # Email alert (best-effort, requires mailutils)
    if command -v mail >/dev/null 2>&1; then
        echo "ClawYard Octane restarted by watchdog at $(ts). Health was $code for $fails consecutive checks. Check: journalctl -u clawyard-octane.service --since '10 min ago'" | \
            mail -s "🚨 ClawYard: Octane auto-restarted" "$ADMIN_EMAIL"
    fi
fi

exit 0
