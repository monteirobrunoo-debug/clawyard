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

                $pickedCost = round((float) ($picked['cost_usd'] ?? $order->cost_usd), 4);

                // Hard budget guard — even though we told the LLM the
                // exact wallet balance, some models still pick expensive
                // variants. Cancel cleanly with a clear note BEFORE
                // touching the wallet, so the operator gets actionable
                // information ('this agent's persona keeps biasing it
                // toward parts above its budget — give it more time to
                // accumulate or relax the persona').
                $wallet = AgentWallet::forAgent($order->agent_key);
                $budget = (float) $wallet->balance_usd;
                if ($pickedCost > $budget + 0.005) {
                    $order->name             = mb_substr((string) ($picked['name'] ?? $order->name), 0, 255);
                    $order->cost_usd         = $pickedCost;
                    $order->source_url       = mb_substr((string) ($picked['source_url'] ?? ''), 0, 500) ?: null;
                    $order->status           = PartOrder::STATUS_CANCELLED;
                    $order->notes            = sprintf(
                        'Buyer pick over-budget: $%.2f vs wallet $%.2f. Persona keeps suggesting expensive variants — wait for budget to grow or relax persona.',
                        $pickedCost, $budget,
                    );
                    $order->save();
                    return $order;
                }

                // Apply buyer's pick.
                $order->name             = mb_substr((string) ($picked['name']             ?? $order->name), 0, 255);
                $order->description      = (string)         ($picked['description']      ?? $order->description);
                $order->source_url       = mb_substr((string) ($picked['source_url']       ?? ''), 0, 500) ?: null;
                $order->source_image_url = mb_substr((string) ($picked['source_image_url'] ?? ''), 0, 500) ?: null;
                $order->cost_usd         = $pickedCost;

                $wallet->adjust(-$pickedCost);

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
     *
     * Critical: the prompt is now budget-aware. Earlier versions let
     * the LLM pick whatever caught its eye in the search results, even
     * when prices clearly exceeded the wallet balance — leaving the
     * order to die at debit-time with a confusing 'insufficient balance'
     * note. Now the buyer is told the EXACT remaining budget and forced
     * to pick within it; a hard guard at the call site catches anything
     * the LLM still slips through.
     */
    private function askBuyerToPick(PartOrder $order, string $searchText): ?array
    {
        $buyerMeta = AgentCatalog::find($order->agent_key);
        $name = $buyerMeta['name'] ?? $order->agent_key;

        // Budget = current wallet balance. The agent CANNOT spend more
        // than this — even if the search results show tempting expensive
        // variants. The committee already estimated a cost, so use the
        // smaller of (committee estimate × 1.2 tolerance, wallet balance)
        // to give the buyer a touch of room to refine but never blow
        // through the wallet.
        $wallet = AgentWallet::forAgent($order->agent_key);
        $budget = (float) $wallet->balance_usd;
        $budgetStr = number_format($budget, 2);

        $system = "You are {$name}. You searched the web for '{$order->search_query}' "
                . "looking for a small robot part. Read the results and PICK ONE that fits.\n\n"
                . "HARD BUDGET CONSTRAINT: cost_usd MUST be ≤ \${$budgetStr}. "
                . "Your wallet is \${$budgetStr}. If results only show parts above \${$budgetStr}, "
                . "return cost_usd: 0 and source_url: \"\" — the system will skip cleanly. "
                . "DO NOT pick an expensive variant 'just to have something' — better to skip than overspend.\n\n"
                . "Output STRICT JSON only — no markdown fences, no preamble:\n"
                . '{ "name": "<short part name>", '
                . '"description": "<1 sentence>", '
                . '"source_url": "<exact URL from results, must match the cost_usd you picked>", '
                . '"source_image_url": "<image URL if visible in results, else empty>", '
                . '"cost_usd": <number, ≤ ' . $budgetStr . '> }';

        $user = "Search query: {$order->search_query}\n\n"
              . "Wallet budget: \${$budgetStr}\n\n"
              . "Results:\n{$searchText}\n\n"
              . "Pick one within the \${$budgetStr} budget. JSON only.";

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
