#!/bin/bash
# ────────────────────────────────────────────────────────────────────────────
# process-watchdog.sh — detecta workers duplicados/zombie e workers em falta.
#
# Pedido directo Bruno 2026-05-28 (Fase A1) depois de descobrirmos que um
# systemd zombie clawyard-queue.service estava a competir com Supervisor há
# ~30 dias. 142 restarts/dia, jobs mortos aos 90s. Watchdog teria apanhado
# em horas.
#
# Verifica a cada minuto:
#   - count de processos `php * artisan queue:work`        (esperado: 2)
#   - count de processos `php * artisan octane:start`      (esperado: 1, mas
#     Octane spawna workers internos: ver SWOOLE_WORKER_NUM em .env)
#
# Acções:
#   - Count > esperado → ZOMBIE detectado → log WARN + grava em Laravel log
#     (capturado por Sentry se configurado).
#   - Count < esperado → worker MORTO → log WARN + grava em Laravel log.
#   - Count == esperado → tudo bem, sem ruído.
#
# Instalação (1× sudo no servidor):
#   chmod +x /home/forge/clawyard.partyard.eu/current/scripts/process-watchdog.sh
#   # Cron como root:
#   echo "* * * * * /home/forge/clawyard.partyard.eu/current/scripts/process-watchdog.sh" \
#     | sudo tee /etc/cron.d/clawyard-process-watchdog
#
# Pode também correr manualmente para debug:
#   /home/forge/clawyard.partyard.eu/current/scripts/process-watchdog.sh --verbose
# ────────────────────────────────────────────────────────────────────────────

set -u

VERBOSE=0
if [[ "${1:-}" == "--verbose" ]] || [[ "${1:-}" == "-v" ]]; then
    VERBOSE=1
fi

EXPECTED_QUEUE_WORKERS=2
LOG_FILE="/var/log/clawyard-process-watchdog.log"
LARAVEL_LOG="/home/forge/clawyard.partyard.eu/current/storage/logs/laravel.log"

ts() { date +'%Y-%m-%d %H:%M:%S'; }

# Não rebenta se o LOG_FILE não for writable — fallback to stderr
log() {
    local msg="[$(ts)] $*"
    if [[ -w "$LOG_FILE" ]] || touch "$LOG_FILE" 2>/dev/null; then
        echo "$msg" >> "$LOG_FILE"
    fi
    [[ $VERBOSE -eq 1 ]] && echo "$msg" >&2
}

# Escreve uma entry estruturada no Laravel log que o Sentry captura.
# Formato matches o handler Laravel (com prefixo production.WARNING:).
laravel_log() {
    local level="$1"
    local message="$2"
    local context="$3"  # JSON
    if [[ -w "$LARAVEL_LOG" ]] || [[ -w "$(dirname "$LARAVEL_LOG")" ]]; then
        local prefix
        prefix="[$(date +'%Y-%m-%d %H:%M:%S')] production.${level}: "
        echo "${prefix}process-watchdog: ${message} ${context}" >> "$LARAVEL_LOG" 2>/dev/null
    fi
}

# Conta processos queue:work activos para este site (path em current/).
# Filtra explicitamente pelo path para não apanhar workers de outros sites.
count_queue_workers() {
    pgrep -af 'artisan queue:work' 2>/dev/null \
        | grep -c '/home/forge/clawyard.partyard.eu/' \
        || echo 0
}

# Lista PIDs + cmdline para incluir no relatório de zombie.
queue_worker_summary() {
    pgrep -af 'artisan queue:work' 2>/dev/null \
        | grep '/home/forge/clawyard.partyard.eu/' \
        || true
}

# ── Verificação queue workers ───────────────────────────────────────────────
queue_count=$(count_queue_workers)

if (( queue_count > EXPECTED_QUEUE_WORKERS )); then
    summary=$(queue_worker_summary | tr '\n' '|' | sed 's/|$//')
    log "WARN: queue worker zombie detectado — count=${queue_count}, expected=${EXPECTED_QUEUE_WORKERS}"
    log "Processos: ${summary}"
    laravel_log "WARNING" "queue worker zombie detectado" \
        "{\"count\":${queue_count},\"expected\":${EXPECTED_QUEUE_WORKERS},\"processes\":\"${summary}\"}"
elif (( queue_count < EXPECTED_QUEUE_WORKERS )); then
    log "WARN: queue workers em falta — count=${queue_count}, expected=${EXPECTED_QUEUE_WORKERS}"
    laravel_log "WARNING" "queue workers em falta" \
        "{\"count\":${queue_count},\"expected\":${EXPECTED_QUEUE_WORKERS}}"
elif [[ $VERBOSE -eq 1 ]]; then
    log "OK: queue workers=${queue_count} (esperado ${EXPECTED_QUEUE_WORKERS})"
fi

# ── Verificação Octane (best effort — count varia com SWOOLE_WORKER_NUM) ────
# Octane spawna 1 master + N workers; só verificamos que pelo menos o master
# existe. Detalhe de worker count fica para watchdog específico.
octane_master=$(pgrep -af 'artisan octane:start' 2>/dev/null \
    | grep -c '/home/forge/clawyard.partyard.eu/' \
    || echo 0)

if (( octane_master < 1 )); then
    log "WARN: Octane master process não detectado"
    laravel_log "WARNING" "Octane master ausente" \
        "{\"check\":\"octane:start\"}"
elif [[ $VERBOSE -eq 1 ]]; then
    log "OK: Octane master detectado (${octane_master} processo)"
fi

exit 0
