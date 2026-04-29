<?php

namespace App\Http\Controllers;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Services\AgentCatalog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * /marketplace — single page consolidating EVERY part the agents have
 * autonomously bought + the deliberation thread that produced each
 * decision. Replaces the per-agent gallery scattered across
 * /agents/{key} pages with a unified feed.
 *
 * Why a single page:
 *   • Operator wants "show me everything the AI did this week" not
 *     "click through 24 agent pages to see who bought what".
 *   • Cross-agent visibility: easy to spot if 3 agents independently
 *     wanted the same kind of part (signal that the budget should
 *     bias them differently).
 *   • The committee_log is the most interesting artefact — agents
 *     literally talking to each other — so it deserves a first-class
 *     surface, not a tooltip on a small card.
 */
class MarketplaceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    public function index(Request $request)
    {
        $filters = [
            'status'    => $request->string('status')->trim()->value(),
            'agent_key' => $request->string('agent_key')->trim()->value(),
        ];

        $query = PartOrder::query()->orderByDesc('created_at');
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['agent_key'] !== '') {
            $query->where('agent_key', $filters['agent_key']);
        }
        $orders = $query->limit(100)->get();

        // Aggregate stats across the whole marketplace — useful header.
        $stats = [
            'total_orders'    => PartOrder::count(),
            'stl_ready'       => PartOrder::where('status', PartOrder::STATUS_STL_READY)->count(),
            'cancelled'       => PartOrder::where('status', PartOrder::STATUS_CANCELLED)->count(),
            'total_spent_usd' => (float) PartOrder::query()
                ->whereIn('status', [
                    PartOrder::STATUS_PURCHASED,
                    PartOrder::STATUS_DESIGNING,
                    PartOrder::STATUS_STL_READY,
                    PartOrder::STATUS_CNC_QUEUED,
                    PartOrder::STATUS_COMPLETED,
                ])
                ->sum('cost_usd'),
            'agents_active'   => PartOrder::distinct('agent_key')->count('agent_key'),
        ];

        // Top wallets — operator can spot which agents are accumulating
        // budget without spending.
        $topWallets = AgentWallet::query()
            ->orderByDesc('balance_usd')
            ->limit(10)
            ->get();

        // Distinct agent keys present in the orders for the filter UI.
        $agentKeysWithOrders = PartOrder::query()
            ->distinct()
            ->orderBy('agent_key')
            ->pluck('agent_key');

        // All status options for the filter UI.
        $statusOptions = [
            PartOrder::STATUS_COMMITTEE,
            PartOrder::STATUS_SEARCHING,
            PartOrder::STATUS_PURCHASED,
            PartOrder::STATUS_DESIGNING,
            PartOrder::STATUS_STL_READY,
            PartOrder::STATUS_CANCELLED,
        ];

        return view('marketplace.index', [
            'orders'              => $orders,
            'stats'               => $stats,
            'topWallets'          => $topWallets,
            'agentKeysWithOrders' => $agentKeysWithOrders,
            'statusOptions'       => $statusOptions,
            'filters'             => $filters,
            'agentCatalog'        => collect(AgentCatalog::all())->keyBy('key'),
        ]);
    }
}
