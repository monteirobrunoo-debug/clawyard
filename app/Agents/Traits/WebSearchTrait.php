<?php

namespace App\Agents\Traits;

use App\Services\WebSearchService;

trait WebSearchTrait
{
    // NOTE: $webSearchKeywords and $searchPolicy are intentionally NOT declared here.
    // Each agent declares its own. This avoids PHP 8.4 fatal errors for conflicting
    // trait/class property definitions.
    //
    // searchPolicy values (HDPO-inspired meta-cognitive tool use):
    //   'always'      → always search (web-specialist agents: RoboDesk, VesselSearch, Research)
    //   'never'       → never search (knowledge-only agents: Kyber, Orchestrator)
    //   'conditional' → keyword-gated search (default for most agents, incl. Batch)
    //
    // Inspired by: "Act Wisely: Cultivating Meta-Cognitive Tool Use in
    // Agentic Multimodal Models" (arXiv:2604.08545) — HDPO framework

    /**
     * Meta-cognitive search gate.
     * Respects $searchPolicy: 'always' | 'never' | 'conditional' (default).
     * Replaces direct calls to needsWebSearch() for policy-aware agents.
     */
    protected function shouldUseWebSearch(string|array $message): bool
    {
        $policy = property_exists($this, 'searchPolicy') ? $this->searchPolicy : 'conditional';

        return match($policy) {
            'always'  => (new WebSearchService())->isAvailable(),
            'never'   => false,
            default   => $this->needsWebSearch($message), // conditional: keyword gate
        };
    }

    /**
     * Smart augment: only searches if shouldUseWebSearch() returns true.
     * Prefer this over augmentWithWebSearch() for policy-aware agents.
     */
    protected function smartAugment(string|array $message, ?callable $heartbeat = null): string|array
    {
        if (!$this->shouldUseWebSearch($message)) return $message;
        return $this->augmentWithWebSearch($message, $heartbeat);
    }

    /**
     * Append a string suffix to a string|array message.
     * For arrays the suffix is added to the text block; the rest (PDF/image) is kept intact.
     */
    protected function appendToMessage(string|array $message, string $suffix): string|array
    {
        if (is_string($message)) return $message . $suffix;

        $result = $message;
        foreach ($result as $i => $block) {
            if (($block['type'] ?? '') === 'text') {
                $result[$i]['text'] = ($block['text'] ?? '') . $suffix;
                return $result;
            }
        }
        // No text block found — create one
        return array_merge([['type' => 'text', 'text' => $suffix]], $message);
    }

    /**
     * Extract the plain-text content from a string|array message.
     * Used for keyword matching, SAP context queries, etc.
     */
    protected function messageText(string|array $message): string
    {
        if (is_string($message)) return $message;
        return implode(' ', array_map(fn($b) => $b['text'] ?? '', $message));
    }

    /**
     * Default keywords — used when the class does not define $webSearchKeywords.
     */
    private function defaultWebSearchKeywords(): array
    {
        return [
            // Portuguese — explicit web/internet mentions (short forms too!)
            'web', 'internet', 'online', 'na net', 'na rede',
            'pesquisa', 'pesquisa na web', 'procura', 'procura na internet', 'busca online', 'busca',
            'vê na web', 'vê na internet', 've na web', 've na internet',
            'verifica', 'confere', 'consulta', 'investiga',
            // Portuguese — topicality
            'notícias', 'noticias', 'hoje', 'atual', 'atualidade', 'recente', 'última hora',
            'o que é', 'quem é', 'quando foi', 'onde fica', 'quanto custa',
            'preço de', 'preco de', 'cotação de', 'cotacao de',
            'concorrente', 'empresa', 'site', 'website',
            'mercado', 'tendência', 'tendencia',
            // English
            'search', 'look up', 'find', 'what is', 'who is', 'latest', 'current',
            'news', 'price of', 'cost of', 'how much', 'recent', 'today',
            'competitor', 'market', 'trend', 'company info',
            'check the web', 'check online', 'browse',
            // Explicit triggers
            'web:', 'search:', 'pesquisa:', 'google',
        ];
    }

    /**
     * Check if the message likely needs a web search
     */
    protected function needsWebSearch(string|array $message): bool
    {
        if (!(new WebSearchService())->isAvailable()) return false;

        $text  = is_array($message)
            ? implode(' ', array_map(fn($b) => $b['text'] ?? '', $message))
            : $message;
        $lower = strtolower($text);

        // Explicit trigger: starts with "web:" or "search:"
        if (str_starts_with($lower, 'web:') || str_starts_with($lower, 'search:') || str_starts_with($lower, 'pesquisa:')) {
            return true;
        }

        $keywords = property_exists($this, 'webSearchKeywords')
            ? $this->webSearchKeywords
            : $this->defaultWebSearchKeywords();

        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }

        return false;
    }

    /**
     * Extract search query from message (accepts string or multimodal array)
     */
    protected function extractSearchQuery(string|array $message): string
    {
        $message = is_array($message)
            ? implode(' ', array_map(fn($b) => $b['text'] ?? '', $message))
            : $message;
        $lower = strtolower($message);

        // Explicit prefix: "web: query" or "search: query"
        foreach (['web:', 'search:', 'pesquisa:'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return trim(substr($message, strlen($prefix)));
            }
        }

        // Use the full message as query (Tavily handles natural language)
        return trim($message);
    }

    /**
     * Augment message with web search results — always searches (no keyword gate).
     * Accepts string OR multimodal array (e.g. [{type:text,...},{type:document,...}]).
     * When an array is passed the search query is extracted from the first text block
     * and the results are appended to that text block; the rest (PDF/image) is preserved.
     *
     * @param  string|array $message
     * @return string|array  same type as input
     */
    protected function augmentWithWebSearch(string|array $message, ?callable $heartbeat = null): string|array
    {
        if (!(new WebSearchService())->isAvailable()) return $message;

        // --- extract plain-text portion for the query -------------------------
        $isArray   = is_array($message);
        $textIndex = 0; // which array entry holds the text block (default: 0)

        if ($isArray) {
            // Find first block with type==='text'
            $textPart = '';
            foreach ($message as $i => $block) {
                if (($block['type'] ?? '') === 'text') {
                    $textPart  = $block['text'] ?? '';
                    $textIndex = $i;
                    break;
                }
            }
        } else {
            $textPart = $message;
        }
        // ----------------------------------------------------------------------

        try {
            if ($heartbeat) $heartbeat('searching web');

            $searcher = new WebSearchService();
            $query    = $this->extractSearchQuery($textPart);
            $results  = $searcher->search($query, 5);

            if (str_starts_with($results, '(')) {
                // Error or unavailable — return original unchanged
                return $message;
            }

            $suffix = "\n\n---\n**WEB SEARCH RESULTS** (searched: \"{$query}\"):\n{$results}\n---\n\nPlease use the above web search results to answer accurately. Cite sources when relevant.";

            if ($isArray) {
                // Append suffix to the text block, leave other blocks intact
                $message[$textIndex]['text'] = $textPart . $suffix;
                return $message;
            }

            return $message . $suffix;

        } catch (\Throwable $e) {
            \Log::warning('WebSearchTrait: search failed — ' . $e->getMessage());
            return $message;
        }
    }
}
