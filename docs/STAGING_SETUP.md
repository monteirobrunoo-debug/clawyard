# Staging environment setup — `staging.partyard.eu`

**Pedido directo Bruno 2026-05-29:** *"temos de melhorar e muito"* → blue/green deployment.

## Objectivo

Ambiente de pré-produção que recebe deploys ANTES de produção. Cada change passa por staging primeiro, smoke test automático, e só depois promove para `clawyard.partyard.eu`. Zero risco de partir users live.

```
git push origin staging  →  staging.partyard.eu deploy + smoke test
                                ↓ if OK
                            (manual) git checkout main && git merge staging && git push
                                ↓
                            clawyard.partyard.eu deploy auto (Forge)
```

## Arquitectura

| Componente | Production | Staging |
|---|---|---|
| Domain | clawyard.partyard.eu | staging.partyard.eu |
| Droplet | mesmo (porta 8000 Octane) | mesmo (porta 8001 Octane) |
| DB | clawyard-pg-prod ($15) | clawyard-pg-staging ($15 **novo**) |
| Redis | mesmo cluster, DB 0 | mesmo cluster, DB 1 |
| DO Spaces | clawyard-prod | mesmo (read-only em staging) |
| Branch git | `main` | `staging` |
| Forge deploy | auto on push | auto on push |
| SSL | Let's Encrypt | Let's Encrypt |

**Custos:** +$15/mês (Postgres staging). Tudo o resto reuse infra existente.

## Checklist setup (1× — Bruno via UI)

### 1. DigitalOcean — Postgres staging
- [ ] Login [DO Console](https://cloud.digitalocean.com/databases)
- [ ] Create Database Cluster → PostgreSQL 16 → fra1 (mesmo region do droplet) → Basic Single Node $15/mês
- [ ] Name: `clawyard-pg-staging`
- [ ] Após provisão (~5min): Settings → Trusted Sources → adicionar IP do droplet
- [ ] Guardar connection string (vai parar à .env staging)

### 2. DNS — staging.partyard.eu
- [ ] DNS provider do partyard.eu (Cloudflare?): adicionar A record:
  - Name: `staging`
  - Value: IP do droplet ClawYard (mesmo IP do prod)
  - Proxied: ON (Cloudflare orange cloud) — mesma protecção do prod
- [ ] Esperar propagação (~1min) — `dig staging.partyard.eu` deve resolver

### 3. Forge — novo site
- [ ] Forge UI → Servers → ClawYard droplet → New Site
- [ ] Root domain: `staging.partyard.eu`
- [ ] Project Type: PHP/Laravel
- [ ] Web Directory: `/public`
- [ ] Create
- [ ] Site dashboard → Git Repository:
  - Provider: GitHub
  - Repository: `monteirobrunoo-debug/clawyard`
  - Branch: **`staging`** ← importante, NÃO main
  - Install Composer Dependencies: ON
- [ ] Site → SSL → Let's Encrypt → Activate (auto)
- [ ] Site → Daemons → New Daemon:
  - Command: `php8.4 artisan octane:start --server=swoole --host=127.0.0.1 --port=8001 --workers=2 --task-workers=1 --max-requests=500`
  - User: `forge`
  - Directory: `/home/forge/staging.partyard.eu/current`
  - **Porta 8001** (não 8000 que é prod)

### 4. Forge — nginx config para staging

Site → Files → Edit Nginx Configuration → adicionar (ou substituir o upstream):

```nginx
# Default location bloco (já existe — só verifica que aponta para 8001):
location / {
    proxy_pass http://127.0.0.1:8001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection 'upgrade';
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_cache_bypass $http_upgrade;
}
```

Save → Forge faz `nginx reload` automaticamente.

### 5. Branch `staging` no GitHub

No teu Mac:
```bash
cd ~/Desktop/clawyard
git checkout -b staging
git push -u origin staging
```

### 6. `.env` staging (via Forge UI)

Site → Environment → editar:

```
APP_NAME="ClawYard Staging"
APP_ENV=staging
APP_DEBUG=true                      # mais verbose em staging
APP_URL=https://staging.partyard.eu

# DB — novo Postgres staging
DB_CONNECTION=pgsql
DB_HOST=<clawyard-pg-staging host>
DB_PORT=25060
DB_DATABASE=defaultdb
DB_USERNAME=doadmin
DB_PASSWORD=<password DO>
DB_SSLMODE=require

# Redis — mesma instância, DB 1 (prod usa 0)
REDIS_CLIENT=phpredis
REDIS_HOST=<redis host>
REDIS_DB=1
CACHE_STORE=redis

# Anthropic — mesmo API key (não há "staging Anthropic")
ANTHROPIC_API_KEY=<mesmo>
ANTHROPIC_MODEL_OPUS=claude-opus-4-5
ANTHROPIC_MODEL_HAIKU=claude-haiku-4-5-20251001

# Cap de budget MAIS BAIXO em staging para não queimar dinheiro em testes
USER_DAILY_BUDGET_EUR=2.0

# SAP — desligar em staging (não queremos POST a SAP de testes)
SAP_ENABLED=false

# Email — direcionar para mailtrap.io / log driver (não enviar real)
MAIL_MAILER=log
```

### 7. Primeiro deploy

Forge UI → site staging → Deploy Now (manual primeira vez)

OU no teu Mac:
```bash
git checkout staging
git push origin staging
```

Forge detecta + corre deploy.sh.

### 8. Migrate DB staging

SSH ao droplet:
```bash
ssh root@droplet
cd /home/forge/staging.partyard.eu/current
sudo -u forge php8.4 artisan migrate --force
sudo -u forge php8.4 artisan db:seed --force  # opcional
```

### 9. Confirmar funciona

```bash
curl -sS https://staging.partyard.eu/health
# Esperado: {"ok":true,"checks":{"db":"ok","redis":"ok"},"ts":"...","version":"unknown"}

# Abre no browser
open https://staging.partyard.eu
# Login com utilizador existente (DB staging vazia — precisa criar user de teste)
```

## Workflow git daily

### Fluxo normal (sem urgency)

```bash
# 1. Trabalhar em feature branch
git checkout -b feature/new-thing
# ... commits ...

# 2. PR para staging primeiro (não main)
git push origin feature/new-thing
# GitHub: PR feature/new-thing → staging
# CI roda (lint + tests). Se green, merge

# 3. Validar em staging.partyard.eu
# Abrir https://staging.partyard.eu, testar manualmente

# 4. Promote para prod
git checkout main
git merge staging  # ou cherry-pick commits específicos
git push origin main
# Forge faz deploy auto para prod
```

### Hotfix urgente (skip staging)

Apenas em emergência (site down, bug crítico):
```bash
git checkout main
git commit -m "hotfix: ..."
git push origin main
# Bypass staging — risco assumido
```

## Próximos passos (futuros)

- **Auto-promote**: GitHub Action que faz `git merge staging → main` se smoke test em staging passar 100%
- **Snapshot DB prod → staging** semanal (Sunday 03:00): dados realistas em staging sem expor PII (usar pg_anonymizer)
- **Visual regression tests** via Percy.io ou Chromatic
- **Load testing** em staging antes de major changes

## Troubleshooting

### Staging não consegue ligar à DB
- Verifica DO Trusted Sources inclui IP do droplet
- Verifica `DB_SSLMODE=require` (DO managed obriga TLS)

### Octane staging não arranca
- Porta 8001 livre? `lsof -i:8001`
- Daemon Forge a correr? `sudo supervisorctl status` (deve listar `staging`)
- Logs: `tail -f /home/forge/.forge/staging-octane.log`

### Deploy falha "branch staging não existe"
- Confirma push: `git push -u origin staging` foi feito 1× no setup
- Forge UI → Git Repository → Branch deve ser `staging`

### Promote staging → main falha
- Conflict em merge? `git status` no Mac, resolve, commit, push
- CI fails? Workflow ci.yml deve passar em main também
