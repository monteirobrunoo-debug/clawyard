# DO Infrastructure Setup — Production hardening

Status as of 2026-05-19. Reference for activating each piece of infrastructure
recommended in the strategic analysis.

## ✅ Já activo

- **Redis cache + queue** (CACHE_STORE=redis, QUEUE_CONNECTION=redis)
- **Queue worker via scheduler** (`Schedule::command('queue:work --stop-when-empty')` em `routes/console.php`)
- **Filesystem mirror para agent shares** em `storage/app/private/agent-shares/`
- **QNAP mirror service code-ready** (precisa montar `/var/www/qnapbackup` antes de activar)

## ⏳ Pronto a activar — só precisa de credenciais

### 1) DO Spaces (anexos + biblioteca técnica)

**Custo:** $5/mês — 250 GB storage + 1 TB egress, $0.02/GB depois.

**Passos no DO Panel:**

1. Spaces → Create Spaces Bucket
   - Region: `fra1` (Frankfurt) ou `ams3` (Amsterdam) — perto da Europa
   - Name: `clawyard-prod`
   - File listing: Private (default)
   - CDN: opt-out por agora (podemos activar mais tarde se servirmos imagens)
2. API → Spaces Keys → Generate New Key
   - Name: `clawyard-prod`
   - Guarda **Access Key** + **Secret**

**No Forge UI → Site → Environment, adicionar:**

```env
DO_SPACES_KEY=DO00xxxxxxxxxxxx
DO_SPACES_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
DO_SPACES_REGION=fra1
DO_SPACES_BUCKET=clawyard-prod
DO_SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com
```

**Backfill dos anexos existentes** (~50 GB) via SSH:

```bash
# Dry-run primeiro (mostra plano sem copiar)
php artisan tenders:migrate-attachments-to-spaces --dry-run

# Copy real (mantém local intacto, idempotente)
php artisan tenders:migrate-attachments-to-spaces --batch=200

# Após verificar no DO Spaces que está tudo lá, libertar disco do droplet:
php artisan tenders:migrate-attachments-to-spaces --delete-local
```

**Switch para usar Spaces como default** (último passo):

```env
FILESYSTEM_DISK=spaces
```

### 2) DO Managed Postgres (BD isolada do app)

**Custo:** $15/mês — Single node 1 GB RAM 10 GB disk (sobra para os ~21 GB actuais; upgrade fácil).

**Passos no DO Panel:**

1. Databases → Create Database Cluster
   - Engine: PostgreSQL 16
   - Region: mesma do droplet (`fra1`)
   - Plan: Basic — 1 vCPU / 1 GB / 10 GB
   - Name: `clawyard-pg-prod`
2. Settings → Trusted Sources → Add `clawyard.partyard.eu` droplet (IP do app)
3. Connection Details → guarda host/port/user/password/dbname

**Migration plan (downtime ~5 min):**

```bash
# 1. No droplet (forge user):
cd /home/forge/clawyard.partyard.eu/current
php artisan down --secret="emergency-deploy-token-2026"  # maintenance mode

# 2. Backup integral (current → tmp file)
PGPASSWORD="ZjUtgdOOb62w60nIn2Uk" pg_dump -U forge -h 127.0.0.1 -d forge \
  -F c -f /tmp/clawyard-pre-migrate.dump

# 3. Restore para o cluster managed (substituir host/user/pw pelos do panel)
PGPASSWORD="<DO_DB_PW>" pg_restore -h <DO_DB_HOST> -p <DO_DB_PORT> \
  -U <DO_DB_USER> -d <DO_DB_NAME> --no-owner --no-acl \
  /tmp/clawyard-pre-migrate.dump

# 4. Trocar .env DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE
nano .env

# 5. Validar conexão
php artisan tinker --execute='echo DB::connection()->getDatabaseName().PHP_EOL;'

# 6. Re-cache config + restart php-fpm (via Forge UI)
php artisan config:cache
# Forge → Site → Restart PHP

# 7. Levantar maintenance
php artisan up
```

**Rollback se algo correr mal:** reverter `.env` para 127.0.0.1, `php artisan config:cache`, `php artisan up`. BD original intacta porque só fizemos `pg_dump` (não destrutivo).

### 3) QNAP mount no droplet

Para o `TenderQnapMirror` (já implementado) funcionar, precisas de montar o NAS no droplet:

```bash
# No droplet, como root (precisa sudo do user dono — não o forge):
apt install -y cifs-utils

mkdir -p /var/www/qnapbackup
echo "//<QNAP_IP>/<share>  /var/www/qnapbackup  cifs  username=<u>,password=<p>,uid=forge,gid=forge,ro=no,vers=3.0,iocharset=utf8  0  0" >> /etc/fstab
mount -a

# Verificar
ls /var/www/qnapbackup
chown -R forge:forge /var/www/qnapbackup   # se necessário
```

Depois, no `.env` do Forge:
```env
QNAP_MIRROR_ENABLED=true
```

## 💰 Custo total estimado

| Item | Custo /mês |
|---|---|
| Droplet 4 GB actual | $24 |
| llm-proxy droplet | $5 |
| DO Spaces 250 GB | $5 |
| DO Managed Postgres Basic | $15 |
| **Total** | **$49/mês** |

vs. **$29 actuais** = +$20/mês para uma stack significativamente mais robusta
(BD isolada, anexos com backup, libertar 50 GB no droplet).

## 🔐 Credenciais que preciso para autonomamente continuar

Diz-me qual destas opções:

A. **Cria o Space + a BD no Panel DO** e dá-me as creds (key, secret, host, password) — eu meto tudo no `.env` e corro a migração.

B. **Dá-me um DO API token** (Personal Access Token com scope `spaces.read`, `spaces.write`, `databases.read`, `databases.write`) — eu provisiono tudo via `doctl` no droplet.

C. **Fazes tu tudo via panel** — eu mantenho-me available para resolver qualquer pormenor que apareça.
