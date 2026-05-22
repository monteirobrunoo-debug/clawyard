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
     *
     * 2026-05-22 — extended to also inject:
     *   1. Priority Matrix (Bornet 2025 Cap 5 — Conflict Competency Gap):
     *      explicit rules para quando objectivos conflitam (segurança >
     *      rapidez > preço, etc). Reduz "decision paralysis" e "false
     *      resolution" — quando o LLM finge resolver o conflito mas
     *      viola um constraint subtilmente.
     *   2. Cost-awareness hint quando token pool ≥ alert threshold —
     *      auto-defesa do pool partilhado: agentes ficam mais concisos
     *      automaticamente quando o pool €150/mês está perto do limite.
     *
     * Falha silenciosa em qualquer extensão para não quebrar o agent
     * se o TokenBudgetService ou DB ainda não estiverem prontos.
     */
    protected function enrichSystemPrompt(string $basePrompt, ?string $query = null): string
    {
        $agentKey = method_exists($this, 'getName') ? $this->getName() : null;
        $userId   = $this->sharedContextUserId();
        $block    = (new SharedContextService())->getContextBlock($query, $agentKey, $userId);

        $out = $block ? ($basePrompt . "\n" . $block) : $basePrompt;
        $out .= $this->priorityMatrixBlock();
        $out .= $this->costAwarenessBlock();
        return $out;
    }

    /**
     * Priority Matrix — Bornet 2025, Cap 5.
     *
     * Resolve goal conflicts de forma determinística: quando 2+
     * objectivos conflitam, a ordem aqui dita a decisão. Em casos
     * ambíguos: ESCALAR ao operador humano em vez de decidir mal.
     */
    private function priorityMatrixBlock(): string
    {
        return "\n\n--- PRIORITY MATRIX (em caso de conflito de objectivos) ---\n"
             . "1. Segurança / compliance / confidencialidade > tudo o resto\n"
             . "2. Precisão > brevidade — melhor pedir mais info ao user do que adivinhar\n"
             . "3. Fontes citadas (livros, tender attachments, web search) > conhecimento geral\n"
             . "4. PartYard/HP-Group constraints (sem CN/RU, mil-def confidencial) são hard rules\n"
             . "5. Em dúvida → PERGUNTA ao user em vez de assumires. Nunca inventes IDs SAP,\n"
             . "   NSNs, preços ou contactos. Falha gracioso é melhor que falsa confiança.\n"
             . "--- FIM PRIORITY MATRIX ---";
    }

    /**
     * Cost-awareness — só dispara quando o pool token está em zona de
     * alerta (default ≥ 80%). Pede aos agentes para serem mais
     * concisos automaticamente. Quando o pool está saudável (< 80%),
     * devolve string vazia (zero overhead).
     *
     * 2026-05-22: cache Redis 60s — summary() faz 3 SUM queries pesadas
     * (messages, agent_runs, tender_service_analyses). Sem cache,
     * corre por CADA chat call → 2-3s extra de latência + risco de
     * nginx timeout em SSE. Cache 60s é frequente o suficiente para
     * apanhar transição de threshold sem matar performance.
     */
    private function costAwarenessBlock(): string
    {
        try {
            $cacheKey = 'token_cost_awareness:v1';
            $summary = \Illuminate\Support\Facades\Cache::remember(
                $cacheKey,
                60,
                fn () => app(\App\Services\TokenBudgetService::class)->summary()
            );
            if (!$summary['is_alert']) return '';

            if ($summary['is_exhausted']) {
                return "\n\n--- ⚠️ TOKEN POOL ESGOTADO ---\n"
                     . "O pool partilhado deste mês (€{$summary['pool_eur']}) foi atingido a "
                     . "{$summary['percent_used']}%. Sê EXTREMAMENTE conciso. Responde em\n"
                     . "1-3 frases curtas. Não uses web_search nem book_search a menos que\n"
                     . "absolutamente crítico. Se a pergunta é complexa, pede ao user para\n"
                     . "esperar até ao próximo período ou contactar admin para subir pool.\n"
                     . "--- FIM ---";
            }

            return "\n\n--- 💰 TOKEN POOL EM ALERTA ({$summary['percent_used']}%) ---\n"
                 . "O pool partilhado deste mês está em {$summary['percent_used']}% (alerta a {$summary['alert_at']}%).\n"
                 . "Sê conciso. Prefere respostas curtas e directas. Usa tools externas\n"
                 . "(web_search, nsn_lookup) só quando claramente necessário — cada call\n"
                 . "extra adiciona custo ao pool partilhado.\n"
                 . "--- FIM ---";
        } catch (\Throwable) {
            return '';  // service não disponível → continua sem o block
        }
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
        $userId    = $this->sharedContextUserId();

        (new SharedContextService())->publish(
            $agentKey,
            $agentName,
            $contextKey,
            $fullResponse,
            $tags,
            $userId
        );
    }

    /**
     * Determine the user that owns bus activity for this request.
     * Uses the authenticated web user if available. Falls back to null
     * for system publishes (queues, CLI, scheduled briefings).
     */
    private function sharedContextUserId(): ?int
    {
        try {
            return \Illuminate\Support\Facades\Auth::id();
        } catch (\Throwable $e) {
            return null;
        }
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
