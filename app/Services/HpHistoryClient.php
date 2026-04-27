<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the `hp-history` service — the pgvector-backed
 * company-memory droplet that indexes PartYard / H&P-Group historical
 * PDFs, emails, proposals and contracts.
 *
 * Surface:
 *   • search(query, ?filters): returns up to `max_results` chunks with
 *     {id, source, title, snippet, score, metadata, citation_url}.
 *   • Optional augmentContextFor($message): LLM-friendly markdown
 *     block ready to paste under an agent's user message, or null
 *     when the message doesn't warrant a history lookup.
 *
 * Properties:
 *   • OFF by default. Set HP_HISTORY_ENABLED=true (+ base_url + hmac
 *     secret) to turn on. Off-state never raises — every call simply
 *     returns null/[] so the agents continue with their other context
 *     paths.
 *   • Authenticated with HMAC-SHA256 over a canonical
 *     "{ts}.{method}.{path}.{body_sha256}" string (replay-protected
 *     by a 5-minute timestamp window verified on the server).
 *   • Cached in Redis (or whatever cache driver is wired) for
 *     `cache_ttl` seconds keyed by SHA-256(query + filters).
 *   • Single retry on 5xx / connection error; otherwise log + return [].
 *
 * The same client is intended to be shared between Marco (sales) and
 * Vasco (vessel) — both want to know "what did we do with this
 * partner / port / engine before?" and both pass the user's raw
 * message into augmentContextFor.
 */
class HpHistoryClient
{
    private array $config;
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->config = (array) config('services.hp_history', []);

        $baseUrl = (string) ($this->config['base_url'] ?? '');
        $this->http = $http ?: new Client([
            'base_uri'        => $baseUrl ?: 'http://invalid.local/',  // never reached when disabled
            'timeout'         => (int) ($this->config['timeout'] ?? 8),
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && !empty($this->config['base_url'])
            && !empty($this->config['hmac_secret']);
    }

    /**
     * Trigger words that indicate the user wants historical context.
     * The match is intentionally loose — false positives are cheap
     * (we just hit the cache and add nothing useful), false negatives
     * are expensive (the LLM hallucinates a precedent that doesn't exist).
     */
    private const HISTORY_INTENT_TOKENS = [
        // Portuguese
        'histórico', 'historico', 'última vez', 'ultima vez',
        'da última', 'da ultima', 'no passado', 'antes',
        'já fizemos', 'ja fizemos', 'já tivemos', 'ja tivemos',
        'precedente', 'precedentes', 'experiência', 'experiencia',
        // English
        'history', 'historical', 'last time', 'previously',
        'past', 'precedent', 'we did', 'we have done',
        // Domain hints — very specific tokens that nearly always benefit
        // from precedent lookup
        'rfq', 'tender win', 'won', 'lost the deal', 'lost', 'last contract',
        'lessons learned', 'post-mortem',
    ];

    public function looksLikeHistoryQuestion(string $lower): bool
    {
        foreach (self::HISTORY_INTENT_TOKENS as $tok) {
            if (str_contains($lower, $tok)) return true;
        }
        return false;
    }

    /**
     * Optional convenience for agents: hand it the raw user message and
     * a `domain` hint ('spares' | 'repair' | null) and either receive a
     * markdown block ready to paste, or `null` if no useful history.
     *
     * @return string|null
     */
    public function augmentContextFor(string $message, ?string $domain = null): ?string
    {
        if (!$this->isEnabled()) return null;

        $lower = mb_strtolower(trim($message));
        if ($lower === '' || !$this->looksLikeHistoryQuestion($lower)) {
            return null;
        }

        $filters = [];
        if ($domain) $filters['domain'] = $domain;

        $hits = $this->search($message, $filters);
        if (empty($hits)) return null;

        return $this->renderBlock($hits, $domain);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $filters  passed verbatim to the server
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, array $filters = []): array
    {
        if (!$this->isEnabled()) return [];

        $body = [
            'query'       => $query,
            'max_results' => (int) ($this->config['max_results'] ?? 5),
            'filters'     => $filters,
        ];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

        $cacheKey = 'hp_history:search:' . hash('sha256', $payload);
        $ttl      = (int) ($this->config['cache_ttl'] ?? 300);

        return Cache::remember($cacheKey, $ttl, function () use ($payload) {
            return $this->postWithRetry('/search', $payload);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function postWithRetry(string $path, string $payload): array
    {
        $attempts = 0;
        $lastErr  = null;

        while ($attempts < 2) {
            $attempts++;
            try {
                $headers = $this->signHeaders('POST', $path, $payload);
                $res = $this->http->post($path, [
                    'headers' => $headers,
                    'body'    => $payload,
                ]);
                $status = $res->getStatusCode();
                $raw    = (string) $res->getBody();

                if ($status >= 200 && $status < 300) {
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded) || !isset($decoded['hits'])) {
                        Log::warning('HpHistory: malformed response', ['raw' => mb_substr($raw, 0, 400)]);
                        return [];
                    }
                    return $decoded['hits'];
                }

                if ($status >= 500) {
                    // Retry once on transient server error.
                    $lastErr = "5xx from hp-history: {$status}";
                    continue;
                }

                // 4xx — not our problem to fix automatically.
                Log::warning('HpHistory: client error', ['status' => $status, 'body' => mb_substr($raw, 0, 400)]);
                return [];
            } catch (GuzzleException $e) {
                $lastErr = $e->getMessage();
                // network / timeout — retry once
            }
        }

        Log::warning('HpHistory: search failed after retry — ' . ($lastErr ?? 'unknown'));
        return [];
    }

    /**
     * Build the HMAC headers expected by the server. The canonical string
     * is "{ts}.{method}.{path}.{sha256(body)}" — purposely simple so
     * the FastAPI side has nothing exotic to parse. Tolerance is 300s on
     * the server.
     *
     * @return array<string, string>
     */
    public function signHeaders(string $method, string $path, string $body): array
    {
        $ts        = (string) time();
        $bodyHash  = hash('sha256', $body);
        $canonical = "{$ts}.{$method}.{$path}.{$bodyHash}";
        $secret    = (string) ($this->config['hmac_secret'] ?? '');
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            'X-HP-Timestamp'  => $ts,
            'X-HP-Signature'  => $signature,
            // Body hash is on the wire too so the server doesn't have to
            // re-buffer for verification when streaming.
            'X-HP-Body-SHA256' => $bodyHash,
        ];
    }

    /**
     * Render the LLM-pasteable block. We tag it `<hp_history>` so the
     * agent prompts can distinguish it from `<partner_workshops>`.
     *
     * @param array<int, array<string, mixed>> $hits
     */
    public function renderBlock(array $hits, ?string $domain = null): string
    {
        $lines = [];
        $tag = $domain ? "<hp_history domain=\"{$domain}\">" : '<hp_history>';
        $lines[] = $tag;
        $lines[] = sprintf(
            '%d historical reference(s) from PartYard / H&P-Group archive. CITE the source field verbatim — do not invent dates or order numbers.',
            count($hits)
        );
        foreach ($hits as $h) {
            $title    = (string) ($h['title'] ?? '(untitled)');
            $source   = (string) ($h['source'] ?? '?');
            $snippet  = trim((string) ($h['snippet'] ?? ''));
            $score    = isset($h['score']) ? sprintf('%.2f', (float) $h['score']) : '?';
            $citation = (string) ($h['citation_url'] ?? '');

            $lines[] = sprintf('- **%s** [%s, score=%s]', $title, $source, $score);
            if ($snippet !== '') {
                // Cap snippet so the block stays under ~600 tokens even
                // with 5 results.
                $lines[] = '  ' . mb_substr($snippet, 0, 280) . (mb_strlen($snippet) > 280 ? '…' : '');
            }
            if ($citation !== '') {
                $lines[] = "  · cite: {$citation}";
            }
        }
        $lines[] = '</hp_history>';
        return implode("\n", $lines);
    }
}
