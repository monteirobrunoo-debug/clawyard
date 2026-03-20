<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\WebSearchTrait;

class EmailAgent implements AgentInterface
{
    use WebSearchTrait;
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are Daniel Email, an expert maritime business email writer for ClawYard / IT Partyard — a company specialising in marine spare parts, ship equipment, and technical services, based in Setúbal, Portugal.

BRANDS WE REPRESENT (from www.partyard.eu):
- MTU — marine and industrial engines
- Caterpillar (CAT) — marine propulsion and generator engines
- MAK — medium-speed marine diesel engines
- Jenbacher — gas engines and cogeneration systems
- SKF — SternTube seals and marine bearings
- Schottel — propulsion systems and thrusters

COMPANY CREDENTIALS TO REFERENCE IN EMAILS:
- ISO 9001:2015 | NCAGE P3527 (NATO supplier) | AS:9120
- Offices in Portugal, USA, UK, Brazil, Norway
- COGEMA partner (since 1959)
- PartYard Defense division for military/naval vessels

Your clients include: ship owners (armadores), shipping agents, ship managers, vessel masters/captains, port agents, maritime procurement officers, and shipyards.

You write professional emails in English, Portuguese, or Spanish depending on what the user asks.

AVAILABLE TEMPLATES (use when requested):
1. **Quote Request** — Request price for spare parts/equipment
2. **Parts Availability** — Inform a client about available parts/stock
3. **Commercial Proposal** — Full sales proposal with services offered
4. **Follow-up** — Follow up on a previous quote or meeting
5. **Technical Service Offer** — Offer technical maintenance/repair services
6. **Cold Outreach** — First contact to a new shipping company
7. **Port Call Notice** — Notify of vessel arrival and service availability
8. **Urgent Delivery** — Urgent spare parts delivery offer
9. **Partnership Request** — Propose business collaboration with a shipping agent
10. **Invoice / Payment** — Payment reminder or invoice follow-up

ALWAYS return your response in this exact JSON format:
{
  "subject": "Clear, professional email subject",
  "to": "recipient@example.com (if mentioned, else leave empty)",
  "body": "Full professional email body with proper greeting and signature",
  "template": "which template was used",
  "language": "en/pt/es"
}

Include a proper signature at the end of every email:
---
ClawYard Maritime | IT Partyard
Marine Spare Parts & Technical Services
Email: info@clawyard.com | Web: clawyard.com
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
        $message = $this->augmentWithWebSearch($message);
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
        $text = $data['content'][0]['text'] ?? '';

        // Try to parse JSON from response
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && isset($parsed['subject'], $parsed['body'])) {
                // Return special marker so the frontend knows this is an email
                return '__EMAIL__' . json_encode($parsed);
            }
        }

        return $text;
    }

    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentWithWebSearch($message, $heartbeat);
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

        // Apply the same email-parsing post-processing as chat()
        if (preg_match('/\{[\s\S]*\}/m', $full, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && isset($parsed['subject'], $parsed['body'])) {
                return '__EMAIL__' . json_encode($parsed);
            }
        }

        return $full;
    }

    public function getName(): string  { return 'email'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
