<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\Tender;
use Illuminate\Support\Collection;

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
    ) {}

    /**
     * Run the full suggester pipeline for a tender.
     *
     * @return array{
     *   categories: array<string>,
     *   local: Collection<Supplier>,
     *   web: array<int, array{title:string,url:string,snippet:string}>,
     *   web_available: bool,
     *   query: string
     * }
     */
    public function suggest(Tender $tender, int $localLimit = 12, bool $includeWeb = true): array
    {
        $categories = $this->inferCategories($tender);

        $local = $this->matchLocal($tender, $categories, $localLimit);

        $webResults = [];
        $webAvailable = $this->web->isAvailable();
        $query = $this->buildWebQuery($tender);

        if ($includeWeb && $webAvailable) {
            $webResults = $this->searchWeb($query);
        }

        return [
            'categories'    => $categories,
            'local'         => $local,
            'web'           => $webResults,
            'web_available' => $webAvailable,
            'query'         => $query,
        ];
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

        // 2. Keyword scan over the tender's free-text fields
        $haystack = mb_strtolower(implode(' ', array_filter([
            $tender->title,
            $tender->reference,
            $tender->purchasing_org,
            $tender->notes,
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
     * Ranking:
     *   1. has primary_email (we can actually reach them)
     *   2. iqf_score DESC (best-rated first)
     *   3. last_contacted_at DESC (recent relationships first)
     *   4. name ASC
     */
    public function matchLocal(Tender $tender, array $codes, int $limit = 12): Collection
    {
        if (empty($codes)) return collect();

        $query = Supplier::contactable()
            ->where(function ($w) use ($codes) {
                foreach ($codes as $c) $w->orWhere(fn($q) => $q->inCategory($c));
            });

        return $query
            ->orderByRaw('primary_email IS NULL')
            ->orderByRaw('iqf_score IS NULL, iqf_score DESC')
            ->orderByRaw('last_contacted_at IS NULL, last_contacted_at DESC')
            ->orderBy('name')
            ->limit($limit)
            ->get();
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
