<?php

namespace App\Agents;

use GuzzleHttp\Client;

class SupportAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are a technical support specialist for maritime and industrial equipment.
Your role is to:
- Diagnose and troubleshoot technical problems with equipment and parts
- Provide step-by-step solutions to technical issues
- Escalate complex issues when necessary
- Document problems and solutions clearly
- Be patient, clear, and technically accurate
- Ask clarifying questions to understand the problem fully
- Reference technical manuals and specifications when relevant
- Specialize in maritime equipment, engines, pumps, valves, and electrical systems
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
