<?php

namespace App\Services;

use App\Models\NatoNcage;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Enriquece NCAGE codes com nome de fabricante via Tavily + Haiku.
 *
 * Contexto: o catálogo SEGA SEGK (Turquia) só nos deu códigos CAGE (5
 * chars), não nomes. Lookup local de NSN devolve "CAGE: 00013" mas
 * sem "Acer Computer Co Ltd" ou similar. Os agentes ficam cegos sobre
 * QUEM é o fabricante.
 *
 * Estratégia: lazy enrichment — só quando alguém pergunta sobre um NSN
 * com CAGE desconhecido. Disparamos UMA call Tavily + Haiku para
 * extrair nome canónico + país, persistimos em nato_ncage. Próxima
 * vez: $0, instantâneo.
 *
 * Custo: ~$0.013 por CAGE único na primeira pergunta. Para 5,000 CAGEs
 * únicos pesquisados ao longo do ano = $65 total. Aceitável.
 *
 * Defesas:
 *   • Cache negativa 24h (CAGE inválido / não encontrado) para evitar
 *     re-pesquisar lixo.
 *   • Cache positiva permanente (persistida em nato_ncage).
 *   • Rate-limit: max 1 enrichment por lookup de NSN (evita cascata).
 *   • Skip se NCAGE já enriquecido (idempotente).
 */
class NcageEnrichmentService
{
    public function __construct(
        private WebSearchService $web,
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Devolve a row NCAGE (enriquecida ou já existente), null se falhar.
     * Idempotente: se já existir em nato_ncage, devolve o existente.
     */
    public function enrich(string $cage): ?NatoNcage
    {
        $cage = strtoupper(trim($cage));
        if (mb_strlen($cage) < 3 || mb_strlen($cage) > 10) return null;

        // 1. Existe já? (cache hit)
        try {
            $existing = NatoNcage::where('cage_code', $cage)->first();
            if ($existing && !empty($existing->company_name)
                && $existing->company_name !== '(sem nome)') {
                return $existing;
            }
        } catch (\Throwable $e) {
            Log::warning('NcageEnrichmentService: DB error checking cage', [
                'cage' => $cage, 'error' => $e->getMessage(),
            ]);
        }

        // 2. Cache negativa — não re-pesquisar CAGEs que falharam recentemente
        $negKey = 'ncage_enrich_fail:' . $cage;
        if (Cache::has($negKey)) return null;

        // 3. Tavily search
        if (!$this->web->isAvailable()) {
            Log::info('NcageEnrichmentService: Tavily não configurado, skipping', ['cage' => $cage]);
            return null;
        }

        try {
            $query = "NCAGE \"{$cage}\" manufacturer company name country";
            $tavilyRaw = $this->web->search($query, maxResults: 5, searchDepth: 'basic');
        } catch (\Throwable $e) {
            Log::warning('NcageEnrichmentService: Tavily failed', [
                'cage' => $cage, 'error' => $e->getMessage(),
            ]);
            Cache::put($negKey, true, now()->addDay());
            return null;
        }

        if (!is_string($tavilyRaw) || mb_strlen($tavilyRaw) < 50) {
            Cache::put($negKey, true, now()->addDay());
            return null;
        }

        // 4. Haiku extrai JSON
        try {
            $intel = $this->extractCompanyIntel($cage, $tavilyRaw);
        } catch (\Throwable $e) {
            Log::warning('NcageEnrichmentService: Haiku extract failed', [
                'cage' => $cage, 'error' => $e->getMessage(),
            ]);
            Cache::put($negKey, true, now()->addDay());
            return null;
        }

        if (empty($intel['name'])) {
            Log::info('NcageEnrichmentService: sem nome extraído', [
                'cage' => $cage, 'intel' => $intel,
            ]);
            Cache::put($negKey, true, now()->addDay());
            return null;
        }

        // 5. Persiste em nato_ncage
        try {
            $row = NatoNcage::updateOrCreate(
                ['cage_code' => $cage],
                [
                    'company_name' => mb_substr($intel['name'], 0, 300),
                    'country_code' => mb_substr($intel['country_code'] ?? '', 0, 5) ?: null,
                    'country_name' => mb_substr($intel['country_name'] ?? '', 0, 100) ?: null,
                    'city'         => mb_substr($intel['city']         ?? '', 0, 150) ?: null,
                    'website'      => filter_var($intel['website'] ?? '', FILTER_VALIDATE_URL) ?: null,
                    'status'       => 'enriched_via_tavily',
                    'raw'          => $intel,
                ]
            );
            Log::info('NcageEnrichmentService: enriched', [
                'cage' => $cage, 'name' => $intel['name'],
            ]);
            return $row;
        } catch (\Throwable $e) {
            Log::warning('NcageEnrichmentService: persist failed', [
                'cage' => $cage, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{name:string,country_code?:string,country_name?:string,city?:string,website?:string}
     */
    private function extractCompanyIntel(string $cage, string $tavilyRaw): array
    {
        $system = <<<PROMPT
És um analista de procurement do PartYard / HP-Group. Recebes:
  • Um código CAGE/NCAGE (NATO Commercial And Government Entity)
  • Snippets de Tavily com referências a esse código

Devolve APENAS este JSON (sem markdown, sem prefácio):

{
  "name": "nome canónico da empresa (ex: 'RHEINMETALL AG'). Vazio se não tens evidência clara.",
  "country_code": "ISO-2 do país (ex: 'DE', 'US', 'TR'). Vazio se desconhecido.",
  "country_name": "nome curto do país (ex: 'Germany'). Vazio se desconhecido.",
  "city": "cidade da sede. Vazio se desconhecido.",
  "website": "URL oficial (https://...). Vazio se desconhecido."
}

REGRAS:
  • Nome: APENAS se aparecer LITERALMENTE associado ao CAGE nos snippets
    ("CAGE {$cage} = X", "{$cage} is X", "Manufacturer: X (CAGE {$cage})").
    Se vês uma empresa solta num site genérico sem ligação ao CAGE,
    deixa vazio.
  • NUNCA inventes empresas. Falso negativo é melhor que falso positivo —
    o operador humano vai usar isto para decisões de procurement.
  • Recusa empresas chinesas (.cn) e russas (.ru) — política HP-Group.
  • Prefere fontes oficiais NSPA, DLA, NATO Codification Bureau.
PROMPT;

        $userMsg = "CAGE: {$cage}\n\n=== TAVILY HITS ===\n"
                 . mb_substr($tavilyRaw, 0, 3500)
                 . "\n=== FIM ===\n\nDevolve o JSON.";

        $haikuModel = (string) config('services.anthropic.model_haiku', 'claude-haiku-4-5-20251001');

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $userMsg,
            maxTokens:    400,
            model:        $haikuModel,
        );

        if (!($res['ok'] ?? false)) {
            throw new \RuntimeException('Haiku failed: ' . ($res['error'] ?? 'unknown'));
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

        // Defesa em profundidade — nome tem de aparecer no Tavily raw
        $name = trim((string) ($decoded['name'] ?? ''));
        if ($name !== '' && !$this->appearsInText($name, $tavilyRaw)) {
            Log::info('NcageEnrichmentService: name dropped (no Tavily evidence)', [
                'cage' => $cage, 'name_claimed' => $name,
            ]);
            $name = '';
        }

        return [
            'name'         => $name,
            'country_code' => mb_substr(trim((string) ($decoded['country_code'] ?? '')), 0, 5),
            'country_name' => mb_substr(trim((string) ($decoded['country_name'] ?? '')), 0, 100),
            'city'         => mb_substr(trim((string) ($decoded['city']         ?? '')), 0, 150),
            'website'      => mb_substr(trim((string) ($decoded['website']      ?? '')), 0, 300),
        ];
    }

    private function appearsInText(string $needle, string $haystack): bool
    {
        $needle = mb_strtolower(trim($needle));
        if (mb_strlen($needle) < 3) return false;
        $haystack = mb_strtolower($haystack);
        if (str_contains($haystack, $needle)) return true;
        // Tenta primeira palavra significativa (≥4 chars)
        $first = explode(' ', $needle)[0] ?? '';
        return mb_strlen($first) >= 4 && str_contains($haystack, $first);
    }
}
