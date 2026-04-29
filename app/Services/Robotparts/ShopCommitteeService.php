<?php

namespace App\Services\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Services\AgentCatalog;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Multi-agent committee that decides what robot part an agent will buy.
 *
 *   1. The BUYER is an agent with budget > minimum. They commission
 *      the deliberation.
 *   2. 2 HELPERS are picked from the catalogue (random, excluding
 *      the buyer + meta-agents like 'orchestrator'). Each gives a
 *      one-paragraph suggestion.
 *   3. The BUYER reads both suggestions + decides. Output is strict
 *      JSON: { name, description, search_query, justification, est_cost_usd }.
 *
 * The whole conversation is logged in part_orders.committee_log so a
 * curious operator can later replay "why did Marco buy a 6-DOF servo
 * when he had $5?".
 *
 * Failure modes (all return PartOrder in cancelled status, log only,
 * never throw to caller):
 *   • Dispatcher 5xx/4xx → cancelled, notes capture the error
 *   • Buyer's JSON unparseable → cancelled with raw text in notes
 *   • Buyer says "nothing" (empty est_cost_usd) → cancelled, no debit
 *
 * Cost: ~3 cheap Sonnet calls per committee = ~$0.001 per part decision.
 */
class ShopCommitteeService
{
    /**
     * Agents that are always excluded from acting as helpers — these
     * are routing/orchestration meta-agents that don't have a personal
     * voice for shopping advice.
     */
    private const META_AGENTS = ['orchestrator', 'auto', 'briefing', 'thinking', 'claude'];

    public function __construct(
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Run a committee for the given buyer. Creates a PartOrder row
     * and returns it. The order's status reflects the outcome:
     *   • 'searching'  → buyer decided what to look for, ready for D4
     *   • 'cancelled'  → committee aborted somewhere, notes explain why
     *
     * @param string $buyerAgentKey   one of AgentCatalog
     * @param float|null $budget       defaults to wallet balance; pass to override
     */
    public function deliberate(string $buyerAgentKey, ?float $budget = null): PartOrder
    {
        $wallet = AgentWallet::forAgent($buyerAgentKey);
        $budget = $budget ?? (float) $wallet->balance_usd;

        // Phase 2 of the robot vision: pick the slot this agent should
        // fill before kicking off deliberation. Filled-slots logic
        // (skip slots already 'stl_ready' to make progress toward a
        // complete robot) lives in RobotBlueprint.
        $filledSlots = PartOrder::query()
            ->whereIn('status', [
                PartOrder::STATUS_PURCHASED,
                PartOrder::STATUS_DESIGNING,
                PartOrder::STATUS_STL_READY,
                PartOrder::STATUS_CNC_QUEUED,
                PartOrder::STATUS_COMPLETED,
            ])
            ->whereNotNull('slot')
            ->distinct()
            ->pluck('slot')
            ->all();
        $slot = RobotBlueprint::nextSlotFor($buyerAgentKey, $filledSlots);

        $order = PartOrder::create([
            'agent_key' => $buyerAgentKey,
            'slot'      => $slot,
            'name'      => '(deliberating)',
            'status'    => PartOrder::STATUS_COMMITTEE,
            'cost_usd'  => 0,
        ]);

        try {
            // Pick 2 helpers + collect their input.
            $helperKeys = $this->pickHelpers($buyerAgentKey, count: 2);
            $helperReplies = [];
            foreach ($helperKeys as $helperKey) {
                $reply = $this->askHelper($helperKey, $buyerAgentKey, $budget, $slot);
                if ($reply !== null) {
                    $order->appendCommittee($helperKey, 'helper', $reply);
                    $helperReplies[$helperKey] = $reply;
                }
            }

            // Buyer decides based on the helper input.
            $decision = $this->askBuyer($buyerAgentKey, $budget, $helperReplies, $slot);
            if ($decision === null) {
                $order->status = PartOrder::STATUS_CANCELLED;
                $order->notes  = 'Committee aborted — buyer dispatch failed.';
                $order->save();
                return $order;
            }

            $order->appendCommittee($buyerAgentKey, 'buyer', json_encode($decision, JSON_UNESCAPED_UNICODE));

            // Sanity check + transition to searching state for D4.
            if (empty($decision['search_query']) || ($decision['est_cost_usd'] ?? 0) <= 0) {
                $order->status = PartOrder::STATUS_CANCELLED;
                $order->notes  = 'Buyer returned no valid search_query or zero cost.';
                $order->save();
                return $order;
            }

            $estCost = (float) $decision['est_cost_usd'];

            // Hard budget check — even if the prompt told the LLM, some
            // models still ignore the constraint and pick expensive parts.
            // Cancel cleanly here rather than letting PartSearchService
            // discover the overdraft on debit and producing a confusing
            // "insufficient balance" trail.
            if ($estCost > $budget + 0.005) {     // +5 millidollars tolerance for rounding
                $order->status = PartOrder::STATUS_CANCELLED;
                $order->notes  = sprintf(
                    'Buyer over-budget: picked $%.2f but budget is $%.2f. Skipped purchase to keep wallet intact.',
                    $estCost, $budget,
                );
                $order->save();
                return $order;
            }

            $order->name         = mb_substr((string) ($decision['name'] ?? '(unnamed part)'), 0, 255);
            $order->description  = (string) ($decision['description'] ?? '');
            $order->purpose      = (string) ($decision['purpose'] ?? '');
            $order->search_query = mb_substr((string) ($decision['search_query'] ?? ''), 0, 255);
            $order->cost_usd     = round($estCost, 4);
            $order->status       = PartOrder::STATUS_SEARCHING;
            $order->save();

            return $order;
        } catch (\Throwable $e) {
            Log::error('ShopCommitteeService: deliberation crashed', [
                'order_id'        => $order->id,
                'buyer_agent_key' => $buyerAgentKey,
                'error'           => $e->getMessage(),
            ]);
            $order->status = PartOrder::STATUS_CANCELLED;
            $order->notes  = 'Internal error: ' . mb_substr($e->getMessage(), 0, 200);
            $order->save();
            return $order;
        }
    }

    /**
     * Pick N helper agents at random, excluding the buyer + meta agents.
     * Deterministic ordering inside the result (sorted) so test setups
     * are reproducible when seeded.
     */
    private function pickHelpers(string $buyerKey, int $count): array
    {
        $candidates = collect(AgentCatalog::all())
            ->pluck('key')
            ->reject(fn($k) => $k === $buyerKey || in_array($k, self::META_AGENTS, true))
            ->values();

        return $candidates->shuffle()->take($count)->sort()->values()->all();
    }

    /**
     * Ask one helper for their suggestion. Returns the helper's reply
     * text, or null if the dispatcher failed (we then proceed without
     * that helper rather than aborting the committee).
     */
    private function askHelper(string $helperKey, string $buyerKey, float $budget, ?string $slot): ?string
    {
        $helperMeta = AgentCatalog::find($helperKey);
        $buyerMeta  = AgentCatalog::find($buyerKey);
        if (!$helperMeta || !$buyerMeta) return null;

        // Slot context — guides the helper to suggest parts that fit
        // the SPECIFIC anatomy slot the buyer must fill, not whatever
        // their persona randomly thinks of.
        $slotMeta = $slot ? RobotBlueprint::find($slot) : null;
        $slotContext = '';
        if ($slotMeta) {
            $slotContext = "\n\nROBOT ANATOMY SLOT TO FILL: {$slotMeta['emoji']} {$slotMeta['label']}\n"
                . "Purpose: {$slotMeta['purpose']}\n"
                . "Typical parts that fit this slot: {$slotMeta['typical_parts']}\n"
                . "Your suggestion MUST be for this slot specifically — don't suggest a sensor when the slot is locomotion.";
        }

        $system = "You are {$helperMeta['name']} ({$helperMeta['role']}). "
                . "Your colleague {$buyerMeta['name']} ({$buyerMeta['role']}) has \${$budget} to spend on a robot body part. "
                . "Suggest ONE part you think would fit them. Reply in 2-3 sentences with: "
                . "(a) what the part is, (b) why it fits, (c) rough cost estimate. "
                . "Keep it under \$" . max(2.0, $budget) . "."
                . $slotContext;

        $user = "What part should {$buyerMeta['name']} buy with \${$budget}? "
              . ($slotMeta
                  ? "Remember: target slot is {$slotMeta['emoji']} {$slotMeta['label']}."
                  : "Stay practical — common DIY robotics components.");

        $res = $this->dispatcher->dispatch($system, $user, maxTokens: 400);
        if (!($res['ok'] ?? false)) {
            Log::warning('ShopCommitteeService: helper dispatch failed', [
                'helper'   => $helperKey,
                'error'    => $res['error'] ?? 'unknown',
            ]);
            return null;
        }
        return trim((string) $res['text']);
    }

    /**
     * Ask the buyer to decide based on the helpers' input. Returns
     * a parsed JSON dict or null on failure (dispatch error or
     * unparseable response).
     */
    private function askBuyer(string $buyerKey, float $budget, array $helperReplies, ?string $slot): ?array
    {
        $buyerMeta = AgentCatalog::find($buyerKey);
        if (!$buyerMeta) return null;

        $budgetStr = number_format($budget, 2);

        // Slot context for the buyer — the robot anatomy assignment.
        $slotMeta = $slot ? RobotBlueprint::find($slot) : null;
        $slotBlock = '';
        if ($slotMeta) {
            $slotBlock = "\n\nROBOT ANATOMY ASSIGNMENT — SLOT TO FILL:\n"
                . "  {$slotMeta['emoji']} {$slotMeta['label']}\n"
                . "  Purpose: {$slotMeta['purpose']}\n"
                . "  Examples that fit this slot: {$slotMeta['typical_parts']}\n\n"
                . "Your pick MUST be for THIS slot. Don't pick a sensor when the slot is locomotion. "
                . "Don't pick a battery when the slot is vision. Stay on target.";
        }

        $system = "You are {$buyerMeta['name']} ({$buyerMeta['role']}). "
                . "You have EXACTLY \${$budgetStr} USD — not a cent more. Your colleagues advised you. "
                . "Now you must DECIDE.{$slotBlock}\n\n"
                . "HARD BUDGET CONSTRAINT: est_cost_usd MUST be ≤ \${$budgetStr}. "
                . "If you suggest a part costing more, the purchase fails and your wallet wastes the deliberation. "
                . "Pick something genuinely affordable for the slot.\n\n"
                . "Output STRICT JSON only — no markdown fences, no preamble — matching:\n"
                . '{ "name": "<short part name>", '
                . '"description": "<1-2 sentences>", '
                . '"purpose": "<1 sentence: what this part does in the robot, in PT-pt>", '
                . '"search_query": "<concrete query for robotshop / aliexpress / adafruit>", '
                . '"justification": "<why this fits the slot AND fits the $' . $budgetStr . ' budget>", '
                . '"est_cost_usd": <number, ≤ ' . $budgetStr . '> }' . "\n"
                . 'If genuinely nothing fits the budget for this slot, return est_cost_usd: 0.';

        $context = "BUDGET: \${$budget}\n\n";
        foreach ($helperReplies as $helperKey => $reply) {
            $hMeta = AgentCatalog::find($helperKey);
            $context .= "ADVICE FROM " . ($hMeta['name'] ?? $helperKey) . ":\n{$reply}\n\n";
        }
        $context .= "Now decide. Emit JSON only.";

        $res = $this->dispatcher->dispatch($system, $context, maxTokens: 600);
        if (!($res['ok'] ?? false)) return null;

        return $this->parseJson((string) $res['text']);
    }

    /**
     * Tolerant JSON parse — same approach as PromptBuilder::parseSynthesis:
     * strip markdown fences, handle trailing prose, return null on failure.
     */
    private function parseJson(string $text): ?array
    {
        $clean = trim($text);
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $clean, $m)) {
            $clean = trim($m[1]);
        }
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $json = substr($clean, $start, $end - $start + 1);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
