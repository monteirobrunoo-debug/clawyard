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
     * Simple keyword-based search for RAG.
     */
    public static function search(string $query, int $limit = 3): array
    {
        $keywords = explode(' ', strtolower($query));
        $results  = [];

        $documents = self::all();

        foreach ($documents as $doc) {
            $score   = 0;
            $content = strtolower($doc->content);

            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 3 && str_contains($content, $keyword)) {
                    $score += substr_count($content, $keyword);
                }
            }

            if ($score > 0) {
                $results[] = ['doc' => $doc, 'score' => $score];
            }
        }

        usort($results, fn($a, $b) => $b['score'] - $a['score']);

        return array_slice(array_map(fn($r) => $r['doc'], $results), 0, $limit);
    }
}
