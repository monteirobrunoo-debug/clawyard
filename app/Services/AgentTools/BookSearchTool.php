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
        return 'Pesquisa a biblioteca técnica PartYard (soldadura, naval, '
             . 'finance, marketing, negotiation/Voss). Devolve até 4 trechos '
             . 'com referência ao livro e página. Útil para citar normas, '
             . 'técnicas de negociação, specs marítimas.';
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
