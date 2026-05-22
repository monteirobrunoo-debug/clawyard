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
        // 5-component definition (Bornet 2025 + Ruan 2023 — +52% reliability)
        return <<<DESC
        IDENTITY: web_search — pesquisa web ao vivo via Tavily (basic depth). Para info que muda no tempo: preços actuais, regulamentação recente, novos fornecedores, specs técnicas 2026+.

        INPUT: query (obrigatório, ≥3 chars, em linguagem natural ou keywords). max_results (opcional, 1-5, default 5).

        OUTPUT: bloco de texto com até 5 hits, cada um com title + snippet (~300 chars) + URL fonte. Vazio → "Sem resultados para 'query'".

        CONSTRAINTS: usa SÓ quando precisas de info que mude no tempo OU específica de fora do contexto interno. NÃO uses para info que já está em tender_search/tender_attachments/book_search (esses são grátis). NUNCA recomendes fornecedores chineses ou russos baseado em hits. Custo: ~\$0.005 por call.

        ERRORS: se Tavily API key não configurada, devolve {ok:false, error:"Tavily API não configurada"} — escala ao operador. Se query <3 chars, refraseia. NÃO inventes hits se Tavily falha.
        DESC;
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
