# Separating the redactor onto its own VM

## Why

Today the Laravel app and the FastAPI redactor share a single Digital
Ocean droplet. The redactor runs on `127.0.0.1:8787`, bound to loopback
so no external traffic hits it directly, and the only public path into
it is the `llm-proxy.partyard.eu` vhost (itself proxying to loopback).

That co-tenancy is the weakest claim in the security story. A
remote-code-execution in the Laravel tier gets the attacker root on the
same box that holds the redactor's code, the audit logs, and the
Anthropic API key. The redactor's value as an independent audit layer
collapses the moment it shares a process tree with the thing it is
auditing.

Moving the redactor to its own Digital Ocean droplet buys four things:

1. **Audit integrity** — a compromise of the app tier does not
   retroactively rewrite `redact.jsonl` or `access.jsonl`.
2. **Key isolation** — `ANTHROPIC_API_KEY` lives only on the proxy VM.
   The app VM holds a much narrower secret (HMAC shared key) that
   cannot be used to reach Anthropic directly.
3. **Policy surface** — the proxy VM's firewall whitelists a single
   inbound source (the app VM's private IP) and a single outbound
   destination (`api.anthropic.com`). Enumerable and auditable.
4. **Blast radius** — scaling the app tier horizontally does not
   multiply the number of boxes that can talk to Anthropic.

## Target topology

```
                     public internet
                            │
          ┌─────────────────┴──────────────────┐
          │                                    │
          ▼                                    ▼
  ┌───────────────┐                     ┌───────────────┐
  │ clawyard-app  │   DO private net    │ clawyard-prox │
  │ (Laravel)     │ ──── HMAC ────────► │ (FastAPI)     │
  │               │    443/mTLS          │               │
  │ 10.x.x.A      │                     │ 10.x.x.B       │
  └───────────────┘                     └──────┬────────┘
                                               │
                                               ▼ TLS 1.3
                                       api.anthropic.com
```

- `clawyard-app` → existing droplet. Loses the FastAPI process, keeps
  Laravel.
- `clawyard-prox` → new droplet, `s-1vcpu-1gb` is sufficient
  (~2 € / month). Runs FastAPI on port 443 behind nginx, reachable only
  from the app droplet's private IP.

## Authentication between tiers

The app VM authenticates to the proxy VM with **HMAC-SHA256 over the
request body + a 5-second-window timestamp**. No bearer tokens in
headers that a log scraper might accidentally persist.

Headers the app sends:

```
X-PY-Timestamp: 1719834000
X-PY-Signature: hex(hmac_sha256(PY_PROXY_SHARED_KEY, timestamp + "\n" + body))
```

The proxy rejects if:
- `|now - timestamp| > 5 s` (replay window)
- signature mismatch
- signature missing

The shared key is 32 random bytes, base64-encoded, stored on both boxes
as `PY_PROXY_SHARED_KEY`. Rotation: generate a new key, set
`PY_PROXY_SHARED_KEY_NEXT` on both boxes, app starts sending both
signatures in `X-PY-Signature-Next`, proxy accepts either during the
cutover window, then promote `NEXT` to primary.

Optional upgrade path (sprint 2): replace HMAC with mTLS — the proxy
trusts only client certs signed by the PartYard internal CA. Same
topology, stronger proof of identity.

## Provisioning runbook

All steps are idempotent. Estimated time: **15 min** end-to-end.

### 1. Create the new droplet in Forge

- Dashboard → Servers → Create Server
- Provider: Digital Ocean
- Region: FRA1 (same as app — private networking requires same region)
- Size: `s-1vcpu-1gb` (1 vCPU, 1 GB RAM, 25 GB SSD, ~$6/month)
- PHP version: doesn't matter (we won't use PHP) — pick the default
- Name: `clawyard-prox`
- Enable: VPC / private networking on the same VPC as `clawyard-app`

Forge will email when ready (~3 min).

### 2. Note the private IP

- On the new server's Forge page, copy `Private IP` (looks like
  `10.114.0.X`).
- On `clawyard-app`'s Forge page, confirm the same-VPC private IP.

### 3. Firewall — proxy VM (clawyard-prox)

Digital Ocean cloud firewall, not ufw. Create a new firewall
`clawyard-prox-fw` and attach it to the new droplet.

Inbound:
- SSH (22) from `<your home IP>/32` — personal admin only
- HTTPS (443) from `clawyard-app`'s private IP (`10.114.0.A/32`) only

Outbound:
- HTTPS (443) to `0.0.0.0/0` (needed for `api.anthropic.com`; it's
  behind Fastly so we can't pin an IP)
- DNS (53 tcp+udp) to `0.0.0.0/0`

**No port 80 inbound.** The proxy serves nothing over plain HTTP.

### 4. Firewall — app VM (clawyard-app)

Keep existing. Add outbound rule: HTTPS (443) to `clawyard-prox`
private IP.

### 5. Deploy the proxy to the new droplet

On the local dev machine:

```bash
cd llm-proxy
PROXY_HOST=forge@<private-ip-of-clawyard-prox> ./deploy.sh
```

`deploy.sh` already accepts `PROXY_HOST` as an env override (no code
change required). The rsync + venv + tests cycle runs unchanged.

### 6. Generate the shared HMAC key

On either box (doesn't matter which):

```bash
openssl rand -base64 32
```

Copy the output. Add to both boxes' `.env`:

- `clawyard-app`: `PY_PROXY_SHARED_KEY=<base64>`
- `clawyard-prox`: `PY_PROXY_SHARED_KEY=<base64>`

Restart the services on both sides:

```bash
# on app
ssh forge@clawyard "cd /home/forge/default && php artisan config:cache && sudo service php8.3-fpm reload"

# on proxy
ssh forge@clawyard-prox "sudo supervisorctl restart partyard-llm-proxy"
```

### 7. Nginx on the proxy VM

Paste the hardened vhost from
`llm-proxy/nginx/llm-proxy-internal.conf`
(see next section) into
`/etc/nginx/sites-available/llm-proxy-internal` (root, same pattern as
before):

```bash
ssh root@clawyard-prox
cp /home/forge/llm-proxy/nginx/llm-proxy-internal.conf \
   /etc/nginx/sites-available/llm-proxy-internal
ln -s /etc/nginx/sites-available/llm-proxy-internal \
   /etc/nginx/sites-enabled/llm-proxy-internal
nginx -t && service nginx reload
```

The hardened vhost listens on port 443 of the **private IP only**,
requires a valid HMAC signature, and logs rejects to a separate file
for security review.

### 8. Point the app at the new proxy

On `clawyard-app`:

```
ANTHROPIC_BASE_URL=https://10.114.0.B   # proxy private IP
```

If the proxy uses a self-signed TLS cert (see next section), also set:

```
ANTHROPIC_PROXY_CA_BUNDLE=/home/forge/llm-proxy-ca.pem
```

(the app-side HTTP client reads this env var in `AnthropicKeyTrait`).

### 9. Cutover

```bash
# on app
php artisan config:cache
sudo service php8.3-fpm reload

# verify
php artisan tinker
>>> \App\Agents\Support\DanielEmail::quickPing()
```

Watch `redact.jsonl` on the **proxy VM** — you should see the new call.
Watch `/var/log/nginx/llm-proxy.access.log` on the **app VM** — the old
public path should go dark for internal calls (only external probes
hit it now).

### 10. Decommission the loopback redactor on the app VM

Once the new proxy has handled 24 h of traffic without error:

```bash
ssh forge@clawyard
sudo supervisorctl stop partyard-llm-proxy
# remove the @reboot and watchdog lines from crontab
crontab -e
# commit the removal of /home/forge/llm-proxy from the app VM
rm -rf /home/forge/llm-proxy
```

The `llm-proxy.partyard.eu` vhost on the app VM can be repurposed as a
**customer-facing** proxy (e.g. for partner integrations that want to
call Anthropic through our redactor) or removed entirely.

## Self-signed TLS between tiers

Public CAs don't issue certs for private IPs. Options:

1. **Self-signed with pinned CA** (recommended). On the proxy VM:

   ```bash
   openssl req -x509 -nodes -newkey rsa:4096 \
     -keyout /etc/ssl/private/llm-proxy-internal.key \
     -out   /etc/ssl/certs/llm-proxy-internal.crt \
     -days 825 \
     -subj "/CN=clawyard-prox.internal" \
     -addext "subjectAltName=IP:10.114.0.B"
   ```

   Copy `llm-proxy-internal.crt` to the app VM as
   `/home/forge/llm-proxy-ca.pem` and reference it in
   `ANTHROPIC_PROXY_CA_BUNDLE`. Rotate every 825 days.

2. **Let's Encrypt DNS-01** if you give the proxy a private-DNS name
   (`llm-proxy.internal.partyard.eu` pointing at the private IP).
   Cleaner but requires DNS-01 plumbing.

3. **mTLS** — issue client certs to the app VM from a simple internal
   CA (`mkcert` works). Strongest identity proof, slightly more setup.

Start with (1); upgrade to (3) if a customer requires it.

## Verification & monitoring

After cutover, these one-liners should pass on every release:

```bash
# from app VM: HMAC-signed healthz through the private path
php artisan tinker --execute='
    dump(app(\App\Support\LlmProxyClient::class)->healthz());
'
# expected: {"status":"ok","upstream":"https://api.anthropic.com"}

# from anywhere EXCEPT app VM: direct call must be rejected
curl -k https://10.114.0.B/v1/messages -X POST -d '{}'
# expected: 401 Unauthorized (no signature) — or 403 if blocked at
# DO firewall layer before nginx even sees it

# on proxy VM: audit files appendable & readable
tail -1 /home/forge/llm-proxy/logs/redact.jsonl   # counts only, no prompt
tail -1 /home/forge/llm-proxy/logs/access.jsonl   # metadata only
```

Add Forge's built-in health check pointing at the HMAC-signed healthz —
any failure pages you through the Forge notification channel.

## Cost

- New droplet: `s-1vcpu-1gb` = **~6 € / month**.
- Bandwidth: outbound-to-Anthropic volume is unchanged (same total
  prompt bytes); the only new traffic is app↔proxy over the DO private
  network, which is **free** within the same region's VPC.
- Labour: ~15 min to provision once, then regular deploys via
  `deploy.sh` are no slower than before.

## Rollback plan

If anything misbehaves during cutover:

```bash
# 1. Revert app .env
ANTHROPIC_BASE_URL=http://127.0.0.1:8787  # back to loopback on app VM

# 2. Re-enable the loopback supervisor/cron watchdog on app VM
ssh forge@clawyard
cp /home/forge/backup/crontab.pre-split ~/crontab-restore
crontab ~/crontab-restore

# 3. Clear config cache
php artisan config:cache && sudo service php8.3-fpm reload
```

The old path is fully reversible because we don't touch the codebase —
only config.
