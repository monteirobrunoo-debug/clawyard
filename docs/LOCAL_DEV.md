# Dev local com Docker — `clawyard`

**Pedido Bruno 2026-05-31:** ambiente local para testar mudanças ANTES de push,
sem custo mensal nem setup de staging/DNS. Resolve a causa raiz dos erros desta
semana (eu testava em tinker mas os workers Octane corriam código antigo —
aqui o ambiente é réplica de prod e consistente).

## Setup (1× — ~10min, maioria é o build)

### 1. Instalar Docker Desktop
- Mac: https://www.docker.com/products/docker-desktop/ → instalar → abrir
- Confirmar: `docker --version` no terminal

### 2. Arrancar o ambiente
```bash
cd ~/Desktop/clawyard
docker compose up -d --build
```
A 1ª build demora ~3-5min (compila OpenSwoole + extensões). Depois é instantâneo.

### 3. Confirmar que está vivo
```bash
docker compose logs -f app
# Espera por: "── Pronto. Octane em http://localhost:8000 ──"

curl http://localhost:8000/health
# {"ok":true,"checks":{"db":"ok","redis":"ok"},...}
```

Abre http://localhost:8000 no browser.

### 4. (Opcional) Testar agentes Anthropic
Edita `.env.docker`, mete a tua `ANTHROPIC_API_KEY=sk-ant-...`, e:
```bash
docker compose restart app
```
Sem key, a app corre mas os agentes LLM não respondem (resto funciona).

## Uso diário

| Acção | Comando |
|---|---|
| Arrancar | `docker compose up -d` |
| Ver logs | `docker compose logs -f app` |
| Shell no container | `docker compose exec app bash` |
| Tinker | `docker compose exec app php artisan tinker` |
| Migrate | `docker compose exec app php artisan migrate` |
| Unit tests | `docker compose exec app vendor/bin/phpunit --testsuite=Unit` |
| Lint um ficheiro | `docker compose exec app php -l app/Agents/X.php` |
| Parar | `docker compose down` (dados persistem) |
| Reset DB | `docker compose down -v` (apaga volumes!) |

## Workflow de mudança segura (a partir de agora)

```
1. Eu edito o código no Mac (~/Desktop/clawyard)
2. As mudanças reflectem no container (bind-mount) — sem rebuild
3. EU testo no container ANTES de push:
     docker compose exec app php artisan tinker  (testar lógica)
     docker compose exec app php -l <ficheiro>    (syntax)
     curl localhost:8000/...                       (HTTP real)
4. Só DEPOIS de passar local → git push → produção
```

Isto é o que faltava esta semana: um sítio para apanhar os bugs runtime
(Octane-specific, streaming, etc) antes de chegarem aos users.

## Troubleshooting

### Build falha no OpenSwoole
Não é crítico — o entrypoint cai automaticamente para `php artisan serve`
(testa 95% dos casos). Para forçar serve, ignora o aviso.

### Porta 8000 ocupada
Muda no `docker-compose.yml`: `"8001:8000"` e acede :8001.

### "Connection refused" Postgres
O healthcheck garante ordem, mas se persistir:
```bash
docker compose down && docker compose up -d
```

### Mudei .env.docker mas não pegou
```bash
docker compose restart app
```

### Rebuild total (após mudar Dockerfile)
```bash
docker compose down && docker compose up -d --build
```

## O que NÃO está no dev local (de propósito)
- SAP B1 (SAP_ENABLED=false) — não tocar SAP de testes
- Email real (MAIL_MAILER=log) — emails vão para os logs
- QNAP mirror (desligado)
- DO Spaces (usa filesystem local)
- Auto-análise multi-agente (desligada)

Estes ficam isolados para o dev local nunca afectar sistemas reais.
