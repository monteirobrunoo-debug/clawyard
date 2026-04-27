#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy-do.sh — first-time bootstrap for the hp-history.partyard.eu droplet.
#
# Run as root (DigitalOcean web console default). Idempotent — re-running
# only updates what's behind. Steps:
#
#   1. apt update + minimum packages (docker, nginx, certbot, ufw)
#   2. firewall: allow 22/80/443 only
#   3. clone the clawyard repo to /opt/hp-history (uses services/hp-history)
#   4. write /opt/hp-history/.env from prompts (HMAC secret, Voyage key)
#   5. nginx vhost for hp-history.partyard.eu → 127.0.0.1:8088
#   6. Let's Encrypt cert (HTTP-01)
#   7. docker compose up -d (db + app)
#   8. health check: curl /healthz
#
# After this you can run the ingest from your laptop with:
#   ssh root@hp-history.partyard.eu "cd /opt/hp-history/services/hp-history && \
#     docker compose run --rm app python -m app.ingest /data/your-folder \
#     --domain spares --year 2024"
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

REPO_URL="${REPO_URL:-git@github.com:monteirobrunoo-debug/clawyard.git}"
APP_DIR="/opt/hp-history"
DOMAIN="${DOMAIN:-hp-history.partyard.eu}"
EMAIL_FOR_LE="${EMAIL_FOR_LE:-bruno@partyard.eu}"

color() { printf "\033[%sm%s\033[0m\n" "$1" "$2"; }
section() { echo; color "1;36" "── $1 ──"; }

[[ "$EUID" -eq 0 ]] || { echo "Run as root."; exit 1; }

section "1/8  Packages"
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release ufw nginx certbot python3-certbot-nginx git

# Docker — official Docker repo so we get the modern compose plugin.
if ! command -v docker >/dev/null; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

section "2/8  Firewall"
ufw default deny incoming
ufw default allow outgoing
ufw allow 22
ufw allow 80
ufw allow 443
ufw --force enable

section "3/8  Repo"
if [[ ! -d "$APP_DIR" ]]; then
    git clone "$REPO_URL" "$APP_DIR"
else
    git -C "$APP_DIR" pull --rebase
fi

cd "$APP_DIR/services/hp-history"

section "4/8  .env"
if [[ ! -f .env ]]; then
    read -r -s -p "HMAC secret (must match clawyard's HP_HISTORY_HMAC_SECRET): " HMAC; echo
    read -r -s -p "Voyage API key (or empty if you'll use OpenAI): " VOYAGE; echo
    cat > .env <<EOF
HPH_HMAC_SECRET=${HMAC}
HPH_VOYAGE_API_KEY=${VOYAGE}
HPH_EMBEDDING_PROVIDER=voyage
HPH_EMBEDDING_MODEL=voyage-3-large
HPH_EMBEDDING_DIM=1024
EOF
    chmod 600 .env
    color "33" ".env created with restricted perms (600)"
else
    color "32" ".env already exists — leaving as-is. Edit and rerun if needed."
fi

section "5/8  nginx vhost"
NGINX_FILE="/etc/nginx/sites-available/hp-history"
cat > "$NGINX_FILE" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    # Cap body size (search payloads are tiny; /doc serves a few MB).
    client_max_body_size 16m;

    # Prometheus scrape endpoint — restrict to monitoring sources.
    # Update the allow list when you bring up a Grafana/Prom server.
    # Default config: localhost only (curl from the droplet itself).
    location /metrics {
        allow 127.0.0.1;
        # allow <prometheus-server-ip>;
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
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

section "6/8  Let's Encrypt"
if [[ ! -d /etc/letsencrypt/live/${DOMAIN} ]]; then
    certbot --nginx -d "${DOMAIN}" -m "${EMAIL_FOR_LE}" --agree-tos --no-eff-email --non-interactive
else
    color "33" "Cert for ${DOMAIN} already present — skipping certbot."
fi

section "7/8  Docker compose up"
docker compose pull
docker compose up -d --build

# Wait briefly for the API to come up.
for i in {1..20}; do
    if curl -fsS http://127.0.0.1:8088/healthz >/dev/null 2>&1; then break; fi
    sleep 1
done

section "8/8  Health check"
curl -fsS "https://${DOMAIN}/healthz" || {
    color "31" "✗ /healthz did not return OK."
    exit 1
}
color "32" "✓ hp-history live at https://${DOMAIN}/healthz"
echo
echo "Next: from clawyard, set"
echo "    HP_HISTORY_ENABLED=true"
echo "    HP_HISTORY_BASE_URL=https://${DOMAIN}"
echo "    HP_HISTORY_HMAC_SECRET=<same secret you typed above>"
echo "and redeploy. Marco and Vasco will start consulting the archive."
