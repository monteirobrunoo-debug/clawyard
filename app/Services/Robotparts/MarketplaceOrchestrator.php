<?php

namespace App\Services\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Services\AgentCatalog;
use Illuminate\Support\Facades\Log;

/**
 * The end-to-end shopping pipeline glued together. Given an agent_key
 * with enough balance, runs:
 *
 *   1. ShopCommitteeService::deliberate()    → status='searching'
 *   2. PartSearchService::findAndPick()      → status='purchased'
 *   3. CadGenerationService::generate()      → status='stl_ready' (or 'designing')
 *
 * Each step is best-effort: if step 2 cancels the order, step 3 is
 * skipped (you can't design what you didn't buy). The early stages
 * each leave the order in a consistent state so a partial failure
 * doesn't ghost a wallet debit without anything to show for it.
 *
 * Used by:
 *   • `php artisan agents:shop` (CLI / cron)
 *   • Future "Run shopping round" admin button on /agents/{key}
 */
class MarketplaceOrchestrator
{
    /** Minimum wallet balance to even start a committee — committee
     *  itself costs ~$0.001 in LLM tokens and a part typically costs
     *  $1-3, so $2 is a reasonable floor. */
    public const MIN_BUDGET_USD = 2.00;

    public function __construct(
        private ShopCommitteeService $committee,
        private PartSearchService $search,
        private CadGenerationService $cad,
        private PartValidationService $validation,
    ) {}

    /**
     * Run a full shopping round for ONE agent. Returns the resulting
     * PartOrder (which may be cancelled). Returns null if the agent
     * doesn't qualify (insufficient balance) — no row created.
     */
    public function runFor(string $agentKey): ?PartOrder
    {
        $wallet = AgentWallet::forAgent($agentKey);
        if (!$wallet->canAfford(self::MIN_BUDGET_USD)) {
            return null;
        }

        $order = $this->committee->deliberate($agentKey, budget: (float) $wallet->balance_usd);
        if ($order->status === PartOrder::STATUS_SEARCHING) {
            $order = $this->search->findAndPick($order);
        }
        if ($order->status === PartOrder::STATUS_PURCHASED) {
            $order = $this->cad->generate($order);
        }

        // Phase A — peer-review by 2 other agents on any successfully-
        // purchased order. Skipped if order cancelled (no point reviewing
        // a non-purchase). Doesn't gate the order in any way: it just
        // adds a validation badge for the operator's signal.
        if (in_array($order->status, [
            PartOrder::STATUS_PURCHASED,
            PartOrder::STATUS_DESIGNING,
            PartOrder::STATUS_STL_READY,
        ], true)) {
            $order = $this->validation->review($order);
        }

        Log::info('MarketplaceOrchestrator: round complete', [
            'agent_key' => $agentKey,
            'order_id'  => $order->id,
            'status'    => $order->status,
            'cost_usd'  => (float) $order->cost_usd,
            'name'      => $order->name,
        ]);

        return $order;
    }

    /**
     * Run a shopping round for EVERY agent that qualifies. Returns
     * a summary array.
     */
    public function runForAllEligible(): array
    {
        $summary = [
            'agents_eligible' => 0,
            'orders_created'  => 0,
            'orders_completed' => 0,    // reached stl_ready or designing-with-scad
            'orders_cancelled' => 0,
            'total_spent_usd' => 0.0,
            'per_agent'       => [],
        ];

        foreach (AgentCatalog::all() as $meta) {
            $key = $meta['key'];
            $wallet = AgentWallet::forAgent($key);
            if (!$wallet->canAfford(self::MIN_BUDGET_USD)) continue;
            $summary['agents_eligible']++;

            $order = $this->runFor($key);
            if ($order === null) continue;
            $summary['orders_created']++;

            if (in_array($order->status, [PartOrder::STATUS_STL_READY, PartOrder::STATUS_DESIGNING], true)
                && $order->design_scad
            ) {
                $summary['orders_completed']++;
                $summary['total_spent_usd'] += (float) $order->cost_usd;
            } elseif ($order->status === PartOrder::STATUS_CANCELLED) {
                $summary['orders_cancelled']++;
            }

            $summary['per_agent'][$key] = [
                'order_id' => $order->id,
                'status'   => $order->status,
                'name'     => $order->name,
            ];
        }

        $summary['total_spent_usd'] = round($summary['total_spent_usd'], 4);
        return $summary;
    }
}
