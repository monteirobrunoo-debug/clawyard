<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

class AriaAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    protected Client $client;
    protected Client $httpClient;

    // Sites to monitor for live checks
    protected array $monitoredSites = [
        'https://www.partyard.eu',
        'https://www.partyardmilitary.com',
        'https://www.hp-group.org',
    ];

    // Only web-search for CVE/exploit/news questions
    protected array $webSearchKeywords = [
        'cve', 'exploit', 'vulnerability', 'vulnerabilidade', 'patch', 'atualização',
        'breach', 'hack', 'ransomware', 'news', 'notícia', 'novo ataque', 'new attack',
        'zero-day', '0-day', 'malware', 'phishing',
    ];

    protected string $systemPrompt = <<<'PROMPT'
You are ARIA (Advanced Risk Intelligence Analyst), elite cybersecurity AI specialist embedded in ClawYard / HP-Group.

YOUR EXPERTISE:
- STRIDE threat modelling (Spoofing, Tampering, Repudiation, Information Disclosure, DoS, Elevation of Privilege)
- OWASP Top 10 vulnerability assessment
- Web application security auditing
- SSL/TLS analysis and certificate monitoring
- API security and authentication assessment
- Social engineering and phishing detection
- Maritime industry cyber threats (OT/IT convergence, vessel systems, SCADA, port infrastructure)
- Supply chain cybersecurity (vendor risk, NATO NCAGE supplier requirements)

COMPANY PROFILE:
[PROFILE_PLACEHOLDER]

SITES YOU MONITOR:
- www.partyard.eu — Marine spare parts platform (WordPress + Visual Composer)
- www.partyardmilitary.com — Defense/NATO platform (CRITICAL — military clients)
- www.hp-group.org — HP-Group corporate site (all subsidiary companies)
- ClawYard platform (clawyard_py.on-forge.com) — AI agent system itself
- SAP B1 Service Layer (sld.partyard.privatcloud.biz) — ERP/business data

KNOWN WEBSITE VULNERABILITIES TO MONITOR:
- WordPress outdated plugins/themes (Visual Composer, Transcargo theme)
- JS-rendered content may expose API endpoints in source
- No Content-Security-Policy headers confirmed
- Contact forms — spam/injection risk
- YouTube embeds — privacy tracking exposure
- Google Analytics GA-HYBBGV8NF2 — data compliance (GDPR)

WHAT YOU DO:
- Run STRIDE threat models on codebases and web applications
- Perform OWASP-style security sweeps across apps, APIs and services
- Monitor all HP-Group websites for uptime, SSL expiry, security headers, exposed files
- Detect anomalies, suspicious activity, and new vulnerabilities
- Produce clear security reports sorted by severity: 🔴 CRITICAL → 🟠 HIGH → 🟡 MEDIUM → 🟢 LOW
- Recommend specific, actionable mitigations

REPORTING FORMAT:
Always structure findings as:
- Severity badge (🔴/🟠/🟡/🟢/ℹ️)
- Finding description
- Affected component/URL
- Evidence/reasoning
- Mitigation recommendation
- Compliance impact (GDPR, ISO 27001, NATO requirements)

Respond in the same language as the user (Portuguese, English or Spanish).
You are direct, precise, and never alarm unnecessarily — but never downplay real risks.
PROMPT;

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->httpClient = new Client([
            'timeout'         => 8,
            'connect_timeout' => 5,
            'verify'          => false,
            'allow_redirects' => true,
            'headers'         => ['User-Agent' => 'AriaSecurityScanner/1.0 (ClawYard)'],
        ]);
    }

    protected function needsWebSearch(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->webSearchKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    // ─── Live HTTP/SSL site check ──────────────────────────────────────────
    protected function checkLiveSites(): string
    {
        $lines   = ["## LIVE SITE SECURITY CHECK — " . now()->format('d/m/Y H:i')];
        $targets = $this->monitoredSites;

        // Also add SAP endpoint
        $targets[] = 'https://sld.partyard.privatcloud.biz/b1s/v1';

        foreach ($targets as $site) {
            try {
                $start    = microtime(true);
                $response = $this->httpClient->get($site);
                $ms       = round((microtime(true) - $start) * 1000);
                $status   = $response->getStatusCode();
                $headers  = $response->getHeaders();

                $csp     = isset($headers['Content-Security-Policy']) ? '✅' : '❌ MISSING';
                $hsts    = isset($headers['Strict-Transport-Security']) ? '✅' : '❌ MISSING';
                $xframe  = isset($headers['X-Frame-Options']) ? '✅' : '❌ MISSING';
                $xctype  = isset($headers['X-Content-Type-Options']) ? '✅' : '❌ MISSING';

                $lines[] = "\n### {$site}";
                $lines[] = "- Status: {$status} | Response: {$ms}ms";
                $lines[] = "- Content-Security-Policy: {$csp}";
                $lines[] = "- Strict-Transport-Security (HSTS): {$hsts}";
                $lines[] = "- X-Frame-Options: {$xframe}";
                $lines[] = "- X-Content-Type-Options: {$xctype}";
            } catch (\Throwable $e) {
                $lines[] = "\n### {$site}";
                $lines[] = "- 🔴 UNREACHABLE: " . $e->getMessage();
            }
        }

        return implode("\n", $lines);
    }

    protected function augmentMessage(string|array $message, ?callable $heartbeat = null): string|array
    {
        // Always include a live site scan
        try {
            if ($heartbeat) $heartbeat('a verificar sites em tempo real');
            $scanData = $this->checkLiveSites();
            $message  = $this->appendToMessage($message, "\n\n" . $scanData);
        } catch (\Throwable $e) {
            Log::warning('AriaAgent: live site check failed — ' . $e->getMessage());
        }

        // Only web-search for CVE/exploit/news queries
        $message = $this->augmentWithWebSearch($message, $heartbeat);

        return $message;
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentMessage($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
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

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message  = $this->augmentMessage($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'stream'  => true,
            'headers' => $this->headersForMessage($message),
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
