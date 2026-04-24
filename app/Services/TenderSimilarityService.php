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
 * Algorithm (v2 — stricter after user reported false positives):
 *   1. Tokenise both titles (lowercase, strip accents, drop stopwords,
 *      drop tokens shorter than 3 chars).
 *   2. Require SUBSTANTIVE overlap before scoring at all:
 *        • at least 2 meaningful tokens in common, OR
 *        • at least 1 domain-discriminator token (length ≥ 6) in common.
 *      Rationale: the old version triggered 43% "similarity" between
 *      "Circuit card … PATRIOT" and "Generator regulator valve" just
 *      because both contained the generic word "electrical". By
 *      requiring either plural overlap or one long, specific token, the
 *      generic-word false positive disappears (patriot / cartridge /
 *      circuit — the real discriminators — stay).
 *   3. Compute Jaccard similarity: |A ∩ B| / |A ∪ B|.
 *   4. Apply bonuses as MULTIPLIERS (not additions), so a weak Jaccard
 *      can no longer be rescued by "same type + has SAP" pumping the
 *      score above the threshold:
 *        ×1.20 same type (Service/Supply)
 *        ×1.20 same purchasing_org
 *        ×1.30 has sap_opportunity_number (prior real deal)
 *   5. Filter out self (id != source id) and soft-deleted rows.
 *   6. Return top N above threshold (0.25), sorted by score desc.
 *
 * No FTS required — works on any DB. For production scale (>100k rows)
 * we'd swap to a real inverted index; 280 rows run in <10ms.
 */
class TenderSimilarityService
{
    private const STOPWORDS = [
        // PT/EN articles, prepositions, connectors
        'a','ao','aos','as','da','das','de','do','dos','e','em','na','nas','no','nos',
        'o','os','para','por','sem','um','uma','uns','umas',
        'the','an','of','for','and','or','to','with','from','on','in','at','by',
        // Procurement boilerplate (appears in every title, zero discriminator value)
        'provision','supply','services','service','works','delivery','sale','purchase',
        'provide','providing','procurement','acquisition',
        // Generic "and various other …" filler that inflated false positives
        // (the PATRIOT vs Generator case). These are in >30% of tender titles
        // and mean nothing by themselves.
        'other','others','various','misc','miscellaneous','etc',
        'item','items','part','parts','piece','pieces','unit','units',
        'any','new','used','general','standard','multiple','several',
        // "electrical component" / "mechanical component" / "assembly" appear
        // constantly — keep them for context but demote via stopword so they
        // don't count as overlap on their own.
        'component','components','assembly','assemblies',
        'equipment','equipments','material','materials','kit','kits',
        // Domain-generic adjectives — present in many titles, not discriminative
        // on their own (the real signal is the noun they modify).
        'electrical','electronic','electronics','mechanical','hydraulic',
        'pneumatic','optical','thermal','system','systems','module','modules',
    ];

    /**
     * Threshold a scored candidate must beat to be shown.
     *
     * Set to 0.15 to match the user-facing copy on tenders/show.blade.php
     * ("correspondências >15% de similaridade"). Combined with the overlap
     * gate (≥2 tokens OR 1 discriminator ≥6 chars) and multiplicative
     * bonuses, this still rejects the PATRIOT-vs-Generator false positive
     * (shared only the generic word "electrical", now a stopword) while
     * letting real matches through — especially single-discriminator
     * matches like two cartridge tenders from different years that share
     * just "cartridge" + "extinguisher" but with a SAP history bonus.
     */
    private const SCORE_THRESHOLD = 0.15;

    /** Minimum token length for a single overlap to count as "discriminator". */
    private const DISCRIMINATOR_MIN_LEN = 6;

    /**
     * @return Collection<Tender> top $limit similar tenders, scored desc
     */
    public function findSimilar(Tender $source, int $limit = 5): Collection
    {
        $sourceTokens = $this->tokenise($source->title);
        if (empty($sourceTokens)) {
            return new Collection();
        }

        // Pull a candidate pool. We only LIKE-match on tokens of length ≥ 4
        // so noise like "mi" or short acronyms doesn't pull in half the
        // table; the discriminative terms (patriot, cartridge, circuit…)
        // are all longer than that anyway.
        $likeTokens = array_filter($sourceTokens, fn($t) => mb_strlen($t) >= 4);
        if (empty($likeTokens)) $likeTokens = $sourceTokens; // fallback

        $candidates = Tender::query()
            ->where('id', '!=', $source->id)
            ->where(function ($q) use ($likeTokens) {
                foreach ($likeTokens as $tok) {
                    $q->orWhere('title', 'LIKE', '%' . $tok . '%');
                }
            })
            ->limit(200) // cap before we do O(n) scoring
            ->get();

        $scored = $candidates->map(function (Tender $c) use ($sourceTokens, $source) {
            $candTokens = $this->tokenise($c->title);
            $intersect  = array_values(array_intersect($sourceTokens, $candTokens));
            $union      = array_unique(array_merge($sourceTokens, $candTokens));
            $jaccard    = empty($union) ? 0.0 : count($intersect) / count($union);

            // Overlap gate — reject matches where the intersection is just
            // one generic word. Either we have multiple tokens in common, or
            // we have one long "discriminator" token (length ≥ 6 → things
            // like "patriot", "cartridge", "circuit" carry real signal).
            $hasDiscriminator = false;
            foreach ($intersect as $tok) {
                if (mb_strlen($tok) >= self::DISCRIMINATOR_MIN_LEN) {
                    $hasDiscriminator = true;
                    break;
                }
            }
            $hasSubstantialOverlap = count($intersect) >= 2 || $hasDiscriminator;
            if (!$hasSubstantialOverlap) {
                return ['tender' => $c, 'score' => 0.0];
            }

            // Bonuses are MULTIPLIERS on top of Jaccard (not flat additions).
            // Before this change, a weak 0.10 Jaccard could be pumped to
            // ~0.45 purely by same-type + SAP-exists + same-org bonuses,
            // producing the PATRIOT-vs-Generator false positive the user
            // reported. Multiplying instead means bonuses only amplify
            // matches that already share real vocabulary.
            $multiplier = 1.0;
            if ($source->type && $c->type === $source->type)                                $multiplier += 0.20;
            if ($source->purchasing_org && $c->purchasing_org === $source->purchasing_org) $multiplier += 0.20;
            if (!empty($c->sap_opportunity_number))                                         $multiplier += 0.30;

            $score = $jaccard * $multiplier;

            return [
                'tender' => $c,
                'score'  => round($score, 3),
            ];
        });

        return $scored
            ->filter(fn($s) => $s['score'] >= self::SCORE_THRESHOLD)
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
