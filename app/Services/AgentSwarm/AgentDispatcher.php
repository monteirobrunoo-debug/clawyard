<?php

namespace App\Services\AgentSwarm;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single-shot Anthropic Messages API call used by the swarm
 * orchestrator. Distinct from the agents' own `chat()` paths
 * (which carry full conversation history + shared bus + augment
 * pipelines) — the swarm needs a stateless one-prompt-one-response
 * exchange where each agent contributes a structured analysis.
 *
 * Responsibilities:
 *   • Inject the Anthropic API key from config + headers.
 *   • Build the request body (model, max_tokens, system prompt,
 *     user message).
 *   • Map Anthropic's usage object onto our cost ledger
 *     (input + output tokens × per-1M rates).
 *   • Single retry on 5xx / connect error; persistent error logged.
 *   • Returns a normalised result dict so the runner doesn't have
 *     to know about the Anthropic response shape.
 *
 * Cost rates (USD per 1M tokens) come from config/services.php and
 * default to Sonnet 4.6 pricing — bump in env when Anthropic changes
 * their card.
 */
class AgentDispatcher
{
    private Client $http;
    private string $apiKey;
    private string $defaultModel;
    private string $baseUri;
    private int $timeoutSeconds;

    public function __construct(?Client $http = null)
    {
        $this->apiKey       = (string) config('services.anthropic.api_key', '');
        $this->defaultModel = (string) config('services.anthropic.model', 'claude-sonnet-4-6');
        $this->baseUri      = (string) config('services.anthropic.base_uri', 'https://api.anthropic.com');
        $this->timeoutSeconds = (int) config('services.agent_swarm.dispatch_timeout_seconds', 60);

        $this->http = $http ?: new Client([
            'base_uri'        => rtrim($this->baseUri, '/') . '/',
            'timeout'         => $this->timeoutSeconds,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ]);
    }

    /**
     * Issue one Messages call. Returns:
     *   [
     *     'ok'         => bool,
     *     'text'       => string         // assistant's reply
     *     'model'      => string
     *     'tokens_in'  => int
     *     'tokens_out' => int
     *     'cost_usd'   => float
     *     'ms'         => int
     *     'error'      => string|null    // non-null when ok=false
     *   ]
     *
     * Never throws — failures come back as ok=false so the caller
     * (AgentSwarmRunner) can record the failure in chain_log without
     * aborting the whole chain.
     *
     * @param string $systemPrompt   per-agent role / persona
     * @param string $userMessage    the prompt with signal + prior context
     * @param int    $maxTokens      Anthropic max_tokens (default 1500)
     * @param string|null $model     override default model (e.g. 'claude-haiku-4-5-…')
     */
    public function dispatch(
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 1500,
        ?string $model = null,
    ): array {
        if ($this->apiKey === '') {
            return $this->failure('anthropic_api_key_missing', 0);
        }

        $started = (int) (microtime(true) * 1000);
        $model = $model ?: $this->defaultModel;

        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        $lastErr = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $res = $this->http->post('v1/messages', [
                    'headers' => [
                        'x-api-key'         => $this->apiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                        'accept'            => 'application/json',
                    ],
                    'json' => $body,
                ]);
                $status = $res->getStatusCode();
                $raw    = (string) $res->getBody();

                if ($status >= 200 && $status < 300) {
                    return $this->parseSuccess($raw, $model, $started);
                }

                if ($status >= 500) {
                    $lastErr = "anthropic_5xx_{$status}";
                    continue;     // retry once
                }

                // 4xx — not retryable.
                $lastErr = "anthropic_4xx_{$status}: " . mb_substr($raw, 0, 300);
                break;
            } catch (GuzzleException|Throwable $e) {
                $lastErr = 'anthropic_transport: ' . $e->getMessage();
                // network glitch — retry once
            }
        }

        Log::warning('AgentDispatcher: failed', ['error' => $lastErr, 'model' => $model]);
        return $this->failure($lastErr ?? 'unknown', (int) (microtime(true) * 1000) - $started);
    }

    /**
     * Parse a 200 OK response. Anthropic returns:
     *   { content: [{type: 'text', text: '...'}], usage: {input_tokens, output_tokens}, model: '...' }
     */
    private function parseSuccess(string $raw, string $model, int $started): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->failure('anthropic_json_decode', (int) (microtime(true) * 1000) - $started);
        }

        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= ($block['text'] ?? '');
            }
        }

        $tokensIn  = (int) ($data['usage']['input_tokens']  ?? 0);
        $tokensOut = (int) ($data['usage']['output_tokens'] ?? 0);
        $cost      = $this->priceFor($model, $tokensIn, $tokensOut);
        $modelEff  = (string) ($data['model'] ?? $model);

        return [
            'ok'         => true,
            'text'       => $text,
            'model'      => $modelEff,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd'   => $cost,
            'ms'         => (int) (microtime(true) * 1000) - $started,
            'error'      => null,
        ];
    }

    private function failure(string $error, int $ms): array
    {
        return [
            'ok'         => false,
            'text'       => '',
            'model'      => $this->defaultModel,
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'cost_usd'   => 0.0,
            'ms'         => $ms,
            'error'      => $error,
        ];
    }

    /**
     * Map (model, tokens) → USD cost. Rates from config; defaults
     * match Sonnet 4.6 published pricing as of 2026-04. Update via
     * env when Anthropic changes their card.
     */
    private function priceFor(string $model, int $tokensIn, int $tokensOut): float
    {
        $rates = (array) config('services.agent_swarm.token_rates', []);
        $key   = $this->matchModel($model, $rates);
        $card  = $rates[$key] ?? ['input' => 3.0, 'output' => 15.0]; // sonnet default

        $cost = ($tokensIn  / 1_000_000) * (float) ($card['input']  ?? 3.0)
              + ($tokensOut / 1_000_000) * (float) ($card['output'] ?? 15.0);

        return round($cost, 6);
    }

    /**
     * Find the rate card for a model. Allows partial matches so e.g.
     * 'claude-sonnet-4-6-20250109' falls under the 'sonnet' card
     * without us needing to enumerate every minor version.
     */
    private function matchModel(string $model, array $rates): string
    {
        $lower = strtolower($model);
        foreach (array_keys($rates) as $key) {
            if (str_contains($lower, strtolower($key))) return $key;
        }
        // Tier inference fallback: sonnet, opus, haiku.
        if (str_contains($lower, 'haiku')) return 'haiku';
        if (str_contains($lower, 'opus'))  return 'opus';
        return 'sonnet';
    }
}
