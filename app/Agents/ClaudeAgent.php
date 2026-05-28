<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\HandlesAnthropicStream;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\ShippingSkillTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\NsnLookupTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\TechnicalBookSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;

class ClaudeAgent implements AgentInterface
{
    use WebSearchTrait;
    use NsnLookupTrait;
    use AnthropicKeyTrait;
    use HandlesAnthropicStream;
    use SharedContextTrait;
    use ShippingSkillTrait;

    use LogisticsSkillTrait;
    use TechnicalBookSkillTrait;
    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';
    protected Client $client;
    protected string $systemPrompt = '';

    public function __construct()
    {
        $persona = 'You are Bruno AI — a powerful general-purpose assistant for HP-Group / PartYard, powered by Claude.';

        $specialty = 'You can help with any task: analysis, strategy, coding, research, writing, data processing, and more. Be direct, concise, and accurate.';

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        // Every customer-facing agent gets the UPS shipping skill so it can
        // give cost estimates when asked — see app/Services/ShippingRateService.
        $this->systemPrompt .= $this->shippingSkillPromptBlock();

        // Strict TLS: peer + host verification, TLS 1.2+, tight timeouts.
        // Base URI comes from AnthropicKeyTrait::getAnthropicBaseUri(), which
        // reads ANTHROPIC_BASE_URL via config and auto-upgrades http→https
        // (unknown schemes fall back to the canonical api.anthropic.com),
        // so a mistyped .env cannot downgrade the transport.
        // Set ANTHROPIC_CA_BUNDLE to pin a specific CA file.
        // anthropicGuzzleClient() layers the HMAC signing middleware (for
        // split-VM topology) on top of a Guzzle client that already honours
        // getAnthropicBaseUri(). The TLS hardening below is merged in.
        $this->client = self::anthropicGuzzleClient([
            'verify'  => env('ANTHROPIC_CA_BUNDLE', true),
            'curl'    => [
                CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ],
            'headers' => [
                'User-Agent' => 'ClawYard/1.0 (+https://clawyard.partyard.eu)',
            ],
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message = $this->augmentWithWebSearch($message);
        $message = $this->augmentWithNsnLookup($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        // Bruno é o agente generalista — tem acesso à biblioteca técnica
        // completa: 30 livros naval/soldadura + 3 de estratégia/liderança
        // (Blue Ocean, Extreme Ownership, Manual do Líder Dohler).
        // Sem domain → semantic search escolhe os melhores chunks por
        // similarity 1024-dim, atravessa todos os domínios. Para queries
        // de strategy/leadership, os chunks dos livros novos surgem
        // naturalmente; para perguntas técnicas, surgem os naval/soldadura.
        $bookCtx = $this->augmentWithTechnicalBooks($message, 4);
        $sys     = $this->enrichSystemPrompt($this->systemPrompt) . ($bookCtx ? "\n\n" . $bookCtx : '');

        $model     = config('services.anthropic.model', 'claude-sonnet-4-6');
        $maxTokens = 8192;

        // 2026-05-28 Fase B2: hash-exact semantic cache. Hit → zero custo
        // Anthropic. Skip se: temp>0, max_tokens>8k, msgs>5 turns, system
        // tem 'no-cache' marker. ClaudeAgent é piloto — se funcionar bem,
        // expandimos para outros agentes em commits seguintes.
        $cache = app(\App\Services\AnthropicResponseCache::class);
        return $cache->remember(
            model:      $model,
            system:     $sys,
            messages:   $messages,
            maxTokens:  $maxTokens,
            compute:    function () use ($message, $messages, $sys, $model, $maxTokens) {
                $response = $this->client->post('/v1/messages', [
                    'headers' => $this->headersForMessage($message),
                    'json'    => [
                        'model'      => $model,
                        'max_tokens' => $maxTokens,
                        'system'     => $sys,
                        'messages'   => $messages,
                    ],
                ]);
                $data = json_decode($response->getBody()->getContents(), true);
                return $data['content'][0]['text'] ?? '';
            },
        );
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentWithWebSearch($message, $heartbeat);
        $message = $this->augmentWithNsnLookup($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        // Mesma biblioteca que em chat() — strategy/liderança + naval/soldadura.
        $bookCtx = $this->augmentWithTechnicalBooks($message, 4);
        $sys     = $this->enrichSystemPrompt($this->systemPrompt) . ($bookCtx ? "\n\n" . $bookCtx : '');

        // 2026-05-28 refactor: stream loop → trait helper.
        $full = $this->streamAnthropicWithRetries(
            config: [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $sys,
                'messages'   => $messages,
                'stream'     => true,
            ],
            headers:          $this->headersForMessage($message),
            onChunk:          $onChunk,
            heartbeat:        $heartbeat,
            heartbeatLabel:   'Claude a analisar',
            retries:          [0, 2, 5],
            emergencyMessage: "⚠️ Bruno AI temporariamente indisponível. Tenta novamente em 30s.",
            agentLabel:       'ClaudeAgent',
        );

        return $full;
    }

    public function getName(): string { return 'claude'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
