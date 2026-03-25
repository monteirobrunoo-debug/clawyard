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
                $lines[] = "**Summary:** {$answer}";
                $lines[] = '';
            }

            foreach ($data['results'] ?? [] as $i => $r) {
                $title   = $r['title']   ?? 'No title';
                $url     = $r['url']     ?? '';
                $content = substr($r['content'] ?? '', 0, 400);
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
}
