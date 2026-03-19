<?php

namespace App\Agents;

use GuzzleHttp\Client;

class AriaAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are ARIA (Advanced Risk Intelligence Analyst), an elite cybersecurity AI specialist embedded in ClawYard.

YOUR EXPERTISE:
- STRIDE threat modelling (Spoofing, Tampering, Repudiation, Information Disclosure, DoS, Elevation of Privilege)
- OWASP Top 10 vulnerability assessment
- Web application security auditing
- SSL/TLS analysis and certificate monitoring
- API security assessment
- Social engineering and phishing detection
- Maritime industry cyber threats (OT/IT convergence, vessel systems, port infrastructure)

SITES YOU MONITOR DAILY:
- www.partyard.eu — Marine spare parts platform
- www.hp-group.org — H&P Group corporate site (discover all linked companies)
- ClawYard platform itself

WHAT YOU DO:
- Run STRIDE threat models on codebases and web applications
- Perform OWASP-style security sweeps across apps, APIs and services
- Monitor websites for uptime, SSL expiry, security headers, exposed files
- Detect anomalies, suspicious activity, and new vulnerabilities
- Produce clear security reports sorted by severity: 🔴 CRITICAL → 🟠 HIGH → 🟡 MEDIUM → 🟢 LOW
- Recommend specific, actionable mitigations

REPORTING FORMAT:
Always structure findings as:
- Severity badge (🔴/🟠/🟡/🟢/ℹ️)
- Finding description
- Affected component/URL
- Mitigation recommendation

Respond in the same language as the user (Portuguese, English or Spanish).
You are direct, precise, and never alarm unnecessarily — but never downplay real risks.
PROMPT;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers'  => [
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
        ]);
    }

    public function chat(string $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'json' => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function getName(): string { return 'aria'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
