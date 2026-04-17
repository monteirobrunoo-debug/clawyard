<?php

namespace App\Agents;

use App\Models\Discovery;
use App\Models\Report;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\SharedContextTrait;
use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\PatentPdfService;

class QuantumAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'research_intel';
    protected array  $contextTags = ['arXiv','paper','investigação','ciência','patent','USPTO','EPO','quantum','descoberta'];
    protected Client $client;
    protected Client $httpClient;


    protected array $digestKeywords = [
        'digest', 'patentes', 'patent', 'arxiv', 'peerj', 'crossref', 'papers',
        'descobertas', 'discoveries', 'análise diária', 'daily',
        'resumos', 'hoje', 'today', 'melhores patentes', 'novas patentes',
        'epo', 'espacenet', 'european patent', 'patente europeia',
        // broader portal/research triggers
        'portal', 'portais', 'científico', 'cientifico', 'pesquisa científica',
        'research', 'publicações', 'publicacoes', 'artigos', 'artigo',
        'novas publicações', 'últimas publicações', 'latest papers',
        'quantum news', 'novidades', 'novidade', 'novos papers',
        'vai ao', 'busca nos', 'procura nos', 'faz a pesquisa',
    ];

    protected array $arxivTopics = [
        'quantum computing',
        'quantum cryptography',
        'quantum machine learning',
        'autonomous vessel maritime',
        'marine propulsion AI',
        'naval defense technology',
        'predictive maintenance industrial',
    ];

    public function __construct()
    {
        $persona = 'You are Professor Quantum Leap, expert AI researcher, science communicator, and strategic innovation analyst for PartYard / HP-Group.';

        $specialty = <<<'SPECIALTY'
YOUR ROLE:
- Analyse REAL data provided to you (arXiv papers, PeerJ articles and EPO patents fetched today)
- Rate papers: 🟢 Accessible / 🟡 Technical / 🔴 Expert
- Priority: 🔴 Act now / 🟠 Monitor closely / 🟡 Watch / 🟢 Awareness
- Think like a CTO + Chief Strategy Officer combined

REPORTING:
When given real data, produce:
- Part 1: Top 10 arXiv quantum/AI papers analysis
- Part 2: Top 4 PeerJ CS articles on agents & multi-agent systems
- Part 3: Top 10 EPO Patents (European Patent Office) — últimas patentes dos últimos 3 dias relevantes para PartYard/HP-Group (naval, defesa, quantum, cyber, AI, IoT, energia, materiais, supply chain)
- End with Professor's Strategic Insight

PATENT ANALYSIS (Part 3):
For each EPO patent, analyse:
- 🏛️ Patent number and title
- 👤 Applicant/Assignee (who filed it — competitor or partner?)
- 📋 What it covers technically
- ⚡ Strategic implication for PartYard/HP-Group
- 💡 Action: license / monitor / design-around / challenge?

IMPORTANT — STRUCTURED DATA OUTPUT:
Always append at the very end a JSON block (hidden from display).
CRITICAL: Use ONLY the REAL IDs and URLs from the data provided to you. NEVER invent IDs. NEVER use "xxxx", "xxxxx", "12345" or placeholders.
For PeerJ papers specifically: the DOI is provided in the EXACT_DOI field of each record — copy it exactly as-is. The URL is in the FULL_URL field. NEVER construct a PeerJ DOI from a template like "10.7717/peerj-cs.xxxxx" — if you don't have the real DOI from the data, skip that paper.
For EPO patents: use ONLY the REAL patent number from the EPO_NUMBER field. URL must be https://worldwide.espacenet.com/patent/search?q=pn%3D[EPO_NUMBER]

<!-- DISCOVERIES_JSON
[
  {
    "source": "arxiv",
    "reference_id": "[REAL arXiv ID from data, e.g. 2503.12987]",
    "title": "Full paper title exactly as provided",
    "authors": "Author A, Author B",
    "summary": "Plain language 2-3 sentence summary",
    "category": "quantum",
    "activity_types": ["Quantum & Computação", "AI & Machine Learning"],
    "priority": "awareness",
    "relevance_score": 6,
    "opportunity": "How this could benefit PartYard/HP-Group",
    "recommendation": "Strategic recommendation",
    "url": "https://arxiv.org/abs/[REAL arXiv ID]",
    "published_date": "2026-03-19"
  }
]
DISCOVERIES_JSON -->

Valid sources: "arxiv", "peerj", "epo"
Valid categories: propulsion, maintenance, defense, seals, digital, energy, materials, quantum, supply_chain, ai_ml, other
Valid priorities: act_now, monitor, watch, awareness
Valid activity_types: "Propulsão Naval", "Manutenção Preditiva", "Defesa & Naval Militar", "Vedantes & Rolamentos", "Plataforma Digital", "Energia & Combustível", "Materiais & Fabrico", "Quantum & Computação", "Supply Chain & Logística", "AI & Machine Learning", "Outro"
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::research($persona, $specialty)
        );

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 600,  // 10 min — large reports with extended thinking can take >2 min
            'connect_timeout' => 10,
        ]);

        // SECURITY: arXiv, USPTO, Google Patents and EPO all have valid TLS certs.
        // Keeping verify=true so downloaded papers / patents can't be tampered with
        // by an on-path attacker.
        $this->httpClient = new Client([
            'timeout'         => 20,
            'connect_timeout' => 8,
            'verify'          => true,
            'headers'         => ['User-Agent' => 'Mozilla/5.0 (compatible; ClawYardBot/1.0)'],
        ]);
    }

    // ─── Fetch arXiv papers + auto-save to discoveries ────────────────────
    protected function fetchArxivPapers(): string
    {
        // Shorter, simpler query → faster response from export.arxiv.org
        $query = urlencode(
            'quantum computing OR quantum cryptography OR quantum machine learning ' .
            'OR autonomous vessel OR marine propulsion OR naval defense OR predictive maintenance'
        );
        $url = "https://export.arxiv.org/api/query?search_query={$query}&start=0&max_results=25&sortBy=submittedDate&sortOrder=descending";

        try {
            // 35s per-request timeout (overrides httpClient default 20s)
            $xml  = $this->httpClient->get($url, ['timeout' => 35])->getBody()->getContents();
            $feed = simplexml_load_string($xml);
            if (!$feed || !isset($feed->entry)) return '(arXiv: sem resultados)';

            $lines = [];
            foreach ($feed->entry as $entry) {
                $id        = basename((string) $entry->id);
                $title     = trim((string) $entry->title);
                $authors   = implode(', ', array_slice(array_map(fn($a) => (string)$a->name, iterator_to_array($entry->author)), 0, 3));
                $published = substr((string) $entry->published, 0, 10);
                $abstract  = trim((string) $entry->summary);

                try {
                    if (!Discovery::where('source', 'arxiv')->where('reference_id', $id)->exists()) {
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
                    \Log::warning("QuantumAgent: arXiv save {$id} — " . $e->getMessage());
                }

                $lines[] = "- [{$id}] {$title} | Authors: {$authors} | Published: {$published} | URL: https://arxiv.org/abs/{$id} | Abstract: " . substr($abstract, 0, 300) . '...';
            }

            return $lines ? implode("\n", $lines) : '(arXiv: sem resultados para os critérios)';

        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: arXiv fetch failed — ' . $e->getMessage());

            // ── Fallback: Tavily web search for recent arXiv papers ──────────
            try {
                \Log::info('QuantumAgent: arXiv fallback → Tavily');
                $fallback = $this->augmentWithWebSearch(
                    'arxiv.org latest papers quantum computing maritime AI naval defense 2025 2026',
                    null
                );
                if ($fallback && strlen($fallback) > 50) {
                    return "(arXiv Quantum/AI — via pesquisa web [API timeout])\n{$fallback}";
                }
            } catch (\Throwable $e2) {
                \Log::warning('QuantumAgent: arXiv Tavily fallback failed — ' . $e2->getMessage());
            }

            return '(arXiv Quantum/AI: ❌ Indisponível — timeout. Tentar novamente mais tarde.)';
        }
    }

    // ─── Fetch PeerJ CS via CrossRef + auto-save to discoveries ──────────
    protected function fetchPeerJPapers(): string
    {
        try {
            $query    = urlencode('multi-agent systems autonomous agents AI maritime industrial');
            $url      = "https://api.crossref.org/works?query={$query}&filter=prefix:10.7717&rows=4&sort=published&order=desc";
            $response = $this->httpClient->get($url, [
                'headers' => ['User-Agent' => 'ClawYard/1.0 (mailto:research@hp-group.org)'],
            ]);

            $data  = json_decode($response->getBody()->getContents(), true);
            $items = $data['message']['items'] ?? [];
            $lines = [];

            foreach ($items as $item) {
                $doi = $item['DOI'] ?? null;

                // Skip papers without a real DOI — nothing useful to save or show
                if (empty($doi) || $doi === 'N/A') continue;

                $title    = is_array($item['title'] ?? '') ? ($item['title'][0] ?? 'N/A') : ($item['title'] ?? 'N/A');
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

                $lines[] = "- EXACT_DOI={$doi} | FULL_URL={$url_art} | TITLE={$title} | Authors: {$authors} | Date: " . ($pubDate ?? 'N/A') . ($abstract ? " | Abstract: {$abstract}..." : '');
            }

            return $lines ? implode("\n", $lines) : '(no PeerJ results today)';
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: PeerJ/CrossRef fetch failed — ' . $e->getMessage());
            return '(PeerJ fetch unavailable)';
        }
    }

    // ─── Fetch EPO patents via OPS API (OAuth2 + CQL search) ──────────────
    protected function fetchEpoAccessToken(): ?string
    {
        try {
            $key    = config('services.epo.consumer_key');
            $secret = config('services.epo.consumer_secret');
            if (!$key || !$secret) return null;

            $response = $this->httpClient->post('https://ops.epo.org/3.2/auth/accesstoken', [
                'form_params' => ['grant_type' => 'client_credentials'],
                'headers'     => [
                    'Authorization' => 'Basic ' . base64_encode("{$key}:{$secret}"),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'] ?? null;
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: EPO OAuth failed — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build EPO CQL query covering ALL HP-Group / PartYard business activities.
     *
     * Uses `ta any "..."` so ANY patent whose title or abstract contains
     * at least ONE of the listed keywords is returned.
     *
     * Covers every PartYard business unit:
     *   PartYard Marine      — spare parts, engines, seals, propulsion
     *   PartYard Military    — defense, aerospace MRO, NATO platforms
     *   Armite Lubricants    — lubricant formulation, MIL-SPEC greases
     *   SETQ Cybersecurity   — network security, quantum crypto, C4ISR
     *   IndYard              — workforce, ERP, supply chain
     *   Viridis Ocean        — decarbonisation, sustainable shipping
     *   HP-Group R&D         — sensors, NDT, digital twin, AI, IoT
     */
    protected function buildEpoCql(?string $dateFrom = null, ?string $dateTo = null): string
    {
        $keywords = implode(' ', [

            // ── PartYard Marine — Engines & Spare Parts ───────────────────
            'propulsion', 'maritime', 'marine', 'naval', 'vessel', 'ship',
            'diesel-engine', 'engine-overhaul', 'crankshaft', 'camshaft',
            'fuel-injection', 'turbocharger', 'intercooler', 'heat-exchanger',
            'governor', 'gearbox', 'coupling', 'aftercooler', 'cylinder-liner',
            'piston', 'connecting-rod', 'exhaust-valve', 'inlet-valve',
            'water-pump', 'oil-cooler', 'separator', 'purifier',

            // ── PartYard Marine — Seals & Propulsion Systems ──────────────
            'stern-tube', 'shaft-seal', 'lip-seal', 'mechanical-seal',
            'propeller', 'thruster', 'azipod', 'pod-drive', 'rudder',
            'controllable-pitch', 'waterjet', 'cavitation', 'bearing',
            'roller-bearing', 'slewing-ring', 'bushing',

            // ── PartYard Military / Defense ───────────────────────────────
            'defense', 'military', 'armament', 'munition', 'ballistic',
            'radar', 'sonar', 'lidar', 'infrared-sensor', 'surveillance',
            'missile', 'torpedo', 'drone', 'UAV', 'UUV', 'USV',
            'combat', 'battlefield', 'C4ISR', 'IFF', 'ECM', 'countermeasure',
            'night-vision', 'thermal-imaging', 'armor', 'ballistic-protection',
            'NBC-protection',

            // ── Aerospace MRO — PartYard Military platforms ───────────────
            'aerospace', 'aircraft', 'helicopter', 'turbine', 'MRO',
            'airframe', 'avionics', 'landing-gear', 'hydraulics', 'actuator',
            'NDT', 'non-destructive', 'ultrasonic-inspection', 'eddy-current',
            'fatigue', 'crack-detection', 'composite-repair',

            // ── Armite Lubricants ─────────────────────────────────────────
            'lubricant', 'grease', 'oil-analysis', 'viscosity', 'tribology',
            'anti-wear', 'extreme-pressure', 'nano-lubricant', 'bio-lubricant',
            'synthetic-oil', 'gear-oil', 'hydraulic-fluid', 'rust-inhibitor',

            // ── SETQ Cybersecurity ─────────────────────────────────────────
            'cybersecurity', 'intrusion-detection', 'firewall', 'zero-trust',
            'SIEM', 'vulnerability', 'penetration-testing', 'encryption',
            'cryptography', 'PKI', 'certificate', 'authentication', 'biometric',

            // ── Quantum Technologies ───────────────────────────────────────
            'quantum', 'qubit', 'entanglement', 'superposition', 'QKD',
            'post-quantum', 'lattice-cryptography', 'quantum-sensing',
            'quantum-radar', 'quantum-communication', 'photon',

            // ── AI / Machine Learning ──────────────────────────────────────
            'machine-learning', 'deep-learning', 'neural-network',
            'autonomous', 'digital-twin', 'predictive-maintenance',
            'anomaly-detection', 'computer-vision', 'reinforcement-learning',
            'natural-language', 'large-language-model', 'transformer',

            // ── IoT & Industry 4.0 ────────────────────────────────────────
            'IoT', 'sensor-fusion', 'condition-monitoring', 'vibration-analysis',
            'thermography', 'remote-monitoring', 'SCADA', 'OPC-UA', 'edge-computing',
            'cloud-platform', 'digital-platform',

            // ── Energy & Decarbonisation ──────────────────────────────────
            'hydrogen', 'biofuel', 'LNG', 'methanol', 'ammonia',
            'fuel-cell', 'shore-power', 'battery-storage', 'hybrid-propulsion',
            'wind-propulsion', 'kite', 'scrubber', 'exhaust-cleaning',
            'decarbonization', 'CII', 'EEXI', 'carbon-capture',

            // ── Materials & Manufacturing ─────────────────────────────────
            'composite', 'additive-manufacturing', '3D-printing', 'coating',
            'corrosion-protection', 'thermal-spray', 'welding', 'casting',
            'high-strength-steel', 'titanium', 'aluminium-alloy',

            // ── Supply Chain & Logistics ──────────────────────────────────
            'supply-chain', 'spare-parts', 'inventory-management',
            'ERP', 'SAP', 'procurement', 'port-logistics', 'cargo',
            'containerization', 'tracking', 'RFID', 'blockchain-supply',

            // ── Simulation & Training ─────────────────────────────────────
            'simulator', 'training-system', 'virtual-reality', 'augmented-reality',
            'tactical-training', 'flight-simulator', 'mission-simulation',

            // ── Satellite & Communications ────────────────────────────────
            'satellite', 'VSAT', 'LEO', 'MEO', 'antenna', 'communication-system',
            'datalink', 'bandwidth', 'maritime-connectivity',
        ]);

        $base = "ta any \"{$keywords}\"";

        if ($dateFrom && $dateTo) {
            return "({$base}) AND pd within \"{$dateFrom} {$dateTo}\"";
        }
        return $base;
    }

    protected function fetchEpoPatents(): string
    {
        try {
            $token = $this->fetchEpoAccessToken();
            if (!$token) return '(EPO API token unavailable — check EPO_CONSUMER_KEY/SECRET in .env)';

            // Progressive date-range fallback: 7 → 30 → 90 days
            // (3-day window was too narrow and returned zero results)
            $dateRanges = [
                ['label' => '7 dias',  'from' => now()->subDays(7)->format('Ymd'),  'to' => now()->format('Ymd')],
                ['label' => '30 dias', 'from' => now()->subDays(30)->format('Ymd'), 'to' => now()->format('Ymd')],
                ['label' => '90 dias', 'from' => now()->subDays(90)->format('Ymd'), 'to' => now()->format('Ymd')],
            ];

            $docs       = [];
            $usedLabel  = '';

            foreach ($dateRanges as $range) {
                $cql      = urlencode($this->buildEpoCql($range['from'], $range['to']));
                $url      = "https://ops.epo.org/3.2/rest-services/published-data/search/biblio?q={$cql}";

                try {
                    $response = $this->httpClient->get($url, [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                            'Accept'        => 'application/json',
                            'X-OPS-Range'   => '1-20',
                        ],
                        'timeout' => 20,
                    ]);

                    $data         = json_decode($response->getBody()->getContents(), true);
                    $searchResult = $data['ops:world-patent-data']['ops:biblio-search']['ops:search-result'] ?? [];
                    $found        = $searchResult['exchange-documents']['exchange-document'] ?? [];

                    // Single result: wrap in array
                    if (isset($found['@country'])) $found = [$found];

                    if (!empty($found)) {
                        $docs      = $found;
                        $usedLabel = $range['label'];
                        break;
                    }
                } catch (\Throwable $e) {
                    \Log::warning("QuantumAgent: EPO search ({$range['label']}) error — " . $e->getMessage());
                }
            }

            if (empty($docs)) {
                \Log::warning('QuantumAgent: EPO OPS returned no results — trying Tavily fallback');
                try {
                    $fallback = $this->augmentWithWebSearch(
                        'site:worldwide.espacenet.com OR site:patents.google.com ' .
                        'marine naval propulsion quantum cryptography cybersecurity aerospace 2025 2026 new patent',
                        null
                    );
                    if ($fallback && strlen($fallback) > 80) {
                        return "(EPO Patents — via pesquisa web [OPS API sem resultados])\n{$fallback}";
                    }
                } catch (\Throwable $ef) {
                    \Log::warning('QuantumAgent: EPO Tavily fallback failed — ' . $ef->getMessage());
                }
                return '(EPO: sem patentes encontradas nos últimos 90 dias para os critérios definidos)';
            }

            $lines = ["=== EPO Patents — últimos {$usedLabel} ==="];
            foreach (array_slice($docs, 0, 20) as $doc) {
                $bib = $doc['bibliographic-data'] ?? [];

                // Patent number from publication reference
                $pubRef  = $bib['publication-reference']['document-id'] ?? [];
                if (isset($pubRef[0])) $pubRef = $pubRef[0]; // first doc-id (epodoc format)
                $country = $pubRef['country']['$'] ?? ($pubRef['@country'] ?? '');
                $docNum  = $pubRef['doc-number']['$'] ?? ($pubRef['@doc-number'] ?? '');
                $kind    = $pubRef['kind']['$'] ?? ($pubRef['@kind'] ?? '');
                $patNum  = trim("{$country}{$docNum}{$kind}");
                if (!$patNum) continue;

                // Title (prefer English)
                $title  = 'N/A';
                $titles = $bib['invention-title'] ?? [];
                if (isset($titles['$'])) {
                    $title = $titles['$'];
                } elseif (is_array($titles)) {
                    foreach ($titles as $t) {
                        if (($t['@lang'] ?? '') === 'en') { $title = $t['$'] ?? $title; break; }
                    }
                    if ($title === 'N/A' && !empty($titles[0]['$'])) $title = $titles[0]['$'];
                }

                // Applicant
                $applicant = 'N/A';
                $parties   = $bib['parties']['applicants']['applicant'] ?? [];
                if (!empty($parties)) {
                    $first     = isset($parties[0]) ? $parties[0] : $parties;
                    $applicant = $first['applicant-name']['name']['$'] ?? 'N/A';
                }

                $espUrl = 'https://worldwide.espacenet.com/patent/search?q=pn%3D' . urlencode($patNum);

                // Auto-save to discoveries
                try {
                    if (!Discovery::where('source', 'epo')->where('reference_id', $patNum)->exists()) {
                        Discovery::create([
                            'source'          => 'epo',
                            'reference_id'    => $patNum,
                            'title'           => $title,
                            'authors'         => $applicant,
                            'summary'         => "Patente EPO {$patNum} — {$title}. Requerente: {$applicant}.",
                            'category'        => 'other',
                            'activity_types'  => ['Propulsão Naval', 'Manutenção Preditiva'],
                            'priority'        => 'watch',
                            'relevance_score' => 5,
                            'opportunity'     => 'Avaliar licenciamento ou impacto competitivo para PartYard',
                            'recommendation'  => 'Analisar reivindicações e identificar sobreposição com produtos actuais',
                            'url'             => $espUrl,
                            'published_date'  => now()->format('Y-m-d'),
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning("QuantumAgent: could not save EPO patent {$patNum} — " . $e->getMessage());
                }

                $lines[] = "- EPO_NUMBER={$patNum} | TITLE={$title} | APPLICANT={$applicant} | URL={$espUrl}";
            }

            return count($lines) > 1 ? implode("\n", $lines) : "(EPO: sem patentes com dados completos nos últimos {$usedLabel})";
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: EPO patent fetch failed — ' . $e->getMessage());
            return '(EPO fetch error: ' . $e->getMessage() . ')';
        }
    }

    // ─── Build enriched message (pre-fetched data) ─────────────────────────
    protected function buildDigestMessage(string|array $userMessage): string
    {
        $arxiv = $this->fetchArxivPapers();
        $peerj = $this->fetchPeerJPapers();
        $epo   = $this->fetchEpoPatents();
        return $this->buildDigestMessageFromData($userMessage, $arxiv, $peerj, $epo);
    }

    protected function buildDigestMessageFromData(string|array $userMessage, string $arxiv, string $peerj, string $epo = ''): string
    {
        $today = now()->format('Y-m-d');

        return <<<MSG
{$userMessage}

--- REAL DATA FETCHED TODAY ({$today}) ---

## arXiv Papers (fetched live from export.arxiv.org — quantum & AI):
{$arxiv}

## PeerJ Computer Science Articles (fetched live via CrossRef — agents & multi-agent systems):
{$peerj}

## EPO Patents (fetched live from European Patent Office OPS API — últimos 3 dias, áreas: naval, defesa, quantum, cyber, AI, IoT, energia, materiais, supply chain):
{$epo}

--- END REAL DATA ---

Please analyse ALL the above real data from the three sources (arXiv + PeerJ + EPO patents).
CRITICAL RULES — READ EVERY RULE CAREFULLY:
- Use ONLY the REAL IDs, titles, authors, applicants and dates from the data above — NEVER invent or fabricate
- For EVERY paper AND patent in your analysis, include the FULL URL (from the data above)
- NEVER write "xxxx", "XXXX", "12345", "xxxxx" or ANY placeholder — use ONLY the real values from the data
- Format each arXiv paper as: **[Title]** (arXiv:[REAL_ID] | 📅 [Published date from data]) — analysis — 🔗 https://arxiv.org/abs/[REAL_ID]
- Format each PeerJ paper as: **[Title]** (DOI:[EXACT_DOI_FROM_EXACT_DOI_FIELD] | 📅 [Date from data]) — analysis — 🔗 [FULL_URL_FROM_FULL_URL_FIELD]
- Format each EPO patent as: **[Title]** (EPO:[EPO_NUMBER_FROM_EPO_NUMBER_FIELD] | Requerente: [APPLICANT]) — analysis — 🔗 [URL from data]
- The PeerJ EXACT_DOI field above IS the real DOI — copy it character-for-character, do NOT substitute or approximate
- If you cannot find the EXACT_DOI for a PeerJ paper in the data above, DO NOT mention that paper at all
- If you cannot find the EPO_NUMBER for a patent in the data above, DO NOT mention that patent at all
- For the DISCOVERIES_JSON block, include entries from all three sources (source: "arxiv", "peerj" or "epo")
MSG;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $finalMessage = $this->isDigestRequest($message)
            ? $this->buildDigestMessage($message)
            : $message;

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 7000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        // Extract text from content blocks (skip thinking blocks)
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }

        if ($this->isDigestRequest($message)) {
            try { $this->saveDiscoveriesFromResponse($text); } catch (\Throwable $e) {
                \Log::warning('QuantumAgent: could not save discoveries — ' . $e->getMessage());
            }
        }

        $cleanText = trim(preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $text));
        $this->publishSharedContext($cleanText);
        return $cleanText;
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $isDigest = $this->isDigestRequest($message);

        if ($isDigest) {
            // Send keep-alive heartbeats before/during each slow HTTP fetch
            // to prevent Nginx fastcgi_read_timeout (60s default) from killing the SSE
            if ($heartbeat) $heartbeat('a pesquisar arXiv');
            $arxiv = $this->fetchArxivPapers();
            if ($heartbeat) $heartbeat('a pesquisar PeerJ / CrossRef');
            $peerj = $this->fetchPeerJPapers();
            if ($heartbeat) $heartbeat('a pesquisar patentes EPO');
            $epo   = $this->fetchEpoPatents();
            if ($heartbeat) $heartbeat('a construir análise');
            $finalMessage = $this->buildDigestMessageFromData($message, $arxiv, $peerj, $epo);
        } else {
            $finalMessage = $message;
            $finalMessage = $this->augmentWithWebSearch($finalMessage, $heartbeat);
        }

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        if ($heartbeat) $heartbeat('a activar raciocínio extendido ⚛️');

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 7000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body        = $response->getBody();
        $full        = '';
        $buf         = '';
        $lastBeat    = time();

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
                        // Stream ALL chunks to client without filtering.
                        // The DISCOVERIES_JSON HTML-comment block is stripped
                        // on the frontend in renderMarkdown() so users never see it.
                        // Filtering here caused 30-60s silence that dropped connections.
                        $full .= $text;
                        $onChunk($text);
                    }
                }
            }
            // Heartbeat every 3s to keep mobile connections alive
            if ($heartbeat && (time() - $lastBeat) >= 3) {
                $heartbeat('streaming');
                $lastBeat = time();
            }
        }

        if ($isDigest) {
            try { $this->saveDiscoveriesFromResponse($full); } catch (\Throwable $e) {
                \Log::warning('QuantumAgent stream: could not save discoveries — ' . $e->getMessage());
            }
            $this->saveDigestReport($full);
        }

        // ── Auto-download patent PDFs found in the response ────────────────
        // Each download is an outbound HTTP request — send heartbeats between them
        // so the SSE connection stays alive during the silent period.
        try {
            $pdfService = new PatentPdfService();
            $patents    = $pdfService->extractPatentNumbers($full);
            if ($patents) {
                if ($heartbeat) $heartbeat('a fazer download de ' . count($patents) . ' patente(s) em PDF');

                $ok     = [];
                $failed = [];
                foreach ($patents as $pn) {
                    if ($heartbeat) $heartbeat('PDF ' . $pn);
                    try {
                        $path = $pdfService->download($pn);
                        if ($path) $ok[$pn] = $path; else $failed[] = $pn;
                    } catch (\Throwable $inner) {
                        $failed[] = $pn;
                        \Log::warning("QuantumAgent PDF {$pn}: " . $inner->getMessage());
                    }
                }

                $summary = "\n\n---\n📥 **PDFs descarregados:** " . count($ok) . "/" . count($patents);
                foreach ($ok as $pn => $path) {
                    $summary .= "\n- ✅ [{$pn}](/patents/download/{$pn})";
                }
                foreach ($failed as $pn) {
                    $summary .= "\n- ⚠️ {$pn} — não disponível online";
                }
                $onChunk($summary);
                $full .= $summary;
            }
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent PDF download: ' . $e->getMessage());
        }

        $streamResult = trim(preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $full));
        $this->publishSharedContext($streamResult);
        return $streamResult;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────
    protected function isDigestRequest(string|array $message): bool
    {
        // Extract text from multimodal array (e.g. message with attached file)
        $text = is_array($message)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $message))
            : $message;

        $lower = strtolower($text);
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

    // ─── Save digest output as a Report so BriefingAgent / PatentAgent can read it ──
    protected function saveDigestReport(string $fullText): void
    {
        try {
            $clean = trim(preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $fullText));
            Report::create([
                'type'    => 'quantum',   // PatentAgent reads type='quantum'
                'title'   => 'Prof. Quantum Digest — ' . now()->format('d/m/Y'),
                'content' => $clean,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: could not save digest report — ' . $e->getMessage());
        }
    }

    public function getName(): string { return 'quantum'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
