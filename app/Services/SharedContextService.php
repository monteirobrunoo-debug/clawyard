<?php

namespace App\Services;

use App\Models\SharedContext;
use Illuminate\Support\Facades\Log;

/**
 * SharedContextService — PSI-inspired Shared Context Bus
 *
 * Agents publish their key findings here. Every agent reads this bus before
 * responding, enabling cross-agent intelligence without direct coupling.
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

    /**
     * Publish agent findings to the shared context bus.
     *
     * @param  string   $agentKey    e.g. 'vessel'
     * @param  string   $agentName   e.g. 'Capitão Vasco'
     * @param  string   $contextKey  e.g. 'vessel_research'
     * @param  string   $rawContent  full response (will be truncated)
     * @param  array    $tags        relevance keywords
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
            if (strlen($summary) < 30) return; // too short to be useful

            // Enforce max entries per agent
            $count = SharedContext::where('agent_key', $agentKey)->count();
            if ($count >= self::MAX_PER_AGENT) {
                SharedContext::where('agent_key', $agentKey)
                    ->orderBy('created_at')
                    ->limit($count - self::MAX_PER_AGENT + 1)
                    ->delete();
            }

            SharedContext::create([
                'agent_key'   => $agentKey,
                'agent_name'  => $agentName,
                'context_key' => $contextKey,
                'summary'     => $summary,
                'tags'        => $tags,
                'expires_at'  => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (\Throwable $e) {
            Log::warning("SharedContextService: publish failed for {$agentKey} — " . $e->getMessage());
        }
    }

    /**
     * Build a formatted block of recent shared context for injection into
     * an agent's system prompt. Optionally filtered by relevance to $query.
     */
    public function getContextBlock(?string $query = null): string
    {
        try {
            $entries = SharedContext::active()
                ->orderBy('created_at', 'desc')
                ->limit(self::MAX_FOR_PROMPT)
                ->get();

            if ($entries->isEmpty()) return '';

            // Relevance filter: if query given, prefer entries whose tags match
            if ($query) {
                $lower = strtolower($query);
                $entries = $entries->sortByDesc(function ($entry) use ($lower) {
                    $tags = $entry->tags ?? [];
                    return count(array_filter($tags, fn($t) => str_contains($lower, strtolower($t))));
                });
            }

            $lines = ['', '╔══ INTEL PARTILHADA — OUTROS AGENTES ══╗'];
            foreach ($entries as $entry) {
                $time   = $entry->created_at->format('d/m H:i');
                $lines[] = "│ [{$time}] {$entry->agent_name}: {$entry->summary}";
            }
            $lines[] = '╚══════════════════════════════════════╝';
            $lines[] = 'Usa este contexto quando relevante. Não o cites directamente a não ser que o utilizador pergunte.';
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

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Extract a concise summary from a full agent response.
     * Strips markdown bloat and keeps key facts.
     */
    private function extractSummary(string $content): string
    {
        // Remove markdown headers and decorators
        $clean = preg_replace('/^#{1,4}\s+/m', '', $content);
        $clean = preg_replace('/\*{2,}([^*]+)\*{2,}/', '$1', $clean);
        $clean = preg_replace('/`[^`]+`/', '', $clean);
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
        $clean = trim($clean);

        // Take the first paragraph(s) up to MAX_SUMMARY_CHARS
        if (strlen($clean) <= self::MAX_SUMMARY_CHARS) return $clean;

        // Try to cut at sentence boundary
        $truncated = substr($clean, 0, self::MAX_SUMMARY_CHARS);
        $lastPeriod = strrpos($truncated, '.');
        if ($lastPeriod > 200) {
            return substr($truncated, 0, $lastPeriod + 1);
        }

        return $truncated . '…';
    }
}
