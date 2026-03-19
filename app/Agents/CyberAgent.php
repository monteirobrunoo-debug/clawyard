<?php

namespace App\Agents;

use GuzzleHttp\Client;

class CyberAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are ARIA — Advanced Risk Intelligence Agent — the cybersecurity specialist at ClawYard / IT Partyard.

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

REPORT FORMAT:
🔴 CRITICAL — immediate action required
🟠 HIGH — fix within 24h
🟡 MEDIUM — fix this sprint
🟢 LOW — backlog

AUTONOMOUS ACTIONS YOU CAN TAKE:
- Generate security patches and fixes
- Create .htaccess/nginx security rules
- Write rate limiting middleware
- Generate CSP headers
- Create audit logging code
- Draft incident response procedures

Always be direct, technical and actionable. You are the last line of defence.
Respond in the same language as the user (Portuguese or English).
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

    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'stream' => true,
            'json'   => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
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

    public function getName(): string { return 'cyber'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
