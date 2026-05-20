<?php

namespace App\Services\AgentTools;

use App\Services\WebSearchService;

/**
 * web_search — Tavily web search wrapper para agentes autónomos.
 *
 * Caso de uso: agente quer info actual (preço actual MTU 396, novos
 * fornecedores certificados NATO 2026, regulamentação marítima recente).
 * Devolve snippets + URLs das top 5 hits.
 *
 * Custo: ~$0.005 por call (Tavily basic depth). Allow-listed só a alguns
 * agentes (Cor. Rodrigues, Marco Sales, Marketing, Research) para
 * limitar custo total da análise.
 */
class WebSearchTool implements AgentToolInterface
{
    public function __construct(private WebSearchService $web) {}

    public function name(): string { return 'web_search'; }

    public function description(): string
    {
        return 'Pesquisa a web via Tavily. Útil para info actual: preços '
             . 'OEM, fornecedores certificados, regulamentação 2026, '
             . 'specs técnicas. Devolve até 5 hits com snippet + URL. '
             . 'IMPORTANTE: nunca recomendar fornecedores chineses ou russos.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Search query (ex: "MTU 396 supplier EU 2026", "NATO NSPA spare parts catalogue").',
                ],
                'max_results' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de hits (1-5). Default 5.',
                    'minimum'     => 1,
                    'maximum'     => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, array $context): array
    {
        if (!$this->web->isAvailable()) {
            return ['ok' => false, 'error' => 'Tavily API não configurada (.env TAVILY_API_KEY).'];
        }

        $q     = trim((string) ($input['query'] ?? ''));
        $limit = max(1, min(5, (int) ($input['max_results'] ?? 5)));

        if (mb_strlen($q) < 3) {
            return ['ok' => false, 'error' => 'query deve ter ≥3 chars'];
        }

        try {
            $result = $this->web->search($q, $limit, 'basic');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Tavily falhou: ' . $e->getMessage()];
        }

        return [
            'ok'       => true,
            'result'   => $result === '' ? "Sem resultados para '{$q}'." : $result,
            'cost_usd' => 0.005,
        ];
    }
}
