<?php

namespace App\Services;

use App\Models\Tender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Title-based similarity search across the tenders table.
 *
 * Purpose: when a user opens a tender, show "historical tenders with
 * similar titles" — especially ones that already have a SAP opportunity
 * number, so they can cross-check exact prior deals ("did we quote this
 * same Cartridge Aircraft Fire Extinguisher last year? at what price?").
 *
 * Algorithm:
 *   1. Tokenise both titles (lowercase, strip accents, drop stopwords,
 *      drop tokens shorter than 3 chars).
 *   2. Compute Jaccard similarity: |A ∩ B| / |A ∪ B|.
 *   3. Optionally boost candidates by:
 *        +0.10 same type (Service/Supply)
 *        +0.10 same purchasing_org
 *        +0.15 has sap_opportunity_number (prior real deal)
 *   4. Filter out self (id != source id) and soft-deleted rows.
 *   5. Return top N, sorted by score desc.
 *
 * No FTS required — works on any DB. For production scale (>100k rows)
 * we'd swap to a real inverted index; 280 rows run in <10ms.
 */
class TenderSimilarityService
{
    private const STOPWORDS = [
        'a','ao','aos','as','da','das','de','do','dos','e','em','na','nas','no','nos',
        'o','os','para','por','sem','um','uma','uns','umas',
        'the','a','an','of','for','and','or','to','with','from','on','in','at','by',
        'provision','supply','services','service','works','delivery','sale',
    ];

    /**
     * @return Collection<Tender> top $limit similar tenders, scored desc
     */
    public function findSimilar(Tender $source, int $limit = 5): Collection
    {
        $sourceTokens = $this->tokenise($source->title);
        if (empty($sourceTokens)) {
            return new Collection();
        }

        // Pull a candidate pool. Any tender with at least one token in
        // common *might* be similar; we filter more carefully in PHP.
        //
        // We scope to non-deleted rows and exclude $source itself.
        $candidates = Tender::query()
            ->where('id', '!=', $source->id)
            ->where(function ($q) use ($sourceTokens) {
                foreach ($sourceTokens as $tok) {
                    $q->orWhere('title', 'LIKE', '%' . $tok . '%');
                }
            })
            ->limit(200) // cap before we do O(n) scoring
            ->get();

        $scored = $candidates->map(function (Tender $c) use ($sourceTokens, $source) {
            $candTokens = $this->tokenise($c->title);
            $intersect  = array_intersect($sourceTokens, $candTokens);
            $union      = array_unique(array_merge($sourceTokens, $candTokens));
            $jaccard    = empty($union) ? 0.0 : count($intersect) / count($union);

            $score = $jaccard;

            if ($source->type && $c->type === $source->type)                      $score += 0.10;
            if ($source->purchasing_org && $c->purchasing_org === $source->purchasing_org) $score += 0.10;
            if (!empty($c->sap_opportunity_number))                               $score += 0.15;

            return [
                'tender' => $c,
                'score'  => round($score, 3),
            ];
        });

        return $scored
            ->filter(fn($s) => $s['score'] > 0.15)       // drop weak matches
            ->sortByDesc('score')
            ->take($limit)
            ->map(function ($entry) {
                /** @var Tender $t */
                $t = $entry['tender'];
                $t->similarity_score = $entry['score']; // attach transient attr for the view
                return $t;
            })
            ->values()
            ->pipe(fn($c) => new Collection($c));
    }

    /**
     * Lowercase, strip accents, split on non-letter, drop stopwords & short tokens.
     *
     * "Provision of Cartridge, Aircraft Fire Extinguisher" →
     *   ['cartridge','aircraft','fire','extinguisher']
     */
    private function tokenise(?string $title): array
    {
        if (!$title) return [];
        $ascii = Str::ascii($title);
        $lower = mb_strtolower($ascii);
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $filtered = array_filter(
            $parts,
            fn($t) => mb_strlen($t) >= 3 && !in_array($t, self::STOPWORDS, true)
        );
        return array_values(array_unique($filtered));
    }
}
