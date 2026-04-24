# PartYard LLM Proxy

FastAPI redacting forwarder that sits between ClawYard (Laravel) and
`api.anthropic.com`. Runs on the same Digital Ocean droplet as ClawYard
under the `llm-proxy.partyard.eu` vhost.

## What it does

1. Receives every request the app would have sent straight to Anthropic.
2. Parses the JSON body and runs a **second pass** of the PII redactor
   (port of `app/Support/PiiRedactor.php`) over:
   - `system` (string or list of content blocks)
   - `messages[].content` (string or list of content blocks)
3. Forwards the scrubbed payload to `https://api.anthropic.com` over TLS,
   streaming SSE chunks back to the client unchanged.
4. Logs one JSONL line per call. **Prompt text is never written to disk.**

## Why two passes of redaction?

The Laravel-side pass (`ANTHROPIC_REDACT_PII=true`) is the primary line of
defence. This proxy is the belt-and-suspenders layer that:

- Catches prompts from new agents that forget to wire up `PiiRedactor`.
- Catches prompts if someone flips `ANTHROPIC_REDACT_PII=false` by accident.
- Gives a single tamper-evident audit log for compliance.

## Layout

    /opt/partyard-llm-proxy/
        app.py                       FastAPI app
        redactor.py                  PII rules (mirror of PiiRedactor.php)
        requirements.txt             fastapi, uvicorn, httpx
        .venv/                       Python virtualenv (created by deploy)
        tests/test_redactor.py       Parity tests

    /etc/systemd/system/
        partyard-llm-proxy.service   Locked-down systemd unit

    /etc/nginx/sites-available/
        llm-proxy.partyard.eu        Terminates TLS → proxy_pass :8787

    /etc/logrotate.d/
        partyard-llm-proxy           Daily rotate, 90-day retention

    /var/log/partyard-llm-proxy/
        access.jsonl                 {id, path, status, ms, bytes, redacted}
        redact.jsonl                 {id, counts:{email:2,nif:1,...}}

## Logs

Both files are JSONL. Example access line (prompt body never recorded):

    {"id":"8f3c91abde01","path":"/v1/messages","method":"POST","status":200,
     "ms":1420,"bytes_in":4096,"bytes_out":28374,"redacted":3,
     "model":"claude-sonnet-4-5-20250929","stream":true}

Example redact line:

    {"id":"8f3c91abde01","counts":{"card":0,"iban":0,"nif":1,"email":2,
     "phone":0,"cc":0,"secret":0,"pem":0},"total":3,
     "model":"claude-sonnet-4-5-20250929","stream":true}

Neither file ever contains request bodies, API keys, response text, or
user identifiers beyond the request ID and the Anthropic model name.

## Deploy

Run `bash deploy.sh` from this directory — it rsyncs the app to
`/opt/partyard-llm-proxy/` on the droplet, (re)builds the venv, installs
the systemd unit, updates nginx, and does a rolling reload.

### First-time setup (one-off, needs sudo)

    ssh forge@clawyard.partyard.eu
    sudo mkdir -p /opt/partyard-llm-proxy /var/log/partyard-llm-proxy
    sudo chown www-data:www-data /opt/partyard-llm-proxy /var/log/partyard-llm-proxy
    sudo apt-get install -y python3-venv

Then from your laptop:

    cd llm-proxy && bash deploy.sh

### Rollback

If the proxy misbehaves, point the app back to direct Anthropic:

    # on the droplet
    sudo sed -i 's#^ANTHROPIC_BASE_URL=.*#ANTHROPIC_BASE_URL=https://api.anthropic.com#' \
        /home/forge/clawyard.partyard.eu/current/.env
    php artisan config:cache

No code change needed on the app side.

## Local dev / tests

    cd llm-proxy
    python3 -m venv .venv
    source .venv/bin/activate
    pip install -r requirements.txt
    python -m pytest tests/ -q
    uvicorn app:app --reload --port 8787

Then from another shell:

    curl -s localhost:8787/healthz
