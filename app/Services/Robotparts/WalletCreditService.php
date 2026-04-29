<?php

namespace App\Services\Robotparts;

use App\Models\AgentMetric;
use App\Models\AgentWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Walks agent_metrics, computes the DELTA since the last credit run
 * for each agent, and credits the wallet by the formula:
 *
 *   $0.50 per leads_won                (closing matters most)
 *  +$0.05 per signals_processed        (showing up matters too)
 *  +$0.10 per thumbs_up                (positive feedback)
 *  –$0.05 per thumbs_down              (honest negative still bites a bit)
 *
 * Idempotent across runs:
 *   • Each wallet stores `last_credit_basis` — a snapshot of metric
 *     values AT THE TIME of the last credit. The next run reads the
 *     metric again, diffs against the snapshot, credits the difference.
 *   • Re-running the same day is a no-op (delta = 0).
 *   • Formula changes don't double-credit historical earnings — only
 *     NEW activity since the last run gets re-credited.
 *
 * The whole walk runs in one transaction per wallet (not one big
 * transaction across all wallets) so a single bad row doesn't block
 * the others.
 */
class WalletCreditService
{
    /**
     * Per-event credit rates in USD. Tuned so an active agent earns
     * ~$5/week — enough to "buy" a small robot part each fortnight.
     *
     * Keys MUST match the names used in last_credit_basis snapshots
     * so historical wallets stay backwards-compatible if rates change.
     */
    public const RATES = [
        'leads_won'         => 0.50,
        'signals_processed' => 0.05,
        'thumbs_up'         => 0.10,
        'thumbs_down'       => -0.05,
    ];

    /**
     * Run a credit pass over every agent_metric. Returns summary:
     *   [
     *     'agents_processed' => int,
     *     'agents_credited'  => int,
     *     'total_credited'   => float (USD),
     *     'per_agent'        => [agent_key => credited_usd, …],
     *   ]
     */
    public function run(): array
    {
        $perAgent = [];
        $totalCredited = 0.0;
        $agentsCredited = 0;
        $metrics = AgentMetric::query()->get();

        foreach ($metrics as $metric) {
            try {
                $credited = DB::transaction(fn() => $this->creditOne($metric));
                if ($credited > 0) {
                    $perAgent[$metric->agent_key] = round($credited, 4);
                    $totalCredited += $credited;
                    $agentsCredited++;
                }
            } catch (\Throwable $e) {
                Log::warning('WalletCreditService: agent failed', [
                    'agent_key' => $metric->agent_key,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return [
            'agents_processed' => $metrics->count(),
            'agents_credited'  => $agentsCredited,
            'total_credited'   => round($totalCredited, 4),
            'per_agent'        => $perAgent,
        ];
    }

    /**
     * Credit ONE agent based on the delta since their last_credit_basis
     * snapshot. Returns the USD amount credited (can be 0 or negative
     * — though negative is clamped to 0 by AgentWallet::adjust()).
     */
    private function creditOne(AgentMetric $metric): float
    {
        $wallet = AgentWallet::forAgent($metric->agent_key);

        $current = [
            'leads_won'         => (int) $metric->leads_won,
            'signals_processed' => (int) $metric->signals_processed,
            'thumbs_up'         => (int) $metric->thumbs_up,
            'thumbs_down'       => (int) $metric->thumbs_down,
        ];
        $previous = (array) ($wallet->last_credit_basis ?? []);

        // Compute delta per metric and value via the rate table.
        $delta = 0.0;
        foreach (self::RATES as $key => $rate) {
            $now  = $current[$key] ?? 0;
            $then = (int) ($previous[$key] ?? 0);
            $diff = max(0, $now - $then);   // never go backwards (e.g. metric reset)
            $delta += $diff * $rate;
        }

        // No new activity since the last run — skip.
        if (abs($delta) < 0.0001) {
            return 0.0;
        }

        // Even if the formula change would result in a NEGATIVE delta
        // (e.g. if we add a rate for thumbs_down BIGGER than activity
        // covers), clamp to 0 — we don't claw back from past-credited
        // wallets. Penalties only apply to NEW thumbs_down events going
        // forward.
        $delta = max(0.0, $delta);

        $wallet->adjust($delta);
        $wallet->last_credit_at    = now();
        $wallet->last_credit_basis = $current;
        $wallet->save();

        return $delta;
    }
}
