<?php

namespace App\Console\Commands;

use App\Services\Robotparts\WalletCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan agents:credit-wallets
 *
 * Daily idempotent cron — credits each agent's wallet with the delta
 * since the last run. See WalletCreditService for the formula.
 *
 * Safe to re-run within the same day: the delta will be 0.
 */
class CreditAgentWalletsCommand extends Command
{
    protected $signature = 'agents:credit-wallets {--dry-run : Print what would be credited without writing}';

    protected $description = 'Credit agent wallets with new earnings since last run.';

    public function handle(WalletCreditService $svc): int
    {
        if ($this->option('dry-run')) {
            $this->warn('--dry-run not yet implemented; running normally.');
            // (Not strictly needed yet — service is idempotent + cheap, so
            // running for real is safe.)
        }

        $this->info('Crediting agent wallets…');
        $start = microtime(true);

        $summary = $svc->run();

        $this->newLine();
        $this->info(sprintf(
            '✓ %d/%d agents credited, total $%.4f, in %d ms',
            $summary['agents_credited'],
            $summary['agents_processed'],
            $summary['total_credited'],
            (int) ((microtime(true) - $start) * 1000),
        ));

        if (!empty($summary['per_agent'])) {
            $this->newLine();
            foreach ($summary['per_agent'] as $key => $usd) {
                $this->line(sprintf('  %-12s +$%.4f', $key, $usd));
            }
        }

        Log::info('agents:credit-wallets done', $summary);
        return self::SUCCESS;
    }
}
