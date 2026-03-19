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
- Part 1: Top 5 arXiv quantum/AI papers analysis
- Part 2: Top 4 PeerJ CS articles on agents & multi-agent systems
- Part 3: Top 7 USPTO patents with strategic analysis for PartYard/HP-Group
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

Valid sources: "arxiv", "peerj", "uspto"
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
        'maritime bearing seal thruster',
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
            'timeout'         => 10,
            'connect_timeout' => 5,
            'verify'          => false,
            'headers'         => ['User-Agent' => 'Mozilla/5.0 (compatible; ClawYardBot/1.0)'],
        ]);
    }

    // ─── Fetch arXiv papers + auto-save to discoveries ────────────────────
    protected function fetchArxivPapers(): string
    {
        try {
            $query = urlencode('quantum computing OR quantum cryptography OR quantum machine learning');
            $url   = "https://export.arxiv.org/api/query?search_query={$query}&start=0&max_results=8&sortBy=submittedDate&sortOrder=descending";
            $xml   = $this->httpClient->get($url)->getBody()->getContents();
            $feed  = simplexml_load_string($xml);
            if (!$feed) return '(arXiv unavailable)';

            $lines = [];
            foreach ($feed->entry as $entry) {
                $id       = basename((string) $entry->id);
                $title    = trim((string) $entry->title);
                $authors  = implode(', ', array_slice(array_map(fn($a) => (string)$a->name, iterator_to_array($entry->author)), 0, 3));
                $published = substr((string) $entry->published, 0, 10);
                $abstract  = trim((string) $entry->summary);

                // Auto-save to discoveries (skip duplicates)
                try {
                    if (!Discovery::where('source','arxiv')->where('reference_id',$id)->exists()) {
                        Discovery::create([
                            'source'          => 'arxiv',
                            'reference_id'    => $id,
                            'title'           => $title,
                            'authors'         => $authors,
                            'summary'         => substr($abstract, 0, 500),
                            'category'        => 'quantum',
                            'activity_types'  => ['Quantum & Computação', 'AI & Machine Learning'],
                            'priority'        => 'awareness',
                            'relevance_score' => 6,
                            'opportunity'     => 'Potencial aplicação em sistemas digitais PartYard/HP-Group',
                            'recommendation'  => 'Monitorizar desenvolvimento e avaliar aplicabilidade',
                            'url'             => "https://arxiv.org/abs/{$id}",
                            'published_date'  => $published,
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning("QuantumAgent: could not save arXiv discovery {$id} — " . $e->getMessage());
                }

                $lines[] = "- [{$id}] {$title} | Authors: {$authors} | Published: {$published} | URL: https://arxiv.org/abs/{$id} | Abstract: " . substr($abstract, 0, 300) . '...';
            }

            return implode("\n", $lines) ?: '(no arXiv results)';
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: arXiv fetch failed — ' . $e->getMessage());
            return '(arXiv fetch error: ' . $e->getMessage() . ')';
        }
    }

    // ─── Fetch real USPTO patents via PatentsView API ──────────────────────
    protected function fetchPatents(): string
    {
        $apiKey = config('services.patentsview.api_key');

        if (!$apiKey) {
            return '(USPTO PatentsView API key not configured — set PATENTSVIEW_API_KEY in .env. Use your training knowledge of recent USPTO patents from 2024-2026 for marine propulsion, predictive maintenance, bearing seals, thruster systems.)';
        }

        $queries = [
            'marine propulsion engine',
            'predictive maintenance vessel',
            'bearing seal maritime',
        ];

        $patents = [];

        foreach ($queries as $q) {
            try {
                $response = $this->httpClient->get('https://search.patentsview.org/api/v1/patent/', [
                    'headers' => [
                        'X-Api-Key' => $apiKey,
                        'accept'    => 'application/json',
                    ],
                    'query' => [
                        'q' => json_encode(['_text_any' => ['patent_title' => $q]]),
                        'f' => json_encode(['patent_id','patent_title','patent_abstract','patent_date']),
                        'o' => json_encode(['size' => 3]),
                        's' => json_encode([['patent_date' => 'desc']]),
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($data['patents'] ?? [] as $p) {
                    $num      = $p['patent_id']    ?? 'N/A';
                    $title    = $p['patent_title']  ?? 'N/A';
                    $date     = $p['patent_date']   ?? 'N/A';
                    $abstract = substr($p['patent_abstract'] ?? '', 0, 250);
                    $patents[] = "- [US{$num}] {$title} | Date: {$date} | URL: https://patents.google.com/patent/US{$num} | Abstract: {$abstract}...";
                    if (count($patents) >= 8) break 2;
                }
            } catch (\Throwable $e) {
                \Log::warning("QuantumAgent: USPTO fetch failed for '{$q}' — " . $e->getMessage());
            }
        }

        return $patents
            ? implode("\n", $patents)
            : '(USPTO API returned no results — use your knowledge of recent patents)';
    }

    // ─── Fetch PeerJ CS via CrossRef + auto-save to discoveries ──────────
    protected function fetchPeerJPapers(): string
    {
        try {
            $query    = urlencode('multi-agent systems autonomous agents AI maritime industrial');
            $url      = "https://api.crossref.org/works?query={$query}&filter=prefix:10.7717&rows=6&sort=published&order=desc";
            $response = $this->httpClient->get($url, [
                'headers' => ['User-Agent' => 'ClawYard/1.0 (mailto:research@hp-group.org)'],
            ]);

            $data  = json_decode($response->getBody()->getContents(), true);
            $items = $data['message']['items'] ?? [];
            $lines = [];

            foreach ($items as $item) {
                $title    = is_array($item['title'] ?? '') ? ($item['title'][0] ?? 'N/A') : ($item['title'] ?? 'N/A');
                $doi      = $item['DOI'] ?? 'N/A';
                $year     = $item['published-online']['date-parts'][0][0] ?? ($item['issued']['date-parts'][0][0] ?? null);
                $month    = $item['published-online']['date-parts'][0][1] ?? null;
                $day      = $item['published-online']['date-parts'][0][2] ?? 1;
                $pubDate  = $year ? "{$year}-" . str_pad($month ?? 1, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT) : null;
                $authors  = implode(', ', array_slice(array_map(fn($a) => ($a['given'] ?? '') . ' ' . ($a['family'] ?? ''), $item['author'] ?? []), 0, 3));
                $abstract = substr(strip_tags($item['abstract'] ?? ''), 0, 500);
                $url_art  = "https://doi.org/{$doi}";

                // Auto-save to discoveries
                try {
                    if (!Discovery::where('source','peerj')->where('reference_id',$doi)->exists()) {
                        Discovery::create([
                            'source'          => 'peerj',
                            'reference_id'    => $doi,
                            'title'           => $title,
                            'authors'         => $authors,
                            'summary'         => $abstract ?: 'Sem resumo disponível',
                            'category'        => 'ai_ml',
                            'activity_types'  => ['AI & Machine Learning', 'Plataforma Digital'],
                            'priority'        => 'watch',
                            'relevance_score' => 5,
                            'opportunity'     => 'Investigação em sistemas multi-agente aplicável ao ClawYard',
                            'recommendation'  => 'Avaliar aplicabilidade ao sistema de agentes PartYard',
                            'url'             => $url_art,
                            'published_date'  => $pubDate,
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning("QuantumAgent: could not save PeerJ discovery {$doi} — " . $e->getMessage());
                }

                $lines[] = "- [PeerJ:{$doi}] {$title} | Authors: {$authors} | Date: " . ($pubDate ?? 'N/A') . " | URL: {$url_art}" . ($abstract ? " | Abstract: {$abstract}..." : '');
            }

            return $lines ? implode("\n", $lines) : '(no PeerJ results today)';
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: PeerJ/CrossRef fetch failed — ' . $e->getMessage());
            return '(PeerJ fetch unavailable)';
        }
    }

    // ─── Build enriched message with real data ─────────────────────────────
    protected function buildDigestMessage(string $userMessage): string
    {
        $today   = now()->format('Y-m-d');
        $arxiv   = $this->fetchArxivPapers();
        $peerj   = $this->fetchPeerJPapers();
        $patents = $this->fetchPatents();

        return <<<MSG
{$userMessage}

--- REAL DATA FETCHED TODAY ({$today}) ---

## arXiv Papers (fetched live from export.arxiv.org — quantum & AI):
{$arxiv}

## PeerJ Computer Science Articles (fetched live via CrossRef — agents & multi-agent systems):
{$peerj}

## USPTO Patent Data:
{$patents}

--- END REAL DATA ---

Please analyse ALL the above real data from all three sources. Use actual IDs, titles, authors and dates. Do NOT invent papers or patents — only analyse what is provided above.
For the DISCOVERIES_JSON block, include entries from all three sources (source: "arxiv", "peerj", or "uspto").
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
