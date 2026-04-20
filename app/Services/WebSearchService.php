<?php

namespace App\Services;

use GuzzleHttp\Client;

class WebSearchService
{
    protected Client $http;
    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.tavily.api_key');
        $this->http   = new Client(['timeout' => 10, 'connect_timeout' => 5]);
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Search the web and return formatted results string.
     *
     * @param string   $query
     * @param int      $maxResults
     * @param string   $searchDepth  'basic' | 'advanced'
     * @param int|null $days         Filter results published in last N days (null = no filter)
     */
    public function search(string $query, int $maxResults = 5, string $searchDepth = 'basic', ?int $days = null): string
    {
        if (!$this->isAvailable()) {
            return '(Web search not available — TAVILY_API_KEY not configured)';
        }

        // Tavily API requires queries between 2 and 400 characters.
        // Clamp before sending to avoid 400 responses.
        $clamped = $this->clampQuery($query);
        if ($clamped === null) {
            \Log::info('WebSearchService: query too short, skipping', ['query' => $query]);
            return '(Query too short for web search — min 2 chars)';
        }
        $query = $clamped;

        try {
            $payload = [
                'api_key'             => $this->apiKey,
                'query'               => $query,
                'max_results'         => $maxResults,
                'search_depth'        => $searchDepth,
                'include_answer'      => true,
                'include_raw_content' => false,
            ];

            // Tavily `days` param: restrict to results published within last N days
            if ($days !== null && $days > 0) {
                $payload['days'] = $days;
            }

            $response = $this->http->post('https://api.tavily.com/search', [
                'json' => $payload,
            ]);

            $data    = json_decode($response->getBody()->getContents(), true);
            $lines   = [];
            $answer  = $data['answer'] ?? null;

            if ($answer) {
                $lines[] = "**Summary:** " . $this->safeUtf8($answer);
                $lines[] = '';
            }

            foreach ($data['results'] ?? [] as $i => $r) {
                $title   = $this->safeUtf8($r['title']   ?? 'No title');
                $url     = $this->safeUtf8($r['url']     ?? '');
                $content = mb_substr($this->safeUtf8($r['content'] ?? ''), 0, 400);
                $score   = isset($r['score']) ? round($r['score'] * 100) . '%' : '';
                $lines[] = ($i + 1) . ". **{$title}** {$score}";
                $lines[] = "   URL: {$url}";
                $lines[] = "   {$content}";
                $lines[] = '';
            }

            return implode("\n", $lines) ?: '(No results found)';

        } catch (\Throwable $e) {
            \Log::warning('WebSearchService error: ' . $e->getMessage());
            return '(Web search failed: ' . $e->getMessage() . ')';
        }
    }

    /**
     * Quick search — returns just the answer + top 3 URLs
     */
    public function quickSearch(string $query): string
    {
        return $this->search($query, 3, 'basic');
    }

    /**
     * Deep search — more results, better quality
     */
    public function deepSearch(string $query): string
    {
        return $this->search($query, 8, 'advanced');
    }

    /**
     * Normalize query to fit Tavily API constraints (2 ≤ length ≤ 400).
     *
     * - Trims whitespace.
     * - Returns null when shorter than 2 chars (caller should skip the call).
     * - Truncates at word boundary when longer than 400 chars.
     */
    private function clampQuery(string $query): ?string
    {
        $query = trim($query);
        $len   = mb_strlen($query);

        if ($len < 2) {
            return null;
        }
        if ($len <= 400) {
            return $query;
        }

        $truncated = mb_substr($query, 0, 400);
        $lastSpace = mb_strrpos($truncated, ' ');
        // Keep at least half the budget to avoid cutting too aggressively
        if ($lastSpace !== false && $lastSpace >= 200) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return rtrim($truncated);
    }

    /**
     * Sanitize a string to valid UTF-8, replacing or removing invalid byte sequences.
     * Prevents "Malformed UTF-8" errors when Guzzle JSON-encodes the API request body.
     */
    private function safeUtf8(string $str): string
    {
        // Convert from detected encoding to UTF-8; ignore/replace invalid sequences
        $converted = @mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        // As a second pass, strip any remaining invalid bytes via iconv
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $converted ?? $str);
        return $clean !== false ? $clean : '';
    }
}
