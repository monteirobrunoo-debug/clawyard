<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'title', 'source', 'file_path', 'content', 'summary', 'chunks', 'metadata',
    ];

    protected $casts = [
        'chunks'   => 'array',
        'metadata' => 'array',
    ];

    /**
     * Relevance-scored keyword search for RAG.
     *
     * Scoring:
     *   - Title match:   +10 per keyword occurrence  (title is a strong signal)
     *   - Content match: +1 per keyword occurrence
     *   - Normalised by sqrt(word_count) to avoid long docs dominating
     *   - Minimum score threshold: 8 (prevents low-signal noise docs)
     *   - Minimum distinct keyword hits: 2 (avoids single-word false positives)
     *   - Source filter: if $sources is provided, only include those sources
     *
     * @param  string        $query
     * @param  int           $limit
     * @param  array|null    $sources   e.g. ['partyard','defense'] — null = all
     * @param  int           $minScore  minimum normalised score to include
     * @return Document[]
     */
    public static function search(
        string $query,
        int    $limit     = 3,
        ?array $sources   = null,
        int    $minScore  = 4
    ): array {
        // Extract meaningful keywords (>3 chars, deduplicated)
        $raw      = preg_split('/\s+/', strtolower($query));
        $stopwords = ['para', 'como', 'mais', 'pelo', 'pela', 'com', 'uma', 'uns', 'das', 'dos',
                      'this', 'that', 'with', 'from', 'have', 'they', 'will', 'what', 'when',
                      'where', 'which', 'their', 'were', 'been', 'each', 'into'];
        $keywords = array_unique(array_filter($raw, fn($k) =>
            strlen($k) > 3 && !in_array($k, $stopwords)
        ));

        if (empty($keywords)) return [];

        // Base query — optionally filter by source
        $q = self::select('id', 'title', 'source', 'content', 'summary');
        if ($sources !== null && count($sources) > 0) {
            $q->whereIn('source', $sources);
        }
        $documents = $q->get();

        if ($documents->isEmpty()) return [];

        $results = [];

        foreach ($documents as $doc) {
            $titleLower   = strtolower($doc->title ?? '');
            $contentLower = strtolower($doc->content ?? '');
            $wordCount    = max(1, str_word_count($contentLower));

            $rawScore        = 0;
            $distinctHits    = 0;

            foreach ($keywords as $keyword) {
                $titleHits   = substr_count($titleLower, $keyword);
                $contentHits = substr_count($contentLower, $keyword);

                if ($titleHits > 0 || $contentHits > 0) {
                    $distinctHits++;
                    $rawScore += ($titleHits * 10) + $contentHits;
                }
            }

            // Require at least 2 distinct keyword matches to avoid single-word noise
            if ($distinctHits < 2) continue;

            // Normalise by sqrt(word_count) — penalises huge docs that match by volume
            $normScore = $rawScore / sqrt($wordCount);

            if ($normScore >= $minScore) {
                $results[] = ['doc' => $doc, 'score' => $normScore];
            }
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_map(fn($r) => $r['doc'], $results), 0, $limit);
    }
}
