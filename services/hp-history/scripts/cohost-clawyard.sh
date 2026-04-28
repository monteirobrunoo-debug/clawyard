#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# cohost-clawyard.sh — install hp-history alongside Forge-managed sites
# on the EXISTING clawyard droplet, without touching Forge's nginx
# configs or the 5 sites already running on port 80/443.
#
# Differs from deploy-do.sh (the standalone-droplet script) by:
#   • skips apt installs of nginx/certbot/ufw (already there via Forge)
#   • does NOT touch /etc/nginx/sites-enabled/default — Forge owns it
#   • adds its own vhost as /etc/nginx/sites-available/hp-history with
#     a sites-enabled/ symlink, so Forge UI lists it but doesn't manage
#     it — when you re-deploy clawyard via Forge, hp-history is unaffected
#   • Postgres container does NOT publish 5432 to the host (uses the
#     internal docker network) so it can never collide with a Forge-
#     installed Postgres
#   • only the FastAPI app exposes 127.0.0.1:8088 — nginx proxies that
#
# Run as root on the clawyard droplet:
#
#   curl -fsSL https://raw.githubusercontent.com/monteirobrunoo-debug/clawyard/main/services/hp-history/scripts/cohost-clawyard.sh -o /tmp/cohost.sh
#   chmod +x /tmp/cohost.sh
#   DOMAIN=hp-history.partyard.eu EMAIL_FOR_LE=bruno@partyard.eu /tmp/cohost.sh
#
# Pre-flight (do this BEFORE running the script):
#   1. DNS: A hp-history.partyard.eu → <SAME IP as clawyard.partyard.eu>
#      Confirm with: dig +short hp-history.partyard.eu
#   2. Voyage API key ready (revoked the leaked one + made a new one)
#   3. HMAC secret ready: openssl rand -hex 32 (in another shell)
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

DOMAIN="${DOMAIN:-hp-history.partyard.eu}"
EMAIL_FOR_LE="${EMAIL_FOR_LE:-bruno@partyard.eu}"
APP_DIR="${APP_DIR:-/opt/hp-history}"
REPO_URL="${REPO_URL:-https://github.com/monteirobrunoo-debug/clawyard.git}"

color() { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }

[[ "$EUID" -eq 0 ]] || { echo "✗ Run as root."; exit 1; }

section "0/8  Pre-flight"
# Verify DNS — refuse to deploy if hp-history.partyard.eu doesn't resolve
# to the IP we're running on. Otherwise certbot would fail loudly later
# AND we'd have a half-installed stack to clean up.
SERVER_IP="$(curl -fsSL https://api.ipify.org 2>/dev/null || curl -fsSL https://ifconfig.me 2>/dev/null || true)"
DNS_IP="$(dig +short "$DOMAIN" | tail -1 || true)"
if [[ -z "$SERVER_IP" || -z "$DNS_IP" || "$SERVER_IP" != "$DNS_IP" ]]; then
    color "31" "✗ DNS mismatch — $DOMAIN resolves to '$DNS_IP', this server is '$SERVER_IP'."
    color "33" "  Add an A record: $DOMAIN → $SERVER_IP, wait ~2 min, then re-run."
    exit 2
fi
color "32" "DNS OK — $DOMAIN → $SERVER_IP"

# Verify Forge / clawyard still healthy — abort if existing sites are
# broken so the operator deals with that BEFORE we add a 6th tenant.
if ! nginx -t >/dev/null 2>&1; then
    color "31" "✗ existing nginx config is broken (nginx -t failed). Fix that first."
    exit 3
fi
color "32" "Existing nginx config valid ($(ls /etc/nginx/sites-enabled/ | wc -l) sites)"

# Docker is required. Was installed by the failed deploy-do.sh run, but
# verify just in case.
command -v docker >/dev/null 2>&1 || {
    color "31" "✗ docker not found. Install with: apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin"
    exit 4
}
docker compose version >/dev/null 2>&1 || {
    color "31" "✗ docker compose plugin missing. Install: apt install -y docker-compose-plugin"
    exit 4
}
color "32" "Docker $(docker --version | cut -d, -f1) ready"

section "1/8  Repo"
if [[ ! -d "$APP_DIR/.git" ]]; then
    rm -rf "$APP_DIR" 2>/dev/null || true
    git clone "$REPO_URL" "$APP_DIR"
else
    git -C "$APP_DIR" pull --rebase
fi

cd "$APP_DIR/services/hp-history"

section "2/8  .env"
if [[ ! -f .env ]]; then
    read -r -s -p "HMAC secret (must match clawyard's HP_HISTORY_HMAC_SECRET): " HMAC; echo
    read -r -s -p "Voyage API key (the NEW one — old one was leaked): " VOYAGE; echo
    cat > .env <<EOF
HPH_HMAC_SECRET=${HMAC}
HPH_VOYAGE_API_KEY=${VOYAGE}
HPH_EMBEDDING_PROVIDER=voyage
HPH_EMBEDDING_MODEL=voyage-3-large
HPH_EMBEDDING_DIM=1024
EOF
    chmod 600 .env
    color "32" ".env created (chmod 600)"
else
    color "33" ".env already present — leaving as-is. Edit and rerun if needed."
fi

section "3/8  nginx vhost (independent of Forge)"
NGINX_FILE="/etc/nginx/sites-available/hp-history"
if [[ -f "$NGINX_FILE" ]] && grep -q "${DOMAIN}" "$NGINX_FILE"; then
    color "33" "Vhost ${NGINX_FILE} already exists — leaving as-is."
else
    cat > "$NGINX_FILE" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    client_max_body_size 16m;

    # Prometheus scrape — restrict to localhost. Bring up Grafana on
    # the clawyard droplet later and add its IP here if you want
    # remote scraping.
    location /metrics {
        allow 127.0.0.1;
        deny all;
        proxy_pass         http://127.0.0.1:8088;
        proxy_http_version 1.1;
        proxy_set_header   Host \$host;
    }

    location / {
        proxy_pass         http://127.0.0.1:8088;
        proxy_http_version 1.1;
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        proxy_read_timeout 30s;
    }
}
EOF
    ln -sf "$NGINX_FILE" /etc/nginx/sites-enabled/hp-history
    nginx -t
    systemctl reload nginx
    color "32" "Vhost added + nginx reloaded"
fi

section "4/8  Let's Encrypt cert"
if [[ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
    certbot --nginx -d "${DOMAIN}" -m "${EMAIL_FOR_LE}" --agree-tos --no-eff-email --non-interactive
else
    color "33" "Cert for ${DOMAIN} already present"
fi

section "5/8  Firewall (no changes — Forge already owns ufw rules)"
color "33" "Skipping. ufw rules already set up for clawyard. hp-history uses 80/443 which are already open."

section "6/8  Docker compose up"
docker compose pull
docker compose up -d --build

# Wait briefly for the API.
for i in {1..20}; do
    if curl -fsS http://127.0.0.1:8088/healthz >/dev/null 2>&1; then break; fi
    sleep 1
done

section "7/8  Health check (local + public)"
curl -fsS "http://127.0.0.1:8088/healthz" >/dev/null && color "32" "✓ local 127.0.0.1:8088/healthz OK"
curl -fsS "https://${DOMAIN}/healthz" >/dev/null && color "32" "✓ public https://${DOMAIN}/healthz OK"

section "8/8  Done"
echo
echo "hp-history is live at https://${DOMAIN}"
echo
echo "Next: connect clawyard to it. On THIS droplet:"
echo
echo "  sudo -u forge bash -c '"
echo "    cd /home/forge/clawyard.partyard.eu/current"
echo "    cat >> .env <<ENV"
echo "HP_HISTORY_ENABLED=true"
echo "HP_HISTORY_BASE_URL=https://${DOMAIN}"
echo "HP_HISTORY_HMAC_SECRET=<o mesmo HMAC que digitaste no passo 2/8>"
echo "ENV"
echo "    php artisan config:clear && php artisan optimize"
echo "  '"
echo
echo "Then ingest some history (replace /tmp/seed with your real PDF folder):"
echo
echo "  cd $APP_DIR/services/hp-history"
echo "  docker compose run --rm app python -m app.ingest /tmp/seed --domain spares --year 2024"
echo
echo "Marco and Vasco will start citing precedents in chat as soon as the .env is updated."
