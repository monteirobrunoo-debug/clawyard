<?php

namespace App\Agents;

use App\Models\Discovery;
use App\Models\Report;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\NsnLookupTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\HandlesAnthropicStream;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\PatentPdfService;

class QuantumAgent implements AgentInterface
{
    use WebSearchTrait;
    use NsnLookupTrait;
    use AnthropicKeyTrait;
    use HandlesAnthropicStream;
    use SharedContextTrait;
    use LogisticsSkillTrait;
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
- Part 4: TechLink Center (techlinkcenter.org — DoD tech transfer dos EUA) — tecnologias dos labs federais disponíveis para licenciamento por sector: naval, defesa, aeroespacial, cyber/QPC, materiais. Para cada tecnologia indica: 🏛️ Lab origem (ARL/NRL/AFRL/NSWC/NASA), 📋 sumário, 💡 oportunidade PartYard, 📥 link para datasheet PDF
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

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
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

    // ─── TechLink Center (DoD tech transfer) ────────────────────────────
    //
    // techlinkcenter.org é o portal oficial de tech-transfer do Department
    // of Defense dos EUA. Lista patentes/tecnologias dos labs federais
    // (Army Research Lab, Naval Research Lab, AFRL, NSWC, NASA, etc.)
    // disponíveis para licenciamento comercial. Sectores cobertos:
    //   • Naval & maritime (NSWC, NRL marine)
    //   • Aeroespacial e defesa (AFRL, NASA spinoff)
    //   • Cibersegurança e quantum
    //   • Materiais e fabricação aditiva
    //   • Sensores, IoT, radar
    //
    // Cada listagem tem título, lab origem, sector, summary, e tipicamente
    // um PDF datasheet downloadable.
    //
    // Estratégia: 5 queries Tavily com site:techlinkcenter.org cobrindo os
    // sectores de interesse PartYard. Resultados agregados num bloco.
    protected function fetchTechLinkPatents(?callable $heartbeat = null): string
    {
        try {
            $searcher = new \App\Services\WebSearchService();
            if (!$searcher->isAvailable()) {
                return '(TechLink — Tavily indisponível, TAVILY_API_KEY em falta)';
            }

            // Sectores alinhados com PartYard / HP-Group business.
            // Cada query inclui site:techlinkcenter.org para limitar ao portal.
            $sectorQueries = [
                'naval'       => 'site:techlinkcenter.org naval maritime ship propulsion sonar',
                'defesa'      => 'site:techlinkcenter.org defense weapon radar countermeasure',
                'aeroespacial'=> 'site:techlinkcenter.org aerospace aviation aircraft UAV propulsion',
                'cyber-qpc'   => 'site:techlinkcenter.org cybersecurity quantum cryptography post-quantum',
                'materiais'   => 'site:techlinkcenter.org materials additive manufacturing composites coating',
            ];

            $blocks = [];
            $totalResults = 0;

            foreach ($sectorQueries as $sector => $query) {
                if ($heartbeat) $heartbeat("a pesquisar TechLink {$sector}");
                try {
                    // 6 results per sector keeps response under ~3k chars
                    $results = $searcher->search($query, 6, 'basic', 90);
                    if ($results && !str_starts_with($results, '(')) {
                        $blocks[] = "### {$sector}\n{$results}";
                        // Rough count by URL occurrences
                        $totalResults += substr_count($results, 'techlinkcenter.org');
                    }
                } catch (\Throwable $e) {
                    \Log::warning("QuantumAgent: TechLink {$sector} search failed — " . $e->getMessage());
                }
            }

            if (empty($blocks)) {
                return '(TechLink: nenhum resultado nos últimos 90 dias para os 5 sectores PartYard)';
            }

            $header = "=== TechLink Center (techlinkcenter.org) — DoD Tech Transfer ===\n"
                    . "Portal oficial de licenciamento de patentes dos labs federais EUA "
                    . "(Army Research Lab, Naval Research Lab, AFRL, NSWC, NASA spinoff).\n"
                    . "Tecnologias disponíveis para licença comercial. {$totalResults} resultados em 5 sectores PartYard.\n";

            return $header . "\n" . implode("\n\n", $blocks)
                 . "\n\n📥 Para descarregar o PDF datasheet de uma tecnologia, abre a URL no browser e procura o link 'Download Datasheet' ou 'Technology Brief PDF'. Alternativamente, cita as URLs específicas e o ClawYard tenta fetch via Tavily extract.";

        } catch (\Throwable $e) {
            \Log::warning('QuantumAgent: TechLink fetch failed — ' . $e->getMessage());
            return '(TechLink: erro ao consultar — ' . $e->getMessage() . ')';
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
        $arxiv    = $this->fetchArxivPapers();
        $peerj    = $this->fetchPeerJPapers();
        $epo      = $this->fetchEpoPatents();
        $techlink = $this->fetchTechLinkPatents();
        return $this->buildDigestMessageFromData($userMessage, $arxiv, $peerj, $epo, $techlink);
    }

    /**
     * 2026-05-29: gera o digest COMPLETO (4 fetches + Anthropic non-stream) e
     * devolve o markdown final. Chamado por RunQuantumDigestJob no queue worker
     * (sem constraint Cloudflare). NÃO chamar do SSE path — demora ~165s.
     *
     * Cache-first async (Bruno fix "tem de ser rectificado"): o digest síncrono
     * cortava no Cloudflare 100s cap. Agora corre em background, cacheia, e o
     * stream() serve do cache instantaneamente.
     */
    public function generateDigestContent(): string
    {
        $arxiv    = $this->fetchArxivPapers();
        $peerj    = $this->fetchPeerJPapers();
        $epo      = $this->fetchEpoPatents();
        $techlink = $this->fetchTechLinkPatents();
        $finalMessage = $this->buildDigestMessageFromData('Digest científico de hoje', $arxiv, $peerj, $epo, $techlink);

        $messages = [['role' => 'user', 'content' => $finalMessage]];

        // Anthropic non-stream (queue worker — não há SSE). Opus + thinking.
        // Opus 16k + thinking demora bem >120s → o timeout=120 default do client
        // cortava o prewarm com "cURL 28 — 0 bytes" e a cache nunca enchia.
        // timeout=300 alinha com o proxy_read_timeout (300s) do nginx do llm-proxy.
        $response = $this->client->post('/v1/messages', [
            'timeout' => 300,
            'headers' => $this->headersForMessage($finalMessage),
            'json'    => [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-8'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 7000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);
        $data = json_decode($response->getBody()->getContents(), true);
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }

        // Persiste discoveries + report (best-effort).
        try { $this->saveDiscoveriesFromResponse($text); } catch (\Throwable $e) {
            \Log::warning('QuantumAgent generateDigestContent: discoveries — ' . $e->getMessage());
        }
        try { $this->saveDigestReport($text); } catch (\Throwable $e) {
            \Log::warning('QuantumAgent generateDigestContent: report — ' . $e->getMessage());
        }

        // Strip DISCOVERIES_JSON HTML comment antes de devolver ao user.
        return trim(preg_replace('/<!--\s*DISCOVERIES_JSON[\s\S]*?DISCOVERIES_JSON\s*-->/m', '', $text));
    }

    protected function buildDigestMessageFromData(
        string|array $userMessage,
        string $arxiv,
        string $peerj,
        string $epo = '',
        string $techlink = ''
    ): string {
        $today = now()->format('Y-m-d');

        $techlinkBlock = $techlink !== ''
            ? "\n## TechLink Center (techlinkcenter.org — DoD tech transfer, 5 sectores PartYard):\n{$techlink}\n"
            : '';

        return <<<MSG
{$userMessage}

--- REAL DATA FETCHED TODAY ({$today}) ---

## arXiv Papers (fetched live from export.arxiv.org — quantum & AI):
{$arxiv}

## PeerJ Computer Science Articles (fetched live via CrossRef — agents & multi-agent systems):
{$peerj}

## EPO Patents (fetched live from European Patent Office OPS API — últimos 3 dias, áreas: naval, defesa, quantum, cyber, AI, IoT, energia, materiais, supply chain):
{$epo}
{$techlinkBlock}
--- END REAL DATA ---

Please analyse ALL the above real data from the FOUR sources (arXiv + PeerJ + EPO patents + TechLink Center).
CRITICAL RULES — READ EVERY RULE CAREFULLY:
- Use ONLY the REAL IDs, titles, authors, applicants and dates from the data above — NEVER invent or fabricate
- For EVERY paper AND patent in your analysis, include the FULL URL (from the data above)
- NEVER write "xxxx", "XXXX", "12345", "xxxxx" or ANY placeholder — use ONLY the real values from the data
- Format each arXiv paper as: **[Title]** (arXiv:[REAL_ID] | 📅 [Published date from data]) — analysis — 🔗 https://arxiv.org/abs/[REAL_ID]
- Format each PeerJ paper as: **[Title]** (DOI:[EXACT_DOI_FROM_EXACT_DOI_FIELD] | 📅 [Date from data]) — analysis — 🔗 [FULL_URL_FROM_FULL_URL_FIELD]
- Format each EPO patent as: **[Title]** (EPO:[EPO_NUMBER_FROM_EPO_NUMBER_FIELD] | Requerente: [APPLICANT]) — analysis — 🔗 [URL from data]
- TechLink: 1 secção por sector (naval, defesa, aeroespacial, cyber-qpc, materiais). Para cada tecnologia, indicar lab origem (Army Research Lab / NSWC / AFRL / NASA spinoff) e link directo. SEMPRE mencionar "📥 Datasheet PDF: <URL>" se o link de descarregar for visível, OU dizer "Datasheet disponível na página — abrir para descarregar".
- The PeerJ EXACT_DOI field above IS the real DOI — copy it character-for-character, do NOT substitute or approximate
- If you cannot find the EXACT_DOI for a PeerJ paper in the data above, DO NOT mention that paper at all
- If you cannot find the EPO_NUMBER for a patent in the data above, DO NOT mention that patent at all
- For the DISCOVERIES_JSON block, include entries from all FOUR sources (source: "arxiv", "peerj", "epo" ou "techlink")
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
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-8'),
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
            // 2026-05-29 CACHE-FIRST ASYNC (Bruno fix "tem de ser rectificado").
            // O digest síncrono (4 fetches + Anthropic = ~165s) cortava no
            // Cloudflare 100s cap → "Erro: network error". Agora:
            //   • Cache HIT  → stream o conteúdo cached instantaneamente.
            //   • Cache MISS → dispatch RunQuantumDigestJob (background) +
            //     mensagem "a gerar, recarrega em 2min". Zero fetch inline.
            // O cron 06:00 pre-warm o cache, por isso quase sempre é HIT.
            $cached = \Cache::get(\App\Jobs\RunQuantumDigestJob::CACHE_KEY);
            if (is_array($cached) && !empty($cached['content'])) {
                $content = (string) $cached['content'];
                $genAt   = $cached['generated_at'] ?? null;
                $stamp   = $genAt
                    ? "\n\n---\n_Digest gerado " . \Illuminate\Support\Carbon::parse($genAt)->diffForHumans() . "._"
                    : '';
                // Stream em chunks de 400 chars para a UI render progressiva.
                foreach (str_split($content . $stamp, 400) as $chunk) {
                    $onChunk($chunk);
                }
                $this->publishSharedContext($content);
                return $content . $stamp;
            }

            // MISS — dispatch job + devolve mensagem amigável (sem fetch inline).
            \App\Jobs\RunQuantumDigestJob::dispatch(auth()->id());
            $waitMsg = "🔬 **Digest científico a ser preparado**\n\n"
                . "Estou a pesquisar **arXiv**, **PeerJ/CrossRef**, **patentes EPO** "
                . "e **TechLink Center** (~2 minutos). Corre em background para não "
                . "cortar a ligação.\n\n"
                . "👉 **Recarrega esta conversa daqui a ~2 minutos** e pede o digest "
                . "de novo — aparece instantaneamente.\n\n"
                . "_Dica: o digest é gerado automaticamente todas as manhãs às 06:00, "
                . "por isso normalmente já está pronto quando chegas._";
            $onChunk($waitMsg);
            return $waitMsg;
        }

        // ── Query NÃO-digest (chat normal do Quantum) ──────────────────────
        {
            $finalMessage = $message;
            // Detect direct TechLink queries even outside digest mode.
            // Extrai texto plano de message (string OR Anthropic multi-block array).
            $msgText = '';
            if (is_string($message)) {
                $msgText = $message;
            } elseif (is_array($message)) {
                foreach ($message as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text') {
                        $msgText .= (string) ($block['text'] ?? '') . ' ';
                    }
                }
            }
            if (preg_match('/techlink|tech\s*link|dod\s*tech.transfer|navsea.tech|nrl.patent|afrl.patent|nasa.spinoff|sbir.*patent/i', $msgText)) {
                if ($heartbeat) $heartbeat('a pesquisar TechLink Center');
                $tl = $this->fetchTechLinkPatents($heartbeat);
                if ($tl) {
                    $finalMessage = (is_string($message) ? $message : trim($msgText))
                                  . "\n\n--- DADOS TECHLINK CENTER ---\n{$tl}\n--- FIM ---\n";
                }
            } else {
                $finalMessage = $this->augmentWithWebSearch($finalMessage, $heartbeat);
                $finalMessage = $this->augmentWithNsnLookup($finalMessage, $heartbeat);
            }
        }

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        if ($heartbeat) $heartbeat('a activar raciocínio extendido ⚛️');

        // 2026-05-28 refactor: stream loop → trait helper.
        // 'thinking' config preserved. Trait skips thinking_delta and
        // handles message_stop + graceful read errors internally.
        $full = $this->streamAnthropicWithRetries(
            config: [
                'model'      => config('services.anthropic.model_opus', 'claude-opus-4-8'),
                'max_tokens' => 16000,
                'thinking'   => ['type' => 'enabled', 'budget_tokens' => 7000],
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
            headers:          $this->headersForMessage($finalMessage),
            onChunk:          $onChunk,
            heartbeat:        $heartbeat,
            heartbeatLabel:   'Quantum a processar',
            retries:          [0, 2, 5],
            emergencyMessage: "⚠️ Quantum temporariamente indisponível. Tenta novamente em 30s.",
            agentLabel:       'QuantumAgent',
        );

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
    public function getModel(): string { return config('services.anthropic.model_opus', 'claude-opus-4-8'); }
}
