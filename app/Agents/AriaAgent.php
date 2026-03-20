<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;

class AriaAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
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
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string $message, array $history = []): string
    {
        $message = $this->augmentWithWebSearch($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentWithWebSearch($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'stream'  => true,
            'headers' => $this->apiHeaders(),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body = $response->getBody();
        $full = '';
        $buf  = '';

        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $text = $evt['delta']['text'] ?? '';
                    if ($text !== '') {
                        $full .= $text;
                        $onChunk($text);
                    }
                }
            }
        }

        return $full;
    }

    public function getName(): string { return 'aria'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
