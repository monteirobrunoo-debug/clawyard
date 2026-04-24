#!/usr/bin/env bash
# PartYard LLM Proxy — idempotent deployment to the DO droplet.
#
# Designed for the Forge-managed server where the `forge` user has
# passwordless sudo ONLY for: nginx reload, supervisorctl reload, php-fpm.
# We therefore avoid all sudo-requiring paths (/opt, /etc/systemd) and
# stick to /home/forge/llm-proxy + Forge Daemons (supervisor).
#
# What it does, in order:
#   1. rsync app source to /home/forge/llm-proxy/ (no sudo needed)
#   2. (re)build the Python venv and install requirements
#   3. restart the supervisor daemon via `sudo supervisorctl` (NOPASSWD)
#   4. hit /healthz end-to-end and fail loudly if the rollout broke anything
#
# Not done by this script (one-off via Forge dashboard):
#   - Add the Forge Daemon (supervisor program) — see supervisor/partyard-llm-proxy.conf
#   - Update the llm-proxy.partyard.eu nginx vhost to proxy_pass 127.0.0.1:8787
#     — see nginx/llm-proxy.partyard.eu.conf (paste into Forge Sites → Files → Edit Nginx Config)
#
# Safe to re-run — every step is a no-op if already in the desired state.

set -euo pipefail

HOST="${PROXY_HOST:-forge@clawyard.partyard.eu}"
REMOTE_APP="/home/forge/llm-proxy"
REMOTE_LOGS="/home/forge/llm-proxy/logs"

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "▸ [1/4] rsyncing app → ${HOST}:${REMOTE_APP}"
ssh "${HOST}" "mkdir -p ${REMOTE_APP} ${REMOTE_LOGS}"
rsync -az --delete \
    --exclude '.venv' \
    --exclude 'logs' \
    --exclude '__pycache__' \
    --exclude '*.pyc' \
    --exclude 'tests/__pycache__' \
    --exclude '.pytest_cache' \
    "${SRC_DIR}/app.py" \
    "${SRC_DIR}/redactor.py" \
    "${SRC_DIR}/requirements.txt" \
    "${SRC_DIR}/tests" \
    "${SRC_DIR}/nginx" \
    "${SRC_DIR}/supervisor" \
    "${HOST}:${REMOTE_APP}/"

echo "▸ [2/4] (re)building venv + installing requirements"
ssh "${HOST}" bash -se <<'REMOTE_VENV'
set -euo pipefail
cd /home/forge/llm-proxy
if [[ ! -d .venv ]]; then
    python3 -m venv .venv
fi
.venv/bin/pip install --disable-pip-version-check -q -U pip
.venv/bin/pip install --disable-pip-version-check -q -r requirements.txt
echo "✓ venv ready: $(.venv/bin/python --version)"
REMOTE_VENV

echo "▸ [3/4] running self-tests on the droplet"
ssh "${HOST}" bash -se <<'REMOTE_TEST'
set -euo pipefail
cd /home/forge/llm-proxy
.venv/bin/pip install --disable-pip-version-check -q pytest
.venv/bin/python -m pytest tests/ -q
REMOTE_TEST

echo "▸ [4/4] reloading supervisor daemon (if registered)"
ssh "${HOST}" bash -se <<'REMOTE_RELOAD'
set -euo pipefail
if sudo -n supervisorctl status partyard-llm-proxy 2>/dev/null | grep -q RUNNING; then
    sudo supervisorctl restart partyard-llm-proxy
    sleep 2
    echo '— local /healthz —'
    curl -fsS http://127.0.0.1:8787/healthz && echo
    echo '— via nginx —'
    if curl -fsS https://llm-proxy.partyard.eu/healthz --max-time 5 2>/dev/null; then
        echo
        echo '✓ end-to-end OK'
    else
        echo 'nginx is still on passthrough mode — update /etc/nginx/sites-available/llm-proxy.partyard.eu via Forge dashboard to point to 127.0.0.1:8787'
    fi
else
    echo 'Supervisor daemon `partyard-llm-proxy` not found yet.'
    echo 'Create it via Forge dashboard → Server → Daemons → New Daemon:'
    echo '  Command:   /home/forge/llm-proxy/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8787 --workers 2 --timeout-keep-alive 75 --log-level warning --no-access-log --proxy-headers --forwarded-allow-ips=127.0.0.1'
    echo '  User:      forge'
    echo '  Directory: /home/forge/llm-proxy'
fi
REMOTE_RELOAD

echo
echo "✓ Code deployed. If this is the first run, finish in Forge dashboard:"
echo "    1. Server → Daemons → New Daemon (see supervisor/partyard-llm-proxy.conf)"
echo "    2. Sites → llm-proxy.partyard.eu → Files → Edit Nginx Configuration"
echo "       (paste nginx/llm-proxy.partyard.eu.conf)"
echo
echo "Tail redaction log:"
echo "    ssh ${HOST} 'tail -f /home/forge/llm-proxy/logs/redact.jsonl'"
