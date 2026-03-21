<?php

namespace App\Agents\Traits;

use App\Services\WebSearchService;

trait WebSearchTrait
{
    // NOTE: $webSearchKeywords is intentionally NOT declared here.
    // Each agent that uses this trait declares its own $webSearchKeywords array.
    // Agents that don't declare one get the defaults from needsWebSearch() below.
    // This avoids the PHP 8.4 fatal error for conflicting trait/class property definitions.

    /**
     * Default keywords — used when the class does not define $webSearchKeywords.
     */
    private function defaultWebSearchKeywords(): array
    {
        return [
            // Portuguese
            'pesquisa', 'pesquisa na web', 'procura na internet', 'busca online',
            'notícias', 'noticias', 'hoje', 'atual', 'atualidade', 'recente', 'última hora',
            'o que é', 'quem é', 'quando foi', 'onde fica', 'quanto custa',
            'preço de', 'preco de', 'cotação de', 'cotacao de',
            'concorrente', 'empresa', 'site', 'website',
            'mercado', 'tendência', 'tendencia',
            // English
            'search', 'look up', 'find', 'what is', 'who is', 'latest', 'current',
            'news', 'price of', 'cost of', 'how much', 'recent', 'today',
            'competitor', 'market', 'trend', 'company info',
            // Explicit triggers
            'web:', 'search:', 'pesquisa:', 'google',
        ];
    }

    /**
     * Check if the message likely needs a web search
     */
    protected function needsWebSearch(string $message): bool
    {
        if (!(new WebSearchService())->isAvailable()) return false;

        $lower = strtolower($message);

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
     * Extract search query from message
     */
    protected function extractSearchQuery(string $message): string
    {
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
     * Augment message with web search results — always searches (no keyword gate)
     */
    protected function augmentWithWebSearch(string $message, ?callable $heartbeat = null): string
    {
        if (!(new WebSearchService())->isAvailable()) return $message;

        try {
            if ($heartbeat) $heartbeat('searching web');

            $searcher = new WebSearchService();
            $query    = $this->extractSearchQuery($message);
            $results  = $searcher->search($query, 5);

            if (str_starts_with($results, '(')) {
                // Error or unavailable — return original
                return $message;
            }

            return $message . "\n\n---\n**WEB SEARCH RESULTS** (searched: \"{$query}\"):\n{$results}\n---\n\nPlease use the above web search results to answer accurately. Cite sources when relevant.";

        } catch (\Throwable $e) {
            \Log::warning('WebSearchTrait: search failed — ' . $e->getMessage());
            return $message;
        }
    }
}
