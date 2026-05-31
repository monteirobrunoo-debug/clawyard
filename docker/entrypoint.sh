#!/bin/bash
# ClawYard dev entrypoint — setup automático na 1ª vez, depois arranca Octane.
set -e

cd /var/www/html

echo "── ClawYard dev container a arrancar ──"

# 1. composer install (vendor está em volume anónimo, não no bind do Mac).
if [ ! -f vendor/autoload.php ]; then
    echo "  composer install (1ª vez, ~1-2min)…"
    composer install --no-interaction --prefer-dist
fi

# 2. .env — SEMPRE sincronizar com .env.docker em dev (idempotente).
#    Antes só copiava se .env não existisse, mas um .env stale sem APP_KEY
#    causava MissingAppKeyException. Em dev, .env.docker é a fonte de verdade.
cp .env.docker .env

# 3. APP_KEY — o .env.docker já tem uma fixa. Se por algum motivo faltar,
#    gerar (belt-and-suspenders).
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "  APP_KEY em falta — a gerar…"
    php artisan key:generate --force
fi

# 4. Esperar Postgres (o healthcheck do compose já garante, mas double-check).
echo "  a aguardar Postgres…"
until php -r "new PDO('pgsql:host=pgsql;port=5432;dbname=clawyard', 'clawyard', 'clawyard_local');" 2>/dev/null; do
    sleep 1
done

# 5. Migrate (dev DB).
echo "  migrate…"
php artisan migrate --force || echo "  ⚠ migrate teve avisos (continua)"

# 6. Clear caches dev (sem config:cache em dev — queremos .env live).
php artisan config:clear  >/dev/null 2>&1 || true
php artisan route:clear   >/dev/null 2>&1 || true
php artisan view:clear    >/dev/null 2>&1 || true

echo "── Pronto. Octane em http://localhost:8000 ──"

# 7. Arrancar Octane (OpenSwoole). Se openswoole não instalou, fallback serve.
if php -m | grep -qi openswoole; then
    exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --workers=2
else
    echo "  ⚠ openswoole ausente → 'php artisan serve' (dev fallback)"
    exec php artisan serve --host=0.0.0.0 --port=8000
fi
