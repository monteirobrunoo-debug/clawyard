<?php

namespace App\Console\Commands;

use App\Services\Robotparts\MarketplaceOrchestrator;
use Illuminate\Console\Command;

/**
 * php artisan agents:shop
 *
 * Runs a shopping round for every agent with sufficient balance.
 * Cron schedule (routes/console.php): weekly, Monday 03:00 Lisbon —
 * after the morning credit run, while everyone's asleep.
 *
 * Optional --only=<key> to run for just one agent (debugging).
 */
class ShopAgentsCommand extends Command
{
    protected $signature = 'agents:shop {--only= : Run only for the given agent key}';

    protected $description = 'Run an autonomous shopping round — agents deliberate + search the web + generate CAD designs.';

    public function handle(MarketplaceOrchestrator $orchestrator): int
    {
        $only = $this->option('only');

        if ($only) {
            $this->info("Shopping round for: {$only}");
            $order = $orchestrator->runFor($only);
            if ($order === null) {
                $this->warn("Agent '{$only}' has insufficient balance (< \$" . MarketplaceOrchestrator::MIN_BUDGET_USD . ").");
                return self::SUCCESS;
            }
            $this->newLine();
            $this->info(sprintf(
                '  order #%d | %s | $%.4f | %s',
                $order->id,
                $order->status,
                (float) $order->cost_usd,
                $order->name,
            ));
            return self::SUCCESS;
        }

        $this->info('Shopping round for ALL eligible agents…');
        $summary = $orchestrator->runForAllEligible();

        $this->newLine();
        $this->info(sprintf(
            '✓ %d eligible / %d orders / %d completed / %d cancelled / $%.4f spent',
            $summary['agents_eligible'],
            $summary['orders_created'],
            $summary['orders_completed'],
            $summary['orders_cancelled'],
            $summary['total_spent_usd'],
        ));

        if (!empty($summary['per_agent'])) {
            $this->newLine();
            foreach ($summary['per_agent'] as $key => $info) {
                $this->line(sprintf(
                    '  %-12s order #%-4d %s — %s',
                    $key,
                    $info['order_id'],
                    str_pad($info['status'], 11),
                    $info['name'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
