<?php

namespace App\Console\Commands;

use App\Models\TokenBudget;
use Illuminate\Console\Command;

/**
 * tokens:set-pool — define o pool €/mês para um período.
 *
 * Uso:
 *   php artisan tokens:set-pool 150              # current month, €150
 *   php artisan tokens:set-pool 200 --period=2026-06
 *   php artisan tokens:set-pool 150 --alert=70   # alerta a 70% em vez de 80%
 *   php artisan tokens:set-pool 150 --gate=95    # hard-gate AI calls a partir de 95%
 */
class TokensSetPoolCommand extends Command
{
    protected $signature = 'tokens:set-pool
                            {pool_eur : Valor do pool em EUR (ex: 150)}
                            {--period= : Período YYYY-MM (default: actual)}
                            {--alert=80 : Threshold % para alerta (default 80)}
                            {--gate=0 : Threshold % para hard-gate (0 = desactivado)}';

    protected $description = 'Define o pool mensal de tokens Anthropic em EUR';

    public function handle(): int
    {
        $pool   = (float) $this->argument('pool_eur');
        $period = (string) ($this->option('period') ?? TokenBudget::currentPeriod());
        $alert  = (int) $this->option('alert');
        $gate   = (int) $this->option('gate');

        if ($pool < 0 || $pool > 100000) {
            $this->error("Pool inválido: €{$pool}. Aceito [0, 100000].");
            return self::FAILURE;
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Período inválido: '{$period}'. Espera YYYY-MM (ex: 2026-05).");
            return self::FAILURE;
        }
        if ($alert < 1 || $alert > 100 || $gate < 0 || $gate > 100) {
            $this->error("alert/gate devem estar em [0-100].");
            return self::FAILURE;
        }

        $budget = TokenBudget::updateOrCreate(
            ['period_yyyy_mm' => $period],
            [
                'pool_eur'             => $pool,
                'alert_at_percent'     => $alert,
                'hard_gate_at_percent' => $gate,
            ],
        );

        // Se alterar pool, faz sentido reset das notificações para que
        // novos alertas sejam re-enviados quando a nova percentagem for
        // atingida.
        if ($budget->wasChanged('pool_eur')) {
            $budget->update(['notified_at_80' => null, 'notified_at_100' => null]);
            $this->line('  (reset dos flags de notificação)');
        }

        $this->info("✓ Pool {$period}: €" . number_format($pool, 2)
            . " | alert={$alert}% | gate=" . ($gate > 0 ? $gate . '%' : 'OFF'));

        return self::SUCCESS;
    }
}
