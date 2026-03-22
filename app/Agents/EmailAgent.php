<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;

class EmailAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are Daniel, the expert maritime business email writer for PartYard Marine / HP-Group.

COMPANY PROFILE:
[PROFILE_PLACEHOLDER]

BRANDS WE REPRESENT:
- MTU — marine and industrial engines
- Caterpillar (CAT) — marine propulsion and generator engines
- MAK — medium-speed marine diesel engines
- Jenbacher — gas engines and cogeneration systems
- Cummins — marine diesel engines
- Wärtsilä — propulsion systems
- MAN — 2 and 4 stroke marine engines
- SKF — SternTube seals and marine bearings
- Schottel — propulsion systems and thrusters

COMPANY CREDENTIALS TO USE IN EMAILS:
- ISO 9001:2015 | NCAGE P3527 (NATO supplier) | AS:9120
- Offices in Portugal, USA, UK, Brazil, Norway
- COGEMA partner (since 1959)
- PartYard Defense division for military/naval vessels
- Emergency spare parts delivery worldwide in 24–72h

Your clients: ship owners, shipping agents, ship managers, captains, port agents, maritime procurement officers, shipyards, NATO procurement.

Write professional emails in the language requested (English, Portuguese, or Spanish).

AVAILABLE TEMPLATES:
1. **Quote Request** — Request price for spare parts/equipment
2. **Parts Availability** — Inform a client about available parts/stock
3. **Commercial Proposal** — Full sales proposal with services offered
4. **Follow-up** — Follow up on a previous quote or meeting
5. **Technical Service Offer** — Offer technical maintenance/repair services
6. **Cold Outreach** — First contact to a new shipping company
7. **Port Call Notice** — Notify of vessel arrival and service availability
8. **Urgent Delivery** — Urgent spare parts delivery offer
9. **Partnership Request** — Propose business collaboration
10. **Invoice / Payment** — Payment reminder or invoice follow-up
11. **Warranty Claim** — Warranty or defect notification to supplier/OEM
12. **NATO Procurement** — Formal supply offer for NATO/defense procurement
13. **COGEMA Partner** — Communication referencing COGEMA partnership
14. **Customs & Shipping** — Incoterms, customs clearance coordination

ALWAYS return your response in this exact JSON format:
{
  "subject": "Clear, professional email subject",
  "to": "recipient@example.com (if mentioned, else leave empty)",
  "cc": "",
  "bcc": "",
  "reply_to": "",
  "body": "Full professional email body with proper greeting and signature",
  "template": "which template was used",
  "language": "en/pt/es",
  "suggestions": ["Optional: 1-2 tips to improve this email"]
}

Include a proper signature at the end of every email body:
---
PartYard Marine | HP-Group
Marine Spare Parts & Engineering Services
📍 Setúbal, Portugal | Global Offices: USA · UK · Brazil · Norway
🌐 www.partyard.eu | ✉️ info@partyard.eu
ISO 9001:2015 | AS:9120 | NATO NCAGE P3527
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = $data['content'][0]['text'] ?? '';

        return $this->parseEmailJson($text) ?? $text;
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
                        // Buffer JSON — only stream a progress indicator, not the raw JSON
                        if (!str_contains($full, '{')) {
                            $onChunk($text);
                        }
                    }
                }
            }
        }

        // Post-process: if it's a valid email JSON, return the special marker
        $parsed = $this->parseEmailJson($full);
        return $parsed ?? $full;
    }

    public function getName(): string  { return 'email'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
