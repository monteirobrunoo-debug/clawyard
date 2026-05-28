#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy.sh — Forge auto-deploy script (HARDENED 2026-05-25)
#
# Resolve 6 race conditions identificadas a 25 Mai 2026:
#   1. Mutex global flock para prevenir deploys concorrentes
#   2. Sem `clear → optimize` gap — só optimize (atomic)
#   3. Octane reload com retry + health check + fallback restart
#   4. Verificação de health após reload (curl /health)
#   5. Migrate só depois de autoload (schema mismatch protection)
#   6. Logs com timestamps para debugar deploys lentos
#
# IMPORTANTE: este script NÃO USA set -e propositadamente — queremos
# que falhas individuais não tumbem o pipeline. Cada step gere o seu
# próprio erro e continua quando seguro.
# ─────────────────────────────────────────────────────────────────────────────

DEPLOY_LOCK="/tmp/clawyard-deploy.lock"
APP_DIR="/home/forge/clawyard.partyard.eu"
HEALTH_URL="http://127.0.0.1:8000/health"

ts() { date +'%H:%M:%S'; }
log() { echo "[$(ts)] $*"; }
err() { echo "[$(ts)] ✗ $*" >&2; }

# ─── 0. Mutex global ────────────────────────────────────────────────────────
# Só 1 deploy de cada vez. Se outro estiver a correr, espera até 60s
# então aborta (não acumula deploys queued).
exec 200>"$DEPLOY_LOCK"
if ! flock -w 60 200; then
    err "Outro deploy a correr há >60s — abortando para evitar race conditions"
    exit 1
fi

log "═══ DEPLOY START ═══"
cd "$APP_DIR" || { err "cd $APP_DIR falhou"; exit 1; }

# ─── 1. Pull código ─────────────────────────────────────────────────────────
log "1/8 git pull"
git pull origin main 2>&1 | tail -3

# ─── 2. Composer install + dump ─────────────────────────────────────────────
log "2/8 composer install + dump-autoload"
$FORGE_PHP composer install --no-interaction --prefer-dist --optimize-autoloader --quiet
$FORGE_PHP composer dump-autoload --optimize -n -q

# ─── 2.5. Smoke test — apanha PHP fatal errors ANTES de tocar Octane ────────
# Boot do Laravel completo via artisan. Se houver erro fatal (typo, classe
# inexistente, etc.), abortamos aqui antes de quebrar produção.
# Pedido directo Bruno 2026-05-27: "estou farto que octane de tantos erros".
log "2.5/8 smoke test (boot Laravel sem afectar produção)"
SMOKE_OUT=$($FORGE_PHP artisan tinker --execute='echo "ok\n"; var_dump(config("app.name"));' 2>&1)
SMOKE_CODE=$?
if [[ $SMOKE_CODE -ne 0 ]] || [[ "$SMOKE_OUT" != *"ok"* ]]; then
    err "Smoke test FALHOU — Laravel não boota com este código. Aborto antes de tocar Octane."
    err "Output: $SMOKE_OUT"
    exit 1
fi
log "  ✓ smoke test passa"

# ─── 3. Migrate ─────────────────────────────────────────────────────────────
# Depois de autoload (caso migration referencie classes novas).
# Antes de cache rebuild (caso novas migrations alterem schemas referenciados).
log "3/8 migrate --force"
if ! $FORGE_PHP artisan migrate --force --no-interaction 2>&1; then
    err "Migration falhou — abortando deploy (cache não rebuild, Octane mantém estado anterior)"
    exit 1
fi

# ─── 4. Cache rebuild atómico ───────────────────────────────────────────────
# Substitui o padrão "clear → optimize" (2-3s gap a 500s) pelo "optimize"
# directo que sobrepõe os ficheiros atomicamente. config:cache + route:cache
# + view:cache + event:cache numa só call.
log "4/8 artisan optimize (atomic cache rebuild)"
if ! $FORGE_PHP artisan optimize 2>&1; then
    err "Optimize falhou — Octane vai correr sem cache (OK mas lento)"
    # Não exit — continua para tentar restart workers
fi

# ─── 4.1. Auto-bump SW version + opcache reset (anti-freeze 2026-05-28) ─────
# Pedido directo Bruno: "já é um problema antigo esse freeze no octane, tem
# de ser melhorado". User via comportamento antigo apesar do deploy ter o
# código novo. 3 vectores combinados resolvem em definitivo:
#
#   a) sw.js — injecta git short SHA na CACHE_VERSION. Cada release tem cache
#      key única → SW invalida automaticamente todos os caches antigos no
#      próximo carregamento (activate event line 36-45 do sw.js).
#   b) opcache_reset() — Octane workers re-leem todos os .php files. Mesmo
#      que opcache.validate_timestamps=0 estivesse activo, reset força.
#   c) view:clear é implícito no view:cache do optimize, mas se houver
#      problemas adicionais, ver storage/framework/views/.
GIT_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
DEPLOY_TS=$(date +'%Y-%m-%d_%H%M')
if [[ -f public/sw.js ]]; then
    NEW_VERSION="clawyard-${GIT_SHA}-${DEPLOY_TS}"
    if sed -i.bak "s|^const CACHE_VERSION = '.*';|const CACHE_VERSION = '${NEW_VERSION}';|" public/sw.js 2>/dev/null; then
        rm -f public/sw.js.bak
        log "  ✓ sw.js bump → ${NEW_VERSION}"
    else
        err "  sw.js sed failed — continuando sem bump"
    fi
fi

# opcache_reset via tinker. Não-crítico: se falhar, octane:reload abaixo
# também limpa opcache no boot dos workers novos.
$FORGE_PHP artisan tinker --execute='function_exists("opcache_reset") ? opcache_reset() : null; echo "opcache_reset:" . (function_exists("opcache_reset") ? "ok" : "skipped") . "\n";' 2>&1 | tail -1

# ─── 5. Octane reload com verificação ───────────────────────────────────────
# octane:reload manda SIGUSR1. Swoole espera que cada worker termine a request
# actual (graceful) antes de o reiniciar com código novo.
#
# 2026-05-28 Fase A4: BUMP 8→30s. User reportou "Erro: network error" mid-
# stream depois de deploys. Streams SSE Opus (15-60s) eram cortadas porque o
# wait de 8s era curto demais — workers velhos killed antes da stream
# completar. 30s cobre a maioria dos casos sem atrasar deploys excessivamente.
# Trade-off: deploys ~22s mais lentos = zero cortes em streams.
log "5/8 octane:reload (graceful drain, espera 30s)"
$FORGE_PHP artisan octane:reload 2>&1 || err "octane:reload sinal falhou — vou tentar hard restart"

# Wait for workers to actually rotate + drain in-flight streams.
sleep 30

# Health check com 3 tentativas (5s entre cada)
log "6/8 health check"
HEALTH_OK=false
for attempt in 1 2 3; do
    code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
    if [[ "$code" == "200" ]]; then
        log "  ✓ health 200 (attempt $attempt)"
        HEALTH_OK=true
        break
    fi
    log "  attempt $attempt → HTTP $code — retry em 5s"
    sleep 5
done

# ─── 7. Fallback: hard restart se health falhou ─────────────────────────────
if [[ "$HEALTH_OK" == "false" ]]; then
    err "Health failed após reload — hard restart"
    sudo -n systemctl restart clawyard-octane.service 2>&1 || \
        err "systemctl restart falhou — investigação manual necessária"

    # Mais 8s + nova verificação
    sleep 8
    code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
    if [[ "$code" == "200" ]]; then
        log "  ✓ recovered after hard restart"
    else
        err "  ✗ STILL DOWN after restart — ALERTAR ADMIN"
        # Não exit — pelo menos queue:restart corre
    fi
fi

# ─── 8. Queue restart — REMOVIDO 2026-05-28 ─────────────────────────────────
# Pedido directo Bruno: jobs em flight (RunTenderAnalysisJob ~5-10min) eram
# killed durante deploys, gerando MaxAttemptsExceededException em Sentry.
#
# Decisão: workers actualizam-se naturalmente via max-requests=500 ciclo
# (~30-60min em produção típica). Trade-off: código de queue jobs pode
# ficar 30-60min desactualizado após deploy, mas in-flight jobs sobrevivem.
#
# Se algum dia precisares de force-restart workers (ex: bug crítico em job
# class), corres manualmente:
#   sudo supervisorctl restart clawyard-queue:*
# OU:
#   sudo -u forge php /home/forge/clawyard.partyard.eu/current/artisan queue:restart
log "7/8 queue:restart SKIPPED (graceful — workers ciclam via max-requests=500)"

log "8/8 ═══ DEPLOY END ═══"

# Resumo final
final_code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
if [[ "$final_code" == "200" ]]; then
    log "✅ Deploy completo. Health 200. Octane operacional."
    exit 0
else
    err "⚠ Deploy completo MAS health=$final_code. Verifica logs Octane."
    exit 2
fi
