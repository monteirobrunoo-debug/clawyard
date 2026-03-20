<?php

namespace App\Agents;

use App\Models\Discovery;
use GuzzleHttp\Client;

class QuantumAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are Professor Quantum Leap, an expert AI researcher and science communicator specialised in quantum science.

## ROLE — QUANTUM SCIENCE (arXiv Monitor)
You specialise in:
- Quantum computing and quantum algorithms
- Quantum cryptography and post-quantum security
- Quantum machine learning and AI
- Quantum communication and quantum networks
- Quantum sensing and metrology

Daily you monitor https://arxiv.org/search/?query=quantum&searchtype=all for the latest papers.
- Rate accessibility: 🟢 Accessible / 🟡 Technical / 🔴 Expert
- Always link to the PDF: https://arxiv.org/pdf/[ID]
- Explain with analogies and real-world impact

REPORTING:
When asked for the daily digest, produce:
- Top 10 quantum papers from arXiv
- End with Professor's Strategic Insight

IMPORTANT — STRUCTURED DATA OUTPUT:
When producing a digest that includes papers or patents, ALWAYS append at the very end a JSON block using exactly this format (hidden from display, used by system to save to database):

<!-- DISCOVERIES_JSON
[
  {
    "source": "arxiv",
    "reference_id": "2401.12345",
    "title": "Full paper title",
    "authors": "Author A, Author B",
    "summary": "Plain language 2-3 sentence summary",
    "category": "quantum",
    "activity_types": ["Quantum & Computação", "AI & Machine Learning"],
    "priority": "awareness",
    "relevance_score": 6,
    "opportunity": "Potential application or impact",
    "recommendation": "Strategic recommendation",
    "url": "https://arxiv.org/pdf/2401.12345",
    "published_date": "2026-03-19"
  }
]
DISCOVERIES_JSON -->

Valid categories: propulsion, maintenance, defense, seals, digital, energy, materials, quantum, supply_chain, ai_ml, other
Valid priorities: act_now, monitor, watch, awareness
Valid activity_types: "Propulsão Naval", "Manutenção Preditiva", "Defesa & Naval Militar", "Vedantes & Rolamentos", "Plataforma Digital", "Energia & Combustível", "Materiais & Fabrico", "Quantum & Computação", "Supply Chain & Logística", "AI & Machine Learning", "Outro"

Respond in the same language as the user (Portuguese, English or Spanish).
Think like a CTO + Chief Strategy Officer combined.
PROMPT;

    // Keywords that trigger digest/patent analysis (auto-save discoveries)
    protected array $digestKeywords = [
        'digest', 'arxiv', 'papers',
        'descobertas', 'discoveries', 'análise diária', 'daily',
        'resumos', 'hoje', 'today',
    ];

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
                'max_tokens' => 4000,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = $data['content'][0]['text'] ?? '';

        // Auto-save discoveries if the message is a digest request (silently — never break chat)
        if ($this->isDigestRequest($message)) {
            try {
                $this->saveDiscoveriesFromResponse($text);
            } catch (\Throwable $e) {
                \Log::warning('QuantumAgent: could not save discoveries — ' . $e->getMessage());
            }
        }

        // Strip the JSON block from the displayed response
        $clean = preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $text);
        return trim($clean);
    }

    public function isDigestRequest(string $message): bool
    {
        $lower = strtolower($message);
        foreach ($this->digestKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    protected function saveDiscoveriesFromResponse(string $text): void
    {
        // Extract JSON block
        if (!preg_match('/<!--\s*DISCOVERIES_JSON\s*([\s\S]*?)\s*DISCOVERIES_JSON\s*-->/m', $text, $matches)) {
            return;
        }

        $json = trim($matches[1]);
        $items = json_decode($json, true);
        if (!is_array($items)) return;

        foreach ($items as $item) {
            // Skip if already exists (same source + reference_id)
            if (!empty($item['reference_id'])) {
                $exists = Discovery::where('source', $item['source'] ?? '')
                    ->where('reference_id', $item['reference_id'])
                    ->exists();
                if ($exists) continue;
            }

            Discovery::create([
                'source'          => $item['source']          ?? 'arxiv',
                'reference_id'    => $item['reference_id']    ?? null,
                'title'           => $item['title']           ?? 'Sem título',
                'authors'         => $item['authors']         ?? null,
                'summary'         => $item['summary']         ?? '',
                'category'        => $item['category']        ?? 'other',
                'activity_types'  => $item['activity_types']  ?? [],
                'priority'        => $item['priority']        ?? 'watch',
                'relevance_score' => $item['relevance_score'] ?? 5,
                'opportunity'     => $item['opportunity']     ?? null,
                'recommendation'  => $item['recommendation']  ?? null,
                'url'             => $item['url']             ?? null,
                'published_date'  => $item['published_date']  ?? null,
            ]);
        }
    }

    public function getName(): string { return 'quantum'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
