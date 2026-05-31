# ClawYard — dev environment local (Bruno fix 2026-05-31)
# Réplica de produção: PHP 8.4 + OpenSwoole (Octane) + extensões Postgres/Redis.
#
# Objectivo: testar mudanças LOCALMENTE antes de qualquer push a produção.
# Resolve a causa raiz dos erros desta semana — eu testava em tinker (disco)
# mas os workers Octane corriam código antigo. Aqui o ambiente é o mesmo.
#
# Build: docker compose build  (openswoole compila ~3-5min na 1ª vez)
# Uso:   docker compose up      → http://localhost:8000

FROM php:8.4-cli-bookworm

# ── Dependências de sistema para as extensões PHP ───────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpq-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libssl-dev libcurl4-openssl-dev libpcre3-dev libicu-dev \
        libonig-dev procps \
    && rm -rf /var/lib/apt/lists/*
# procps → dá o `pgrep` que o Octane usa internamente ("pgrep: not found"
# no 1º run). Apanhado no dev local 2026-05-31.

# ── Extensões PHP (mesmas de produção) ──────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql gd zip bcmath intl mbstring opcache \
        pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis
# pcntl + sockets: OBRIGATÓRIOS para o Octane/OpenSwoole arrancar.
# Sem pcntl: "Signals are not supported" → octane:start crasha (exit 1).
# Apanhado no dev local 2026-05-31 antes de chegar a prod.

# ── OpenSwoole (igual a prod — para Octane) ─────────────────────────────────
# Se a compilação falhar no teu Mac (ARM/x86), o fallback é `php artisan serve`
# (ver docs/LOCAL_DEV.md). Para dev, serve cobre 95% dos casos.
RUN pecl install openswoole \
    && docker-php-ext-enable openswoole \
    || echo "⚠ openswoole falhou — usa 'php artisan serve' (ver LOCAL_DEV.md)"

# ── Composer ────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── php.ini dev ─────────────────────────────────────────────────────────────
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-clawyard-dev.ini

WORKDIR /var/www/html

# Entrypoint faz composer install + key + migrate na 1ª vez, depois arranca.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000
ENTRYPOINT ["entrypoint"]
