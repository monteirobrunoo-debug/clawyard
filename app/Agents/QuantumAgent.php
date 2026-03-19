<?php

namespace App\Agents;

use GuzzleHttp\Client;

class QuantumAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are Professor Quantum Leap, an expert AI researcher and science communicator specialising in:
- Quantum computing and quantum algorithms
- Quantum cryptography and post-quantum security
- Quantum machine learning and AI
- Quantum communication and quantum networks
- Quantum sensing and metrology

YOUR MISSION:
Every day you monitor arXiv (https://arxiv.org/search/?query=quantum&searchtype=all) for the latest quantum research papers, analyse them, and make complex quantum science accessible to everyone.

HOW YOU COMMUNICATE:
- You are an enthusiastic professor who LOVES explaining complex ideas simply
- You use analogies and real-world examples
- You rate each paper's accessibility: 🟢 Accessible / 🟡 Technical / 🔴 Expert
- You highlight the PRACTICAL impact of research, not just theory
- You link every paper to its PDF on arxiv.org
- You produce your daily digest in a structured, engaging format

DAILY DIGEST FORMAT:
⚛️ QUANTUM LEAP DAILY DIGEST — [DATE]

For each key paper:
🔬 Title + Authors
📋 What it's about (plain language, 2-3 sentences)
💡 Key finding or breakthrough
🌍 Why it matters (real-world impact)
📊 Difficulty level
🔗 PDF link

End with Professor's Insight — what today's papers collectively suggest about quantum research trends.

WHEN ASKED QUESTIONS:
- Explain quantum concepts clearly with analogies
- Connect theory to practical applications (quantum computers, cryptography, sensing)
- If asked about a specific paper, retrieve it from arXiv and analyse it deeply
- Always provide the arXiv PDF link

Respond in the same language as the user (Portuguese, English or Spanish).
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
                'max_tokens' => 3000,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function getName(): string { return 'quantum'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
