<?php

namespace App\Services;

use App\Models\Document;

/**
 * RagService — Knowledge Base retrieval for agent context injection.
 *
 * Each agent may specify which document sources are relevant (allowlist).
 * Documents that score below the minimum threshold are silently excluded,
 * preventing unrelated content (e.g. aquaponia docs in a defense query)
 * from polluting the agent's context.
 */
class RagService
{
    /**
     * Agent → allowed document sources.
     * null = all sources accepted.
     * Empty array = no RAG injection (agent has its own data sources).
     *
     * Source values come from Document.source field set at upload time.
     */
    private const AGENT_SOURCES = [
        // SAP and CRM have live data — RAG would add noise
        'sap'      => [],
        'crm'      => [],

        // Defense agent: only defense, naval and general PartYard docs
        'mildef'   => ['defense', 'military', 'partyard', 'naval'],

        // Contracts agent: procurement, legal, government portals
        'acingov'  => ['procurement', 'contracts', 'partyard', 'defense', 'naval'],

        // Patent agent: IP, technical specs, R&D
        'patent'   => ['patent', 'rd', 'technical', 'partyard'],

        // Energy agent: maritime energy, decarbonisation
        'energy'   => ['energy', 'maritime', 'partyard', 'naval'],

        // Maritime / vessel agents: ports, ships, navigation
        'capitao'  => ['naval', 'maritime', 'partyard', 'ports'],
        'vessel'   => ['naval', 'maritime', 'partyard'],

        // Research: all public sources
        'research' => null,

        // General agents accept all sources
        'sales'    => null,
        'support'  => null,
        'email'    => null,
        'document' => null,
        'finance'  => null,
        'engineer' => null,
        'quantum'  => null,
        'aria'     => null,
        'auto'     => null,
        'claude'   => null,
    ];

    /**
     * Get relevant documents for a query, filtered by agent.
     */
    public function getContext(string $query, ?string $agentKey = null): string
    {
        $sources = $this->sourcesForAgent($agentKey);

        // Agent explicitly opted out of RAG (e.g. sap, crm)
        if ($sources !== null && count($sources) === 0) {
            return '';
        }

        $documents = Document::search($query, 3, $sources, 4);

        if (empty($documents)) {
            return '';
        }

        $context = "## Relevant PartYard Knowledge Base:\n\n";
        foreach ($documents as $doc) {
            $context .= "### {$doc->title}\n";
            $context .= substr($doc->content, 0, 1500) . "\n\n";
        }

        return $context;
    }

    /**
     * Inject RAG context into a message, with optional agent filtering.
     */
    public function augmentMessage(string|array $message, ?string $agentKey = null): string|array
    {
        $textMessage = is_array($message)
            ? collect($message)->where('type', 'text')->pluck('text')->implode(' ')
            : $message;

        $context = $this->getContext($textMessage, $agentKey);

        if (empty($context)) {
            return $message;
        }

        if (is_array($message)) {
            // Prepend context to the text block
            foreach ($message as &$block) {
                if (($block['type'] ?? '') === 'text') {
                    $block['text'] = $context . "\n---\n\n" . $block['text'];
                    break;
                }
            }
            return $message;
        }

        return $context . "\n---\n\nUser question: " . $message;
    }

    /**
     * Ingest a document into the knowledge base.
     */
    public function ingest(string $title, string $content, string $source = 'partyard'): Document
    {
        $chunks = $this->chunkText($content);

        return Document::create([
            'title'   => $title,
            'source'  => $source,
            'content' => $content,
            'chunks'  => $chunks,
            'summary' => substr($content, 0, 500),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Return the source allowlist for a given agent key.
     * null = all sources. [] = no RAG.
     */
    private function sourcesForAgent(?string $agentKey): ?array
    {
        if ($agentKey === null) return null;
        return self::AGENT_SOURCES[$agentKey] ?? null;
    }

    protected function chunkText(string $text, int $chunkSize = 1000): array
    {
        $words  = explode(' ', $text);
        $chunks = [];
        $chunk  = [];
        $count  = 0;

        foreach ($words as $word) {
            $chunk[] = $word;
            $count++;

            if ($count >= $chunkSize) {
                $chunks[] = implode(' ', $chunk);
                $chunk    = [];
                $count    = 0;
            }
        }

        if (!empty($chunk)) {
            $chunks[] = implode(' ', $chunk);
        }

        return $chunks;
    }
}
