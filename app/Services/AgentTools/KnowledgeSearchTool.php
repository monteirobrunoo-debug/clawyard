<?php

namespace App\Services\AgentTools;

use App\Services\OrganizationalMemoryService;

/**
 * knowledge_search — tool para agentes pesquisarem a memória
 * organizacional da PartYard.
 *
 * Filosofia: agente decide quando precisa. NÃO é auto-injectado no
 * system prompt como a tentativa anterior de LTM per-user. Isto evita
 * o problema que matou Marta + Marine.
 *
 * Para usar num agente, adicionar ao `tools[]` passado ao
 * AutonomousAgentRunner. Para agentes em chat directo (que não usam
 * o runner), basta dar acesso via PromptLibrary mention.
 */
class KnowledgeSearchTool implements AgentToolInterface
{
    public function __construct(private OrganizationalMemoryService $svc) {}

    public function name(): string { return 'knowledge_search'; }

    public function description(): string
    {
        // 5-component (Bornet 2025 + Ruan 2023 — +52% reliability)
        return <<<DESC
        IDENTITY: knowledge_search — pesquisa a memória corporativa partilhada da PartYard/HP-Group. Memórias guardadas pela empresa toda: fornecedores aprovados, contactos, preferências de OEM, regulamentações, histórico de pricing.

        INPUT: query (obrigatório, ≥3 chars, keyword ou pergunta natural). category (opcional: 'supplier'|'customer'|'pricing'|'regulation'|'process'|'product'|'preference'). limit (opcional, 1-10, default 5).

        OUTPUT: lista de memórias [key, value, category, importance] ordenadas por relevância (importance × recency).

        CONSTRAINTS: usa quando o user pergunta sobre algo que pode estar no histórico interno PartYard. PREFERE este sobre web_search quando o conhecimento é sobre PartYard, fornecedores aprovados, vendedores SAP, processos internos. Memórias auto-extraídas têm source='auto-extracted' — confirma com o user antes de actuar.

        ERRORS: 0 hits → diz "Sem memória interna sobre X" e oferece web_search. Categoria inválida → cai para 'general'.
        DESC;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Keyword ou pergunta sobre conhecimento interno PartYard',
                ],
                'category' => [
                    'type'        => 'string',
                    'enum'        => ['supplier','customer','pricing','regulation','process','product','preference','general'],
                    'description' => 'Filtra por categoria (opcional)',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'Máximo de resultados (default 5)',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, array $context): array
    {
        $query    = trim((string) ($input['query'] ?? ''));
        $category = (string) ($input['category'] ?? '') ?: null;
        $limit    = max(1, min(10, (int) ($input['limit'] ?? 5)));

        if (mb_strlen($query) < 3) {
            return ['ok' => false, 'error' => 'query deve ter ≥3 chars'];
        }

        $rows = $this->svc->search($query, $limit, $category);

        if (empty($rows)) {
            return [
                'ok'       => true,
                'result'   => "Sem memória interna PartYard sobre '{$query}'."
                            . ($category ? " (filtro: {$category})" : '')
                            . " Considera web_search se for info externa.",
                'cost_usd' => 0,
            ];
        }

        $lines = ["Memória PartYard — " . count($rows) . " hits para '{$query}':"];
        foreach ($rows as $m) {
            $lines[] = sprintf(
                "  [%s · importance %.2f · %s] %s: %s",
                $m->category,
                (float) $m->importance,
                $m->source,
                $m->knowledge_key,
                mb_strimwidth($m->knowledge_value, 0, 200, '…'),
            );
        }

        return [
            'ok'       => true,
            'result'   => implode("\n", $lines),
            'cost_usd' => 0,  // sem chamada Anthropic
        ];
    }
}
