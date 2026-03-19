<?php

namespace App\Agents;

use App\Models\Discovery;
use GuzzleHttp\Client;

class QuantumAgent implements AgentInterface
{
    protected Client $client;
    protected Client $httpClient;

    protected string $systemPrompt = <<<'PROMPT'
You are Professor Quantum Leap, expert AI researcher, science communicator, and strategic innovation analyst for PartYard / HP-Group.

COMPANY CONTEXT:
PartYard (www.partyard.eu) — marine spare parts, Setúbal Portugal.
Brands: MTU, Caterpillar, MAK, Jenbacher, SKF SternTube seals, Schottel propulsion.
Certifications: ISO 9001, NCAGE P3527 (NATO), AS:9120.
HP-Group (www.hp-group.org) — parent group; maritime, defense, industrial, technology.

YOUR ROLE:
- Analyse REAL data provided to you (arXiv papers and patents fetched today)
- Rate papers: 🟢 Accessible / 🟡 Technical / 🔴 Expert
- For patents: assess technical relevance, business opportunity, competitive threat
- Priority: 🔴 Act now / 🟠 Monitor closely / 🟡 Watch / 🟢 Awareness
- Think like a CTO + Chief Strategy Officer combined

REPORTING:
When given real data, produce:
- Part 1: Top 5 quantum/tech papers analysis
- Part 2: Top 7 patents with strategic analysis for PartYard/HP-Group
- End with Professor's Strategic Insight

IMPORTANT — STRUCTURED DATA OUTPUT:
Always append at the very end a JSON block (hidden from display):

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
  }
]
DISCOVERIES_JSON -->

Valid categories: propulsion, maintenance, defense, seals, digital, energy, materials, quantum, supply_chain, ai_ml, other
Valid priorities: act_now, monitor, watch, awareness
Valid activity_types: "Propulsão Naval", "Manutenção Preditiva", "Defesa & Naval Militar", "Vedantes & Rolamentos", "Plataforma Digital", "Energia & Combustível", "Materiais & Fabrico", "Quantum & Computação", "Supply Chain & Logística", "AI & Machine Learning", "Outro"

Respond in the same language as the user (Portuguese, English or Spanish).
PROMPT;

    protected array $digestKeywords = [
        'digest', 'patentes', 'patent', 'arxiv', 'uspto', 'papers',
        'descobertas', 'discoveries', 'análise diária', 'daily',
        'resumos', 'hoje', 'today', 'melhores patentes', 'novas patentes',
    ];

    protected array $arxivTopics = [
        'quantum computing',
        'quantum cryptography',
        'quantum machine learning',
    ];

    protected array $patentTopics = [
        'marine propulsion engine',
        'predictive maintenance vessel',
        'maritime digital platform',
        'bearing seal marine',
        'thruster propulsion system',
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

        $this->httpClient = new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'headers'         => ['User-Agent' => 'ClawYard/1.0 (research@hp-group.org)'],
        ]);
    }

    // ─── Fetch real arXiv papers ───────────────────────────────────────────
    protected function fetchArxivPapers(): string
    {
        try {
            $query   = urlencode('quantum computing OR quantum cryptography OR quantum machine learning');
            $url     = "https://export.arxiv.org/api/query?search_query={$query}&start=0&max_results=8&sortBy=submittedDate&sortOrder=descending";
            $xml     = $this->httpClient->get($url)->getBody()->getContents();
            $feed    = simplexml_load_string($xml);
            if (!$feed) return '(arXiv unavailable)';

            $papers = [];
            foreach ($feed->entry as $entry) {
                $id      = basename((string) $entry->id);
                $authors = implode(', ', array_slice(array_map(fn($a) => (string)$a->name, iterator_to_array($entry->author)), 0, 3));
                $papers[] = "- [{$id}] " . trim((string) $entry->title) . " | Authors: {$authors} | Published: " . substr((string) $entry->published, 0, 10) . " | URL: https://arxiv.org/abs/{$id} | Abstract: " . substr(trim((string) $entry->summary), 0, 300) . '...';
            }

            return implode("\n", $papers) ?: '(no arXiv results)';
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: arXiv fetch failed — ' . $e->getMessage());
            return '(arXiv fetch error: ' . $e->getMessage() . ')';
        }
    }

    // ─── Fetch real patents via Google Patents RSS ─────────────────────────
    protected function fetchPatents(): string
    {
        $allPatents = [];

        foreach (array_slice($this->patentTopics, 0, 3) as $topic) {
            try {
                $q    = urlencode($topic);
                $url  = "https://patents.google.com/rss/query?q={$q}&before=priority:20260320&after=priority:20250101&num=5";
                $xml  = $this->httpClient->get($url)->getBody()->getContents();
                $feed = simplexml_load_string($xml);
                if (!$feed) continue;

                $channel = $feed->channel ?? null;
                if (!$channel) continue;

                foreach ($channel->item as $item) {
                    $title = trim((string) $item->title);
                    $link  = trim((string) $item->link);
                    $desc  = substr(trim(strip_tags((string) $item->description)), 0, 250);
                    $date  = trim((string) ($item->pubDate ?? ''));
                    if ($title && $link) {
                        $allPatents[] = "- [{$topic}] {$title} | URL: {$link} | Date: {$date} | Abstract: {$desc}...";
                    }
                    if (count($allPatents) >= 10) break 2;
                }
            } catch (\Throwable $e) {
                \Log::warning("QuantumAgent: patent fetch failed for '{$topic}' — " . $e->getMessage());
            }
        }

        return $allPatents ? implode("\n", array_slice($allPatents, 0, 8)) : '(patent data unavailable — use your knowledge of recent patents)';
    }

    // ─── Build enriched message with real data ─────────────────────────────
    protected function buildDigestMessage(string $userMessage): string
    {
        $today   = now()->format('Y-m-d');
        $papers  = $this->fetchArxivPapers();
        $patents = $this->fetchPatents();

        return <<<MSG
{$userMessage}

--- REAL DATA FETCHED TODAY ({$today}) ---

## arXiv Papers (fetched live from export.arxiv.org):
{$papers}

## Patent Data (fetched live from Google Patents RSS):
{$patents}

--- END REAL DATA ---

Please analyse ALL the above real data. Use the actual paper IDs, titles, authors and dates provided above. Do NOT invent papers or patents — only analyse what is in the real data above.
MSG;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string $message, array $history = []): string
    {
        $finalMessage = $this->isDigestRequest($message)
            ? $this->buildDigestMessage($message)
            : $message;

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'json' => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 8000,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = $data['content'][0]['text'] ?? '';

        if ($this->isDigestRequest($message)) {
            try { $this->saveDiscoveriesFromResponse($text); } catch (\Throwable $e) {
                \Log::warning('QuantumAgent: could not save discoveries — ' . $e->getMessage());
            }
        }

        return trim(preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $text));
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string $message, array $history, callable $onChunk): string
    {
        $isDigest     = $this->isDigestRequest($message);
        $finalMessage = $isDigest ? $this->buildDigestMessage($message) : $message;

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'stream' => true,
            'json'   => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 8000,
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
                        // Don't stream the hidden JSON block to the user
                        $full .= $text;
                        if (!str_contains($full, '<!-- DISCOVERIES_JSON')) {
                            $onChunk($text);
                        }
                    }
                }
            }
        }

        if ($isDigest) {
            try { $this->saveDiscoveriesFromResponse($full); } catch (\Throwable $e) {
                \Log::warning('QuantumAgent stream: could not save discoveries — ' . $e->getMessage());
            }
        }

        return trim(preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $full));
    }

    // ─── Helpers ───────────────────────────────────────────────────────────
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
        if (!preg_match('/<!--\s*DISCOVERIES_JSON\s*([\s\S]*?)\s*DISCOVERIES_JSON\s*-->/m', $text, $matches)) return;

        $items = json_decode(trim($matches[1]), true);
        if (!is_array($items)) return;

        foreach ($items as $item) {
            if (!empty($item['reference_id'])) {
                $exists = Discovery::where('source', $item['source'] ?? '')
                    ->where('reference_id', $item['reference_id'])->exists();
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
