<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\ShippingSkillTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;

class EmailAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use ShippingSkillTrait;
    use LogisticsSkillTrait;
    protected string $contextKey  = 'email_intel';
    protected array  $contextTags = ['email','cliente','proposta','cotação','follow-up','armador','navio','contacto'];
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';
    protected Client $client;

    public function __construct()
    {
        $persona = 'You are Daniel, senior business development manager and expert email writer for PartYard Marine / HP-Group.';

        $specialty = <<<'SPECIALTY'
BRANDS REPRESENTED:
- MTU — marine & industrial engines (Series 2000, 4000, 8000)
- Caterpillar (CAT) — marine propulsion & generator engines (C18, C32, C3516)
- MAK — medium-speed marine diesel engines (M20, M25, M32, M43)
- Jenbacher — gas engines and cogeneration
- Cummins — marine diesel engines
- Wärtsilä — propulsion systems & spare parts
- MAN — 2 & 4 stroke marine engines
- SKF — SternTube seals and marine bearings
- Schottel — propulsion systems, thrusters, rudder propellers

CREDENTIALS TO MENTION WHEN RELEVANT:
- ISO 9001:2015 | AS:9120 | NATO NCAGE P3527
- Offices: Portugal (HQ Setúbal), USA, UK, Brazil, Norway
- COGEMA partner since 1959
- PartYard Defense — military/naval vessels division
- Emergency worldwide delivery in 24–72h
- 30+ years in maritime spare parts

TARGET CLIENTS:
Ship owners, ship managers, captains, port agents, shipping agents, maritime procurement officers, shipyards, NATO/defense procurement, offshore operators.

WRITING RULES — FOLLOW STRICTLY:
1. Write ONLY in the language explicitly requested (EN/PT/ES). Default: English.
2. Use professional maritime business tone — formal but direct.
3. Subject line: concise, specific, action-oriented. Never generic.
4. Opening: address by name/title if provided. Never use "Dear Sir/Madam" unless no name given.
5. Body: 3–4 short paragraphs max. No filler phrases ("I hope this email finds you well").
6. Always include 1 clear call-to-action (reply, call, confirm, etc.).
7. Signature: always include the standard PartYard signature.
8. If recipient email is not specified, leave "to" field empty.
9. Never invent part numbers, prices or delivery dates unless provided.
10. CC field: only fill if explicitly requested.

EMAIL TEMPLATES:
1. Quote Request — request price from supplier/OEM
2. Parts Availability — inform client about available stock
3. Commercial Proposal — full sales proposal
4. Follow-up — follow up on quote, meeting or offer
5. Technical Service Offer — maintenance/repair services
6. Cold Outreach — first contact to new prospect
7. Port Call Notice — vessel arrival + service availability
8. Urgent Delivery — emergency spare parts offer
9. Partnership Request — business collaboration proposal
10. Invoice / Payment — payment reminder or invoice follow-up
11. Warranty Claim — defect/warranty notification to OEM
12. NATO Procurement — formal defense/NATO supply offer
13. COGEMA Partner — communication via COGEMA network
14. Customs & Shipping — Incoterms, customs coordination

SIGNATURE (always append to body):
---
Best regards,

Daniel Ferreira
Business Development | PartYard Marine
HP-Group — Marine Spare Parts & Engineering

📍 Setúbal, Portugal | Global: USA · UK · Brazil · Norway
📞 +351 265 000 000
✉️ daniel.ferreira@partyard.eu
🌐 www.partyard.eu

ISO 9001:2015 | AS:9120 | NATO NCAGE P3527

RESPONSE FORMAT — return ONLY valid JSON, no markdown, no extra text:
{
  "subject": "Specific professional subject line",
  "to": "recipient@domain.com or empty string",
  "cc": "",
  "bcc": "",
  "body": "Complete email body including greeting, paragraphs and signature",
  "template": "Template name used",
  "language": "en or pt or es",
  "suggestions": ["One concrete tip to improve conversion rate"]
}
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::maritime($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        // Every customer-facing agent gets the UPS shipping skill so it can
        // give cost estimates when asked — see app/Services/ShippingRateService.
        $this->systemPrompt .= $this->shippingSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    protected function parseEmailJson(string $text): ?string
    {
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && isset($parsed['subject'], $parsed['body'])) {
                return '__EMAIL__' . json_encode($parsed);
            }
        }
        return null;
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithWebSearch($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data   = json_decode($response->getBody()->getContents(), true);
        $text   = $data['content'][0]['text'] ?? '';
        $result = $this->parseEmailJson($text) ?? $text;
        $this->publishSharedContext($result);
        return $result;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message  = $this->augmentWithWebSearch($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body          = $response->getBody();
        $full          = '';
        $buf           = '';
        $jsonStarted   = false;
        $progressSent  = false;
        $lastBeat      = time();

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
                    $chunk = $evt['delta']['text'] ?? '';
                    if ($chunk === '') continue;

                    $full .= $chunk;

                    // While Claude builds the JSON silently, send heartbeats so
                    // Nginx / SSE connection stays alive (avoids "stuck" appearance).
                    // We do NOT stream raw JSON chunks — we wait for the complete
                    // JSON, parse it, then push the result in one shot below.
                    if ($heartbeat && (time() - $lastBeat >= 1)) {
                        $heartbeat('a escrever');
                        $lastBeat = time();
                    }
                }
            }
        }

        // Post-process: parse the completed JSON and push the email to the browser
        $parsed = $this->parseEmailJson($full);
        $result = $parsed ?? $full;

        // ── CRITICAL: send the result to the browser via onChunk ──────────────
        // Without this, the browser never receives the email and appears "stuck".
        $onChunk($result);

        $this->publishSharedContext($result);
        return $result;
    }

    public function getName(): string  { return 'email'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
