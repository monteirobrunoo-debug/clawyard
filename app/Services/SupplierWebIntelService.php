<?php

namespace App\Services;

use App\Models\Supplier;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza informação real-do-website de cada fornecedor aprovado.
 *
 * Pedido directo do Bruno (2026-05-21):
 *   "Os agentes tem de verificar na web o que faz os fornecedores
 *    e confrontar se os que temos aprovado tem o mesmo material
 *    também".
 *
 * Hoje o matching (TenderSupplierSuggesterService::matchLocal) só
 * sabe das categorias/subcategorias estáticas. Essa informação foi
 * preenchida manualmente, está desactualizada e incompleta — uma
 * categoria "13 military" não diz se o supplier vende NETGATE,
 * MTU, ou apenas radar.
 *
 * Esta service:
 *   1. Tavily search com (nome + "products" + "catalog") restrito
 *      ao próprio domain se conhecido. Pega nos 5 snippets mais
 *      relevantes do site real.
 *   2. Claude analisa snippets e devolve JSON estruturado:
 *      {summary, products[], evidence[{url,title}]}
 *   3. Cache no DB (suppliers.web_intel_*) com timestamp para
 *      re-sync periódico.
 *
 * Custo ≈ $0.005 Tavily + $0.005 Claude = ~$0.01 por sync.
 * 214 fornecedores aprovados = ~$2 batch inicial.
 *
 * Segurança: respeita RESTRICTED_CATEGORIES (13 militar, 14
 * PartYard Systems) — nunca envia nomes destes para Tavily. Estado
 * fica "skipped_restricted".
 */
class SupplierWebIntelService
{
    /**
     * Mesma lista que SupplierEnrichmentService — alinhado com a
     * auditoria de segurança 2026-05-02 "nomes não saem para 3rd-party
     * search APIs sem opt-in manual".
     */
    private const RESTRICTED_CATEGORIES = ['13', '14'];

    /** TTL antes de re-sync. Websites mudam. */
    public const SYNC_TTL_DAYS = 30;

    public function __construct(
        private WebSearchService $web,
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   status: string,
     *   summary?: ?string,
     *   products?: array,
     *   urls?: array,
     *   error?: string,
     * }
     */
    public function syncOne(Supplier $supplier): array
    {
        // 1. Restricted category guard.
        $supplierCats = array_values((array) ($supplier->categories ?? []));
        if (!empty(array_intersect($supplierCats, self::RESTRICTED_CATEGORIES))) {
            $supplier->forceFill([
                'web_intel_status'    => 'skipped_restricted',
                'web_intel_synced_at' => now(),
                'web_intel_error'     => null,
            ])->save();
            return [
                'ok'     => false,
                'status' => 'skipped_restricted',
                'error'  => 'Categoria restrita (militar/PartYard Systems) — nome não envia para Tavily.',
            ];
        }

        if (!$this->web->isAvailable()) {
            return ['ok' => false, 'status' => 'failed', 'error' => 'tavily_not_configured'];
        }

        // 2. Tavily search.
        $query = $this->buildQuery($supplier);
        $rawResults = $this->web->search($query, maxResults: 5, searchDepth: 'advanced');

        if (str_starts_with(trim($rawResults), '(') || mb_strlen(trim($rawResults)) < 50) {
            $supplier->forceFill([
                'web_intel_status'    => 'no_data',
                'web_intel_synced_at' => now(),
                'web_intel_error'     => 'Tavily devolveu zero hits utilizáveis.',
            ])->save();
            return ['ok' => false, 'status' => 'no_data', 'error' => 'no_tavily_hits'];
        }

        // 3. Claude extracts structured intel.
        try {
            $intel = $this->extractIntel($supplier, $rawResults);
        } catch (\Throwable $e) {
            Log::warning('SupplierWebIntel: extract failed', [
                'supplier_id' => $supplier->id,
                'error'       => $e->getMessage(),
            ]);
            $supplier->forceFill([
                'web_intel_status'    => 'failed',
                'web_intel_synced_at' => now(),
                'web_intel_error'     => mb_substr($e->getMessage(), 0, 500),
            ])->save();
            return ['ok' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        // 4. Persist.
        $supplier->forceFill([
            'web_intel_summary'   => $intel['summary'] ?? null,
            'web_intel_products'  => $intel['products'] ?? [],
            'web_intel_urls'      => $intel['evidence'] ?? [],
            'web_intel_status'    => 'ok',
            'web_intel_synced_at' => now(),
            'web_intel_error'     => null,
        ])->save();

        Log::info('SupplierWebIntel: sync ok', [
            'supplier_id'   => $supplier->id,
            'products_cnt'  => count((array) ($intel['products'] ?? [])),
            'evidence_cnt'  => count((array) ($intel['evidence'] ?? [])),
        ]);

        return [
            'ok'       => true,
            'status'   => 'ok',
            'summary'  => $intel['summary'] ?? null,
            'products' => $intel['products'] ?? [],
            'urls'     => $intel['evidence'] ?? [],
        ];
    }

    public function isStale(Supplier $supplier): bool
    {
        if (!$supplier->web_intel_synced_at) return true;
        return $supplier->web_intel_synced_at
            ->lt(now()->subDays(self::SYNC_TTL_DAYS));
    }

    private function buildQuery(Supplier $supplier): string
    {
        // Se temos website, restringe ao domain para resultados precisos.
        // Caso contrário, query genérica com "products catalog".
        $name = (string) $supplier->name;
        $site = (string) ($supplier->website ?? '');

        if ($site !== '') {
            $host = parse_url($site, PHP_URL_HOST) ?: $site;
            $host = preg_replace('/^www\./', '', (string) $host);
            return "{$name} products catalog site:{$host}";
        }
        return "{$name} products catalog manufacturer supplier what they sell";
    }

    /**
     * Single Claude call: snippets in, structured JSON out.
     */
    private function extractIntel(Supplier $supplier, string $snippets): array
    {
        $name = $supplier->name;
        $existingCats = implode(', ', (array) ($supplier->categories ?? []));
        $existingSubs = implode(', ', (array) ($supplier->subcategories ?? []));
        $existingBrands = implode(', ', (array) ($supplier->brands ?? []));

        $system = <<<PROMPT
És um analista de procurement do PartYard / HP-Group. Recebes snippets
de Tavily sobre o fornecedor e tens de devolver APENAS este JSON
(sem markdown, sem prefácio):

{
  "summary": "≤500 chars — em PT, o que esta empresa REALMENTE faz (não o slogan, factos): produtos, mercados, especialidades, geografia se relevante",
  "products": ["item ou linha de produto concreta detectada, máx 12 strings ≤80 chars cada — ex.: 'distribution boxes', 'circuit breakers MCB', 'NETGATE firewalls', 'MTU spare parts'"],
  "evidence": [{"url":"https://...","title":"..."}, ...máx 5...]
}

REGRAS:
  • Se snippets não têm informação útil sobre produtos, devolve products=[].
  • NUNCA inventes produtos sem evidence no texto. Melhor lista vazia que mentir.
  • Ignora press releases, news, awards — foca em catálogo / "what we do".
  • Produtos em inglês ou PT conforme aparecer (não traduzir agressivo).
  • evidence só com URLs que aparecem realmente no texto Tavily.
PROMPT;

        $userMsg = "Fornecedor: {$name}\n";
        if ($existingCats !== '')   $userMsg .= "Categorias existentes (estáticas, podem estar erradas): {$existingCats}\n";
        if ($existingSubs !== '')   $userMsg .= "Subcategorias: {$existingSubs}\n";
        if ($existingBrands !== '') $userMsg .= "Brands conhecidas: {$existingBrands}\n";
        $userMsg .= "\n=== SNIPPETS TAVILY ===\n" . mb_substr($snippets, 0, 6000) . "\n=== FIM ===\n\nDevolve o JSON.";

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    1200,
        );

        if (!($res['ok'] ?? false)) {
            throw new \RuntimeException('Claude failed: ' . ($res['error'] ?? 'unknown'));
        }

        $raw = trim((string) ($res['text'] ?? ''));
        $clean = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $raw) ?? $raw;
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) {
            throw new \RuntimeException('LLM did not return JSON');
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON decode failed');
        }

        // Sanitize products (max 12, ≤80 chars each)
        $products = [];
        foreach ((array) ($decoded['products'] ?? []) as $p) {
            $p = mb_substr(trim((string) $p), 0, 80);
            if ($p !== '') $products[] = $p;
            if (count($products) >= 12) break;
        }

        // Sanitize evidence (max 5, only http/https URLs)
        $evidence = [];
        foreach ((array) ($decoded['evidence'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $url = trim((string) ($e['url'] ?? ''));
            if (!preg_match('#^https?://#', $url)) continue;
            $evidence[] = [
                'url'   => mb_substr($url, 0, 500),
                'title' => mb_substr(trim((string) ($e['title'] ?? '')), 0, 200),
            ];
            if (count($evidence) >= 5) break;
        }

        return [
            'summary'  => mb_substr(trim((string) ($decoded['summary'] ?? '')), 0, 500) ?: null,
            'products' => $products,
            'evidence' => $evidence,
        ];
    }
}
