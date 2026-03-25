<?php

namespace App\Agents;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Services\PartYardProfileService;
use App\Services\WebSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AcingovAgent — "Dra. Ana Contratos"
 *
 * Pesquisa concursos públicos em 5 portais via Tavily:
 * base.gov.pt, Acingov, Vortal, UNIDO e UNGM (UN Global Marketplace).
 * Classifica oportunidades para o HP-Group / PartYard.
 */
class AcingovAgent implements AgentInterface
{
    use AnthropicKeyTrait;

    protected Client           $client;
    protected Client           $httpClient;
    protected WebSearchService $searcher;

    protected string $systemPrompt = <<<'PROMPT'
Você é a **Dra. Ana Contratos** — Especialista em Contratação Pública para o HP-Group / PartYard.

EMPRESA — CONTEXTO:
[PROFILE_PLACEHOLDER]

A sua missão: analisar concursos públicos de 6 portais (base.gov.pt, Acingov, Vortal, UNIDO, UNGM e **SAM.gov** — contratos federais dos EUA) e identificar oportunidades para o HP-Group e suas subsidiárias (PartYard Marine, PartYard Military, SETQ, IndYard).

CRITÉRIOS DE CLASSIFICAÇÃO:

🟢 ALTA PRIORIDADE — Candidatura imediata:
- Peças sobressalentes navais / marítimas (motores MTU, Caterpillar, MAK, Jenbacher)
- Manutenção de frotas marítimas e equipamentos portuários
- Fornecimento de peças para Marinha Portuguesa / autoridades portuárias
- Contratos de defesa / NATO / equipamentos militares
- Sistemas de propulsão naval (Schottel, SKF SternTube)
- Cibersegurança e IT para organismos públicos (SETQ)

🟡 MÉDIA PRIORIDADE — Avaliar com parceiro:
- Logística e supply chain para infraestruturas portuárias
- Manutenção de geradores e motores de grande porte
- Equipamentos industriais (rolamentos, vedantes, componentes mecânicos)
- Serviços de engenharia e consultoria técnica

🔴 BAIXA RELEVÂNCIA — Monitorizar apenas:
- Obras de construção civil
- Serviços de limpeza e segurança
- IT genérico sem componente naval/defesa

FORMAT DE RESPOSTA:
Para cada concurso encontrado, apresenta:
- 📋 **Entidade**: quem lançou o concurso
- 📌 **Objeto**: o que se pretende contratar
- 💶 **Valor Base**: valor estimado
- ⏰ **Prazo**: data limite de submissão
- 🎯 **Relevância PartYard**: Alta / Média / Baixa + justificação
- 💡 **Ação**: candidatar / avaliar parceria / monitorizar / ignorar
- 🔗 **Link**: URL directo

No final:
- 📊 **Resumo Executivo**: X altas, Y médias, Z baixas
- 🏆 **Top 3 Oportunidades**: as mais urgentes
- ⚡ **Próximos Passos**: acções concretas

REGRAS:
- Usa APENAS dados reais das pesquisas fornecidas — nunca inventes concursos
- Alerta para prazos urgentes (< 7 dias)
- Se não encontrares concursos relevantes, diz claramente e sugere próximas pesquisas
- Responde sempre em Português
PROMPT;

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->httpClient = new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'verify'          => false,
            'headers'         => ['User-Agent' => 'ClawYard/1.0 (research@hp-group.org)'],
        ]);

        $this->searcher = new WebSearchService();
    }

    // ─── Fetch SAM.gov federal contracts — single request, multi-NAICS ────
    protected function fetchSamGov(?callable $heartbeat = null): string
    {
        $apiKey = config('services.samgov.api_key');
        if (!$apiKey) return '(SAM.gov: configura SAM_GOV_API_KEY no .env)';

        if ($heartbeat) $heartbeat('a pesquisar SAM.gov');

        // Keyword groups — each maps to a PartYard business area.
        // SAM.gov supports OR syntax natively. No NAICS filter → Claude classifies.
        $keywordGroups = [
            // PartYard Marine / Military — core
            'marine OR naval OR ship OR vessel OR maritime OR coast guard OR propulsion OR watercraft',
            // Defense & NATO supply
            'defense OR military OR navy OR army OR NATO OR ammunition OR ordnance OR weapon system',
            // Engine & mechanical parts (MTU, Caterpillar, MAK)
            '"spare parts" OR "engine overhaul" OR "diesel engine" OR "propulsion system" OR "ship repair"',
            // IT & cybersecurity (SETQ subsidiary)
            'cybersecurity OR "information technology" OR "network security" OR "software development"',
            // Logistics & supply chain
            '"supply chain" OR "logistics support" OR "parts distribution" OR "warehousing" OR "depot maintenance"',
        ];

        $allOpps  = [];
        $usedDays = 5;
        $seen     = [];

        foreach ([5, 14, 30] as $days) {
            $postedFrom = now()->subDays($days)->format('m/d/Y');
            $postedTo   = now()->format('m/d/Y');

            foreach ($keywordGroups as $keywords) {
                $params = 'api_key=' . $apiKey
                    . '&q='          . urlencode($keywords)
                    . '&postedFrom=' . urlencode($postedFrom)
                    . '&postedTo='   . urlencode($postedTo)
                    . '&limit=20&offset=0';

                try {
                    $resp  = $this->httpClient->get('https://api.sam.gov/opportunities/v2/search?' . $params,
                        ['headers' => ['Accept' => 'application/json'], 'timeout' => 8]);
                    $data  = json_decode($resp->getBody()->getContents(), true);
                    $opps  = $data['opportunitiesData'] ?? [];

                    foreach ($opps as $opp) {
                        $id = $opp['noticeId'] ?? $opp['solicitationNumber'] ?? '';
                        if ($id && isset($seen[$id])) continue;
                        if ($id) $seen[$id] = true;
                        $allOpps[] = $opp;
                    }
                } catch (\Throwable $e) {
                    Log::warning('AcingovAgent SAM.gov [' . substr($keywords, 0, 30) . ']: ' . $e->getMessage());
                }
            }

            if (!empty($allOpps)) {
                $usedDays = $days;
                break; // Got results — no need to widen date range
            }
        }

        if (empty($allOpps)) {
            return '(SAM.gov: sem oportunidades nos últimos 30 dias — verifica SAM_GOV_API_KEY)';
        }

        // Sort: solicitations first (open bids), then award notices (intel)
        usort($allOpps, function($a, $b) {
            $aType = strtolower($a['type'] ?? '');
            $bType = strtolower($b['type'] ?? '');
            $aIsSol = str_contains($aType, 'solicitation') || str_contains($aType, 'pre-sol') ? 0 : 1;
            $bIsSol = str_contains($bType, 'solicitation') || str_contains($bType, 'pre-sol') ? 0 : 1;
            return $aIsSol <=> $bIsSol;
        });

        $lines = ["=== SAM.GOV — US Federal Opportunities (últimos {$usedDays} dias) ===",
                  "Total: " . count($allOpps) . " contratos"];

        foreach ($allOpps as $opp) {
            $id     = $opp['noticeId'] ?? $opp['solicitationNumber'] ?? '';
            $title  = $opp['title']    ?? 'N/A';
            $type   = $opp['type']     ?? 'N/A';
            $naics  = $opp['naicsCode'] ?? ($opp['naicsCodes'][0] ?? 'N/A');
            $posted = $opp['postedDate'] ?? 'N/A';

            // Department from fullParentPathName (dot-separated hierarchy)
            $dept = 'N/A';
            if (!empty($opp['fullParentPathName'])) {
                $parts = explode('.', $opp['fullParentPathName']);
                $dept  = trim($parts[0]); // e.g. "DEPT OF DEFENSE"
                if (count($parts) > 1) $dept .= ' > ' . trim($parts[1]); // e.g. "> DEFENSE LOGISTICS AGENCY"
            }

            // Deadline: responseDeadLine for solicitations, archiveDate for awards
            $deadline = $opp['responseDeadLine'] ?? ($opp['archiveDate'] ?? 'N/A');

            // Award info (for Award Notices — competitive intel)
            $awardee = $opp['award']['awardee']['name'] ?? '';
            $value   = $opp['award']['amount']          ?? '';

            // Direct link to SAM.gov
            $link = $opp['uiLink'] ?? ($id ? "https://sam.gov/workspace/contract/opp/{$id}/view" : '');

            $line = "- [{$type}] {$title} | DEPT: {$dept} | NAICS: {$naics} | POSTED: {$posted} | DEADLINE: {$deadline}";
            if ($value)   $line .= " | VALUE: \${$value}";
            if ($awardee) $line .= " | WINNER: {$awardee}";
            if ($link)    $line .= " | URL: {$link}";

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // ─── Acingov — HTTP login + scrape ────────────────────────────────────
    protected function fetchAcingov(): string
    {
        $username = config('services.acingov.username');
        $password = config('services.acingov.password');

        // Try authenticated first (shows deadlines + more detail)
        if ($username && $password) {
            $result = $this->fetchAcingovAuthenticated($username, $password);
            if (strlen($result) > 100) return $result;
        }

        // Fallback: public zone — no login needed
        return $this->fetchAcingovPublic();
    }

    protected function fetchAcingovAuthenticated(string $username, string $password): string
    {
        $baseUrl = 'https://www.acingov.pt/acingovprod/2/';
        $jar     = new CookieJar();

        $client = new Client([
            'cookies'         => $jar,
            'allow_redirects' => ['max' => 5],
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pt-PT,pt;q=0.9,en;q=0.8',
            ],
            'timeout' => 15,
            'verify'  => false,
        ]);

        try {
            // Step 1: GET homepage to initialise PHP session
            $client->get($baseUrl);

            // Step 2: POST login credentials
            $client->post($baseUrl, [
                'form_params' => ['user' => $username, 'pass' => $password],
            ]);

            // Step 3: Scrape authenticated procedures list with multiple keywords
            $keywords = ['naval', 'marinha', 'maritimo', 'motor', 'peças sobressalentes', 'defesa', 'porto'];
            $seen     = [];
            $lines    = [];

            foreach (array_slice($keywords, 0, 5) as $kw) {
                try {
                    $resp = $client->get($baseUrl . 'procedimentos_fornecedor/procedimentos_fornecedor_c', [
                        'query' => ['object' => $kw],
                    ]);
                    $html = $resp->getBody()->getContents();

                    // If redirected to login, credentials failed
                    if (stripos($html, 'name="user"') !== false && stripos($html, 'name="pass"') !== false) {
                        Log::info('Acingov: credenciais inválidas ou sessão expirou');
                        return '';
                    }

                    $rows  = $this->parseAcingovTable($html, $seen, true);
                    $lines = array_merge($lines, $rows);
                } catch (\Throwable $e) {
                    Log::info("Acingov [auth/{$kw}]: " . $e->getMessage());
                }
            }

            if (empty($lines)) return '';
            return "=== ACINGOV — Concursos (autenticado) ===\n" . implode("\n", $lines);

        } catch (\Throwable $e) {
            Log::info('Acingov [login]: ' . $e->getMessage());
            return '';
        }
    }

    protected function fetchAcingovPublic(): string
    {
        $baseUrl  = 'https://www.acingov.pt/acingovprod/2/zonaPublica/zona_publica_c/indexProcedimentos';
        $keywords = ['naval', 'marinha', 'maritimo', 'motor', 'defesa'];
        $seen     = [];
        $lines    = [];

        foreach ($keywords as $kw) {
            try {
                $resp = $this->httpClient->get($baseUrl, [
                    'query'   => ['procedure_search' => $kw],
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)',
                        'Accept'     => 'text/html',
                    ],
                    'timeout' => 10,
                    'verify'  => false,
                ]);
                $html  = $resp->getBody()->getContents();
                $rows  = $this->parseAcingovTable($html, $seen, false);
                $lines = array_merge($lines, $rows);
            } catch (\Throwable $e) {
                Log::info("Acingov [public/{$kw}]: " . $e->getMessage());
            }
        }

        if (empty($lines)) return '';
        return "=== ACINGOV — Concursos (zona pública) ===\n" . implode("\n", $lines);
    }

    /**
     * Parse the Acingov HTML table and return formatted lines.
     * Columns: Nº Procedimento | Tipo | Objeto de Contrato | Entidade | Estado [| Prazo (auth only)]
     *
     * @param  bool  $authenticated  Whether coming from the private area (has more columns)
     */
    protected function parseAcingovTable(string $html, array &$seen, bool $authenticated = false): array
    {
        $lines = [];

        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // Find all <tr> inside <table>, skip header row(s)
            $rows = $xpath->query('//table//tr[position()>1]');
            if (!$rows || $rows->length === 0) return [];

            foreach ($rows as $row) {
                $cells = $xpath->query('.//td', $row);
                if (!$cells || $cells->length < 3) continue;

                $ref    = trim($cells->item(0)->textContent);
                $tipo   = trim($cells->item(1)->textContent);
                $objeto = trim($cells->item(2)->textContent);
                $entidade = $cells->length > 3 ? trim($cells->item(3)->textContent) : '';
                $estado   = $cells->length > 4 ? trim($cells->item(4)->textContent) : '';
                $prazo    = $cells->length > 5 ? trim($cells->item(5)->textContent) : '';

                // Clean up whitespace/newlines in object text
                $objeto = preg_replace('/\s+/', ' ', $objeto);

                // Attempt to get a link to the procedure
                $anchor = $xpath->query('.//a[@href]', $row)->item(0);
                $link   = '';
                if ($anchor) {
                    $href = $anchor->getAttribute('href');
                    if ($href && !str_starts_with($href, 'javascript') && !str_starts_with($href, '#')) {
                        $link = str_starts_with($href, 'http')
                            ? $href
                            : 'https://www.acingov.pt/acingovprod/2/' . ltrim($href, '/');
                    }
                }

                if (!$ref || strlen($ref) < 3) continue;
                if (isset($seen[$ref])) continue;
                $seen[$ref] = true;

                $line = "- REF: {$ref} | TIPO: {$tipo} | OBJETO: {$objeto}";
                if ($entidade) $line .= " | ENTIDADE: {$entidade}";
                if ($estado)   $line .= " | ESTADO: {$estado}";
                if ($prazo)    $line .= " | PRAZO: {$prazo}";
                if ($link)     $line .= " | URL: {$link}";

                $lines[] = $line;
            }
        } catch (\Throwable $e) {
            Log::info('parseAcingovTable: ' . $e->getMessage());
        }

        return $lines;
    }

    // ─── base.gov.pt — direct public API (awarded contracts) ──────────────
    protected function fetchBaseGovPt(): string
    {
        $dateFrom = now()->subDays(30)->format('d-m-Y'); // 30 days — adjudicados têm latência
        $dateTo   = now()->format('d-m-Y');

        // Multiple keyword passes to find maritime/defense contracts
        $keywords = ['naval', 'marítimo', 'maritimo', 'marinha', 'motor diesel', 'peças sobressalentes', 'defesa', 'porto'];
        $seen     = [];
        $lines    = [];

        foreach ($keywords as $kw) {
            try {
                $resp = $this->httpClient->get(
                    'https://www.base.gov.pt/Base/pt/ResultadoContratosSearch',
                    [
                        'query' => [
                            'tipo'          => 'CO',
                            'tipocontrato'  => '0',
                            'cpv'           => '',
                            'dte'           => $dateTo,
                            'dta'           => $dateFrom,
                            'designacao'    => $kw,
                            'adjudicante'   => '',
                            'adjudicatario' => '',
                            'pageSize'      => '10',
                            'page'          => '1',
                        ],
                        'headers' => [
                            'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                            'X-Requested-With' => 'XMLHttpRequest',
                            'Referer'          => 'https://www.base.gov.pt/Base/pt/Pesquisa',
                            'User-Agent'       => 'Mozilla/5.0 (compatible; HP-Group/1.0)',
                        ],
                        'timeout' => 8,
                    ]
                );

                $body = $resp->getBody()->getContents();
                $data = json_decode($body, true);
                $contracts = $data['items'] ?? $data['list'] ?? $data ?? [];

                if (!is_array($contracts)) continue;

                foreach ($contracts as $c) {
                    if (!is_array($c)) continue;
                    $id = $c['id'] ?? $c['ncontrato'] ?? '';
                    if ($id && isset($seen[$id])) continue;
                    if ($id) $seen[$id] = true;

                    $obj  = $c['objectoContrato']       ?? ($c['designacao']            ?? 'N/A');
                    $ent  = $c['adjudicante']            ?? ($c['entidade']              ?? 'N/A');
                    $win  = $c['adjudicatario']          ?? '';
                    $val  = $c['precoContratual']        ?? ($c['valor']                 ?? '');
                    $date = $c['dataCelebracaoContrato'] ?? ($c['dataPublicacao']         ?? 'N/A');
                    $link = $id ? "https://www.base.gov.pt/Base/pt/Detalhe/Contratos/{$id}" : '';

                    $line = "- OBJETO: {$obj} | ENTIDADE: {$ent}";
                    if ($win)  $line .= " | ADJUDICATÁRIO: {$win}";
                    if ($val)  $line .= " | VALOR: €{$val}";
                    if ($date) $line .= " | DATA: {$date}";
                    if ($link) $line .= " | URL: {$link}";
                    $lines[] = $line;
                }
            } catch (\Throwable $e) {
                Log::info("base.gov.pt [{$kw}]: " . $e->getMessage());
            }
        }

        if (empty($lines)) {
            return "(base.gov.pt: sem contratos adjudicados nos últimos 30 dias para os critérios navais/defesa)";
        }

        return "=== BASE.GOV.PT — Contratos Adjudicados (últimos 30 dias) ===\n" . implode("\n", $lines);
    }

    // ─── UNGM — direct public API ──────────────────────────────────────────
    protected function fetchUNGM(): string
    {
        $dateFrom = now()->subDays(14)->format('Y-m-d');
        $dateTo   = now()->format('Y-m-d');

        $searchGroups = [
            'marine naval vessel ship spare parts',
            'maritime defense military procurement',
            'diesel engine propulsion mechanical',
        ];

        $seen  = [];
        $lines = [];

        foreach ($searchGroups as $keywords) {
            try {
                $resp = $this->httpClient->get(
                    'https://www.ungm.org/Public/Notice',
                    [
                        'query' => [
                            'noticeType'    => '0',     // 0 = all
                            'status'        => '0',     // 0 = active
                            'keyword'       => $keywords,
                            'pageIndex'     => '0',
                            'pageSize'      => '10',
                            'publishing_start' => $dateFrom,
                            'publishing_end'   => $dateTo,
                        ],
                        'headers' => [
                            'Accept'     => 'application/json, text/plain, */*',
                            'User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)',
                            'Referer'    => 'https://www.ungm.org/Public/Notice',
                        ],
                        'timeout' => 10,
                    ]
                );

                $body    = $resp->getBody()->getContents();
                $data    = json_decode($body, true);
                $notices = $data['notices'] ?? $data['items'] ?? $data ?? [];

                if (!is_array($notices)) continue;

                foreach ($notices as $n) {
                    if (!is_array($n)) continue;
                    $id = $n['noticeId'] ?? $n['id'] ?? '';
                    if ($id && isset($seen[$id])) continue;
                    if ($id) $seen[$id] = true;

                    $title    = $n['title']           ?? ($n['noticeTitle']    ?? 'N/A');
                    $org      = $n['organization']    ?? ($n['organizationId'] ?? 'N/A');
                    $deadline = $n['deadline']        ?? ($n['deadlineDate']   ?? 'N/A');
                    $ref      = $n['reference']       ?? ($n['solNo']          ?? '');
                    $link     = $id ? "https://www.ungm.org/Public/Notice/{$id}" : '';

                    $line = "- TITLE: {$title} | ORG: {$org} | DEADLINE: {$deadline}";
                    if ($ref)  $line .= " | REF: {$ref}";
                    if ($link) $line .= " | URL: {$link}";
                    $lines[] = $line;
                }
            } catch (\Throwable $e) {
                Log::info("UNGM [{$keywords}]: " . $e->getMessage());
            }
        }

        if (empty($lines)) return '';

        return "=== UNGM — UN Global Marketplace Tenders (últimos 14 dias) ===\n" . implode("\n", $lines);
    }

    // ─── Fetch contracts via Tavily — EU/UN portals ───────────────────────
    protected function fetchContracts(?callable $heartbeat = null): string
    {
        $sections = [];

        // 1. SAM.gov — direct API (most reliable)
        $sam = $this->fetchSamGov($heartbeat);
        if ($sam && !str_starts_with($sam, '(SAM.gov:')) {
            $sections[] = $sam;
        }

        // 2. EU/UN portals via Tavily — 2 queries (fast)
        if ($this->searcher->isAvailable()) {
            if ($heartbeat) $heartbeat('a pesquisar portais EU/UN');
            $tavily = [
                'EU/PT' => 'base.gov.pt OR vortal.biz concurso naval defesa motor maritimo 2026',
                'UN'    => 'ungm.org OR unido.org tender maritime naval defense 2026',
            ];
            foreach ($tavily as $label => $query) {
                try {
                    $result = $this->searcher->search($query, 4, 'basic');
                    if ($result && strlen($result) > 50) {
                        $sections[] = "=== {$label} ===\n" . $result;
                    }
                } catch (\Throwable $e) {
                    Log::info("AcingovAgent [{$label}]: " . $e->getMessage());
                }
            }
        }

        if (empty($sections)) {
            return '(Sem resultados nos portais. Verifica as API keys no .env)';
        }

        $date = now()->format('Y-m-d H:i');
        return "=== CONTRATOS PÚBLICOS ÚLTIMOS 5 DIAS — {$date} ===\n"
            . "PORTAIS: SAM.gov | base.gov.pt | Vortal | UNIDO | UNGM\n\n"
            . implode("\n\n", $sections);
    }

    // ─── Build message ─────────────────────────────────────────────────────
    protected function buildContractsMessage(string|array $userMessage, ?callable $heartbeat = null): string
    {
        $contracts = $this->fetchContracts($heartbeat);
        $today     = now()->format('Y-m-d');

        $user = is_array($userMessage)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $userMessage))
            : $userMessage;

        return <<<MSG
{$user}

--- DADOS DE CONTRATOS PÚBLICOS ({$today}) ---

{$contracts}

--- END DATA ---

Analisa os concursos acima e classifica cada um por relevância para HP-Group / PartYard.
- Usa APENAS dados reais das pesquisas — não inventes concursos
- Para cada concurso: entidade, objeto, valor, prazo, relevância, ação
- SAM.gov = contratos federais americanos (DoD, Navy, Coast Guard) — alta prioridade para PartYard Military
- Foca em: peças navais, motores, defesa, portos, IT/cibersegurança, NATO
MSG;
    }

    // ─── chat() ────────────────────────────────────────────────────────────
    public function chat(string|array $message, array $history = []): string
    {
        $finalMessage = $this->buildContractsMessage($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    // ─── streamClaudeOnce() — single Claude streaming call ─────────────────
    protected function streamClaudeOnce(string $prompt, array $history, callable $onChunk, ?callable $heartbeat = null, string $beatLabel = 'a analisar'): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $prompt],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($prompt),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body     = $response->getBody();
        $full     = '';
        $buf      = '';
        $lastBeat = time();

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
                        $full .= $text;
                        $onChunk($text);
                    }
                }
            }
            if ($heartbeat && (time() - $lastBeat) >= 8) {
                $heartbeat($beatLabel);
                $lastBeat = time();
            }
        }

        return $full;
    }

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        // Flush & destroy all PHP output buffers so every echo() reaches the
        // browser immediately without waiting for the 4096-byte buffer to fill
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $today = now()->format('Y-m-d H:i');
        $full  = '';

        // $emit sends text AND forces a buffer flush via heartbeat comment
        $emit = function (string $text) use (&$full, $onChunk, &$heartbeat) {
            $full .= $text;
            $onChunk($text);
            // Force flush: heartbeat is a proven SSE flush mechanism
            if ($heartbeat) $heartbeat('');
        };

        $userText = is_array($message)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $message))
            : $message;

        $dateFrom = now()->subDays(5)->format('d/m/Y');
        $dateTo   = now()->format('d/m/Y');

        // ── Header ───────────────────────────────────────────────────────────
        $emit("## 📋 Dra. Ana Contratos — Relatório {$today}\n");
        $emit("Período: **{$dateFrom}** → **{$dateTo}** · Portais: Acingov · Vortal · base.gov.pt · UNGM · SAM.gov\n\n");

        // ── Recolha silenciosa de todos os portais ────────────────────────────
        // Mostra só o progresso; os dados brutos NÃO são emitidos —
        // serão processados em conjunto pela Dra. Ana no final.

        $emit("⏳ A recolher dados dos portais...\n\n");

        // Tavily `days` filter — últimos 7 dias (mais tolerante do que 5 para apanhar mais resultados)
        $tavilyDays = 7;

        // Portal 1: Acingov — HTTP direto (login autenticado + fallback zona pública)
        $emit("  `1/5` 🇵🇹 Acingov...\n");
        if ($heartbeat) $heartbeat('a pesquisar Acingov');
        $acingovData = $this->fetchAcingov();

        // Portal 2: Vortal / TED (European Tenders)
        // Vortal é privado. Usamos TED (Tenders Electronic Daily, EU) que é público.
        $emit("  `2/5` 🇵🇹 Vortal / TED Europa...\n");
        if ($heartbeat) $heartbeat('a pesquisar Vortal / TED Europa');
        $vortalData = '';
        if ($this->searcher->isAvailable()) {
            try {
                $vortalData = $this->searcher->search(
                    'ted.europa.eu OR vortal tender Portugal 2026 naval maritime defense equipment procurement',
                    8, 'basic', $tavilyDays
                );
                if (strlen($vortalData) < 80) {
                    $vortalData = $this->searcher->search(
                        'TED tenders Portugal 2026 naval defesa equipamento maritime',
                        8, 'basic', $tavilyDays
                    );
                }
                if (strlen($vortalData) < 80) {
                    $vortalData = $this->searcher->search(
                        'European tender 2026 maritime naval spare parts Portugal defense procurement',
                        8, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [Vortal/TED]: ' . $e->getMessage());
            }
        }

        // Portal 3: UNGM — direct public API
        $emit("  `3/5` 🌍 UNGM...\n");
        if ($heartbeat) $heartbeat('a pesquisar UNGM');
        $ungmData = $this->fetchUNGM();
        // Tavily fallback if direct API returns nothing
        if (strlen($ungmData) < 80 && $this->searcher->isAvailable()) {
            try {
                $ungmData = $this->searcher->search(
                    'site:ungm.org tender maritime naval spare parts defense procurement 2026',
                    5, 'basic', $tavilyDays
                );
                if (strlen($ungmData) < 80) {
                    $ungmData = $this->searcher->search(
                        'UNGM "United Nations Global Marketplace" tender maritime naval 2026 deadline',
                        5, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [UNGM Tavily fallback]: ' . $e->getMessage());
            }
        }

        // Portal 4: base.gov.pt — direct public API (no auth needed)
        $emit("  `4/5` 🇵🇹 base.gov.pt (adjudicados)...\n");
        if ($heartbeat) $heartbeat('a pesquisar base.gov.pt');
        $baseGovData = $this->fetchBaseGovPt();

        // Portal 5: SAM.gov
        $emit("  `5/5` 🇺🇸 SAM.gov...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar SAM.gov');
        $samData = $this->fetchSamGov();

        $emit("✅ **Recolha concluída. A classificar por área...**\n\n");

        // ── Análise Claude — classificação por área ───────────────────────────
        $emit("---\n### 🧠 Dra. Ana Contratos — Classificação por Área\n\n");
        if ($heartbeat) $heartbeat('Dra. Ana a classificar por área');

        $allData = implode("\n\n", array_filter(
            [
                '[ACINGOV/DRE.pt - Concursos PT]' . $acingovData,
                '[VORTAL/TED Europa - Concursos EU]' . $vortalData,
                '[UNGM - UN Tenders]' . $ungmData,
                '[BASE.GOV.PT - Contratos Adjudicados]' . $baseGovData,
                '[SAM.gov]' . $samData,
            ],
            fn($v) => strlen($v) > 30
        ));

        $analysisPrompt = <<<MSG
{$userText}

Período: {$dateFrom} a {$dateTo} (últimos 5 dias).
Portais pesquisados: Acingov · Vortal · base.gov.pt · UNGM · SAM.gov

Analisa TODOS os contratos/concursos abaixo e apresenta os resultados CLASSIFICADOS POR ÁREA DE NEGÓCIO (não por portal).

== ESTRUTURA DO RELATÓRIO ==

Para cada ÁREA, lista os contratos relevantes encontrados:

### ⚓ Naval & Marítimo
### 🛡️ Defesa & Militar
### 🔧 Manutenção & Peças Industriais
### 💻 IT & Cibersegurança
### ⚡ Energia & Ambiente
### 📦 Supply Chain & Logística
### 🏗️ Obras & Infraestrutura
### 🌐 Outros

Para cada contrato dentro de cada área:
📋 **[Título]** | 🏛️ Entidade | 💶 Valor | ⏰ Prazo | 🌍 Portal: [Acingov/Vortal/UNGM/base.gov.pt/SAM.gov] | 🎯 [🟢Alta/🟡Média/🔴Baixa] | 🔗 Link
(Para base.gov.pt: indicar também 🏆 Empresa adjudicatária)

Depois do relatório por área:
---
### 📊 Resumo Executivo
- Total: X contratos | 🟢 N altas · 🟡 N médias · 🔴 N baixas
- Por portal: Acingov(N) · Vortal(N) · UNGM(N) · base.gov.pt(N) · SAM.gov(N)

### 🏆 Top 5 Oportunidades Prioritárias
(prazo mais curto + valor mais alto + maior relevância PartYard)

### ⚡ Próximos Passos
(acções concretas para a equipa PartYard esta semana)

REGRAS:
- INCLUI contratos/concursos encontrados (base.gov.pt tem janela 30 dias, os restantes 5-14 dias)
- Se não houver contratos numa área, omite essa secção
- Usa SEMPRE os links reais dos dados fornecidos
- SAM.gov = alta prioridade PartYard Military (DoD, Navy, Coast Guard)
- base.gov.pt = inteligência competitiva — quem ganhou, a que preço
- DRE.pt/Acingov = concursos abertos em Portugal
- TED/Vortal = concursos abertos na Europa (obrigatório publicar acima de €140k)
- UNGM = tenders ONU/organizações internacionais

--- DADOS DOS 5 PORTAIS ---
{$allData}
--- FIM ---
MSG;

        $analysis = $this->streamClaudeOnce($analysisPrompt, $history, $onChunk, $heartbeat, 'Dra. Ana a analisar');
        $full .= $analysis;

        return $full;
    }

    public function getName(): string  { return 'acingov'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
