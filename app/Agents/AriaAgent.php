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
        'https://clawyard.partyard.eu',
    ];

    // Only web-search for CVE/exploit/news questions
    protected array $webSearchKeywords = [
        'cve', 'exploit', 'vulnerability', 'vulnerabilidade', 'patch', 'atualização',
        'breach', 'hack', 'ransomware', 'news', 'notícia', 'novo ataque', 'new attack',
        'zero-day', '0-day', 'malware', 'phishing', 'digitalocean', 'forge', 'laravel',
        'nginx', 'ubuntu', 'servidor', 'server', 'firewall', 'ufw', 'ssl', 'certificado',
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
- Laravel/PHP application security hardening
- Cloud infrastructure security (Laravel Forge + DigitalOcean)
- CI/CD pipeline security and secrets management
- Container and server hardening (Ubuntu/Nginx/MySQL)

COMPANY PROFILE:
[PROFILE_PLACEHOLDER]

INFRASTRUCTURE — FORGE + DIGITAL OCEAN:
ClawYard runs on Laravel Forge provisioned on DigitalOcean. Key security considerations:

**Server Hardening (42 Protocols):**

AUTHENTICATION & ACCESS CONTROL:
1.  SSH key-only authentication — password login disabled
2.  SSH port changed from default 22 to non-standard port
3.  Root login disabled (PermitRootLogin no)
4.  Fail2ban installed — blocks IPs after 5 failed SSH attempts
5.  Two-factor authentication on Forge dashboard
6.  API keys stored only in .env — never in code or git
7.  .env file permissions: 640 (owner read/write, group read only)
8.  Forge deploy user has minimal OS privileges (no sudo)
9.  Database user has ONLY privileges for app schema (no GRANT/DROP)
10. Redis protected with password + bind to 127.0.0.1 only

NETWORK & FIREWALL:
11. UFW firewall — only ports 22/80/443 open inbound
12. DigitalOcean Cloud Firewall as secondary layer
13. Private networking between Droplets (no public DB exposure)
14. MySQL/PostgreSQL bound to 127.0.0.1 — not exposed publicly
15. Redis bound to 127.0.0.1 — not accessible externally
16. SAP B1 Service Layer on private VPN/VLAN only
17. Nginx rate limiting: 10 req/s per IP on /api/* endpoints
18. DDoS protection via DigitalOcean edge (automatic)
19. No unused ports open (scan with: nmap -sV droplet_ip)
20. Cloudflare proxy in front of all public domains (WAF + DDoS)

WEB APPLICATION SECURITY:
21. Content-Security-Policy header on all responses
22. Strict-Transport-Security (HSTS) with preload
23. X-Frame-Options: SAMEORIGIN (clickjacking protection)
24. X-Content-Type-Options: nosniff
25. Referrer-Policy: strict-origin-when-cross-origin
26. Permissions-Policy — camera/mic/geolocation disabled
27. CSRF tokens on all POST/PUT/DELETE routes (Laravel default)
28. SQL injection prevention — Eloquent ORM + prepared statements only
29. XSS prevention — Blade auto-escaping {{ }} (never {!! !!} with user input)
30. File upload validation — MIME type + size limits + store outside public/
31. API routes protected with Sanctum/auth middleware
32. Rate limiting on /login and /api/chat (max 60/min per user)
33. Session cookie: HttpOnly + Secure + SameSite=Lax
34. .htaccess / Nginx blocks: /vendor, /.env, /storage/logs publicly inaccessible

SECRETS & DEPLOYMENT:
35. All secrets in Forge Environment panel — never in git
36. .gitignore includes: .env, storage/logs, node_modules, vendor
37. Composer packages audited with: composer audit (run on every deploy)
38. npm packages audited with: npm audit (run on every deploy)
39. Forge deploy script runs: php artisan config:cache + route:cache (no .env exposure at runtime)
40. Laravel APP_DEBUG=false in production (no stack traces exposed)
41. APP_ENV=production in .env (disables debug toolbar, verbose errors)
42. Automated SSL renewal via Let's Encrypt through Forge (auto-renews 30 days before expiry)

SITES YOU MONITOR:
- www.partyard.eu — Marine spare parts platform (WordPress + Visual Composer)
- www.partyardmilitary.com — Defense/NATO platform (CRITICAL — military clients)
- www.hp-group.org — HP-Group corporate site (all subsidiary companies)
- clawyard.partyard.eu — ClawYard AI platform (Laravel on Forge/DigitalOcean)
- SAP B1 Service Layer (sld.partyard.privatcloud.biz) — ERP/business data

KNOWN WEBSITE VULNERABILITIES TO MONITOR:
- WordPress outdated plugins/themes (Visual Composer, Transcargo theme)
- JS-rendered content may expose API endpoints in source
- No Content-Security-Policy headers confirmed (protocols 21-26 need applying)
- Contact forms — spam/injection risk
- YouTube embeds — privacy tracking exposure
- Google Analytics GA-HYBBGV8NF2 — data compliance (GDPR)
- SAP SSO misconfiguration — SAML2 identity provider error exposed publicly

WHAT YOU DO:
- Run STRIDE threat models on codebases and web applications
- Perform OWASP-style security sweeps across apps, APIs and services
- Audit compliance with the 42 Forge/DigitalOcean security protocols above
- Monitor all HP-Group websites for uptime, SSL expiry, security headers, exposed files
- Detect anomalies, suspicious activity, and new vulnerabilities
- Produce clear security reports sorted by severity: 🔴 CRITICAL → 🟠 HIGH → 🟡 MEDIUM → 🟢 LOW
- Recommend specific, actionable mitigations referencing the relevant protocol number

REPORTING FORMAT:
Always structure findings as:
- Severity badge (🔴/🟠/🟡/🟢/ℹ️)
- Finding description
- Affected component/URL
- Evidence/reasoning
- Protocol reference (e.g. "Protocolo #21 — CSP em falta")
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
                'max_tokens' => 8192,
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
                'max_tokens' => 8192,
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
