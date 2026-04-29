<?php

namespace App\Services\Robotparts;

use App\Models\PartOrder;
use App\Services\AgentCatalog;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Peer review service. Picks 2 reviewer agents (different from buyer
 * and from the committee helpers) and asks each to validate the
 * purchase. Each reviewer emits:
 *
 *   { verdict: 'approve' | 'concern', note: '<1 sentence>' }
 *
 * Results are appended to part_orders.validations (json array).
 *
 * Why peer review:
 *   • The buyer's persona biases the choice — review from a different
 *     persona catches over-spending or wrong-fit picks.
 *   • Forms a checkbalance: even if budget guards let a marginal
 *     purchase through, the validation surfaces concerns to the
 *     human operator via the marketplace badges.
 *   • Lays the foundation for the broader robot research council
 *     (Phase B) where agents discuss the architecture as a whole.
 *
 * Failure mode: NEVER blocks. Reviewer dispatch failure → review is
 * skipped (no row added). The order's status is unaffected by review
 * outcomes — humans use the validation signal as a hint, not a gate.
 */
class PartValidationService
{
    /** Minimum reviewers we want per order. */
    public const TARGET_REVIEWERS = 2;

    /** Agents excluded from review (meta-agents without a specific persona). */
    private const META_AGENTS = ['orchestrator', 'auto', 'briefing', 'thinking', 'claude'];

    public function __construct(private AgentDispatcher $dispatcher) {}

    /**
     * Run the peer review for a single PartOrder. Idempotent: if the
     * order already has TARGET_REVIEWERS or more reviews, returns
     * early. Returns the order with updated validations.
     */
    public function review(PartOrder $order): PartOrder
    {
        $existing = (array) ($order->validations ?? []);
        if (count($existing) >= self::TARGET_REVIEWERS) {
            return $order;
        }

        $reviewers = $this->pickReviewers($order, count: self::TARGET_REVIEWERS - count($existing));
        $newReviews = [];

        foreach ($reviewers as $reviewerKey) {
            $review = $this->askReviewer($reviewerKey, $order);
            if ($review !== null) {
                $newReviews[] = $review;
            }
        }

        if (!empty($newReviews)) {
            $order->validations = array_merge($existing, $newReviews);
            $order->save();
        }

        return $order;
    }

    /**
     * Pick N reviewer agents from the catalogue, excluding the buyer
     * and any helpers already in the committee_log (no double-voting
     * by the same persona).
     */
    private function pickReviewers(PartOrder $order, int $count): array
    {
        $exclude = [$order->agent_key];
        foreach ((array) ($order->committee_log ?? []) as $msg) {
            if (!empty($msg['agent_key'])) $exclude[] = $msg['agent_key'];
        }
        foreach ((array) ($order->validations ?? []) as $v) {
            if (!empty($v['agent_key'])) $exclude[] = $v['agent_key'];
        }

        $candidates = collect(AgentCatalog::all())
            ->pluck('key')
            ->reject(fn($k) => in_array($k, $exclude, true) || in_array($k, self::META_AGENTS, true))
            ->values();

        return $candidates->shuffle()->take($count)->values()->all();
    }

    /**
     * Ask one reviewer for their verdict. Returns:
     *   { agent_key, role: 'reviewer', verdict: 'approve' | 'concern',
     *     note: '<sentence>', at: ISO-8601 }
     * or null on dispatch / parse failure.
     */
    private function askReviewer(string $reviewerKey, PartOrder $order): ?array
    {
        $reviewerMeta = AgentCatalog::find($reviewerKey);
        if (!$reviewerMeta) return null;

        $slotMeta = $order->slot ? RobotBlueprint::find($order->slot) : null;
        $slotContext = $slotMeta
            ? "\nSlot: {$slotMeta['emoji']} {$slotMeta['label']} — {$slotMeta['purpose']}\nTipos típicos: {$slotMeta['typical_parts']}"
            : '';

        $system = "You are {$reviewerMeta['name']} ({$reviewerMeta['role']}). "
                . "A colleague just bought a robot body part. Peer-review the choice. "
                . "Be honest — your job is to flag bad picks and rubber-stamp good ones.\n\n"
                . "Output STRICT JSON only — no markdown fences:\n"
                . '{ "verdict": "approve" | "concern", "note": "<1 sentence in PT-pt, ≤120 chars>" }' . "\n\n"
                . "Use 'concern' if: cost seems too high for the part, slot mismatch, "
                . "obvious cheaper alternative exists, dubious vendor. "
                . "Use 'approve' if: cost is reasonable, fits slot, vendor is credible.";

        $user = "Compra a rever:\n"
              . "  Peça:    {$order->name}\n"
              . "  Preço:   \${$order->cost_usd}\n"
              . "  Source:  " . ($order->source_url ?: '(no URL)') . "\n"
              . "  Compradora: " . (AgentCatalog::find($order->agent_key)['name'] ?? $order->agent_key)
              . $slotContext
              . "\n\nDá o teu verdict. JSON apenas.";

        $res = $this->dispatcher->dispatch($system, $user, maxTokens: 300);
        if (!($res['ok'] ?? false)) {
            Log::warning('PartValidationService: reviewer dispatch failed', [
                'reviewer' => $reviewerKey,
                'order_id' => $order->id,
                'error'    => $res['error'] ?? 'unknown',
            ]);
            return null;
        }

        $parsed = $this->parseJson((string) $res['text']);
        if ($parsed === null) return null;

        $verdict = strtolower((string) ($parsed['verdict'] ?? ''));
        if (!in_array($verdict, ['approve', 'concern'], true)) return null;

        return [
            'agent_key' => $reviewerKey,
            'role'      => 'reviewer',
            'verdict'   => $verdict,
            'note'      => mb_substr((string) ($parsed['note'] ?? ''), 0, 200),
            'at'        => now()->toIso8601String(),
        ];
    }

    /** Tolerant JSON parse — same as elsewhere in robotparts. */
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
