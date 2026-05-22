<?php

namespace App\Services\AgentTools;

use App\Services\TechnicalBookSearch;

/**
 * book_search — pesquisa os 35.000+ chunks técnicos da biblioteca PartYard
 * (soldadura, naval, finance, marketing, negotiation/Voss quando ingerido).
 *
 * Caso de uso: agente quer citar uma norma técnica (WPS, ISO 15614),
 * uma técnica de negociação (BATNA, calibrated questions), ou uma spec
 * marítima. Devolve até 4 trechos com book + page reference.
 */
class BookSearchTool implements AgentToolInterface
{
    public function __construct(private TechnicalBookSearch $books) {}

    public function name(): string { return 'book_search'; }

    public function description(): string
    {
        // 5-component (Bornet 2025 + Ruan 2023 — +52% reliability)
        return <<<DESC
        IDENTITY: book_search — pesquisa semântica (pgvector) na biblioteca técnica PartYard. Cobre soldadura, naval (IMO/SOLAS), finance, marketing, negotiation (Voss "Never Split the Difference"), agentic AI (Bornet 2025), e outros manuais ingeridos.

        INPUT: query (obrigatório, ≥4 chars — pergunta ou conceito). domain (opcional: "naval"|"finance"|"negotiation"|"engineering"|"strategy" para filtrar). limit (opcional, 1-6, default 4).

        OUTPUT: até N trechos (~800 chars cada) com citação completa (livro, capítulo, página). Embedding-ranked por relevância semântica à query.

        CONSTRAINTS: usa para fundamentar respostas com fontes citáveis (normas IMO, tactics Voss, modelos financeiros, agentic patterns). NÃO uses para info actual (preços hoje, regulamentação 2026) — usa web_search. Citações DEVEM aparecer literalmente na resposta — "Voss (2016) cap 3" não basta, cita o trecho.

        ERRORS: 0 hits → diz "Sem cobertura na biblioteca para este tópico" e oferece web_search. NUNCA inventes citações ou números de página. Se o trecho contradiz info recente, prevalece a info recente — sinaliza ao user que o livro pode estar desactualizado.
        DESC;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Termo técnico ou conceito (ex: "calibrated questions", "WPS T-joint", "BATNA").',
                ],
                'domain' => [
                    'type'        => 'string',
                    'description' => 'Domínio para focar (opcional): soldadura, naval, finance, marketing, negotiation, etc.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de trechos (1-4). Default 3.',
                    'minimum'     => 1,
                    'maximum'     => 4,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, array $context): array
    {
        $q     = trim((string) ($input['query'] ?? ''));
        $domain = trim((string) ($input['domain'] ?? '')) ?: null;
        $limit  = max(1, min(4, (int) ($input['limit'] ?? 3)));

        if (mb_strlen($q) < 3) {
            return ['ok' => false, 'error' => 'query deve ter ≥3 chars'];
        }

        try {
            $block = $this->books->buildContextBlock($q, $limit, $domain);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'book_search falhou: ' . $e->getMessage()];
        }

        if ($block === '') {
            $hint = $domain ? " (domain={$domain})" : '';
            return ['ok' => true, 'result' => "Nenhum trecho encontrado para '{$q}'{$hint}."];
        }

        return ['ok' => true, 'result' => $block];
    }
}
