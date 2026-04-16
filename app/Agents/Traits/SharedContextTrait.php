<?php

namespace App\Agents\Traits;

use App\Services\SharedContextService;

/**
 * SharedContextTrait — PSI Shared Context Bus for ClawYard Agents
 *
 * Enables agents to:
 *  1. READ: inject recent findings from other agents into their system prompt
 *  2. WRITE: publish their own key findings to the shared bus after responding
 *
 * Each agent that uses this trait may define:
 *   protected string $contextKey  = '';     // empty = don't publish
 *   protected array  $contextTags = [];     // relevance keywords for filtering
 *
 * Inspired by: "PSI: Shared State as the Missing Layer for Coherent
 * AI-Generated Instruments in Personal AI Agents" (arXiv:2604.08529)
 */
trait SharedContextTrait
{
    // NOTE: $contextKey and $contextTags are intentionally NOT declared here.
    // Each agent declares its own. Agents without them simply don't publish.

    /**
     * Enrich a base system prompt with shared context from the bus.
     * Call this in stream()/chat() instead of using $this->systemPrompt directly.
     *
     * Usage: 'system' => $this->enrichSystemPrompt($this->systemPrompt)
     */
    protected function enrichSystemPrompt(string $basePrompt, ?string $query = null): string
    {
        $agentKey = method_exists($this, 'getName') ? $this->getName() : null;
        $block = (new SharedContextService())->getContextBlock($query, $agentKey);
        if (!$block) return $basePrompt;
        return $basePrompt . "\n" . $block;
    }

    /**
     * Publish key findings from this agent to the shared context bus.
     * Call this at the END of stream()/chat() when $this->contextKey is set.
     *
     * Usage: $this->publishSharedContext($fullResponse);
     */
    protected function publishSharedContext(string $fullResponse): void
    {
        $contextKey = property_exists($this, 'contextKey') ? $this->contextKey : '';
        if (!$contextKey) return;

        $tags      = property_exists($this, 'contextTags') ? $this->contextTags : [];
        $agentKey  = method_exists($this, 'getName') ? $this->getName() : 'unknown';
        $agentName = $this->getAgentDisplayName();

        (new SharedContextService())->publish(
            $agentKey,
            $agentName,
            $contextKey,
            $fullResponse,
            $tags
        );
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function getAgentDisplayName(): string
    {
        // Try AgentShare meta first
        if (method_exists('\App\Models\AgentShare', 'agentMeta')) {
            $key  = method_exists($this, 'getName') ? $this->getName() : '';
            $meta = \App\Models\AgentShare::agentMeta();
            if (isset($meta[$key]['name'])) return $meta[$key]['name'];
        }
        return method_exists($this, 'getName') ? $this->getName() : 'Agent';
    }
}
