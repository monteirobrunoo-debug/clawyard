<?php

namespace App\Services\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Services\AgentCatalog;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\WebSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 of the marketplace: the buyer agent SEARCHES THE WEB for
 * the part it described, picks one, and the wallet is debited.
 *
 * Inputs: PartOrder in status='searching' with a non-empty search_query.
 * Output: PartOrder transitions to 'purchased' (success) or 'cancelled'
 *         (search empty / buyer chose nothing / dispatch failed).
 *
 * Real net access happens via WebSearchService (Tavily). The buyer
 * agent reads the search result text + emits JSON picking 1 candidate
 * — same pattern as the synthesis JSON in PromptBuilder.
 *
 * Wallet debit happens INSIDE the same transaction as the order update
 * so a crash mid-write doesn't leave a "purchased" order without
 * matching wallet movement.
 */
class PartSearchService
{
    public function __construct(
        private AgentDispatcher $dispatcher,
        private WebSearchService $webSearch,
    ) {}

    /**
     * Run the search-and-pick pass for a single PartOrder.
     * Returns the same order with updated status. Idempotent: if the
     * order is not in 'searching' state, returns it as-is.
     */
    public function findAndPick(PartOrder $order): PartOrder
    {
        if ($order->status !== PartOrder::STATUS_SEARCHING) {
            return $order;
        }
        if (empty($order->search_query)) {
            $order->status = PartOrder::STATUS_CANCELLED;
            $order->notes  = 'PartSearchService: empty search_query';
            $order->save();
            return $order;
        }

        try {
            $searchText = $this->webSearch->search($order->search_query, maxResults: 5);

            // If the search service is disabled (no Tavily key), skip
            // gracefully — buyer can't pick from nothing.
            if (str_contains($searchText, 'Web search not available')) {
                $order->status = PartOrder::STATUS_CANCELLED;
                $order->notes  = 'PartSearchService: Tavily not configured (TAVILY_API_KEY).';
                $order->save();
                return $order;
            }

            $picked = $this->askBuyerToPick($order, $searchText);
            if ($picked === null) {
                $order->status = PartOrder::STATUS_CANCELLED;
                $order->notes  = 'PartSearchService: buyer dispatch failed or picked nothing.';
                $order->save();
                return $order;
            }

            return DB::transaction(function () use ($order, $picked, $searchText) {
                // Capture raw search text for audit. Truncate aggressively
                // so the JSON column doesn't bloat (search results can be 5KB+).
                $order->search_candidates = ['raw_text' => mb_substr($searchText, 0, 4000)];

                // Apply buyer's pick.
                $order->name             = mb_substr((string) ($picked['name']             ?? $order->name), 0, 255);
                $order->description      = (string)         ($picked['description']      ?? $order->description);
                $order->source_url       = mb_substr((string) ($picked['source_url']       ?? ''), 0, 500) ?: null;
                $order->source_image_url = mb_substr((string) ($picked['source_image_url'] ?? ''), 0, 500) ?: null;
                $order->cost_usd         = round((float) ($picked['cost_usd'] ?? $order->cost_usd), 4);

                // Debit the wallet. canAfford is checked LAST so we
                // race the wallet write atomically — if balance dropped
                // since the committee, we cancel cleanly here.
                $wallet = AgentWallet::forAgent($order->agent_key);
                if (!$wallet->canAfford((float) $order->cost_usd)) {
                    $order->status = PartOrder::STATUS_CANCELLED;
                    $order->notes  = 'PartSearchService: insufficient balance at debit time';
                    $order->save();
                    return $order;
                }

                $wallet->adjust(-(float) $order->cost_usd);

                $order->status = PartOrder::STATUS_PURCHASED;
                $order->save();
                return $order;
            });
        } catch (\Throwable $e) {
            Log::error('PartSearchService: crashed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            $order->status = PartOrder::STATUS_CANCELLED;
            $order->notes  = 'PartSearchService: ' . mb_substr($e->getMessage(), 0, 200);
            $order->save();
            return $order;
        }
    }

    /**
     * Ask the buyer agent to read search results + pick 1.
     * Returns null on dispatch failure or unparseable response.
     */
    private function askBuyerToPick(PartOrder $order, string $searchText): ?array
    {
        $buyerMeta = AgentCatalog::find($order->agent_key);
        $name = $buyerMeta['name'] ?? $order->agent_key;

        $system = "You are {$name}. You searched the web for '{$order->search_query}' "
                . "looking for a small robot part. Read the results and PICK ONE that fits "
                . "best given your persona. Output STRICT JSON only:\n"
                . '{ "name": "<short part name>", '
                . '"description": "<1 sentence>", '
                . '"source_url": "<exact URL from results>", '
                . '"source_image_url": "<image URL if visible in results, else empty>", '
                . '"cost_usd": <number> }' . "\n"
                . 'If no candidate fits, return cost_usd: 0 and source_url: "".';

        $user = "Search query: {$order->search_query}\n\nResults:\n{$searchText}\n\nPick one. JSON only.";

        $res = $this->dispatcher->dispatch($system, $user, maxTokens: 600);
        if (!($res['ok'] ?? false)) return null;

        $parsed = $this->parseJson((string) $res['text']);
        if ($parsed === null) return null;
        // Reject zero-cost / empty-url picks — buyer signalled "no fit".
        if (empty($parsed['source_url']) || (float) ($parsed['cost_usd'] ?? 0) <= 0) return null;

        return $parsed;
    }

    /** Tolerant JSON parse — same as elsewhere in the project. */
    private function parseJson(string $text): ?array
    {
        $clean = trim($text);
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $clean, $m)) {
            $clean = trim($m[1]);
        }
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $data = json_decode(substr($clean, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
