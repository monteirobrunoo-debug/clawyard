# Anthropic egress proxy — Digital Ocean setup

## Why

Every ClawYard agent POSTs to `api.anthropic.com/v1/messages`. By default the
request goes straight from the Laravel box to Anthropic's API, which means
the full prompt (including any PII that survived the `PiiRedactor`) leaves
the company network in one hop with only TLS 1.2+ protection.

Routing traffic through a small Digital Ocean droplet gives us:

1. **Audit** — one place that logs every outbound LLM call (timestamp, agent,
   model, token count, outcome) without mutating the Laravel app.
2. **Policy** — central kill-switch (block model X, stop all outbound for Y
   minutes on incident).
3. **Redaction depth** — a second-layer regex scrub that runs even when a
   developer forgets to turn on `ANTHROPIC_REDACT_PII` in the app.
4. **Cost control** — rate limit + per-agent budget enforcement.
5. **Forensics** — keep a 30/90-day rolling log of upstream calls, sealed
   behind our VPC, so a compliance request ("show me every prompt that
   mentioned client X") is answerable.

## Wire it up

In the ClawYard `.env`:

```
ANTHROPIC_BASE_URL=https://llm-proxy.partyard.eu
ANTHROPIC_REDACT_PII=true
```

Run `php artisan config:clear && php artisan config:cache`. Every agent
(24 of them) reads this via `AnthropicKeyTrait::getAnthropicBaseUri()` at
request time — no restart required. The trait auto-upgrades `http→https`
and rejects unknown schemes, so a typo won't downgrade traffic.

## Minimal proxy (nginx on DO droplet)

The simplest working proxy is a pass-through with logging. Paste into
`/etc/nginx/sites-available/llm-proxy`:

```nginx
# Upstream Anthropic — host header must match or TLS handshake fails.
upstream anthropic_api {
    server api.anthropic.com:443;
    keepalive 32;
}

# Structured access log — one line per upstream call.
log_format llm_jsonl escape=json '{'
    '"ts":"$time_iso8601",'
    '"ip":"$remote_addr",'
    '"method":"$request_method",'
    '"path":"$request_uri",'
    '"status":$status,'
    '"bytes_in":$request_length,'
    '"bytes_out":$bytes_sent,'
    '"upstream_status":"$upstream_status",'
    '"upstream_ms":"$upstream_response_time",'
    '"ua":"$http_user_agent"'
'}';

server {
    listen 443 ssl http2;
    server_name llm-proxy.partyard.eu;

    # Pin the proxy's own cert (Let's Encrypt).
    ssl_certificate     /etc/letsencrypt/live/llm-proxy.partyard.eu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/llm-proxy.partyard.eu/privkey.pem;
    ssl_protocols TLSv1.3 TLSv1.2;

    access_log /var/log/nginx/llm-proxy.jsonl llm_jsonl;
    error_log  /var/log/nginx/llm-proxy.error.log warn;

    # IP allow-list — only ClawYard Forge box (and staging, if used).
    # Replace with the public IP of the web server(s).
    allow 203.0.113.10;   # Forge prod
    allow 203.0.113.11;   # Forge staging
    deny  all;

    # Per-IP burst limit — 60 req/min, 5 simultaneous.
    limit_req_zone $binary_remote_addr zone=llm:10m rate=60r/m;
    limit_conn_zone $binary_remote_addr zone=llmconn:10m;
    limit_req  zone=llm burst=20 nodelay;
    limit_conn llmconn 5;

    # Body cap — reject huge payloads (a stray 100MB PDF blob is almost
    # certainly a bug). Raise if you see legitimate oversize prompts.
    client_max_body_size 25m;

    # Explicitly strip incoming Host / Authorization headers that the proxy
    # itself should not forward verbatim — the app sets x-api-key.
    proxy_set_header Host                api.anthropic.com;
    proxy_set_header X-Forwarded-For     $remote_addr;
    proxy_set_header X-Forwarded-Proto   https;

    # IMPORTANT: must preserve SSE streaming.
    proxy_http_version 1.1;
    proxy_buffering    off;
    proxy_cache        off;
    proxy_request_buffering off;
    proxy_set_header Connection "";
    chunked_transfer_encoding off;

    # Timeouts — long-running Opus calls can stream for minutes.
    proxy_connect_timeout 10s;
    proxy_send_timeout    300s;
    proxy_read_timeout    300s;

    location / {
        proxy_ssl_server_name     on;
        proxy_ssl_name            api.anthropic.com;
        proxy_ssl_protocols       TLSv1.3 TLSv1.2;
        proxy_ssl_verify          on;
        proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;

        proxy_pass https://anthropic_api;
    }
}
```

Test with:

```
curl -v https://llm-proxy.partyard.eu/v1/messages \
    -H "x-api-key: $ANTHROPIC_API_KEY" \
    -H "anthropic-version: 2023-06-01" \
    -H "content-type: application/json" \
    -d '{"model":"claude-haiku-4-6","max_tokens":32,"messages":[{"role":"user","content":"ping"}]}'
```

## Upgrading to a redacting proxy

When `nginx` alone isn't enough (you want the proxy itself to scrub PII
before handing traffic to Anthropic), swap the `proxy_pass` layer for a
lightweight Python / Go process that:

1. Reads the request body.
2. Runs a second pass of `PiiRedactor` (or a stricter profile).
3. Forwards to Anthropic.
4. Streams the SSE response back unchanged.

Recommended stack: FastAPI + `httpx` (SSE-aware) behind the same nginx.
The repo is intentionally kept app-side — proxy code lives in its own
repository (`partyard/llm-proxy`) so its lifecycle is independent.

## Log retention on the droplet

The Laravel side already trims `agent_share_access_logs` via
`php artisan agentshares:cleanup`. On the proxy, rotate the JSONL log via
`/etc/logrotate.d/llm-proxy`:

```
/var/log/nginx/llm-proxy.jsonl {
    daily
    rotate 90
    missingok
    notifempty
    compress
    delaycompress
    sharedscripts
    postrotate
        /usr/bin/killall -HUP nginx
    endscript
}
```

90 days matches the default `AGENT_SHARE_LOG_RETENTION_DAYS`.

## Fallback / disaster recovery

If the proxy is down, the app's CSP `connect-src` directive still allows
direct-to-Anthropic (`api.anthropic.com` is always in the list). Clear
`ANTHROPIC_BASE_URL` in `.env`, run `php artisan config:cache`, and every
agent reverts to direct upstream — no code change needed.
