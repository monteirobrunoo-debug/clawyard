<?php

namespace App\Http\Controllers;

use App\Models\PartOrder;
use App\Services\AgentCatalog;
use App\Services\Robotparts\RobotBlueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * /robot — the assembled body view. Shows every anatomy slot and
 * what part fills it (or 'empty' with the assigned owners visible
 * so a manager knows which agents to push toward earning faster).
 *
 * The PART of the system the user sees here:
 *   • Big-picture progress: 'we have 7/15 slots filled'
 *   • Per-slot card: filled vs empty, which agent bought it, cost,
 *     STL download, assembly notes inline
 *   • Total robot cost so far
 *   • 'Missing slots' breakdown so the next shop round has visible
 *     priorities
 */
class RobotController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    public function index(Request $request)
    {
        // Map slot → best PartOrder filling it. 'Best' = most advanced
        // status (stl_ready > purchased > designing > anything else),
        // tie-broken by latest created_at.
        $statusRank = [
            PartOrder::STATUS_STL_READY  => 5,
            PartOrder::STATUS_CNC_QUEUED => 4,
            PartOrder::STATUS_COMPLETED  => 6,
            PartOrder::STATUS_PURCHASED  => 3,
            PartOrder::STATUS_DESIGNING  => 2,
            PartOrder::STATUS_SEARCHING  => 1,
            PartOrder::STATUS_COMMITTEE  => 0,
            PartOrder::STATUS_CANCELLED  => -1,
        ];

        $orders = PartOrder::query()
            ->whereNotNull('slot')
            ->whereIn('status', [
                PartOrder::STATUS_PURCHASED,
                PartOrder::STATUS_DESIGNING,
                PartOrder::STATUS_STL_READY,
                PartOrder::STATUS_CNC_QUEUED,
                PartOrder::STATUS_COMPLETED,
            ])
            ->orderByDesc('created_at')
            ->get();

        $bestPerSlot = [];
        foreach ($orders as $order) {
            $slot = $order->slot;
            $rank = $statusRank[$order->status] ?? 0;
            if (!isset($bestPerSlot[$slot])
                || ($statusRank[$bestPerSlot[$slot]->status] ?? 0) < $rank
            ) {
                $bestPerSlot[$slot] = $order;
            }
        }

        $allSlots = RobotBlueprint::all();
        $catalogByKey = collect(AgentCatalog::all())->keyBy('key');

        // Per-slot view-model rows, in canonical blueprint order so
        // the page reads top-down 🧠 → 👁 → 👂 → … → 🎯.
        $slotRows = [];
        foreach ($allSlots as $slotKey => $slotMeta) {
            $order = $bestPerSlot[$slotKey] ?? null;
            $owners = collect($slotMeta['owners'])
                ->map(fn($k) => $catalogByKey->get($k) ?? ['key' => $k, 'name' => $k, 'emoji' => '🤖'])
                ->all();
            $slotRows[] = [
                'key'     => $slotKey,
                'meta'    => $slotMeta,
                'owners'  => $owners,
                'order'   => $order,
                'filled'  => $order !== null,
                'agent'   => $order ? $catalogByKey->get($order->agent_key) : null,
            ];
        }

        $totalSlots   = count($allSlots);
        $filledSlots  = count(array_filter($slotRows, fn($r) => $r['filled']));
        $totalCost    = (float) PartOrder::query()
            ->whereIn('status', [
                PartOrder::STATUS_PURCHASED,
                PartOrder::STATUS_DESIGNING,
                PartOrder::STATUS_STL_READY,
                PartOrder::STATUS_CNC_QUEUED,
                PartOrder::STATUS_COMPLETED,
            ])
            ->sum('cost_usd');

        // Missing slots — for the call-to-action panel.
        $missingSlots = array_filter($slotRows, fn($r) => !$r['filled']);

        return view('robot.index', [
            'slotRows'     => $slotRows,
            'totalSlots'   => $totalSlots,
            'filledSlots'  => $filledSlots,
            'totalCost'    => $totalCost,
            'missingSlots' => $missingSlots,
        ]);
    }
}
