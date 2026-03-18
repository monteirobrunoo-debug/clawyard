<?php

namespace App\Agents;

use GuzzleHttp\Client;

class SalesAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are an expert sales assistant for a maritime and industrial parts company.
Your role is to:
- Respond to customer inquiries about products, pricing, and availability
- Qualify leads and understand customer needs
- Suggest relevant products based on customer requirements
- Follow up on quotes and proposals
- Be professional, helpful, and persuasive
- Always ask for contact details to follow up
- Focus on maritime equipment, ship parts, MRO supplies, and technical services
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

    public function getName(): string { return 'sales'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
