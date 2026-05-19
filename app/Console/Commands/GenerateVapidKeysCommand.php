<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Gera VAPID key pair (public + private) para Web Push.
 *
 * Corre 1× na vida do projecto. Output: 2 linhas para meter no .env:
 *   VAPID_PUBLIC_KEY=...
 *   VAPID_PRIVATE_KEY=...
 *
 * Usage:
 *   php artisan push:generate-vapid
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'push:generate-vapid';
    protected $description = 'Gera VAPID key pair para Web Push (corre 1× na vida do projecto)';

    public function handle(): int
    {
        if (!class_exists(VAPID::class)) {
            $this->error('minishlink/web-push não instalado. Corre `composer install` primeiro.');
            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        $this->info('✓ VAPID keys geradas. Cola no .env de produção:');
        $this->line('');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:bruno.monteiro@hp-group.org');
        $this->line('');
        $this->warn('Depois: php artisan config:clear && reload PHP-FPM.');
        $this->warn('NÃO regeneres estas keys — invalidaria todas as subscriptions existentes.');

        return self::SUCCESS;
    }
}
