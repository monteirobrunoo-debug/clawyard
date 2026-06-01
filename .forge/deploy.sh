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

# 2026-05-29 (Bruno fix "temos de melhorar e muito"): suporte staging+prod.
# Forge corre este script no working dir do site. Detectamos qual ambiente
# pelo basename do dir actual — staging.partyard.eu vs clawyard.partyard.eu.
# Lock + URL + port mudam por ambiente para que ambos possam fazer deploy
# em paralelo sem race conditions.
SITE_DIR=$(pwd)
SITE_NAME=$(basename "$SITE_DIR")
case "$SITE_NAME" in
    staging.partyard.eu)
        DEPLOY_LOCK="/tmp/clawyard-staging-deploy.lock"
        APP_DIR="/home/forge/staging.partyard.eu"
        HEALTH_URL="http://127.0.0.1:8001/health"
        DEPLOY_BRANCH="staging"
        OCTANE_SVC="clawyard-staging-octane.service"  # opcional — pode não existir
        IS_STAGING=true
        ;;
    *)
        DEPLOY_LOCK="/tmp/clawyard-deploy.lock"
        APP_DIR="/home/forge/clawyard.partyard.eu"
        HEALTH_URL="http://127.0.0.1:8000/health"
        DEPLOY_BRANCH="main"
        OCTANE_SVC="clawyard-octane.service"
        IS_STAGING=false
        ;;
esac

ts() { date +'%H:%M:%S'; }
log() { echo "[$(ts)] [$SITE_NAME] $*"; }
err() { echo "[$(ts)] [$SITE_NAME] ✗ $*" >&2; }

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
# 2026-05-29: branch dinâmico (main para prod, staging para staging.partyard.eu).
log "1/8 git pull (branch $DEPLOY_BRANCH)"
git pull origin "$DEPLOY_BRANCH" 2>&1 | tail -3

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

# ─── 5. Octane RESTART (Bruno fix 2026-05-29 "tem de ser rectificado") ───────
# MUDANÇA CRÍTICA: trocámos `octane:reload` por `systemctl restart`.
#
# PORQUÊ: o octane:reload (SIGUSR1 graceful) FALHA SILENCIOSAMENTE de forma
# recorrente — os workers continuam a executar o código ANTIGO durante horas
# sem qualquer erro visível. Hoje (2026-05-29) isto partiu 3 deploys:
#   - b96873e (timer pulse) — reload não tomou, código velho
#   - 5a690d1 (quantum digest cache) — reload não tomou, workers c/ 2h14m
#     uptime serviam o fetch-inline antigo → "Erro: network error"
#   - dc24152 (css fix) — idem
# Em cada caso, só `systemctl restart` resolveu.
#
# TRADE-OFF: o restart mata workers in-flight (~5s downtime + streams SSE
# activos cortam). MAS:
#   - Deploys são esporádicos (não há users 24/7 a meio de stream)
#   - O frontend já trata "ligação cortada — re-envia" (commit 034898f)
#   - 5s de downtime determinístico >>> horas de código antigo silencioso
#
# A garantia de que o código novo ESTÁ activo vale mais que o graceful drain.
log "5/8 octane RESTART (systemctl — garante código novo nos workers)"
# Fix 2026-06-01: o "| tail -2 || err; reload" mascarava a falha — o exit code
# era do `tail` (sempre 0), por isso o `err` NUNCA disparava e o octane:reload
# corria SEMPRE a seguir (mesmo quando o restart "falhava" por falta de sudo).
# Resultado: dependíamos do reload (o flaky) e nunca soubemos do problema.
# Agora detectamos a sério. Com o sudoers no sítio (NOPASSWD systemctl restart
# clawyard-octane.service) isto faz RESTART REAL garantido; só cai no reload se
# o restart genuinamente falhar.
if sudo -n systemctl restart "$OCTANE_SVC" 2>&1; then
    log "  ✓ systemctl restart OK (código novo garantido nos workers)"
else
    err "systemctl restart FALHOU (falta o sudoers NOPASSWD?) — fallback octane:reload"
    $FORGE_PHP artisan octane:reload 2>&1 || true
fi

# Wait for Swoole master + workers a arrancar (boot ~5-8s).
sleep 10

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
    sudo -n systemctl restart "$OCTANE_SVC" 2>&1 || \
        err "systemctl restart falhou — investigação manual necessária"

    # 5× verificação (era 1×). Octane swoole boot ~5-8s.
    sleep 8
    RESTART_OK=false
    for attempt in 1 2 3 4 5; do
        code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
        if [[ "$code" == "200" ]]; then
            log "  ✓ recovered after hard restart (attempt $attempt)"
            RESTART_OK=true
            break
        fi
        log "  restart attempt $attempt → HTTP $code — retry em 3s"
        sleep 3
    done

    # ─── 7.1 ROLLBACK AUTOMÁTICO (Bruno fix 2026-05-29) ──────────────────────
    # Bruno: "temos de melhorar e muito" depois de 500 pós-deploy.
    # Se nem restart resolve, release novo tem bug fatal — switch symlink
    # para release ANTERIOR e reload. Limita downtime a ~60-90s vs Bruno
    # descobrir + fix manual.
    if [[ "$RESTART_OK" == "false" ]]; then
        err "  ✗ STILL DOWN after restart — INICIAR ROLLBACK AUTOMÁTICO"

        RELEASES_DIR=$(dirname $(realpath "$APP_DIR/current"))
        CURRENT_REL=$(basename $(realpath "$APP_DIR/current"))
        PREV_REL=$(ls -t "$RELEASES_DIR" 2>/dev/null | grep -v "^$CURRENT_REL$" | head -1)

        if [[ -n "$PREV_REL" ]] && [[ -d "$RELEASES_DIR/$PREV_REL" ]]; then
            log "  ↩ rollback: $CURRENT_REL → $PREV_REL"
            ln -sfn "$RELEASES_DIR/$PREV_REL" "$APP_DIR/current.new"
            mv -Tf "$APP_DIR/current.new" "$APP_DIR/current"
            sudo -n systemctl restart "$OCTANE_SVC" 2>&1 | tail -2
            sleep 8

            code=$(curl -s -m 5 -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
            if [[ "$code" == "200" ]]; then
                log "  ✅ ROLLBACK SUCCEDED — release $PREV_REL active"
                exit 3  # special: deploy failed, rollback worked
            else
                err "  ✗ ROLLBACK FAILED — manual intervention required NOW"
                if command -v mail >/dev/null 2>&1; then
                    echo "ClawYard deploy failed + rollback failed at $(date). Site DOWN. Check journalctl -u clawyard-octane.service NOW." | \
                        mail -s "🚨🚨 ClawYard DOWN — deploy + rollback failed" "${ADMIN_EMAIL:-bruno.monteiro@hp-group.org}" 2>/dev/null || true
                fi
                exit 4
            fi
        else
            err "  ✗ no previous release found — cannot rollback. Manual NOW."
            exit 4
        fi
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
