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

        $order = PartOrder::create([
            'agent_key' => $buyerAgentKey,
            'name'      => '(deliberating)',
            'status'    => PartOrder::STATUS_COMMITTEE,
            'cost_usd'  => 0,
        ]);

        try {
            // Pick 2 helpers + collect their input.
            $helperKeys = $this->pickHelpers($buyerAgentKey, count: 2);
            $helperReplies = [];
            foreach ($helperKeys as $helperKey) {
                $reply = $this->askHelper($helperKey, $buyerAgentKey, $budget);
                if ($reply !== null) {
                    $order->appendCommittee($helperKey, 'helper', $reply);
                    $helperReplies[$helperKey] = $reply;
                }
            }

            // Buyer decides based on the helper input.
            $decision = $this->askBuyer($buyerAgentKey, $budget, $helperReplies);
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

            $order->name         = mb_substr((string) ($decision['name'] ?? '(unnamed part)'), 0, 255);
            $order->description  = (string) ($decision['description'] ?? '');
            $order->search_query = mb_substr((string) ($decision['search_query'] ?? ''), 0, 255);
            $order->cost_usd     = round((float) $decision['est_cost_usd'], 4);
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
    private function askHelper(string $helperKey, string $buyerKey, float $budget): ?string
    {
        $helperMeta = AgentCatalog::find($helperKey);
        $buyerMeta  = AgentCatalog::find($buyerKey);
        if (!$helperMeta || !$buyerMeta) return null;

        $system = "You are {$helperMeta['name']} ({$helperMeta['role']}). "
                . "Your colleague {$buyerMeta['name']} ({$buyerMeta['role']}) has \${$budget} to spend on a small robot body part. "
                . "Suggest ONE part you think would fit them well. Reply in 2-3 sentences with: "
                . "(a) what the part is, (b) why it fits {$buyerMeta['name']}'s persona/role, (c) rough cost estimate. "
                . "Keep it grounded — small mechanical/electronic parts under \$" . max(2.0, $budget) . ".";

        $user = "What small robot body part should {$buyerMeta['name']} buy with their \${$budget}? "
              . "Stay practical — common DIY robotics components like servos, sensors, brackets, mini-actuators, LED indicators, etc.";

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
    private function askBuyer(string $buyerKey, float $budget, array $helperReplies): ?array
    {
        $buyerMeta = AgentCatalog::find($buyerKey);
        if (!$buyerMeta) return null;

        $system = "You are {$buyerMeta['name']} ({$buyerMeta['role']}). "
                . "You have \${$budget} to spend on a small robot body part. Your colleagues advised you. "
                . "Now you must DECIDE. Output STRICT JSON only — no markdown fences, no preamble — matching:\n"
                . '{ "name": "<short part name>", '
                . '"description": "<1-2 sentences>", '
                . '"search_query": "<concrete query for finding this on robotshop / aliexpress / adafruit, e.g. \'mg90s mini servo 9g\'>", '
                . '"justification": "<why you chose this over the helpers other ideas>", '
                . '"est_cost_usd": <number, must be <= ' . $budget . '> }' . "\n"
                . 'If nothing fits the budget, return est_cost_usd: 0 and the system will skip the purchase.';

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
