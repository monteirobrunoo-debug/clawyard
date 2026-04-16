<?php

namespace App\Services;

use App\Models\SharedContext;
use Illuminate\Support\Facades\Log;

/**
 * SharedContextService — PSI-inspired Shared Context Bus with Reconciliation
 *
 * Agents publish their key findings here. Every agent reads this bus before
 * responding, enabling cross-agent intelligence without direct coupling.
 *
 * New in v2: Context Reconciliation Engine
 *   - Each new entry is compared to the agent's previous entry
 *   - Classified as: 'new' | 'confirmed' | 'updated' | 'contradicted'
 *   - Change indicators shown in the system prompt block so agents know
 *     when data has evolved, avoiding stale assumptions
 *
 * Inspired by: "PSI: Shared State as the Missing Layer for Coherent
 * AI-Generated Instruments in Personal AI Agents" (arXiv:2604.08529)
 */
class SharedContextService
{
    /** Max characters stored per summary */
    private const MAX_SUMMARY_CHARS = 700;

    /** Max entries kept per agent (oldest deleted when exceeded) */
    private const MAX_PER_AGENT = 3;

    /** Default TTL in hours */
    private const TTL_HOURS = 8;

    /** Max entries returned for the system prompt block */
    private const MAX_FOR_PROMPT = 8;

    // Reconciliation thresholds
    private const SIMILARITY_CONFIRMED    = 0.78; // ≥78% → confirmed (same info)
    private const SIMILARITY_UPDATED      = 0.35; // 35–78% → updated (evolved)
    // Below 35% = contradicted or major shift

    /**
     * Publish agent findings to the shared context bus.
     * Compares with the agent's previous entry and classifies the change type.
     */
    public function publish(
        string $agentKey,
        string $agentName,
        string $contextKey,
        string $rawContent,
        array  $tags = []
    ): void {
        try {
            $summary = $this->extractSummary($rawContent);
            if (strlen($summary) < 30) return;

            // Reconcile with previous entry from this agent
            $previous = SharedContext::where('agent_key', $agentKey)
                ->orderBy('created_at', 'desc')
                ->first();

            $reconciliation = $this->reconcile($summary, $previous?->summary);

            // Enforce max entries per agent
            $count = SharedContext::where('agent_key', $agentKey)->count();
            if ($count >= self::MAX_PER_AGENT) {
                SharedContext::where('agent_key', $agentKey)
                    ->orderBy('created_at')
                    ->limit($count - self::MAX_PER_AGENT + 1)
                    ->delete();
            }

            SharedContext::create([
                'agent_key'        => $agentKey,
                'agent_name'       => $agentName,
                'context_key'      => $contextKey,
                'summary'          => $summary,
                'tags'             => $tags,
                'expires_at'       => now()->addHours(self::TTL_HOURS),
                'change_type'      => $reconciliation['type'],
                'similarity_score' => $reconciliation['score'],
                'change_note'      => $reconciliation['note'],
                'previous_summary' => $previous?->summary,
            ]);
        } catch (\Throwable $e) {
            Log::warning("SharedContextService: publish failed for {$agentKey} — " . $e->getMessage());
        }
    }

    /**
     * Build a formatted block of recent shared context for injection into
     * an agent's system prompt. Optionally filtered by relevance to $query.
     * Includes reconciliation indicators so agents know when data has changed.
     */
    public function getContextBlock(?string $query = null, ?string $currentAgent = null): string
    {
        try {
            $query_obj = SharedContext::active()->orderBy('created_at', 'desc');

            // Block SAP intel from leaking via the shared bus on external share links
            if (config('app.sap_access_blocked', false)) {
                $query_obj->where('agent_key', '!=', 'sap');
            }

            $entries = $query_obj->limit(self::MAX_FOR_PROMPT)->get();

            if ($entries->isEmpty()) return '';

            // Relevance scoring: tag match + recency + exclude self
            if ($query || $currentAgent) {
                $lower = $query ? strtolower($query) : '';
                $entries = $entries
                    ->filter(fn($e) => $e->agent_key !== $currentAgent)
                    ->sortByDesc(function ($entry) use ($lower) {
                        $tags  = $entry->tags ?? [];
                        $score = count(array_filter($tags, fn($t) => $lower && str_contains($lower, strtolower($t))));
                        if ($entry->created_at->diffInMinutes(now()) < 30) $score += 2;
                        // Prioritise changes and updates — agents should notice them
                        if (in_array($entry->change_type, ['updated', 'contradicted'])) $score += 1;
                        return $score;
                    })
                    ->take(6);
            }

            if ($entries->isEmpty()) return '';

            $lines = ['', '╔══ INTEL DOS AGENTES ══╗'];
            foreach ($entries as $entry) {
                $mins   = (int) $entry->created_at->diffInMinutes(now());
                $ageStr = $mins === 0 ? 'agora' : ($mins < 60 ? "{$mins}m" : round($mins / 60) . 'h');
                $badge  = $this->changeBadge($entry->change_type, $entry->change_note);
                $lines[] = "│ [{$ageStr}]{$badge} {$entry->agent_name}: {$entry->summary}";
            }
            $lines[] = '╚═══════════════════════╝';
            $lines[] = 'Contexto de outros agentes. Usa quando relevante — não cites directamente salvo pedido.';
            $lines[] = 'Indicadores: [✓] confirmado  [↑] actualizado  [⚠] contradição detectada  [•] novo';
            $lines[] = '';

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning('SharedContextService: getContextBlock failed — ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Delete all expired entries.
     */
    public function pruneExpired(): void
    {
        SharedContext::where('expires_at', '<', now())->delete();
    }

    // ── Reconciliation Engine ────────────────────────────────────────────────

    /**
     * Compare a new summary to the agent's previous summary.
     * Returns: ['type' => string, 'score' => float|null, 'note' => string|null]
     *
     * Types:
     *   'new'          — no previous entry exists
     *   'confirmed'    — high similarity, same facts
     *   'updated'      — moderate change, data evolved
     *   'contradicted' — major divergence, possible contradiction
     */
    private function reconcile(string $newSummary, ?string $prevSummary): array
    {
        if (!$prevSummary || strlen(trim($prevSummary)) < 20) {
            return ['type' => 'new', 'score' => null, 'note' => null];
        }

        $score = $this->textSimilarity($newSummary, $prevSummary);

        // Extract numbers from both for change detection
        $numChanges = $this->detectNumericChanges($newSummary, $prevSummary);

        // Classify
        if ($score >= self::SIMILARITY_CONFIRMED && empty($numChanges)) {
            return [
                'type'  => 'confirmed',
                'score' => $score,
                'note'  => null,
            ];
        }

        if ($score >= self::SIMILARITY_CONFIRMED && !empty($numChanges)) {
            // Same narrative but numbers changed → update
            return [
                'type'  => 'updated',
                'score' => $score,
                'note'  => $this->buildNumericNote($numChanges),
            ];
        }

        if ($score >= self::SIMILARITY_UPDATED) {
            $note = !empty($numChanges)
                ? $this->buildNumericNote($numChanges)
                : 'Conteúdo actualizado.';
            return [
                'type'  => 'updated',
                'score' => $score,
                'note'  => $note,
            ];
        }

        // Low similarity → contradicted or major update
        $note = !empty($numChanges)
            ? 'Valores alterados: ' . $this->buildNumericNote($numChanges)
            : 'Conteúdo substancialmente diferente da versão anterior.';

        return [
            'type'  => 'contradicted',
            'score' => $score,
            'note'  => $note,
        ];
    }

    /**
     * Normalised text similarity (0.0–1.0) using PHP's similar_text().
     * Runs on lowercased, whitespace-collapsed strings.
     */
    private function textSimilarity(string $a, string $b): float
    {
        $a = $this->normaliseForCompare($a);
        $b = $this->normaliseForCompare($b);

        if (!$a || !$b) return 0.0;

        // Use smaller sample for speed on long texts
        $a = substr($a, 0, 500);
        $b = substr($b, 0, 500);

        similar_text($a, $b, $pct);
        return round($pct / 100, 4);
    }

    /**
     * Extract numbers (integers and floats) from both texts and report changed values.
     * Returns array of ['old' => x, 'new' => y] pairs where values differ.
     */
    private function detectNumericChanges(string $newText, string $oldText): array
    {
        $extractNums = function (string $text): array {
            // Match numbers: optionally prefixed by € $ £
            // Handles both PT format (145.000,50) and EN format (145,000.50)
            preg_match_all('/[\€\$\£]?\s*\d[\d\s.,]*\d|\b\d{2,}\b/', $text, $m);
            return array_filter(array_map(function (string $n): float {
                $n = trim($n, " \t€\$£");
                // PT format: 145.000,50 → detect by trailing ,XX
                if (preg_match('/\.\d{3}(,\d+)?$/', $n)) {
                    $n = str_replace('.', '', $n); // remove thousand sep
                    $n = str_replace(',', '.', $n); // decimal sep
                } else {
                    // EN format: 145,000
                    $n = str_replace(',', '', $n);
                }
                return (float) $n;
            }, $m[0]), fn($v) => $v > 0);
        };

        $oldNums = $extractNums($oldText);
        $newNums = $extractNums($newText);

        if (empty($oldNums) || empty($newNums)) return [];

        $changes = [];
        $compared = min(count($oldNums), count($newNums), 5); // compare up to 5 numbers

        for ($i = 0; $i < $compared; $i++) {
            $old = $oldNums[$i];
            $new = $newNums[$i];
            if ($old == 0 && $new == 0) continue;
            $maxVal = max(abs($old), abs($new));
            if ($maxVal == 0) continue;
            $pctChange = abs($new - $old) / $maxVal;
            if ($pctChange > 0.05) { // >5% change = meaningful
                $changes[] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * Build a human-readable note from numeric changes.
     */
    private function buildNumericNote(array $numChanges): string
    {
        $parts = [];
        foreach (array_slice($numChanges, 0, 3) as $c) {
            $old = $this->formatNum($c['old']);
            $new = $this->formatNum($c['new']);
            $dir = $c['new'] > $c['old'] ? '↑' : '↓';
            $parts[] = "{$dir} {$old}→{$new}";
        }
        return implode(', ', $parts);
    }

    private function formatNum(float $n): string
    {
        if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
        if ($n >= 1_000)     return round($n / 1_000, 1) . 'K';
        return (string) round($n, 2);
    }

    /**
     * Return the emoji badge for a change type, with optional note in parens.
     */
    private function changeBadge(string $changeType, ?string $note): string
    {
        return match ($changeType) {
            'confirmed'    => ' [✓]',
            'updated'      => ' [↑' . ($note ? " {$note}" : '') . ']',
            'contradicted' => ' [⚠' . ($note ? " {$note}" : '') . ']',
            default        => ' [•]', // new
        };
    }

    // ── Text helpers ─────────────────────────────────────────────────────────

    private function normaliseForCompare(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/[^\w\s\d.,€$%]/u', '', $text);
        return trim($text);
    }

    /**
     * Extract a concise summary from a full agent response.
     * Strips markdown bloat and keeps key facts.
     */
    private function extractSummary(string $content): string
    {
        $clean = preg_replace('/^#{1,4}\s+/m', '', $content);
        $clean = preg_replace('/\*{2,}([^*]+)\*{2,}/', '$1', $clean);
        $clean = preg_replace('/`[^`]+`/', '', $clean);
        $clean = preg_replace('/```[\s\S]*?```/', '', $clean);
        $clean = preg_replace('/\|[^\n]+\|/', '', $clean);
        $clean = preg_replace('/\n{3,}/', "\n", $clean);
        $clean = trim($clean);

        if (strlen($clean) <= self::MAX_SUMMARY_CHARS) return $clean;

        $truncated  = substr($clean, 0, self::MAX_SUMMARY_CHARS);
        $lastPeriod = strrpos($truncated, '.');
        if ($lastPeriod > 150) return substr($truncated, 0, $lastPeriod + 1);

        return $truncated . '…';
    }
}
