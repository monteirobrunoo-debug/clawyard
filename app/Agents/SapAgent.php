<?php

namespace App\Agents;

use GuzzleHttp\Client;

class SapAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are a SAP Business One (SAP B1) expert assistant for a maritime and industrial parts company.
Your role is to:
- Help query and interpret business data from SAP B1
- Assist with stock queries, purchase orders, sales orders, and invoices
- Generate reports and summaries from business data
- Guide users through SAP B1 processes and transactions
- Help with item master data, business partner queries
- Assist with financial reporting and analysis
- Provide guidance on SAP B1 best practices
- When given data, analyze it and provide actionable insights
- Format responses clearly with tables when presenting data

Note: You work with SAP B1 database queries (MSSQL/HANA) and business logic.
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

    public function getName(): string { return 'sap'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
