<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\Tender;
use App\Services\AgentSwarm\AgentDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Picks the best supplier candidates for a given tender, combining:
 *
 *   1. INTERNAL — H&P approved suppliers (Excel seed + auto-extracted),
 *      filtered by category match. This is the trusted bucket — these
 *      have IQF scores and known relationships.
 *
 *   2. WEB — Tavily search results for "<tender title> supplier" /
 *      "<key spec> manufacturer". Surfaced as advisory candidates the
 *      operator can promote into the directory via /suppliers/create.
 *
 * Why both and not one or the other:
 *   • Internal-only misses new suppliers we haven't worked with yet —
 *     critical for niche RFQs where our usual list isn't a match.
 *   • Web-only ignores 805 vetted relationships we already have.
 *   • Combined = "use the people who already deliver, but don't be
 *     blind to what's out there".
 */
class TenderSupplierSuggesterService
{
    public function __construct(
        private WebSearchService $web,
        private AgentExpertSupplierConsultant $experts,
        private AgentDispatcher $dispatcher,
    ) {}

    /**
     * Run the full suggester pipeline for a tender.
     *
     * @param bool $includeExperts when true (default), also consults the
     *  1-2 most-relevant specialist agents (Cor. Rodrigues for military,
     *  Marco Sales for engines, Captain Porto for ports, etc.) and folds
     *  their picks into the bundle. Costs ≈ $0.01 in LLM tokens.
     */
    public function suggest(
        Tender $tender,
        int $localLimit = 3,
        bool $includeWeb = true,
        bool $includeExperts = true,
    ): array {
        $categories = $this->inferCategories($tender);

        $local = $this->matchLocal($tender, $categories, $localLimit);

        $webResults = [];
        $webAvailable = $this->web->isAvailable();
        $query = $this->buildWebQuery($tender);

        if ($includeWeb && $webAvailable) {
            $webResults = $this->searchWeb($query);
        }

        // Specialist-agent consultation. Picks the 1-2 most-relevant
        // agents from EXPERTISE_BY_CATEGORY and dispatches one LLM
        // call each. Cached per (tender, agent, body+webhits hash)
        // for 1h. Now feeds the Tavily web hits to each expert so
        // the agent's pick can include fresh web signal alongside
        // its tribal knowledge — user request 2026-05-02.
        $expertOpinions = [];
        if ($includeExperts) {
            $picked = $this->experts->pickExperts($categories, max: 2);
            foreach ($picked as $agentKey) {
                $opinion = $this->experts->consult($tender, $agentKey, $webResults);
                if ($opinion['ok'] ?? false) {
                    $expertOpinions[] = $opinion;
                }
            }
        }

        // 2026-05-18: detectar quando o concurso está FORA DO DOMÍNIO H&P.
        // Pedido directo do operador: "porquê sugeriu fornecedores internos
        // da H&P quando os especialistas disseram para contactar OEM directo
        // (Interacoustics, Karl Storz, Medtronic ENT)".
        //
        // Sinal: experts consultados, TODOS devolveram suppliers vazios E
        // pelo menos um summary tem keyword de fora-do-domínio. Quando isso
        // acontece, escondemos os matches locais (que são H&P generic
        // military, irrelevantes para ENT/medical/specialty) e sugerimos
        // OEMs directamente via 1 chamada LLM dedicada.
        $outOfScope = false;
        $oemDirect = [];

        if (!empty($expertOpinions)) {
            $allEmpty = collect($expertOpinions)->every(fn($o) => empty($o['suppliers'] ?? []));
            if ($allEmpty) {
                $outOfScopeKeywords = ['fora do domínio', 'fora do dominio', 'outside.*domain', 'outside.*core', 'sem overlap', 'no overlap', 'fully outside', 'not.*core', 'totalmente fora', 'fora do.*ecossistema'];
                $signal = collect($expertOpinions)
                    ->map(fn($o) => mb_strtolower((string) ($o['response'] ?? '')))
                    ->filter(function ($s) use ($outOfScopeKeywords) {
                        foreach ($outOfScopeKeywords as $kw) {
                            if (preg_match('/' . str_replace('/', '\\/', $kw) . '/u', $s)) return true;
                        }
                        return false;
                    });

                if ($signal->count() > 0) {
                    $outOfScope = true;
                    $oemDirect = $this->suggestOemDirect($tender);
                }
            }
        }

        // Quando out of scope: esconder H&P local (são irrelevantes)
        // mas manter web + experts (operador pode querer ver).
        $effectiveLocal = $outOfScope ? collect() : $local;

        return [
            'categories'      => $categories,
            'local'           => $effectiveLocal,
            'local_hidden'    => $outOfScope ? $local : collect(),   // expostos para debug se necessário
            'web'             => $webResults,
            'web_available'   => $webAvailable,
            'query'           => $query,
            'expert_opinions' => $expertOpinions,
            'out_of_scope'    => $outOfScope,
            'oem_direct'      => $oemDirect,
        ];
    }

    /**
     * Quando os especialistas H&P unanimemente dizem que o concurso está
     * fora do domínio, pedir ao LLM uma lista de 3-5 OEMs directos que
     * deveriam ser contactados em vez dos H&P approved.
     *
     * Exemplo de saída para concurso ENT/medical NSPA:
     *   [
     *     {"name":"Interacoustics","focus":"audiómetros, equipamento otorrino"},
     *     {"name":"Karl Storz","focus":"endoscopia ENT, instrumentos cirúrgicos"},
     *     {"name":"Medtronic ENT","focus":"microdebriders, navegação cirúrgica"},
     *     {"name":"Grason-Stadler","focus":"audiometria clínica"},
     *   ]
     *
     * Cache 24h por (tender_id + title hash) — OEMs por categoria não
     * mudam com frequência.
     *
     * @return list<array{name: string, focus: string}>
     */
    public function suggestOemDirect(Tender $tender): array
    {
        $cacheKey = 'oem_direct.' . $tender->id . '.' . md5((string) $tender->title);
        return Cache::remember($cacheKey, 60 * 60 * 24, function () use ($tender) {
            $pdfBlob = '';
            try {
                $first = $tender->attachments()->where('extraction_status', 'ok')->first();
                if ($first) {
                    $pdfBlob = mb_substr((string) $first->extracted_text, 0, 4000);
                }
            } catch (\Throwable $e) { /* ignore */ }

            $system = 'És um buyer sénior de procurement. Para um concurso/RFP, identifica os 3-5 OEM (Original Equipment Manufacturer) ou prime contractors que fabricam ou distribuem o tipo de produto descrito.'
                . ' Regras: (1) NUNCA OEMs da Russia ou China. (2) Foco em fabricantes EU/NATO/USA. (3) Dá o nome COMERCIAL real que o operador vai pesquisar.'
                . ' (4) CRITICAL: para CADA OEM diz exactamente que LINHAS / ITEMS do RFP esse fabricante cobre — não basta "endoscopia médica", diz "Item 1 e 2: endoscópios rígidos 4mm + microdebrider".'
                . ' Devolve APENAS JSON, sem markdown:'
                . ' {"oems": [{"name": "Karl Storz", "items": "Items 1,3: endoscópios + instrumentos cirúrgicos ENT"}, ...]}';

            $user = "Concurso:\n"
                . "  Título: {$tender->title}\n"
                . "  Organização: " . ($tender->purchasing_org ?: '—') . "\n"
                . "  Referência: " . ($tender->reference ?: '—') . "\n"
                . "  Fonte: " . strtoupper((string) $tender->source) . "\n";
            if ($pdfBlob) {
                $user .= "\nExcerto do RFP:\n---\n{$pdfBlob}\n---\n";
            }
            $user .= "\nIdentifica 3-5 OEMs. Para CADA UM indica os números/descrições dos items específicos do RFP que esse fabricante consegue cotar. JSON only.";

            $res = $this->dispatcher->dispatch(systemPrompt: $system, userMessage: $user, maxTokens: 500);
            if (!($res['ok'] ?? false)) {
                Log::warning('OEM direct suggest: dispatch failed', ['tender_id' => $tender->id, 'error' => $res['error'] ?? '?']);
                return [];
            }

            $text = (string) ($res['text'] ?? '');
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start === false || $end === false || $end <= $start) return [];
            $json = json_decode(substr($text, $start, $end - $start + 1), true);
            if (!is_array($json)) return [];

            $oems = [];
            foreach (($json['oems'] ?? []) as $o) {
                $name = trim((string) ($o['name'] ?? ''));
                // 2026-05-18: prompt pede 'items' (linhas correspondentes
                // do RFP) mas aceita 'focus' como fallback se a LLM
                // continuar com o formato antigo.
                $items = trim((string) ($o['items'] ?? $o['focus'] ?? ''));
                if ($name === '') continue;
                $oems[] = [
                    'name'  => mb_substr($name, 0, 80),
                    'items' => mb_substr($items, 0, 240),
                    // Mantém 'focus' alias para retrocompatibilidade UI antiga
                    'focus' => mb_substr($items, 0, 240),
                ];
                if (count($oems) >= 5) break;
            }
            return $oems;
        });
    }

    /**
     * Map a tender to a set of supplier-category codes (the H&P
     * top-level taxonomy from SupplierCategories::TOP_LEVEL).
     *
     * Heuristic mix:
     *   • source-based default (NSPA/NCIA/NATO → Military 13;
     *     Acingov → Industrial 15; etc.)
     *   • keyword scan over title+notes (engine → 4 prime movers,
     *     pump → 5, cable → 9, valve → 5, …)
     *
     * Falls back to ["15"] (Industrial machinery & spares) when the
     * heuristic finds nothing — that's the broadest catch-all in the
     * H&P taxonomy.
     */
    public function inferCategories(Tender $tender): array
    {
        $codes = [];

        // 1. Source-based defaults
        $source = mb_strtolower((string) $tender->source);
        $sourceMap = [
            'nspa'      => ['13', '14'],
            'nato'      => ['13', '14'],
            'ncia'      => ['13', '14', '17'],
            'sam_gov'   => ['13'],
            'ungm'      => ['13', '14', '15'],
            'unido'     => ['15'],
            'acingov'   => ['15'],
            'vortal'    => ['15', '12'],
        ];
        if (isset($sourceMap[$source])) {
            $codes = array_merge($codes, $sourceMap[$source]);
        }

        // 2. Keyword scan over the tender's free-text fields PLUS the
        //    extracted text from any attached PDF (RFP/RFQ body has
        //    far more signal than the tender title for category
        //    inference). Cap each PDF contribution to keep regex
        //    passes fast and predictable.
        $pdfBlob = '';
        try {
            foreach ($tender->attachments()->where('extraction_status', 'ok')->get() as $att) {
                $pdfBlob .= ' ' . mb_substr((string) $att->extracted_text, 0, 8000);
            }
        } catch (\Throwable $e) { /* attachments relation missing on legacy boot path */ }

        $haystack = mb_strtolower(implode(' ', array_filter([
            $tender->title,
            $tender->reference,
            $tender->purchasing_org,
            $tender->notes,
            $pdfBlob,
        ])));

        $keywordMap = [
            // Top-level → keywords (any match adds the code)
            '1'  => ['ship ', 'vessel', 'navio', 'embarcação', 'barco'],
            '2'  => ['shipyard', 'estaleiro', 'dock', 'doca', 'pontoon'],
            '3'  => ['fitting', 'window', 'door', 'janela', 'porta', 'shipbuilding steel'],
            '4'  => ['engine', 'motor', 'mtu', 'caterpillar', 'cummins', 'mak', 'man b&w', 'jenbacher', 'gearbox', 'caixa redutora', 'propulsion'],
            '5'  => ['pump', 'bomba', 'valve', 'válvula', 'cooler', 'compressor', 'ar comprimido', 'fuel system', 'cooling', 'hidraulico', 'hydraulic'],
            '6'  => ['propeller', 'hélice', 'thruster', 'rudder', 'leme', 'stabiliser', 'estabilizador', 'schottel'],
            '7'  => ['hvac', 'air conditioning', 'ar condicionado', 'fresh water', 'water treatment', 'fire detection', 'detecção incêndio', 'cctv', 'surveillance'],
            '8'  => ['crane', 'grua', 'lashing', 'pneumatic', 'cargo handling'],
            '9'  => ['electric', 'electrónica', 'electronic', 'cable', 'cabo', 'generator', 'gerador', 'converter', 'switchboard', 'lighting'],
            '10' => ['offshore', 'marine technology', 'subsea', 'polar'],
            '11' => ['port', 'porto', 'harbour', 'logística portuária', 'port security'],
            '12' => ['classification', 'survey', 'consulting', 'design', 'documentation', 'maritime services'],
            '13' => ['military', 'militar', 'defense', 'defesa', 'naval defence', 'arma', 'munição', 'missile', 'torpedo', 'ammunition', 'nato', 'tactical'],
            '14' => ['partyard', 'monitor', 'sensor', 'radar', 'comms', 'sonar'],
            '15' => ['industrial machinery', 'spare', 'sobressalente', 'metrologia', 'compactor', 'shredder', 'rack'],
            '16' => ['cummins', 'sherwood', 'mercedes', 'evac', 'bosch', 'yamaha', 'perkins'],
            '17' => ['communication', 'comunicação', 'radio', 'satcom', 'gsm'],
            '18' => ['medical', 'médico', 'consumível médico', 'orthopedic', 'rehabilitation'],
            '19' => ['transport', 'transporte', 'despachante', 'forwarder', 'freight'],
            '20' => ['palete', 'pallet', 'storage material', 'embalagem'],
        ];

        foreach ($keywordMap as $code => $needles) {
            foreach ($needles as $n) {
                if (str_contains($haystack, $n)) {
                    $codes[] = $code;
                    break;
                }
            }
        }

        $codes = array_values(array_unique(array_filter($codes, fn($c) => $c !== '')));
        sort($codes);

        // Catch-all: tenders that match nothing still need at least
        // one candidate bucket — "industrial machinery & spares" is
        // the broadest umbrella.
        if (empty($codes)) $codes = ['15'];

        return $codes;
    }

    /**
     * Pull internal suppliers matching any of the inferred categories.
     * Strict mode: ONLY validated() rows (status=approved + has email).
     * Auto-extracted PENDING suppliers são deliberadamente excluídos —
     * o operador só vê fornecedores vetted manualmente.
     *
     * If the result is empty, the suggester returns an empty collection
     * and the dashboard mostra apenas as opções web (Tavily) — melhor
     * vazio que sugerir um fornecedor inventado.
     *
     * Ranking entre os validados:
     *   1. iqf_score DESC (best-rated first)
     *   2. last_contacted_at DESC (relação recente primeiro)
     *   3. name ASC
     */
    public function matchLocal(Tender $tender, array $codes, int $limit = 3): Collection
    {
        if (empty($codes)) return collect();

        // 2026-05-21: pedido directo: "ainda está a aparecer como
        // fornecedores aprovados 4lean; AAGE Hempel; AAR são sempre
        // os mesmos, não pode ser". Causa: 63% dos 214 suppliers
        // (136 rows) têm exactamente IQF=3 e nenhum foi contactado.
        // O sort antigo (iqf DESC → last_contacted DESC → name ASC)
        // colapsava sempre em "name ASC alfabético" → os 3 primeiros
        // do alfabeto (4Lean, AAGE, AAR) apareciam em TODOS os tenders.
        //
        // Fix:
        // (a) Score composto: subcategory + brand match contra
        //     keywords do tender dá boost real (não só código numérico).
        // (b) Tie-breaker determinístico-por-tender (MD5(name||id)) em
        //     vez de alfabético: refresh da mesma página dá sempre os
        //     mesmos suppliers (não confuso), mas tenders diferentes
        //     veem suppliers diferentes (variety).

        // Constrói haystack de keywords do tender (já tinhamos a lógica
        // em inferCategories, replicamos o trecho relevante aqui).
        $pdfBlob = '';
        try {
            foreach ($tender->attachments()->where('extraction_status', 'ok')->get() as $att) {
                $pdfBlob .= ' ' . mb_substr((string) $att->extracted_text, 0, 8000);
            }
        } catch (\Throwable $e) { /* ok */ }
        $haystack = mb_strtolower(implode(' ', array_filter([
            $tender->title,
            $tender->reference,
            $tender->purchasing_org,
            $tender->notes,
            $pdfBlob,
        ])));

        // Puxar mais candidatos do que o limit, scoring em PHP, top N.
        // 50 é generoso para tier IQF=3 mas ainda <500ms.
        $candidates = Supplier::validated()
            ->where(function ($w) use ($codes) {
                foreach ($codes as $c) $w->orWhere(fn($q) => $q->inCategory($c));
            })
            ->orderByRaw('iqf_score IS NULL, iqf_score DESC')
            ->orderByRaw('last_contacted_at IS NULL, last_contacted_at DESC')
            ->limit(50)
            ->get();

        // Tender-seeded shuffle para o tie-breaker determinístico.
        $tenderSalt = (string) $tender->id;

        $scored = $candidates->map(function (Supplier $s) use ($haystack, $tenderSalt) {
            $score = (float) ($s->iqf_score ?? 0) * 10;   // base IQF weight

            // Brand match — mais forte (signal directo: o supplier
            // trabalha com este OEM e o tender menciona o OEM).
            foreach ((array) ($s->brands ?? []) as $b) {
                $b = trim(mb_strtolower((string) $b));
                if ($b !== '' && mb_strlen($b) >= 3 && str_contains($haystack, $b)) {
                    $score += 30;
                }
            }
            // Subcategory match — bom signal (specialty alinha com
            // keyword do tender). Subcats são "13.45", "9.4", etc;
            // só usamos o nome legível se existir mas por agora bate
            // pelo código directo é suficiente para boost.
            foreach ((array) ($s->subcategories ?? []) as $sc) {
                $sc = trim(mb_strtolower((string) $sc));
                if ($sc !== '' && str_contains($haystack, $sc)) {
                    $score += 15;
                }
            }
            // Nome do supplier no texto — boost forte (o operador
            // muitas vezes já mencionou o supplier nas notas).
            $nameLow = mb_strtolower($s->name);
            if (mb_strlen($nameLow) >= 4 && str_contains($haystack, $nameLow)) {
                $score += 50;
            }

            // Tie-breaker: hash determinístico do (id, name, tender_id).
            // Pequeno (range 0-9) para não overpower os boosts acima.
            $shuffleTie = hexdec(substr(md5($s->id . '|' . $s->name . '|' . $tenderSalt), 0, 4)) % 1000 / 100.0;

            return ['supplier' => $s, 'score' => $score + $shuffleTie];
        });

        return $scored
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('supplier')
            ->values();
    }

    /**
     * Build a focused Tavily query from the tender title + key org.
     * We strip CIPV/RFP boilerplate that confuses general web search.
     */
    public function buildWebQuery(Tender $tender): string
    {
        $title = trim((string) $tender->title);
        // Strip common procurement boilerplate so the search focuses on
        // the actual product/service, not "tender for".
        $title = preg_replace(
            '/\b(tender|rfq|rfp|request for (?:quote|proposal|tender|information)|procurement|aquisição|aquisicao|contrato|contract|fornecimento)\b/i',
            '',
            $title,
        ) ?? $title;
        $title = preg_replace('/\s{2,}/', ' ', $title) ?? $title;
        $title = trim($title);

        $org = trim((string) $tender->purchasing_org);
        $bits = array_filter([
            mb_substr($title, 0, 120),
            $org !== '' ? "for {$org}" : '',
            'manufacturer OR supplier OR distributor',
        ]);
        return implode(' ', $bits) ?: 'maritime spare parts supplier';
    }

    /**
     * Hit Tavily and shape the response into a small array consumable
     * by the front-end (3-5 results, title + url + snippet).
     */
    private function searchWeb(string $query): array
    {
        try {
            $raw = $this->web->search($query, maxResults: 5, searchDepth: 'basic');
        } catch (\Throwable $e) {
            \Log::warning('TenderSupplierSuggester web search failed', ['error' => $e->getMessage()]);
            return [];
        }

        // The service currently returns formatted text; parse out
        // numbered hits. Pattern: "1. **Title** 80%\n   URL: https://..\n   content..."
        $results = [];
        if (!is_string($raw) || $raw === '') return $results;

        $blocks = preg_split('/\n(?=\d+\.\s+\*\*)/', $raw) ?: [];
        foreach ($blocks as $block) {
            if (!preg_match('/\*\*(.+?)\*\*/', $block, $tm)) continue;
            $title = trim($tm[1]);
            preg_match('/URL:\s*(\S+)/i', $block, $um);
            $url = trim($um[1] ?? '');
            // Snippet = the line after the URL line
            $snippet = '';
            if (preg_match('/URL:\s*\S+\s*\n\s+(.+)/', $block, $sm)) {
                $snippet = mb_substr(trim($sm[1]), 0, 240);
            }
            if ($title === '' || $url === '') continue;
            $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
            if (count($results) >= 5) break;
        }

        return $results;
    }
}
