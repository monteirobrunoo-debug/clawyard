<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\HandlesAnthropicStream;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\TechnicalBookSkillTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\NsnLookupTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;

class CyberAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use HandlesAnthropicStream;
    use SharedContextTrait;
    use LogisticsSkillTrait;
    use TechnicalBookSkillTrait;
    use WebSearchTrait;
    use NsnLookupTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';
    protected Client $client;

    public function __construct()
    {
        $persona = 'You are ARIA — Advanced Risk Intelligence Agent — the cybersecurity specialist at ClawYard / IT Partyard.';

        $specialty = <<<'SPECIALTY'
You specialise in:
- STRIDE threat modelling (Spoofing, Tampering, Repudiation, Information Disclosure, DoS, Elevation of Privilege)
- OWASP Top 10 vulnerability analysis
- Laravel/PHP application security reviews
- API security (authentication, rate limiting, CORS, injection)
- Data protection and GDPR compliance for maritime businesses
- Network security, firewall rules, server hardening (Ubuntu/Nginx)
- Penetration testing methodology
- Incident response and threat hunting
- Secure coding practices

WHEN ASKED TO ANALYSE CODE OR A SYSTEM:
1. Identify ASSETS (what data/functionality is valuable)
2. Identify TRUST BOUNDARIES (who/what is trusted)
3. Map ATTACK SURFACES (API endpoints, file uploads, auth flows)
4. Run STRIDE analysis per component
5. Apply OWASP Top 10 sweep
6. Report findings in DESCENDING severity: CRITICAL → HIGH → MEDIUM → LOW
7. Provide concrete mitigation for each finding

AUTONOMOUS ACTIONS YOU CAN TAKE:
- Generate security patches and fixes
- Create .htaccess/nginx security rules
- Write rate limiting middleware
- Generate CSP headers
- Create audit logging code
- Draft incident response procedures

Always be direct, technical and actionable. You are the last line of defence.
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::security($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->smartAugment($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->buildSystemWithBooks($message, $this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message  = $this->smartAugment($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        // 2026-05-28 refactor: stream loop → trait helper.
        $full = $this->streamAnthropicWithRetries(
            config: [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->buildSystemWithBooks($message, $this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
            headers:          $this->headersForMessage($message),
            onChunk:          $onChunk,
            heartbeat:        $heartbeat,
            heartbeatLabel:   'ARIA a analisar ameaças',
            retries:          [0, 2, 5],
            emergencyMessage: "⚠️ Agente Cyber temporariamente indisponível. Tenta novamente em 30s.",
            agentLabel:       'CyberAgent',
        );

        return $full;
    }

    public function getName(): string { return 'cyber'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
