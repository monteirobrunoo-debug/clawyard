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
  • Um código CAGE/NCAGE específico: {$cage}
  • Snippets de Tavily

Devolve APENAS este JSON (sem markdown, sem prefácio):

{
  "name": "nome canónico da empresa OU vazio",
  "country_code": "ISO-2 do país OU vazio",
  "country_name": "nome curto do país OU vazio",
  "city": "cidade da sede OU vazio",
  "website": "URL oficial https:// OU vazio",
  "evidence_phrase": "frase EXACTA dos snippets que liga {$cage} à empresa OU vazio"
}

REGRAS CRÍTICAS (anti-falso-positivo):

1. EVIDÊNCIA OBRIGATÓRIA: O nome só é válido se houver UMA das seguintes
   frases LITERALMENTE nos snippets, com o código {$cage} VISÍVEL:
     • "CAGE {$cage}" + nome empresa na MESMA frase ou frase adjacente
     • "{$cage} – X" / "{$cage} - X" / "{$cage}: X"
     • "Manufacturer: X" + "CAGE {$cage}" na MESMA página
     • "X (CAGE {$cage})" / "X / CAGE {$cage}"
   Cole essa frase no campo evidence_phrase.

2. NÃO ATRIBUIR SÓ PORQUE O NOME APARECE: Se vês "Curtiss-Wright" mencionado
   numa página que também menciona o CAGE {$cage}, isso NÃO chega. Tens de
   ver os dois LIGADOS na mesma frase/parágrafo.

3. NUNCA usar o nome mais comum em defesa por defeito. Se não tens prova
   directa, devolve TUDO vazio. É preferível 0% de hits do que 50% de
   falsos positivos que poluem a base de dados.

4. SE O CAGE NÃO APARECE LITERALMENTE NOS SNIPPETS, devolve vazio. O
   Tavily devia trazer páginas que mencionam o CAGE — se não há, é porque
   não há boa evidência online.

5. Recusa empresas chinesas (.cn) e russas (.ru).

VERIFICAÇÃO ANTES DE DEVOLVER:
  • Procuro mentalmente "{$cage}" no snippet — vejo?
  • Procuro o nome que vou devolver na mesma frase/parágrafo que o CAGE?
  • Se respondi "não" a qualquer uma → devolvo VAZIO.

Exemplos:

INPUT snippet: "Manufacturer: Daken S.p.a. CAGE Code: 22670. Italian battery..."
OUTPUT: {"name":"Daken S.p.a.","evidence_phrase":"Manufacturer: Daken S.p.a. CAGE Code: 22670"}

INPUT snippet: "Curtiss-Wright is a major defense supplier. Other companies with CAGE 15120 also..."
OUTPUT: {"name":"","evidence_phrase":""} (Curtiss-Wright NÃO está ligado ao CAGE 15120 — só são mencionados em frases adjacentes)
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

        // Defesa em profundidade — TRÊS validações cumulativas:
        $name           = trim((string) ($decoded['name'] ?? ''));
        $evidencePhrase = trim((string) ($decoded['evidence_phrase'] ?? ''));

        // (1) O CAGE TEM de aparecer literalmente no Tavily raw — senão a
        //     query nem trouxe o catálogo certo.
        if (!str_contains(mb_strtolower($tavilyRaw), mb_strtolower($cage))) {
            Log::info('NcageEnrichmentService: CAGE não aparece no Tavily raw — dropping name', [
                'cage' => $cage, 'name_claimed' => $name,
            ]);
            $name = '';
        }

        // (2) A evidence_phrase TEM de aparecer no Tavily raw E TEM de conter o CAGE
        if ($name !== '' && $evidencePhrase !== '') {
            $rawLower    = mb_strtolower($tavilyRaw);
            $phraseLower = mb_strtolower($evidencePhrase);
            $cageLower   = mb_strtolower($cage);

            $phraseInRaw = str_contains($rawLower, $phraseLower);
            $cageInPhrase = str_contains($phraseLower, $cageLower);

            if (!$phraseInRaw || !$cageInPhrase) {
                Log::info('NcageEnrichmentService: evidence_phrase invalid', [
                    'cage' => $cage, 'name_claimed' => $name,
                    'phrase_in_raw' => $phraseInRaw,
                    'cage_in_phrase' => $cageInPhrase,
                ]);
                $name = '';
            }
        } elseif ($name !== '' && $evidencePhrase === '') {
            // Sem evidence_phrase a empresa não foi devidamente ligada ao CAGE.
            Log::info('NcageEnrichmentService: name without evidence_phrase — dropping', [
                'cage' => $cage, 'name_claimed' => $name,
            ]);
            $name = '';
        }

        // (3) Fallback antigo: nome tem de existir no Tavily (já implícito por #2)
        if ($name !== '' && !$this->appearsInText($name, $tavilyRaw)) {
            Log::info('NcageEnrichmentService: name not in Tavily — dropping', [
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
