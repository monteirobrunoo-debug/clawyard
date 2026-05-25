#!/bin/bash

cd /home/forge/clawyard.partyard.eu

git pull origin main

$FORGE_PHP composer install --no-interaction --prefer-dist --optimize-autoloader

# 2026-05-22: dump-autoload explícito mesmo quando composer install não tem
# changes a aplicar. Sem isto, classes PHP novas (Models, Services, Mails,
# Exceptions, etc.) adicionadas num commit sem mexer composer.json ficam
# FORA do classmap optimized → Octane crash a 500 quando tenta resolve-las.
# Acontece sempre que adicionamos features sem novas dependências composer.
$FORGE_PHP composer dump-autoload --optimize -n -q

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan view:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan optimize

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

# 2026-05-21: Octane graceful reload — workers re-fazem autoload contra
# a nova vendor/ do release. Sem isto, workers em memória ficam com
# classloader stale apontando para releases antigas que Forge cleanup
# remove → 500 errors em todas as requests.
$FORGE_PHP artisan octane:reload || true

# Reset queue workers (Supervisor) também, para apanharem novo código
$FORGE_PHP artisan queue:restart || true
