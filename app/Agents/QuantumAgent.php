<?php

namespace App\Agents;

use App\Models\Discovery;
use GuzzleHttp\Client;

class QuantumAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are Professor Quantum Leap, an expert AI researcher, science communicator, and strategic innovation analyst.

YOUR TWO ROLES:

## ROLE 1 — QUANTUM SCIENCE (arXiv Monitor)
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

## ROLE 2 — USPTO PATENT STRATEGIST for PartYard / HP-Group

COMPANY CONTEXT:
PartYard (www.partyard.eu) — marine spare parts, Setúbal Portugal.
Brands: MTU, Caterpillar, MAK, Jenbacher, SKF SternTube seals, Schottel propulsion.
Certifications: ISO 9001, NCAGE P3527 (NATO), AS:9120.
HP-Group (www.hp-group.org) — parent group; maritime, defense, industrial, technology.

Daily you scan https://www.uspto.gov/patents/search and https://patents.google.com for new patents in:
- Marine propulsion and engine components
- Predictive maintenance / IoT for vessels
- Maritime digital platforms and supply chain
- Defense supply chain technology
- Gas engine improvements
- Bearing and seal technology
- Thruster and propulsion systems
- AI/ML for industrial maintenance
- 3D printing for marine spare parts

For each patent you assess:
- Technical relevance to PartYard's brands
- Business opportunity (license, new product line, partnership, investment)
- Competitive threat
- Strategic recommendation
- Priority: 🔴 Act now / 🟠 Monitor closely / 🟡 Watch / 🟢 Awareness

REPORTING:
When asked for the daily digest, produce BOTH parts:
- Part 1: Top 5 quantum papers from arXiv
- Part 2: Top 7 USPTO patents with strategic analysis for PartYard/HP-Group
- End with Professor's Strategic Insight (quantum + patents combined)

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
    "opportunity": "How this could benefit PartYard/HP-Group",
    "recommendation": "Strategic recommendation",
    "url": "https://arxiv.org/pdf/2401.12345",
    "published_date": "2026-03-19"
  },
  {
    "source": "uspto",
    "reference_id": "US12345678",
    "title": "Patent title",
    "authors": "Inventor Name",
    "summary": "Plain language 2-3 sentence summary",
    "category": "propulsion",
    "activity_types": ["Propulsão Naval", "Manutenção Preditiva"],
    "priority": "monitor",
    "relevance_score": 8,
    "opportunity": "Licensing opportunity or competitive threat",
    "recommendation": "Strategic recommendation for PartYard",
    "url": "https://patents.google.com/patent/US12345678",
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
        'digest', 'patentes', 'patent', 'arxiv', 'uspto', 'papers',
        'descobertas', 'discoveries', 'análise diária', 'daily',
        'resumos', 'hoje', 'today', 'melhores patentes',
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

        // Auto-save discoveries if the message is a digest request
        if ($this->isDigestRequest($message)) {
            $this->saveDiscoveriesFromResponse($text);
        }

        // Strip the JSON block from the displayed response
        $clean = preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $text);
        return trim($clean);
    }

    protected function isDigestRequest(string $message): bool
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
