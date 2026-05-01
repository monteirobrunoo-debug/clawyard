<?php

namespace App\Services\AgentSwarm;

use App\Models\LeadOpportunity;
use Illuminate\Support\Facades\DB;

/**
 * Closed-loop learning helper for the agent swarm.
 *
 * Inspired by Hernandez et al. (PeerJ Comp Sci, 2026) — autonomous
 * agents learn primarily via reinforcement, and the dominant approach
 * is to feed past outcomes back as context for new decisions
 * ("aspect-based" + "progressive" learning).
 *
 * What this does:
 *   • Given a fresh signal (typically a Tender or partial lead),
 *     finds the top-K most similar past LeadOpportunity rows that
 *     ended in a terminal state (won / lost / discarded).
 *   • Formats them as compact "PRECEDENTS" injected into the
 *     synthesis-agent prompt. The model sees: "leads parecidos a
 *     este: 1× ganho com Marco→Marina→Daniel; 2× perdidos pq
 *     deadline curto demais" and adjusts its score / recommendation.
 *
 * Heuristic similarity (no vectors yet — keep it cheap):
 *   • Same source_signal_type weights heavily (NSPA winners predict
 *     other NSPA winners better than mixed sources)
 *   • Substring overlap on customer_hint + equipment_hint
 *   • Recency tiebreaker (last 12 months >> older)
 *
 * Returns up to maxExamples precedents — split roughly 50/50 between
 * wins (positive examples) and losses (negative examples) so the
 * model can calibrate both directions.
 */
class LeadPrecedentRetriever
{
    public const TERMINAL_STATUSES = [
        LeadOpportunity::STATUS_WON,
        LeadOpportunity::STATUS_LOST,
        LeadOpportunity::STATUS_DISCARDED,
    ];

    /**
     * @param array $signal  the new signal blob (must have at least
     *                       source_signal_type; customer_hint and
     *                       equipment_hint help similarity ranking)
     * @return array<int,array{
     *   id:int, status:string, score:int, title:string, summary:string,
     *   source_signal_type:string, customer_hint:?string,
     *   equipment_hint:?string, age_days:int
     * }>
     */
    public function fetch(array $signal, int $maxExamples = 5): array
    {
        $source   = (string) ($signal['source_signal_type'] ?? $signal['source'] ?? '');
        $customer = mb_strtolower(trim((string) ($signal['customer_hint'] ?? $signal['purchasing_org'] ?? '')));
        $equipment= mb_strtolower(trim((string) ($signal['equipment_hint'] ?? $signal['title'] ?? '')));

        $query = LeadOpportunity::query()
            ->whereIn('status', self::TERMINAL_STATUSES);

        // Same-source bias: when the signal has a known source, only
        // pull from same source. Falls back to all sources when empty
        // (orphans like manual leads).
        if ($source !== '') {
            $query->where('source_signal_type', $source);
        }

        // Pull a wider net (3× target) so the in-PHP scorer has room
        // to rank and pick the best matches.
        $candidates = $query
            ->orderByDesc('updated_at')
            ->limit($maxExamples * 3)
            ->get();

        if ($candidates->isEmpty()) return [];

        // Score each candidate by string overlap on customer/equipment
        // hints + a recency boost. Pure-PHP, no DB cost.
        $scored = $candidates->map(function (LeadOpportunity $l) use ($customer, $equipment) {
            $score = 0;
            $candCustomer = mb_strtolower((string) $l->customer_hint);
            $candEquipment = mb_strtolower((string) $l->equipment_hint);

            if ($customer !== '' && $candCustomer !== '') {
                if ($candCustomer === $customer) $score += 30;
                elseif (str_contains($candCustomer, $customer) || str_contains($customer, $candCustomer)) $score += 15;
            }

            if ($equipment !== '' && $candEquipment !== '') {
                // Word-overlap rather than substring: equipment hints
                // are noisy (e.g. "MTU 4000 spares" vs "spares MTU
                // engines"). Count common ≥4-char tokens.
                $a = preg_split('/\s+/', $equipment) ?: [];
                $b = preg_split('/\s+/', $candEquipment) ?: [];
                $a = array_filter($a, fn($w) => mb_strlen($w) >= 4);
                $b = array_filter($b, fn($w) => mb_strlen($w) >= 4);
                $shared = count(array_intersect($a, $b));
                $score += min($shared * 6, 30);
            }

            // Recency: leads in last 90d → +10, last 365d → +5.
            $ageDays = $l->updated_at->diffInDays(now());
            if ($ageDays <= 90)  $score += 10;
            elseif ($ageDays <= 365) $score += 5;

            // Won leads weighted slightly above lost — positive signal
            // is harder to come by and more informative for the model.
            if ($l->status === LeadOpportunity::STATUS_WON) $score += 5;

            return [
                'lead'  => $l,
                'score' => $score,
                'age'   => (int) $ageDays,
            ];
        })
        ->sortByDesc('score')
        ->values();

        // Pick a balanced set: alternate won + lost when possible.
        $wins   = $scored->where('lead.status', LeadOpportunity::STATUS_WON)->take(intval(ceil($maxExamples / 2)));
        $losses = $scored->whereIn('lead.status', [LeadOpportunity::STATUS_LOST, LeadOpportunity::STATUS_DISCARDED])
                         ->take($maxExamples - $wins->count());

        $picked = $wins->concat($losses)->values();

        return $picked->map(fn($e) => [
            'id'                 => $e['lead']->id,
            'status'             => $e['lead']->status,
            'score'              => (int) $e['lead']->score,
            'title'              => mb_substr((string) $e['lead']->title, 0, 120),
            'summary'            => mb_substr((string) $e['lead']->summary, 0, 280),
            'source_signal_type' => (string) $e['lead']->source_signal_type,
            'customer_hint'      => $e['lead']->customer_hint,
            'equipment_hint'     => $e['lead']->equipment_hint,
            'age_days'           => $e['age'],
        ])->all();
    }

    /**
     * Format precedents as a compact text block suitable for prompt
     * injection. Empty when no precedents found.
     */
    public function formatForPrompt(array $precedents): string
    {
        if (empty($precedents)) return '';

        $lines = [];
        $lines[] = "PRECEDENTS — past similar leads and their outcomes (newest first):";
        $lines[] = "Use these as calibration: do NOT copy verbatim, but adjust your score";
        $lines[] = "and recommendation based on what worked / failed before.";
        $lines[] = "";

        foreach ($precedents as $i => $p) {
            $emoji = match ($p['status']) {
                LeadOpportunity::STATUS_WON       => '✅ WON',
                LeadOpportunity::STATUS_LOST      => '❌ LOST',
                LeadOpportunity::STATUS_DISCARDED => '⚪ DISCARDED',
                default => '·',
            };
            $custBit = $p['customer_hint']  ? " · {$p['customer_hint']}"  : '';
            $eqBit   = $p['equipment_hint'] ? " · {$p['equipment_hint']}" : '';
            $lines[] = ($i + 1) . ". {$emoji} (score={$p['score']}, {$p['age_days']}d ago{$custBit}{$eqBit})";
            $lines[] = "   {$p['title']}";
            if (!empty($p['summary'])) $lines[] = "   → " . $p['summary'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
