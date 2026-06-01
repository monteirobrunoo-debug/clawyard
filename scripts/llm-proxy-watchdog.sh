#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# llm-proxy-watchdog.sh — vigia o /healthz do llm-proxy e reinicia-o se PENDURAR.
#
# Contexto (2026-06-01): o llm-proxy (FastAPI+httpx async, :8787) é o gateway
# por onde passam TODAS as chamadas Anthropic. Passa a correr sob Supervisor
# (autorestart=true) — isso cobre CRASHES (processo morre → supervisor levanta).
#
# MAS autorestart NÃO apanha um HANG (processo vivo, event loop preso). Para
# isso serve este watchdog: bate ao /healthz; se falhar N vezes seguidas,
# força `supervisorctl restart` (NOPASSWD já configurado para /usr/bin/supervisorctl).
#
# NOTA: a causa das falhas de 31 Mai→1 Jun NÃO foi o proxy pendurar — foi o
# timeout de 120s do CLIENTE (Laravel) em chamadas non-stream lentas, já
# corrigido (300s). Este watchdog é defesa-em-profundidade, não o fix principal.
#
# Cron (forge, NÃO root):
#   * * * * * /home/forge/clawyard.partyard.eu/current/scripts/llm-proxy-watchdog.sh
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

HEALTH_URL="http://127.0.0.1:8787/healthz"
PROGRAM="partyard-llm-proxy"           # nome do programa no supervisor (Forge daemon)
STATE="/tmp/llm-proxy-watchdog.fails"  # contador de falhas consecutivas
THRESHOLD=3                            # 3 falhas seguidas (~3min @ cron 1min) → restart
LOG="/home/forge/llm-proxy/logs/watchdog.log"

ts() { date +'%Y-%m-%d %H:%M:%S'; }

code=$(curl -s -m 8 -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)

# Saudável → zera o contador e sai.
if [ "$code" = "200" ]; then
    [ -f "$STATE" ] && rm -f "$STATE"
    exit 0
fi

# Falhou → incrementa contador.
fails=$(( $(cat "$STATE" 2>/dev/null || echo 0) + 1 ))
echo "$fails" > "$STATE"
echo "[$(ts)] /healthz=$code — falha consecutiva $fails/$THRESHOLD" >> "$LOG"

# Abaixo do limiar → espera (pode ser um pico transitório).
[ "$fails" -lt "$THRESHOLD" ] && exit 0

# Limiar atingido → restart via supervisor (NOPASSWD).
echo "[$(ts)] LIMIAR atingido — a reiniciar $PROGRAM via supervisorctl" >> "$LOG"
sudo -n /usr/bin/supervisorctl restart "$PROGRAM" >> "$LOG" 2>&1
rm -f "$STATE"

# Verifica recuperação (boot ~3-8s).
sleep 8
code2=$(curl -s -m 8 -o /dev/null -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000)
echo "[$(ts)] pós-restart /healthz=$code2" >> "$LOG"
[ "$code2" = "200" ] || echo "[$(ts)] ⚠ AINDA em baixo após restart — intervenção manual" >> "$LOG"
exit 0
