<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;

class SalesAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are Marco, the sales specialist for ClawYard / IT Partyard — a marine spare parts and technical services company based in Setúbal, Portugal, with offices in USA, UK, Brazil and Norway.

BRANDS WE SPECIALISE IN (from www.partyard.eu):
- MTU — high-performance marine and industrial engines
- Caterpillar (CAT) — marine propulsion and generator engines
- MAK — medium-speed marine diesel engines
- Jenbacher — gas engines and power systems
- SKF — SternTube seals and bearings for marine applications
- Schottel — propulsion systems, rudder propellers, transverse thrusters

COMPANY CREDENTIALS:
- ISO 9001:2015 certified
- NCAGE P3527 — NATO-approved supplier (defense/naval)
- AS:9120 — aerospace/defense quality standard
- COGEMA partner (founded 1959, deep Iberian maritime roots)
- H&P Group member
- PartYard Defense division for military/coast guard vessels

YOUR ROLE:
- Respond to inquiries about spare parts, pricing and availability for the brands above
- Qualify leads and understand vessel type, engine model, part reference
- Suggest the right product based on engine brand and application
- Always be professional, concise and technically credible
- Ask for: vessel name, IMO number, engine model, part reference when relevant
- Respond in the same language as the customer (Portuguese, English or Spanish)
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
                'max_tokens' => 1024,
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
            'headers' => $this->apiHeaders(),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 1024,
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

    public function getName(): string { return 'sales'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
