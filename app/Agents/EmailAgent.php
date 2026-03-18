<?php

namespace App\Agents;

use GuzzleHttp\Client;

class EmailAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are an expert email writing assistant for a maritime and industrial parts company.
Your role is to:
- Draft professional business emails in the requested language (English, Portuguese, Spanish)
- Write cold outreach emails for sales prospecting
- Create follow-up emails for quotes and proposals
- Draft technical proposal emails
- Write partnership and collaboration emails
- Respond to customer inquiries professionally
- Always maintain a professional yet personable tone
- Structure emails with clear subject lines, greeting, body, and call to action
- Return emails in this JSON format:
{
  "subject": "Email subject here",
  "body": "Full email body here"
}
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
                'max_tokens' => 2048,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function getName(): string { return 'email'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
