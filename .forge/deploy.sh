#!/bin/bash

cd /home/forge/clawyard.partyard.eu

git pull origin main

$FORGE_PHP composer install --no-interaction --prefer-dist --optimize-autoloader

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan view:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan optimize

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
