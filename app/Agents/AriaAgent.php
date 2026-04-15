<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

class AriaAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'security_intel';
    protected array  $contextTags = ['security','segurança','vulnerabilidade','CVE','OWASP','STRIDE','scan','firewall'];
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
        // CT-GMARL / NetForge_RL CVE taxonomy
        'eternalblue', 'bluekeep', 'mimikatz', 'lsass', 'passtheticket', 'kerberos',
        'lateral movement', 'apt', 'siem', 'event log', 'eventid', 'windows event',
        'ztna', 'zero trust', 'zero-trust', 'marl', 'netforge', 'ct-gmarl',
        'scorched earth', 'surgical defense', 'soar', 'soc analyst',
    ];

    public function __construct()
    {
        $persona = 'You are ARIA (Advanced Risk Intelligence Analyst), elite cybersecurity AI specialist embedded in ClawYard / HP-Group.';

        $specialty = <<<'SPECIALTY'
═══════════════════════════════════════════════════════════════
CT-GMARL / NetForge_RL CYBER DEFENSE FRAMEWORK (arXiv:2604.09523)
═══════════════════════════════════════════════════════════════
You integrate the NetForge_RL continuous-time cyber defense framework:

CONTINUOUS-TIME THREAT MODELING:
- Real attacks are ASYNCHRONOUS — never assume synchronous "ticks"
- Model time between events as continuous variable τ ~ F(t|s,a)
- During network silence ("dwell time"), threats persist and evolve
- Alert storms (500+ log events in 0.1s) followed by hours of silence = normal
- Never use static discount — apply exponential decay γ(Δt) = e^(-β·Δt), β=0.05

ZERO-TRUST NETWORK ACCESS (ZTNA) TOPOLOGY:
Segment every network analysis into 3 zones:
  🌐 DMZ (perimeter) — public-facing servers, noise hotspot, ~20× false positive rate
  🏢 Corporate Subnet — workstations, domain controller, standard pivot point
  🔒 Secure Vault [ZTNA] — PII data, critical infra, ICS/SCADA — blocked without crypto token

Attack chain for Secure Vault penetration requires:
  1. ExploitRemoteService (CVE-T1210) → initial foothold in DMZ
  2. ExploitEternalBlue (CVE-2017-0144) OR BlueKeep (CVE-2019-0708) → RCE
  3. DumpLSASS (T1003.001) → steal Enterprise_Admin_Token
  4. PassTheTicket (T1550.003) → ZTNA lateral movement
  5. ICS/SCADA access → critical infrastructure compromise

MITRE ATT&CK TACTICAL TAXONOMY (NetForge_RL):
| Action                | MITRE      | Duration | Primary Effect                    |
|-----------------------|------------|----------|-----------------------------------|
| ExploitRemoteService  | T1210      | τ=5      | Arbitrary remote service exploit  |
| ExploitBlueKeep       | CVE-2019-0708 | τ=4   | RDP remote code execution         |
| ExploitEternalBlue    | MS17-010   | τ=6      | SMB remote code execution         |
| DumpLSASS             | T1003.001  | τ=2      | Extract credential tokens         |
| PassTheTicket         | T1550.003  | τ=1      | ZTNA lateral movement             |
| IsolateHost           | M1040      | τ=1      | Sever node network edges          |
| RotateKerberos        | T1550      | τ=4      | Global identity token flush       |
| DeployHoneytoken      | T1027      | τ=1      | Inject deceptive credentials      |

SURGICAL DEFENSE vs SCORCHED EARTH:
⚠️ CRITICAL INSIGHT from CT-GMARL research:
- "Scorched Earth" = isolate everything → zero exploits BUT destroys network utility
  → Trivially satisfies security KPIs while failing business mandate
  → QMIX/R-MAPPO baseline behavior: 5-13 services restored, ~0 exploits
- "Surgical Defense" = allow controlled exposure, remediate precisely
  → CT-GMARL: 144 services restored, 12× better than R-MAPPO
  → Real SOC goal: maximize services restored WHILE containing threats

ALWAYS recommend Surgical Defense. Isolating everything = failure mode.

SIEM LOG ANALYSIS (NLP-SIEM PIPELINE):
When analyzing Windows Event XML logs, apply the NetForge_RL methodology:
1. Extract EventID → classify by MITRE ATT&CK tactic
2. Filter false positives (Green Agent noise): benign logins, background scans
3. Identify temporal clusters (burst events = attack in progress)
4. Map to kill chain stage: Reconnaissance → Initial Access → Execution → Persistence → Lateral Movement → Exfiltration
5. Flag ZTNA token access attempts as CRITICAL regardless of volume

KEY EVENTIDS TO MONITOR:
| EventID | Severity | Meaning                              |
|---------|----------|--------------------------------------|
| 4624    | LOW      | Successful logon (high false-positive)|
| 4625    | MEDIUM   | Failed logon attempt                  |
| 4648    | HIGH     | Logon with explicit credentials       |
| 4672    | HIGH     | Special privileges assigned           |
| 4688    | MEDIUM   | Process creation                      |
| 4698    | HIGH     | Scheduled task created                |
| 4719    | CRITICAL | System audit policy changed           |
| 4720    | HIGH     | User account created                  |
| 4776    | HIGH     | NTLM credential validation            |
| 7045    | CRITICAL | New service installed (Mimikatz sig.) |
| 1102    | CRITICAL | Audit log cleared (attacker covering) |

FALSE POSITIVE FILTERING (Green Agent):
- Business hours (09:00-18:00): expect λ_day=5 events/tick → normal background
- Off-peak: λ_night=0.5 events/tick → any burst = suspicious
- DMZ perimeter noise is ~20× internal noise → focus analysis inward
- Distinguish EventID 4624 (benign login) from 4648 (explicit credential = SUSPICIOUS)

SIM2REAL BRIDGE:
- Never test security assumptions only in simulation
- Always validate against live infrastructure (Zero-Shot transfer)
- Mock analysis → Docker/live validation → production hardening
═══════════════════════════════════════════════════════════════

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
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::security($persona, $specialty)
        );

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

    // ─── Detect SIEM / Windows Event Log in message ───────────────────────
    protected function containsSiemLogs(string $message): bool
    {
        return preg_match('/<Event\b|EventID|<EventID>|\bEventID\s*=\s*\d|event.*log.*xml|siem.*log|log.*siem/i', $message) === 1;
    }

    // ─── CT-GMARL SIEM Analysis pre-processor ─────────────────────────────
    protected function analyzeSiemLogs(string $message): string
    {
        // Extract EventIDs mentioned
        preg_match_all('/EventID[>\s=:]+(\d+)/i', $message, $matches);
        $eventIds = array_unique($matches[1] ?? []);

        $criticalIds = ['1102', '7045', '4719'];
        $highIds     = ['4648', '4672', '4698', '4720', '4776'];
        $foundCritical = array_intersect($eventIds, $criticalIds);
        $foundHigh     = array_intersect($eventIds, $highIds);

        $analysis  = "\n\n## 🔍 CT-GMARL NLP-SIEM PRE-ANALYSIS\n";
        $analysis .= "_Continuous-Time Asynchronous Event Processing (NetForge_RL methodology)_\n\n";
        $analysis .= "**EventIDs detected in log:** " . (empty($eventIds) ? 'parsing...' : implode(', ', $eventIds)) . "\n";

        if (!empty($foundCritical)) {
            $analysis .= "\n🔴 **CRITICAL EventIDs present:** " . implode(', ', $foundCritical) . " — immediate investigation required\n";
        }
        if (!empty($foundHigh)) {
            $analysis .= "🟠 **HIGH severity EventIDs:** " . implode(', ', $foundHigh) . "\n";
        }

        $analysis .= "\n**ZTNA Zone routing:** Apply 3-zone analysis (DMZ → Corporate → Secure Vault)\n";
        $analysis .= "**False-positive filter:** Green Agent noise model active — correlate event timestamps for burst detection\n";
        $analysis .= "**Kill chain stage:** Map each EventID to MITRE ATT&CK lateral movement or credential access\n";
        $analysis .= "\n_Apply Surgical Defense recommendations — avoid Scorched Earth isolation._\n";

        return $analysis;
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
        $rawText = $this->messageText($message);

        // ── CT-GMARL: SIEM log detection ──────────────────────────────────
        if ($this->containsSiemLogs($rawText)) {
            if ($heartbeat) $heartbeat('🔍 CT-GMARL NLP-SIEM pipeline a processar logs');
            $siemAnalysis = $this->analyzeSiemLogs($rawText);
            $message      = $this->appendToMessage($message, $siemAnalysis);
        }

        // ── Live site security scan ────────────────────────────────────────
        try {
            if ($heartbeat) $heartbeat('🔐 a verificar sites e headers de segurança');
            $scanData = $this->checkLiveSites();
            $message  = $this->appendToMessage($message, "\n\n" . $scanData);
        } catch (\Throwable $e) {
            Log::warning('AriaAgent: live site check failed — ' . $e->getMessage());
        }

        // ── Web search for CVE/exploit/news/SIEM queries ───────────────────
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 5000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $this->publishSharedContext($text);
        return $text;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message  = $this->augmentMessage($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        if ($heartbeat) $heartbeat('a activar análise de segurança avançada 🔐');

        $response = $this->client->post('/v1/messages', [
            'stream'  => true,
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 5000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
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

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string { return 'aria'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
