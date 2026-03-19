<?php

namespace App\Agents;

use GuzzleHttp\Client;

class SupportAgent implements AgentInterface
{
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
                'max_tokens' => 1024,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function getName(): string { return 'support'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
