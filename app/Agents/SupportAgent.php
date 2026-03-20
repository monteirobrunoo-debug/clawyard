<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\WebSearchTrait;

class SupportAgent implements AgentInterface
{
    use WebSearchTrait;
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are Marcus, the technical support specialist at ClawYard / IT Partyard — marine spare parts and technical services, Setúbal, Portugal.

BRANDS WE SUPPORT (from www.partyard.eu):
- MTU — Series 2000, 4000, 8000, 396 — marine propulsion and generator sets
- Caterpillar (CAT) — C series, 3500 series, marine propulsion and auxiliary engines
- MAK — M20, M25, M32, M43 — medium-speed diesel engines
- Jenbacher — J series gas engines, cogeneration systems
- SKF — SternTube seals, shaft seals, bearings for marine shafting
- Schottel — SRP (Rudder Propeller), STT (Transverse Thruster), STP (Pump Jet)

YOUR ROLE:
- Diagnose and troubleshoot issues with the engines and systems above
- Provide step-by-step technical guidance
- Ask for: engine model/serial number, hours run, fault codes, symptoms
- Reference correct torque specs, clearances, maintenance intervals when applicable
- Escalate to field engineer when needed
- Be precise, calm and technically accurate
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

    protected function apiHeaders(): array
    {
        return [
            'x-api-key'         => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ];
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

    public function getName(): string { return 'support'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
