<?php

namespace App\Services\AgentTools;

use App\Models\Tender;
use Illuminate\Support\Facades\DB;

/**
 * tender_search — full-text + simple ranking sobre os tenders existentes.
 *
 * Caso de uso: o agente quer ver "o que já fizemos para este cliente"
 * ou "tenders parecidos com este RFQ". Devolve top 5 hits com refs,
 * títulos, organização, deadline, status.
 *
 * Implementação: ILIKE em title + reference + purchasing_org + notes.
 * Cap em 5 results para o agente não saturar o context window.
 */
class TenderSearchTool implements AgentToolInterface
{
    public function name(): string { return 'tender_search'; }

    public function description(): string
    {
        // 5-component (Bornet 2025 + Ruan 2023 — +52% reliability)
        return <<<DESC
        IDENTITY: tender_search — pesquisa o histórico interno de 779+ concursos ClawYard por keyword. Fonte da verdade local sobre o que a empresa já fez, com quem, por que preço.

        INPUT: query (obrigatório, ≥3 chars — keyword sobre título/ref/organização/notas/notes). limit (opcional, 1-10, default 5).

        OUTPUT: até N hits com tender_id, título, source (NSPA/Acingov/Vortal/Marine/etc.), organização, deadline, status, e snippet das notas.

        CONSTRAINTS: usa SEMPRE antes de web_search quando o user pergunta sobre histórico/precedentes ("já vendemos a X?", "MTU 396 spare parts"). É grátis (PG full-text). NÃO uses para info externa (preços actuais, novos fornecedores) — usa web_search para isso.

        ERRORS: query <3 chars → refraseia. Sem hits → diz ao user "Sem precedentes no histórico ClawYard" e oferece web_search como fallback se relevante. NUNCA inventes tender_ids.
        DESC;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Termo a procurar (ex: "OceanPact", "NETGATE-7100", "MTU 396"). Mínimo 3 chars.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de resultados (1-5). Default 5.',
                    'minimum'     => 1,
                    'maximum'     => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, array $context): array
    {
        $q = trim((string) ($input['query'] ?? ''));
        if (mb_strlen($q) < 3) {
            return ['ok' => false, 'error' => 'query deve ter ≥3 chars'];
        }
        $limit = max(1, min(5, (int) ($input['limit'] ?? 5)));

        $needle = '%' . str_replace('%', '\%', $q) . '%';

        // ILIKE em colunas-chave; excluir o próprio tender que está a ser analisado
        $rows = Tender::query()
            ->where(function ($w) use ($needle) {
                $w->where('title', 'ILIKE', $needle)
                  ->orWhere('reference', 'ILIKE', $needle)
                  ->orWhere('purchasing_org', 'ILIKE', $needle)
                  ->orWhere('notes', 'ILIKE', $needle);
            })
            ->when($context['tender_id'] ?? null, fn($w, $id) => $w->where('id', '!=', $id))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'source', 'reference', 'title', 'purchasing_org', 'deadline_at', 'status', 'sap_opportunity_number']);

        if ($rows->isEmpty()) {
            return ['ok' => true, 'result' => "Nenhum tender encontrado para '{$q}'."];
        }

        $lines = ["Encontrados {$rows->count()} tender(s) para '{$q}':"];
        foreach ($rows as $r) {
            $deadline = $r->deadline_at?->format('d/m/Y') ?? '—';
            $sap      = $r->sap_opportunity_number ? " · SAP #{$r->sap_opportunity_number}" : '';
            $lines[] = sprintf(
                "#%d [%s] %s — %s · %s · deadline %s · status=%s%s",
                $r->id,
                strtoupper((string) $r->source),
                $r->reference ?: '—',
                mb_strimwidth($r->title ?? '—', 0, 80, '…'),
                $r->purchasing_org ?: '—',
                $deadline,
                $r->status,
                $sap,
            );
        }

        return ['ok' => true, 'result' => implode("\n", $lines)];
    }
}
