<?php

namespace App\Services;

use App\Models\Tender;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Loops in PartYard's specialist agents (Cor. Rodrigues Defesa,
 * Marco Sales, Captain Porto, Eng. Victor R&D, Dr. Ana Contracts)
 * when the supplier suggester runs, asking each one for fornecedor
 * picks from their domain.
 *
 * Why this matters: the heuristic suggester knows the H&P directory
 * + Tavily, but doesn't have the lived experience that makes a real
 * salesperson valuable — "for this Bell-412 part, ABC Helicopters
 * Spain delivers in 5 days; for the same NSN AAR Defense charges
 * 30% more but ships from CONUS". That tribal knowledge sits in
 * each agent's persona prompt + system instructions.
 *
 * Cost control:
 *   • Only the 1-2 most-relevant agents per tender (chosen by
 *     category match), never the full 27.
 *   • Each call cached for 1h per (tender_id, agent_key) so a refresh
 *     doesn't re-bill. Cache invalidates if the tender body changes
 *     (we hash title+ref+notes+pdfs as the cache key suffix).
 *   • max_tokens 800 ≈ $0.005/call. Two agents per tender ≈ $0.01.
 */
class AgentExpertSupplierConsultant
{
    /**
     * Map of category code → ranked list of agent keys most likely
     * to know suppliers in that category. The first agent for a
     * category is the "primary expert". When a tender hits multiple
     * categories, we pick the top 2 distinct primary agents.
     */
    private const EXPERTISE_BY_CATEGORY = [
        '1'  => ['capitao', 'sales'],            // Ships
        '2'  => ['engineer', 'sales'],            // Shipyard
        '3'  => ['engineer', 'sales'],            // Ship fittings
        '4'  => ['sales', 'engineer'],            // Prime movers (MTU/CAT/MAK)
        '5'  => ['sales', 'engineer'],            // Auxiliary systems
        '6'  => ['capitao', 'sales'],             // Propulsion
        '7'  => ['capitao', 'engineer'],          // Ship operation
        '8'  => ['capitao', 'sales'],             // Cargo handling
        '9'  => ['engineer', 'sales'],            // Electrical
        '10' => ['engineer', 'capitao'],          // Marine technology
        '11' => ['capitao', 'acingov'],           // Ports
        '12' => ['acingov', 'capitao'],           // Maritime services (PT contracts)
        '13' => ['mildef', 'sales'],              // Military
        '14' => ['engineer', 'mildef'],           // PartYard Systems
        '15' => ['sales', 'engineer'],            // Industrial machinery
        '16' => ['sales', 'engineer'],            // Brand reps
        '17' => ['engineer', 'mildef'],           // Communication tech
        '18' => ['engineer', 'sales'],            // Medical
        '19' => ['acingov', 'sales'],             // Logistics
        '20' => ['engineer', 'sales'],            // Material armazém
    ];

    public function __construct(
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Pick up to 2 distinct expert agents for the inferred categories.
     * Returns an array of agent keys, primary first.
     */
    public function pickExperts(array $categories, int $max = 2): array
    {
        if (empty($categories)) return [];
        $picked = [];
        foreach ($categories as $code) {
            $candidates = self::EXPERTISE_BY_CATEGORY[$code] ?? [];
            foreach ($candidates as $a) {
                if (in_array($a, $picked, true)) continue;
                $picked[] = $a;
                if (count($picked) >= $max) break 2;
            }
        }
        return $picked;
    }

    /**
     * Consult one expert agent and return a small structured response:
     *   ['ok' => bool, 'agent' => key, 'response' => str (≤500 chars),
     *    'suppliers' => [{name, why}, ...] (≤4)]
     *
     * The agent is asked to return JSON with up to 4 supplier picks.
     * Loose parsing (the same tolerance as Daniel's parser): accepts
     * markdown fences and stray preambles.
     */
    /**
     * @param array $webHits Optional Tavily search snippets to feed
     *   the agent so its picks consider fresh web signal too.
     *   Each entry: ['title' => ..., 'url' => ..., 'snippet' => ...]
     */
    public function consult(Tender $tender, string $agentKey, array $webHits = []): array
    {
        $cacheKey = $this->cacheKeyFor($tender, $agentKey, $webHits);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return $cached;

        $meta = AgentCatalog::find($agentKey);
        if (!$meta) {
            return ['ok' => false, 'agent' => $agentKey, 'reason' => 'unknown_agent'];
        }

        // Build a tight system prompt that anchors the agent to the
        // task (not its general persona behaviour). Each agent gets a
        // domain-specific framing so the response is sharper than a
        // generic "list suppliers" query.
        $system = $this->buildSystemPrompt($agentKey, $meta);
        $user   = $this->buildUserPrompt($tender, $webHits);

        $res = $this->dispatcher->dispatch(
            systemPrompt: $system,
            userMessage:  $user,
            maxTokens:    800,
        );

        if (!($res['ok'] ?? false)) {
            $payload = ['ok' => false, 'agent' => $agentKey, 'reason' => $res['error'] ?? 'dispatch_error'];
            Cache::put($cacheKey, $payload, 60 * 5);    // short cache for failures
            return $payload;
        }

        $parsed = $this->parseResponse((string) ($res['text'] ?? ''));
        $payload = [
            'ok'        => true,
            'agent'     => $agentKey,
            'agent_meta'=> [
                'name'  => $meta['name']  ?? $agentKey,
                'emoji' => $meta['emoji'] ?? '🤖',
                'color' => $meta['color'] ?? '#76b900',
            ],
            'response'  => $parsed['summary'] ?? '',
            'suppliers' => $parsed['suppliers'] ?? [],
            'cost_usd'  => $res['cost_usd'] ?? 0,
        ];
        Cache::put($cacheKey, $payload, 60 * 60);   // 1h cache for successful calls
        return $payload;
    }

    private function buildSystemPrompt(string $agentKey, array $meta): string
    {
        $name = $meta['name']  ?? $agentKey;
        $role = $meta['role']  ?? 'Especialista de domínio';

        $domainHint = match ($agentKey) {
            'mildef'   => "Domínio: defesa e procurement militar. Prioriza fornecedores NATO/EU/USLI, NUNCA China nem Russia. Pensa em NSN/NCAGE, ITAR/EAR compliance, nível de classificação. Conhece prime contractors (Lockheed, BAE, Rheinmetall, Leonardo, Indra, Edge Group), distribuidores (AAR Defense, ETOP, GovDefense), e brokers especializados.",
            'sales'    => "Domínio: vendas marítimas e industriais. Conheces o ecossistema MTU, Caterpillar Marine, MAK, Jenbacher, SKF, Schottel, MAN, Wartsila, Cummins. Pensa em distribuidores oficiais regionais, em part-numbers genuínos vs OEM-equivalents, em prazos de entrega típicos.",
            'capitao'  => "Domínio: operações marítimas e port calls. Conheces estaleiros, agentes portuários, fornecedores de bunkering, IMO/SOLAS compliance, classificadoras (DNV, BV, Lloyd's, ABS, RINA). Pensa em quem opera nos portos relevantes (Lisboa, Leixões, Setúbal, Sines, Roterdão, Antuérpia, Hamburgo).",
            'engineer' => "Domínio: engenharia e R&D. Conheces fornecedores de componentes técnicos, instrumentação, eletrónica industrial, automação, sensores. Distingues claramente OEM vs aftermarket. Pensa em catalogue suppliers (RS, Farnell, Mouser, Digi-Key) vs specialists (HBM, Endress+Hauser, Festo, SMC).",
            'acingov'  => "Domínio: contratos públicos PT/EU. Conheces fornecedores aprovados em concursos públicos portugueses, com NIPC válido e CAE registado. Sabes que muitas oportunidades exigem fornecedor com escritório em PT ou EU para questões fiscais.",
            default    => "Domínio: " . $role,
        };

        return <<<PROMPT
És o {$name}, especialista da PartYard / HP-Group. Outro agente está a sugerir
fornecedores para um concurso e quer a tua experiência de campo.

{$domainHint}

A tua tarefa: dar UMA recomendação curta e prática, depois listar até 4
fornecedores que conheças (do teu mundo, não inventes) que sejam relevantes
para o equipamento descrito. Para cada um, diz numa frase porquê.

FORMATO DE SAÍDA — devolve APENAS este JSON, sem markdown, sem texto antes ou depois:

{
  "summary": "<= 200 chars · uma frase com a tua leitura estratégica do RFQ",
  "suppliers": [
    { "name": "nome do fornecedor", "why": "<= 120 chars · porque este encaixa" },
    ...
  ]
}

REGRAS:
  • Suppliers: NUNCA inventes nomes que não conheças. Se só conheces 1, devolve 1.
    Se nenhum fornecedor te ocorrer, devolve "suppliers": [].
  • NUNCA escrevas Russia/China como recomendação (compliance HP-Group).
  • Não cites preços nem prazos exactos — só nomes + razão de fit.
  • Se vires sinais de classified/restricted no documento, diz-o no summary.
PROMPT;
    }

    private function buildUserPrompt(Tender $tender, array $webHits = []): string
    {
        $deadline = $tender->deadline_lisbon?->format('d/m/Y') ?? '—';
        $ref      = $tender->reference ?: '—';
        $org      = $tender->purchasing_org ?: '—';

        // Include up to 3KB of attached PDF text per attachment so the
        // agent knows the actual specs, not just the title.
        $pdfBlock = '';
        try {
            $atts = $tender->attachments()->where('extraction_status', 'ok')->limit(3)->get();
            if ($atts->isNotEmpty()) {
                $chunks = [];
                foreach ($atts as $att) {
                    $chunks[] = "[{$att->original_name}]\n" . $att->promptSnippet(3000);
                }
                $pdfBlock = "\n\nDocumentos do concurso:\n---\n" . implode("\n\n---\n\n", $chunks) . "\n---\n";
            }
        } catch (\Throwable $e) { /* attachments not loaded */ }

        // Web research block — gives the agent fresh Tavily snippets so
        // its supplier picks can consider companies that recently opened
        // facilities, won contracts, or got mentioned in industry press.
        // The agent is instructed to use this as INPUT but never copy
        // verbatim, and to flag if it doesn't recognise a hit.
        $webBlock = '';
        if (!empty($webHits)) {
            $webChunks = [];
            foreach (array_slice($webHits, 0, 5) as $hit) {
                $title   = mb_substr((string) ($hit['title']   ?? ''), 0, 120);
                $url     = (string) ($hit['url']     ?? '');
                $snippet = mb_substr((string) ($hit['snippet'] ?? ''), 0, 240);
                $webChunks[] = "• {$title}\n  {$url}\n  {$snippet}";
            }
            $webBlock = "\n\nResultados da pesquisa web (Tavily) — usa como contexto, NÃO como verdade absoluta:\n"
                      . implode("\n\n", $webChunks)
                      . "\n\nSe algum destes resultados for um fornecedor que reconheças e queiras incluir, "
                      . "menciona-o explicitamente. Se algum parecer suspeito ou marketing puro, ignora.";
        }

        return <<<USER
Concurso para o qual preciso da tua leitura:

  • Título: {$tender->title}
  • Referência: {$ref}
  • Fonte: {$tender->source}
  • Organização: {$org}
  • Deadline: {$deadline}{$pdfBlock}{$webBlock}

Devolve o JSON com o teu summary + até 4 fornecedores que conheças do teu domínio.
Considera tanto a tua experiência interna como (se relevante) os resultados da web.
USER;
    }

    /**
     * Parse the JSON response. Same tolerance as Daniel's parser:
     * markdown fences, leading prose, missing trailing brace.
     */
    private function parseResponse(string $raw): array
    {
        $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?? $raw);
        if (!preg_match('/\{[\s\S]*\}/', $clean, $m)) return ['summary' => $raw, 'suppliers' => []];

        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) return ['summary' => $raw, 'suppliers' => []];

        $summary = trim((string) ($decoded['summary'] ?? ''));
        $suppliers = [];
        foreach (($decoded['suppliers'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $name = trim((string) ($s['name'] ?? ''));
            $why  = trim((string) ($s['why']  ?? ''));
            if ($name === '') continue;
            $suppliers[] = [
                'name' => mb_substr($name, 0, 80),
                'why'  => mb_substr($why,  0, 200),
            ];
            if (count($suppliers) >= 4) break;
        }

        return ['summary' => $summary, 'suppliers' => $suppliers];
    }

    /**
     * Cache key includes a hash of the tender body + the web hits
     * fingerprint so the cached opinion stays valid only while both
     * inputs are unchanged.
     */
    private function cacheKeyFor(Tender $tender, string $agentKey, array $webHits = []): string
    {
        $bodyHash = sha1(implode('|', [
            (string) $tender->title,
            (string) $tender->reference,
            (string) $tender->notes,
            (string) $tender->updated_at,
            // Web hits change daily (Tavily indexes evolve) — fingerprint
            // their URLs so the agent re-runs when they shift.
            implode(',', array_map(fn($h) => (string) ($h['url'] ?? ''), array_slice($webHits, 0, 5))),
        ]));
        return 'expert_supplier:' . $tender->id . ':' . $agentKey . ':' . substr($bodyHash, 0, 12);
    }
}
