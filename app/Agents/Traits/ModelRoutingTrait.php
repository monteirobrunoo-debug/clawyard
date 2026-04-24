<?php

namespace App\Agents\Traits;

use App\Support\SensitivityClassifier;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Hybrid routing — pick Claude or a locally-hosted model based on the
 * sensitivity classifier's tier.
 *
 * How it fits
 * -----------
 * Agents call `$this->routedChat($messages, $system, $opts)`. The trait:
 *   1. Runs SensitivityClassifier::classify() on the *unscrubbed* payload.
 *   2. Picks a backend:
 *        - low / medium → Claude via anthropicGuzzleClient() (this
 *          still redacts + signs — the existing pipeline).
 *        - high → local model endpoint (LOCAL_LLM_ENDPOINT), never
 *          leaves the VPC.
 *   3. Returns the response in Anthropic's /v1/messages shape so agent
 *      code downstream doesn't know which backend answered.
 *
 * Why classify *before* redaction?
 * --------------------------------
 * The classifier should see the original signal. After redaction the
 * PII density is artificially 0 and the keyword triggers may be
 * masked. This is the ONLY place in the pipeline that reads raw
 * customer data; the classifier itself has no network or disk
 * side-effects (see SensitivityClassifier docblock).
 *
 * Configuration
 * -------------
 * `config/services.php` → `hybrid`:
 *   enabled           → bool, default false
 *   local_endpoint    → http://127.0.0.1:11434/v1/chat/completions
 *                       (ollama compatible) — shape is OpenAI-ish
 *   local_model       → llama3.1:8b-instruct-q5_K_M (or whatever)
 *   force_tier        → optional override for tests: "low"|"medium"|"high"
 *
 * When `hybrid.enabled=false`, this trait degrades to the existing
 * Claude-only path — zero behavioural change.
 */
trait ModelRoutingTrait
{
    use AnthropicKeyTrait;

    /**
     * @param array<int,array{role:string,content:string|array}> $messages
     * @return array{content:string,tier:string,backend:string,usage:array<string,mixed>}
     */
    protected function routedChat(array $messages, string $system = '', array $opts = []): array
    {
        $enabled = (bool) config('services.hybrid.enabled', false);
        $forced  = $opts['force_tier'] ?? null;

        $classification = SensitivityClassifier::classify($messages);
        $tier = is_string($forced) ? $forced : $classification['tier'];

        // Structured log line — useful to measure traffic mix before we
        // actually wire a local model. Emits even when disabled so you
        // can see what WOULD have been routed.
        logger()->info('hybrid.classify', [
            'tier'    => $tier,
            'score'   => $classification['score'],
            'signals' => $classification['signals'],
            'routed'  => $enabled ? $tier : 'claude (disabled)',
        ]);

        if ($enabled && $tier === SensitivityClassifier::TIER_HIGH) {
            return $this->callLocalModel($messages, $system, $classification, $opts);
        }

        return $this->callClaude($messages, $system, $classification, $opts);
    }

    /** @return array{content:string,tier:string,backend:string,usage:array<string,mixed>} */
    private function callClaude(array $messages, string $system, array $classification, array $opts): array
    {
        $client = self::anthropicGuzzleClient();
        $response = $client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
            'json'    => array_filter([
                'model'      => $opts['model'] ?? config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => $opts['max_tokens'] ?? 4096,
                'system'     => $system !== '' ? $system : null,
                'messages'   => $messages,
            ], fn ($v) => $v !== null),
        ]);
        $body = json_decode((string) $response->getBody(), true) ?: [];
        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        return [
            'content' => $text,
            'tier'    => $classification['tier'],
            'backend' => 'claude',
            'usage'   => $body['usage'] ?? [],
        ];
    }

    /**
     * @return array{content:string,tier:string,backend:string,usage:array<string,mixed>}
     */
    private function callLocalModel(array $messages, string $system, array $classification, array $opts): array
    {
        $endpoint = (string) config('services.hybrid.local_endpoint', '');
        $model    = $opts['local_model']
            ?? config('services.hybrid.local_model', 'llama3.1:8b-instruct-q5_K_M');

        if ($endpoint === '') {
            // Local backend not wired yet. Fail closed: we do NOT fall
            // back to Claude, because the whole point of tier=high is
            // "this prompt must stay local". Better to surface the
            // misconfiguration than to silently leak.
            throw new \RuntimeException(
                'Hybrid routing picked local model but services.hybrid.local_endpoint is empty. '
                . 'Refusing to fall back to an external model.'
            );
        }

        // ollama + llama.cpp server expose an OpenAI-compatible
        // /v1/chat/completions. Convert from Anthropic's messages[]
        // shape to OpenAI's (role + string content).
        $openaiMessages = [];
        if ($system !== '') {
            $openaiMessages[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $m) {
            $content = $m['content'];
            if (is_array($content)) {
                // Flatten text blocks; ignore images/docs (local model
                // may not support them — future work).
                $content = collect($content)
                    ->filter(fn ($b) => is_array($b) && ($b['type'] ?? '') === 'text')
                    ->map(fn ($b) => $b['text'] ?? '')
                    ->implode("\n");
            }
            $openaiMessages[] = [
                'role'    => $m['role'] ?? 'user',
                'content' => $content,
            ];
        }

        $client = new Client([
            'base_uri'        => $endpoint,
            'timeout'         => 180,
            'connect_timeout' => 5,
        ]);
        $response = $client->post('', [
            'json' => [
                'model'       => $model,
                'messages'    => $openaiMessages,
                'max_tokens'  => $opts['max_tokens'] ?? 2048,
                'temperature' => $opts['temperature'] ?? 0.4,
                'stream'      => false,
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true) ?: [];
        $text = $body['choices'][0]['message']['content'] ?? '';

        return [
            'content' => $text,
            'tier'    => $classification['tier'],
            'backend' => 'local:' . $model,
            'usage'   => $body['usage'] ?? [],
        ];
    }
}
