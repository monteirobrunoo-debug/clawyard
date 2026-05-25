<?php

namespace App\Services;

use App\Models\OrganizationalKnowledge;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OrganizationalMemoryService — CRUD + auto-extraction de knowledge.
 *
 * Filosofia (lições do rollback do per-user LTM):
 *   • Service é STANDALONE — não modifica nenhum agente
 *   • Auto-extract corre via QUEUE JOB pós-chat, não no hot path
 *   • Acessível via KnowledgeSearchTool (agentes podem usar como tool)
 *   • Failure-mode: tudo isolado em try/catch, agentes nunca rebentam
 *     por causa deste service
 */
class OrganizationalMemoryService
{
    // AgentDispatcher é OBRIGATÓRIO — sem default null. Antes era opcional
    // (?AgentDispatcher = null) e o container injectava null porque assumia
    // que era opcional → autoExtract() sempre fazia skip. Bug encontrado
    // 2026-05-25: 50 jobs corridos sem extrair nada.
    public function __construct(private AgentDispatcher $dispatcher) {}

    /**
     * Adiciona uma memória manualmente. Devolve o registo ou null se
     * inválido (key duplicada gera update silencioso).
     */
    public function remember(
        string $key,
        string $value,
        string $category = 'general',
        float $importance = 0.5,
        ?array $tags = null,
        string $source = 'manual',
        ?int $extractedByUserId = null,
        ?string $extractedFromContext = null,
    ): ?OrganizationalKnowledge {
        $key = Str::slug(mb_substr($key, 0, 150), '_');
        $value = trim(mb_substr($value, 0, 2000));
        if ($key === '' || $value === '') return null;

        if (!in_array($category, OrganizationalKnowledge::CATEGORIES, true)) {
            $category = 'general';
        }
        if (!in_array($source, OrganizationalKnowledge::SOURCES, true)) {
            $source = 'manual';
        }
        $importance = max(0.0, min(1.0, $importance));

        return OrganizationalKnowledge::updateOrCreate(
            ['knowledge_key' => $key],
            [
                'knowledge_value'        => $value,
                'category'               => $category,
                'importance'             => $importance,
                'source'                 => $source,
                'tags'                   => $tags,
                'extracted_from_user_id' => $extractedByUserId,
                'extracted_from_context' => $extractedFromContext,
            ],
        );
    }

    /**
     * Procura por keyword nos campos value, key, tags. Devolve top-N.
     * Usado pela KnowledgeSearchTool.
     *
     * @return array<int,OrganizationalKnowledge>
     */
    public function search(string $query, int $limit = 5, ?string $category = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];

        $q = OrganizationalKnowledge::query()->fresh();

        if ($category) {
            $q->ofCategory($category);
        }

        // Full-text style — value/key/tags contém qualquer termo
        $terms = preg_split('/\s+/', mb_strtolower($query));
        $terms = array_values(array_filter($terms, fn ($t) => mb_strlen($t) >= 3));

        if (empty($terms)) {
            $rows = $q->orderByRelevance()->limit($limit)->get();
        } else {
            $q->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $w->orWhereRaw('LOWER(knowledge_value) LIKE ?', ["%{$t}%"]);
                    $w->orWhereRaw('LOWER(knowledge_key) LIKE ?', ["%{$t}%"]);
                }
            });
            $rows = $q->orderByRelevance()->limit($limit)->get();
        }

        // Bump recall_count em background
        foreach ($rows as $m) {
            try { $m->markRecalled(); } catch (\Throwable) {}
        }

        return $rows->all();
    }

    /**
     * Auto-extract — usa Haiku (cheap) para analisar uma conversação
     * e extrair factos para guardar. Chamado por queue job, NUNCA
     * no hot path do chat.
     *
     * Devolve número de memórias gravadas.
     */
    public function autoExtract(
        string $conversationText,
        ?int $extractedByUserId = null,
        ?string $context = null,
    ): int {
        if (mb_strlen($conversationText) < 200) {
            Log::info('OrganizationalMemory: skip — texto < 200 chars', ['context' => $context]);
            return 0;
        }

        $system = <<<PROMPT
És um knowledge extractor da PartYard/HP-Group. Lês uma conversação
entre um colaborador e um agente AI. Extrais APENAS factos VERIFICÁVEIS
que valham a pena memorizar para a empresa toda — não preferências
pessoais.

Boas extracções:
  • "MTU AG Friedrichshafen é fornecedor oficial NATO de motores 396"
  • "Cliente Naval Logistik LTDA — NIF 514234567, vendedor SlpCode 12"
  • "EU regulation 2024/1342 entra em vigor 2026-Q3 — afecta dual-use"

Más extracções (skip):
  • "Bruno está cansado hoje"  (pessoal)
  • "preciso de info sobre X"  (não é facto)
  • "obrigado"                  (small talk)

Devolve APENAS este JSON (sem markdown):
{
  "facts": [
    {
      "key": "slug_unico_em_underscore (ex: mtu_ag_oficial_nato)",
      "value": "facto em ≤200 chars",
      "category": "supplier|customer|pricing|regulation|process|product|preference|general",
      "importance": 0.0-1.0,
      "tags": ["array de palavras-chave para search"]
    }
  ]
}

Se nada vale a pena extrair, devolve {"facts": []}.
Max 5 facts por chamada.
PROMPT;

        $userMsg = "=== CONVERSAÇÃO ===\n" . mb_substr($conversationText, 0, 6000)
                 . "\n=== FIM ===\n\nExtrai factos que valham a pena para a empresa.";

        try {
            $res = $this->dispatcher->dispatch(
                systemPrompt: $system,
                userMessage:  $userMsg,
                maxTokens:    1200,
                model:        (string) config('services.anthropic.model_haiku', 'claude-haiku-4-5-20251001'),
            );
        } catch (\Throwable $e) {
            Log::warning('OrganizationalMemory: dispatch failed — ' . $e->getMessage());
            return 0;
        }

        if (!($res['ok'] ?? false)) return 0;

        $raw = trim((string) ($res['text'] ?? ''));
        $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) return 0;

        $decoded = json_decode($m[0], true);
        if (!is_array($decoded) || !isset($decoded['facts'])) return 0;

        $saved = 0;
        foreach (array_slice((array) $decoded['facts'], 0, 5) as $f) {
            if (!is_array($f)) continue;
            $row = $this->remember(
                key:        (string) ($f['key']        ?? ''),
                value:      (string) ($f['value']      ?? ''),
                category:   (string) ($f['category']   ?? 'general'),
                importance: (float)  ($f['importance'] ?? 0.5),
                tags:       (array)  ($f['tags']       ?? null),
                source:     'auto-extracted',
                extractedByUserId:    $extractedByUserId,
                extractedFromContext: $context,
            );
            if ($row) $saved++;
        }

        if ($saved > 0) {
            Log::info('OrganizationalMemory: auto-extracted', [
                'saved' => $saved,
                'context' => $context,
                'user' => $extractedByUserId,
            ]);
        }
        return $saved;
    }

    /** Total de memórias active (não expiradas). */
    public function count(): int
    {
        return OrganizationalKnowledge::query()->fresh()->count();
    }

    /** Breakdown por categoria. */
    public function statsByCategory(): array
    {
        return OrganizationalKnowledge::query()
            ->fresh()
            ->selectRaw('category, COUNT(*) as n, AVG(importance) as avg_imp')
            ->groupBy('category')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'count'    => (int) $r->n,
                'avg_imp'  => round((float) $r->avg_imp, 2),
            ])
            ->toArray();
    }
}
