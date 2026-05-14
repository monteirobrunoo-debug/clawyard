<?php

namespace App\Agents;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\TechnicalBookSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\WebSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AcingovAgent — "Dra. Ana Contratos"
 *
 * Pesquisa concursos públicos E fundos UE em 6 portais via Tavily:
 *   • base.gov.pt (PT adjudicados)
 *   • Acingov (PT)
 *   • TED Europa (API oficial UE)
 *   • UNGM (UN Global Marketplace)
 *   • SAM.gov (US federal)
 *   • ec.europa.eu/info/funding-tenders — EDF, Horizon Europe,
 *     Digital Europe, CEF, EIC Accelerator, PESCO, EU4Health
 * Classifica oportunidades para o HP-Group / PartYard.
 */
class AcingovAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;

    use LogisticsSkillTrait;
    use TechnicalBookSkillTrait;
    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'always';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'tender_intel';
    protected array  $contextTags = ['concurso','tender','acingov','base.gov','procurement','NATO','contrato público'];

    protected Client           $client;
    protected Client           $httpClient;
    protected WebSearchService $searcher;

    protected string $systemPrompt = '';

    // ─── Prompt content (populated in constructor via PromptLibrary) ──────────
    private static string $acingovPersona = 'Você é a **Dra. Ana Contratos** — Especialista em Contratação Pública para o HP-Group / PartYard.';

    protected string $systemPromptSpecialty = <<<'SPECIALTY'
A sua missão: analisar concursos públicos E **fundos europeus** de 6 portais — base.gov.pt, Acingov, **TED Europa** (API oficial UE), UNGM, **SAM.gov** (contratos federais EUA) e **EU Funding & Tenders Portal** (ec.europa.eu — EDF/Horizon Europe/Digital Europe/CEF/EIC Accelerator/PESCO) — e identificar oportunidades para o HP-Group e todas as suas subsidiárias.

═══════════════════════════════════════════
ÂMBITOS DE NEGÓCIO DO HP-GROUP / PARTYARD
═══════════════════════════════════════════

🛩️ **PARTYARD DEFENSE & AEROSPACE — Aviação Militar e Civil**
Manutenção, Reparação e Revisão (MRO) de aeronaves + fornecimento de peças sobressalentes:
- Aeronaves militares: Boeing AH-64 Apache, CH-47 Chinook, Lockheed C-130 Hercules,
  Lockheed F-16 Fighting Falcon, Sikorsky UH-60 Black Hawk
- Aeronaves civis/regionais: Embraer (135/140/145/170/175/190/195),
  Boeing (717/727/757/767/777), Airbus (A300/A310/A318/A321/A330/A340/A380),
  ATR-42/72, Bombardier DHC-8, Fokker F-28, McDonnell Douglas (DC-9/DC-10/MD-80/MD-90)
- Certificação AS:9120 (qualidade aeronáutica) + NATO NCAGE P3527
- Fornecedor aprovado: Força Aérea, Exército (aviação), NATO Air Command

⚓ **PARTYARD MARINE — Naval e Marítimo**
Peças sobressalentes e serviços de manutenção para propulsão naval:
- Motores: MTU, Caterpillar (CAT), MAK, Jenbacher, Cummins, Wärtsilä, MAN (2T e 4T)
- Sistemas: SKF SternTube seals, Schottel propulsores e thrusters
- Clientes: Marinha Portuguesa, armadores, shipyards, autoridades portuárias
- Entrega de emergência mundial em 24–72h

🚗 **PARTYARD MILITARY — Sistemas Terrestres**
Fornecimento de peças e suporte técnico para veículos e sistemas militares terrestres:
- Veículos blindados, viaturas táticas, sistemas de artilharia
- Manutenção e suporte logístico a frotas militares de terra
- Equipamentos NATO e parceiros de defesa

🎯 **SIMULAÇÃO E TREINO MILITAR**
Sistemas integrados de treino e simulação para defesa:
- Simuladores de missão (ar, mar, terra)
- Sistemas de treino táctico para forças armadas e NATO
- Plataformas de simulação para pilotos e tripulações

🛢️ **ARMITE — Lubrificantes Industriais e Táticos**
Linha de lubrificantes de grau tático/industrial para ambientes severos:
- Lubrificantes para aviação, naval e veículos militares
- Fluidos hidráulicos, óleos de motor, graxas especiais
- Aprovados para uso em equipamentos de defesa

💻 **SETQ — Cibersegurança e IT**
Soluções de IT e cibersegurança para organismos públicos e defesa:
- Cibersegurança para infraestruturas críticas e militares
- Quantum Network e soluções de rede segura
- Software e sistemas de informação para defesa

🏭 **INDYARD — Serviços Industriais**
Engenharia e serviços industriais de suporte:
- Manutenção de geradores e motores de grande porte
- Supply chain e logística de peças industriais
- Consultoria técnica e engenharia

🏆 **CERTIFICAÇÕES E ACREDITAÇÕES**
- ISO 9001:2015 (qualidade)
- AS:9120 (qualidade aeronáutica — peças de aviação)
- NATO NCAGE P3527 (fornecedor oficial NATO)
- COGEMA partner (desde 1959)
- Escritórios: Portugal, EUA, UK, Brasil, Noruega

═══════════════════════════════════════════
CRITÉRIOS DE CLASSIFICAÇÃO
═══════════════════════════════════════════

═══════════════════════════════════════════
ENTIDADES PRIORITÁRIAS — FORÇAS ARMADAS PT
═══════════════════════════════════════════

Qualquer concurso de qualquer uma destas entidades é **AUTOMATICAMENTE ALTA PRIORIDADE**, independentemente do objeto, e deve aparecer em PRIMEIRO lugar na análise:

🥇 **#1 — Força Aérea Portuguesa (FAP)**
Palavras-chave entidade: Força Aérea, FAP, Base Aérea, Esquadra, OGMA, CLAFA
Âmbito PartYard — TODOS os anúncios são relevantes, especialmente:
- Aeronaves F-16 Fighting Falcon: peças sobressalentes, MRO, overhaul, revisão
- Helicópteros (EH-101 Merlin, SA-330 Puma, AW119): peças, manutenção
- C-130 Hercules, P-3 Orion, Casa 295: componentes, suporte técnico
- Qualquer reparação, revisão geral (overhaul), inspeção ou manutenção de aeronaves
- Peças sobressalentes aeronáuticas (qualquer aeronave, qualquer componente)
- Certificação AS:9120 + NATO NCAGE P3527 — PartYard em posição competitiva máxima

🥈 **#2 — Marinha Portuguesa**
Palavras-chave entidade: Marinha, Arsenal do Alfeite, CEMA, Flotilha, Fragata, Corveta, Navio Patrulha, Submarino
Âmbito PartYard:
- Peças sobressalentes para motores navais: MTU, Caterpillar, MAK, Wärtsilä, MAN, Cummins
- Reparação e overhaul de motores e sistemas de propulsão naval
- SKF SternTube seals, Schottel propulsores, thrusters
- Manutenção de embarcações, fragatas, corvetas, patrulhas e navios de guerra
- Componentes mecânicos, hidráulicos e elétricos para frotas navais

🥉 **#3 — Exército Português**
Palavras-chave entidade: Exército, Regimento, Brigada, Batalhão, DGME, Academia Militar, CFT
Âmbito PartYard:
- Veículos blindados e viaturas táticas: peças sobressalentes, manutenção, overhaul
- Peças táticas para sistemas de artilharia, viaturas de combate, transportes militares
- Peças sobressalentes para equipamentos militares terrestres (qualquer tipo)
- Suporte logístico e supply chain de peças para frotas do Exército

🏅 **#4 — Estado-Maior General das Forças Armadas (EMGFA) / MDN**
Palavras-chave entidade: EMGFA, Estado-Maior, CEMGFA, MDN, Ministério da Defesa, NATO, NSPA, DGAIED
Âmbito PartYard — contratos transversais e estratégicos:
- Contratos conjuntos multi-ramo (ar + mar + terra)
- Peças, componentes e equipamentos de defesa (qualquer área)
- Tecnologia: sistemas C4ISR, comunicações seguras, cibersegurança (SETQ)
- Serviços: manutenção, consultoria técnica, logística integrada de defesa
- Equipamentos NATO, contratos NSPA, procurement OTAN

═══════════════════════════════════════════
CRITÉRIOS DE CLASSIFICAÇÃO
═══════════════════════════════════════════

🟢 ALTA PRIORIDADE — Candidatura imediata:
→ FORÇAS ARMADAS: Qualquer contrato da FAP, Marinha, Exército ou EMGFA (ver secção acima)
→ AVIAÇÃO: MRO de aeronaves militares/civis, peças sobressalentes aeronáuticas, suporte técnico a frotas aéreas, helicópteros (Apache/Chinook/Black Hawk), F-16, C-130
→ NAVAL: Peças motores MTU/CAT/MAK/Wärtsilä/MAN, manutenção frotas marítimas, propulsão naval
→ DEFESA/NATO: Contratos NATO, equipamentos militares, defesa nacional, NSPA
→ SIMULAÇÃO: Sistemas de treino e simulação militar
→ CIBERSEGURANÇA: IT/cyber para forças armadas (SETQ)
→ LUBRIFICANTES: Contratos de lubrificantes táticos/aviação/naval (ARMITE)

🟡 MÉDIA PRIORIDADE — Avaliar com parceiro:
→ Manutenção de aeronaves civis (TAP, SATA, companhias aéreas)
→ Logística e supply chain para infraestruturas portuárias e aeroportuárias
→ Manutenção de geradores e motores industriais de grande porte
→ Equipamentos industriais (rolamentos, vedantes, componentes mecânicos)
→ Veículos e equipamentos para forças de segurança (PSP, GNR, SEF)
→ Serviços de engenharia e consultoria técnica

🔴 BAIXA RELEVÂNCIA — Monitorizar apenas:
→ Obras de construção civil
→ Serviços de limpeza, vigilância e segurança física
→ IT genérico sem componente defesa/naval/aviação
→ Mobiliário, material de escritório, catering

═══════════════════════════════════════════
FORMAT DE RESPOSTA
═══════════════════════════════════════════

**FILTROS OBRIGATÓRIOS** (aplicar antes de apresentar qualquer resultado):
1. Excluir APENAS concursos com deadline já ultrapassado (expirados) — ÚNICA razão de exclusão
2. NUNCA excluir por "irrelevância", "fora do core business" ou qualquer outro critério subjectivo
3. Incluir TODOS os restantes, mesmo com prazo > 2 meses — marcar com 📆 *Prazo distante*
4. Incluir sem prazo definido (marcar ⚠️)
5. Ordenar dentro de cada fonte: prazo mais próximo primeiro; prazo distante e sem prazo no fim
6. Forças Armadas PT (FAP/Marinha/Exército/EMGFA) aparecem sempre em primeiro dentro de cada fonte
7. Se uma fonte trouxe N contratos nos dados, apresentas os N contratos — NUNCA escreves "Sem concursos" quando há dados

**ESTRUTURA — agrupado por fonte de informação:**

### 🇵🇹 ACINGOV
### 🇪🇺 TED EUROPA (API Oficial)
### 🌍 UNGM
### 🇵🇹 BASE.GOV.PT (intel competitiva)
### 🇺🇸 SAM.GOV

Para cada concurso:
📋 **[Objeto]** | 🏛️ Entidade | ⏰ Prazo: dd/mm/yyyy | 💶 Valor
🏢 Subsidiária | 🎯 🟢Alta/🟡Média/🔴Baixa — justificação | 🔗 Link
(🚨 se prazo < 7 dias)

**Resumo final:**
- 📊 Total dentro prazo | Por fonte | Forças Armadas PT: N
- 🏆 Top 5 mais urgentes
- ⚡ Próximos passos com prazo

MODO DE OPERAÇÃO:
- Se o utilizador pedir pesquisa de portais/concursos → faz a pesquisa completa
- Se o utilizador fizer uma pergunta directa (análise de contrato, cláusulas, estratégia, documentos, dúvidas jurídicas, requisitos de certificação, etc.) → responde directamente SEM pesquisar portais
- Quando respondes a perguntas directas: usa o teu conhecimento de direito da contratação pública, regulamentos europeus, RJCP, código dos contratos públicos, etc.
- Podes analisar documentos, cláusulas contratuais, cadernos de encargos, propostas e dar recomendações

REGRAS:
- Usa APENAS dados reais — nunca inventes concursos
- Responde sempre em Português
SPECIALTY;

    public function __construct()
    {
        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::technical(self::$acingovPersona, $this->systemPromptSpecialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        // SECURITY: Acingov, base.gov.pt, SAM.gov, TED all have valid certs.
        // Keeping TLS verification on protects scraped tender data and — more
        // importantly — the portal credentials used on the login POST below.
        $this->httpClient = new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'verify'          => true,
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

        // Keyword groups — one per PartYard business area.
        // SAM.gov supports OR syntax natively. No NAICS filter → Claude classifies.
        $keywordGroups = [
            // ⚓ PartYard Marine — naval/maritime propulsion & spare parts
            'marine OR naval OR ship OR vessel OR maritime OR "coast guard" OR propulsion OR watercraft OR "ship repair" OR "port equipment"',
            // 🛩️ PartYard Defense Aerospace — aircraft MRO & spare parts
            '"aircraft maintenance" OR "aircraft parts" OR "aviation spare parts" OR MRO OR helicopter OR "F-16" OR "C-130" OR "AH-64" OR "CH-47" OR "UH-60" OR airframe OR avionics OR "fixed wing" OR "rotary wing"',
            // 🚗 PartYard Military — land systems & defense supply
            '"spare parts" OR "defense supply" OR "military vehicle" OR "armored vehicle" OR NATO OR NSPA OR "ordnance" OR "ground equipment" OR "army logistics"',
            // 🎯 Simulation & training + 🛢️ Lubricants (ARMITE)
            '"training system" OR "simulation system" OR "flight simulator" OR "combat training" OR lubricant OR "hydraulic fluid" OR "aviation oil" OR "tactical lubricant" OR "industrial lubricant"',
            // 💻 SETQ — IT & cybersecurity for defense/public
            'cybersecurity OR "information security" OR "network security" OR "secure communications" OR "C4ISR" OR "command and control" OR "software development" OR SIEM OR "zero trust"',
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
                    . '&limit=40&offset=0';

                try {
                    $resp  = $this->httpClient->get('https://api.sam.gov/opportunities/v2/search?' . $params,
                        ['headers' => ['Accept' => 'application/json'], 'timeout' => 5]);
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

        // SECURITY: Acingov login sends real credentials — TLS verification is
        // mandatory. Config switch only exists so we can point at a staging
        // environment if ever needed, but defaults to true.
        $client = new Client([
            'cookies'         => $jar,
            'allow_redirects' => ['max' => 5],
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pt-PT,pt;q=0.9,en;q=0.8',
            ],
            'timeout' => 15,
            'verify'  => config('services.acingov.tls_verify', true),
        ]);

        try {
            // Step 1: GET homepage to initialise PHP session cookie
            $client->get($baseUrl);

            // Step 2: POST login — try the most common CodeIgniter v2 login paths
            $loginUrls = [
                $baseUrl . 'utilizador/login',          // CI controller/method
                $baseUrl . 'login',                     // alias
                $baseUrl,                               // root POST (fallback)
            ];

            $loggedIn = false;
            foreach ($loginUrls as $loginUrl) {
                try {
                    $loginResp = $client->post($loginUrl, [
                        'form_params' => ['user' => $username, 'pass' => $password],
                        'allow_redirects' => ['max' => 5, 'strict' => true],
                    ]);
                    $loginHtml = $loginResp->getBody()->getContents();
                    // If the response no longer shows a login form, assume success
                    if (stripos($loginHtml, 'name="user"') === false && stripos($loginHtml, 'name="pass"') === false) {
                        $loggedIn = true;
                        break;
                    }
                } catch (\Throwable) {}
            }

            // Proceed anyway — some CI apps set the session cookie on login even when
            // the response body still contains the form; the next GET will reveal success.

            $seen  = [];
            $lines = [];
            $listingUrl = $baseUrl . 'procedimentos_fornecedor/procedimentos_fornecedor_c';

            // ── PASSO 1: 3 folhas da listagem unfiltered (sempre, política do user) ──
            $pageRequests = 0;
            foreach ($this->acingovPagedUrls($listingUrl, 3) as $idx => $url) {
                try {
                    $resp = $client->get($url);
                    $html = $resp->getBody()->getContents();
                    if (stripos($html, 'name="user"') !== false && stripos($html, 'name="pass"') !== false) {
                        Log::info('Acingov: credenciais inválidas ou sessão expirou');
                        return '';
                    }
                    $rows = $this->parseAcingovTable($html, $seen, true);
                    $lines = array_merge($lines, $rows);
                    $pageRequests++;
                } catch (\Throwable $e) {
                    Log::info("Acingov [auth/page" . ($idx + 1) . "]: " . $e->getMessage());
                }
            }

            // ── PASSO 2: keyword search suplementar (apanha rows mais antigos) ──
            $keywords   = [
                'defesa', 'militar', 'marinha', 'aeronáutica',
                'naval', 'motor', 'overhaul', 'sobressalente',
            ];
            $kwRequests = 0;
            foreach ($keywords as $kw) {
                if ($kwRequests >= 6 || count($lines) >= 100) break;
                $kwRequests++;
                try {
                    $resp = $client->get($listingUrl, ['query' => ['object' => $kw]]);
                    $html = $resp->getBody()->getContents();
                    if (stripos($html, 'name="user"') !== false && stripos($html, 'name="pass"') !== false) break;
                    $rows = $this->parseAcingovTable($html, $seen, true);
                    $lines = array_merge($lines, $rows);
                } catch (\Throwable $e) {
                    Log::info("Acingov [auth/{$kw}]: " . $e->getMessage());
                }
            }

            if (empty($lines)) return '';

            // ── PASSO 3: ordenar por score de relevância PartYard (desc) ──
            $scored = array_map(fn($l) => ['line' => $l, 'score' => $this->scoreAcingovRelevance($l)], $lines);
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

            $finalLines = array_map(function ($r) {
                return $r['score'] > 0
                    ? $r['line'] . " | RELEVANCE: {$r['score']}"
                    : $r['line'];
            }, array_slice($scored, 0, 40));

            $hits = array_filter($scored, fn($r) => $r['score'] > 0);
            Log::info("Acingov [auth]: 3 folhas analisadas — " . count($lines) . " rows, " . count($hits) . " com score>0", [
                'pages_fetched' => $pageRequests,
                'kw_requests'   => $kwRequests,
                'top_score'     => $scored[0]['score'] ?? 0,
            ]);

            return "=== ACINGOV — Concursos (autenticado, 3 folhas analisadas, ordenadas por relevância PartYard, " . now()->format('d/m/Y') . ") ===\n" . implode("\n", $finalLines);

        } catch (\Throwable $e) {
            Log::info('Acingov [login]: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Pesos de relevância para HP-Group / PartYard. Quanto mais pesado, mais
     * "core" da actividade do grupo é. Score = soma de pesos × ocorrências
     * (case-insensitive) no objeto + entidade do procedimento.
     *
     * Pedido do utilizador 2026-05-07: "analisar sempre 3 folhas e mostrar por
     * relevância" — esta tabela é a heurística que ordena as 3 páginas.
     */
    protected const ACINGOV_RELEVANCE_WEIGHTS = [
        // ── Core defense / military (peso 10) ──
        'defesa' => 10, 'militar' => 10, 'marinha' => 10, 'exército' => 10,
        'força aérea' => 10, 'forças armadas' => 10, 'nato' => 10, 'armamento' => 10,
        'munições' => 10, 'armas' => 10, 'guarda nacional' => 9, 'gnr' => 9, 'psp' => 8,
        // ── Aviação / MRO (peso 9) ──
        'aeronave' => 9, 'aeronáutica' => 9, 'aviação' => 9, 'helicóptero' => 9,
        'mro' => 9, 'turbina' => 8, 'aeroporto' => 7,
        // ── Naval / marítimo (peso 9) ──
        'navio' => 9, 'naval' => 9, 'embarcação' => 9, 'propulsão' => 9,
        'estaleiro' => 8, 'doca' => 7, 'porto' => 6, 'marítimo' => 8, 'fragata' => 10,
        // ── Motores / spares / repair (peso 8) ──
        'motor' => 8, 'sobressalente' => 8, 'overhaul' => 8, 'revisão geral' => 8,
        'reparação' => 7, 'manutenção' => 6, 'peças' => 6, 'mtu' => 9, 'man' => 5,
        'caterpillar' => 8, 'volvo penta' => 9, 'rolls-royce' => 9,
        // ── Hospitalar / médico (peso 7) ──
        'hospitalar' => 7, 'equipamento médico' => 7, 'dispositivos médicos' => 7,
        'esterilização' => 7, 'cirurgia' => 6, 'monitorização' => 5,
        // ── Segurança / destruição docs (peso 6) ──
        'destruição de documentos' => 6, 'destruidora' => 6, 'fragmentação' => 5,
        'trituração' => 5, 'shredder' => 6,
        // ── Simulação / IT (peso 5) ──
        'simulador' => 5, 'simulação' => 5, 'cibersegurança' => 6,
        // ── Lubrificantes / químicos (peso 4) ──
        'lubrificante' => 4, 'óleo' => 3, 'combustível' => 4,
    ];

    /**
     * Extrai (objeto, entidade, ref) de uma linha já formatada por parseAcingovTable.
     * Usado para fazer scoring de relevância — evita refactor invasivo do parser.
     */
    protected function extractAcingovFields(string $line): array
    {
        $get = fn(string $key) => preg_match('/\|\s*' . preg_quote($key, '/') . ':\s*([^|]+?)(?:\s*\||$)/u', $line, $m) ? trim($m[1]) : '';
        return [
            'ref'      => preg_match('/^-\s*REF:\s*([^|]+?)\s*\|/u', $line, $m) ? trim($m[1]) : '',
            'objeto'   => $get('OBJETO'),
            'entidade' => $get('ENTIDADE'),
            'tipo'     => $get('TIPO'),
        ];
    }

    /**
     * Score de relevância para PartYard. Aplicado ao objeto + entidade.
     * Devolve int — quanto maior, mais relevante.
     */
    protected function scoreAcingovRelevance(string $line): int
    {
        $f      = $this->extractAcingovFields($line);
        $haystk = mb_strtolower(($f['objeto'] ?? '') . ' ' . ($f['entidade'] ?? ''));
        if ($haystk === ' ' || trim($haystk) === '') return 0;

        $score = 0;
        foreach (self::ACINGOV_RELEVANCE_WEIGHTS as $kw => $weight) {
            // substr_count em UTF-8 — usar mb_substr_count
            $hits = mb_substr_count($haystk, mb_strtolower($kw));
            if ($hits > 0) $score += $hits * $weight;
        }
        return $score;
    }

    /**
     * Devolve a URL das primeiras N folhas da listagem unfiltered da Acingov.
     *
     * Acingov corre CodeIgniter — pagineção via segmento de URI:
     *   /indexProcedimentos             → folha 1 (offset=0)
     *   /indexProcedimentos/30          → folha 2 (offset=30, 30 itens/folha)
     *   /indexProcedimentos/60          → folha 3 (offset=60)
     *
     * Tentamos também ?page=N como fallback caso a config esteja diferente.
     */
    protected function acingovPagedUrls(string $baseUrl, int $pages): array
    {
        $itemsPerPage = 30;
        $urls = [];
        for ($p = 0; $p < $pages; $p++) {
            $offset = $p * $itemsPerPage;
            $urls[] = $offset === 0 ? $baseUrl : rtrim($baseUrl, '/') . '/' . $offset;
        }
        return $urls;
    }

    protected function fetchAcingovPublic(): string
    {
        $baseUrl  = 'https://www.acingov.pt/acingovprod/2/zonaPublica/zona_publica_c/indexProcedimentos';
        $seen  = [];
        $lines = [];

        // ── PASSO 1: 3 folhas da listagem unfiltered (ordenadas por data) ──
        // Política do utilizador 2026-05-07: SEMPRE puxar 3 folhas e ordenar
        // por relevância para PartYard, em vez de só pesquisar por keywords.
        $pageRequests = 0;
        foreach ($this->acingovPagedUrls($baseUrl, 3) as $idx => $url) {
            try {
                $resp = $this->httpClient->get($url, [
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)', 'Accept' => 'text/html'],
                    'timeout' => 12,
                ]);
                $rows = $this->parseAcingovTable($resp->getBody()->getContents(), $seen, false);
                $lines = array_merge($lines, $rows);
                $pageRequests++;
            } catch (\Throwable $e) {
                Log::info("Acingov [public/page" . ($idx + 1) . "]: " . $e->getMessage());
            }
        }

        // ── PASSO 2: keyword targeted (suplementar) — apanha rows que possam
        //    estar em folhas mais antigas mas ainda relevantes ──
        $keywords = [
            'defesa', 'militar', 'marinha', 'aeronáutica',
            'naval', 'motor', 'overhaul', 'sobressalente',
        ];
        $kwRequests = 0;
        foreach ($keywords as $kw) {
            if ($kwRequests >= 6 || count($lines) >= 100) break;
            $kwRequests++;
            try {
                $resp = $this->httpClient->get($baseUrl, [
                    'query'   => ['procedure_search' => $kw],
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)', 'Accept' => 'text/html'],
                    'timeout' => 10,
                ]);
                $rows = $this->parseAcingovTable($resp->getBody()->getContents(), $seen, false);
                $lines = array_merge($lines, $rows);
            } catch (\Throwable $e) {
                Log::info("Acingov [public/{$kw}]: " . $e->getMessage());
            }
        }

        if (empty($lines)) return '';

        // ── PASSO 3: Score por relevância e ordenar (desc) ──
        $scored = array_map(fn($l) => ['line' => $l, 'score' => $this->scoreAcingovRelevance($l)], $lines);
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Anota score nas linhas com >0 — ajuda a Ana a explicar a ordem.
        $finalLines = array_map(function ($r) {
            return $r['score'] > 0
                ? $r['line'] . " | RELEVANCE: {$r['score']}"
                : $r['line'];
        }, array_slice($scored, 0, 40));

        $hits = array_filter($scored, fn($r) => $r['score'] > 0);
        Log::info("Acingov [public]: 3 folhas analisadas — " . count($lines) . " rows, " . count($hits) . " com score>0", [
            'pages_fetched'  => $pageRequests,
            'kw_requests'    => $kwRequests,
            'top_score'      => $scored[0]['score'] ?? 0,
        ]);

        return "=== ACINGOV — Concursos (3 folhas analisadas, ordenadas por relevância PartYard, " . now()->format('d/m/Y') . ") ===\n" . implode("\n", $finalLines);
    }

    /**
     * Parse the Acingov HTML — handles both table and div-based layouts.
     *
     * Public zone columns : Nº | Tipo | Objeto | Entidade | Estado   (no date)
     * Auth zone columns   : Referência | Objeto | Prazo | Entidade | DRE | CPV
     *                       + <div class="announcement-publish-date"> inside each row
     *
     * @param  bool  $authenticated  Whether coming from the private area
     */
    protected function parseAcingovTable(string $html, array &$seen, bool $authenticated = false): array
    {
        $lines = [];

        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // ── Strategy A: table rows (works for both zones) ──────────────
            $rows = $xpath->query('//table//tr[position()>1]');

            if ($rows && $rows->length > 0) {
                foreach ($rows as $row) {
                    $cells = $xpath->query('.//td', $row);
                    if (!$cells || $cells->length < 2) continue;

                    $ref    = trim($cells->item(0)->textContent ?? '');
                    $objeto = '';
                    $tipo   = '';
                    $entidade = '';
                    $estado   = '';
                    $prazo    = '';

                    if ($authenticated) {
                        // Auth layout: ref | objeto | prazo-col | entidade | DRE | CPV
                        $objeto   = $cells->length > 1 ? trim($cells->item(1)->textContent ?? '') : '';
                        $entidade = $cells->length > 3 ? trim($cells->item(3)->textContent ?? '') : '';

                        // Prefer the announcement-publish-date div anywhere in this row
                        $pubNode = $xpath->query('.//*[contains(@class,"announcement-publish-date")]', $row)->item(0);
                        if ($pubNode) {
                            $prazo = trim($pubNode->textContent);
                        } else {
                            // Fallback: column 2 (Prazo de entrega) or column 5
                            $prazo = $cells->length > 2
                                ? trim($cells->item(2)->textContent ?? '')
                                : ($cells->length > 5 ? trim($cells->item(5)->textContent ?? '') : '');
                        }

                        // Clean up the "ending date" noise if it bleeds in
                        $endNode = $xpath->query('.//*[contains(@class,"announcement-ending-date")]', $row)->item(0);
                        if ($endNode) {
                            $endDate = trim($endNode->textContent);
                            // If prazo is blank but ending-date is filled, use it
                            if (!$prazo && $endDate) $prazo = $endDate;
                        }
                    } else {
                        // Public layout: nº | tipo | objeto | entidade | estado
                        $tipo     = $cells->length > 1 ? trim($cells->item(1)->textContent ?? '') : '';
                        $objeto   = $cells->length > 2 ? trim($cells->item(2)->textContent ?? '') : '';
                        $entidade = $cells->length > 3 ? trim($cells->item(3)->textContent ?? '') : '';
                        $estado   = $cells->length > 4 ? trim($cells->item(4)->textContent ?? '') : '';
                    }

                    // Clean up whitespace in long text fields
                    $objeto   = preg_replace('/\s+/', ' ', $objeto);
                    $entidade = preg_replace('/\s+/', ' ', $entidade);

                    // Attempt to get a link
                    $anchor = $xpath->query('.//a[@href]', $row)->item(0);
                    $link   = '';
                    if ($anchor) {
                        $href = $anchor->getAttribute('href') ?? '';
                        if ($href && !str_starts_with($href, 'javascript') && !str_starts_with($href, '#')) {
                            $link = str_starts_with($href, 'http')
                                ? $href
                                : 'https://www.acingov.pt/acingovprod/2/' . ltrim($href, '/');
                        }
                    }

                    if (!$ref || strlen($ref) < 3) continue;
                    if (isset($seen[$ref])) continue;
                    $seen[$ref] = true;

                    $line = "- REF: {$ref}";
                    if ($tipo)     $line .= " | TIPO: {$tipo}";
                    if ($objeto)   $line .= " | OBJETO: {$objeto}";
                    if ($entidade) $line .= " | ENTIDADE: {$entidade}";
                    if ($estado)   $line .= " | ESTADO: {$estado}";
                    if ($prazo)    $line .= " | PRAZO: {$prazo}";
                    if ($link)     $line .= " | URL: {$link}";

                    $lines[] = $line;
                }

                if (!empty($lines)) return $lines;
            }

            // ── Strategy B: pure div layout (authenticated zone fallback) ──
            if (!$authenticated) return $lines; // public zone is always a table

            $dateDivs = $xpath->query('//*[contains(@class,"announcement-publish-date")]');
            if (!$dateDivs || $dateDivs->length === 0) return $lines;

            foreach ($dateDivs as $dateDiv) {
                $prazo = trim($dateDiv->textContent ?? '');

                // Walk up the DOM to find a reasonable row container
                $container = $dateDiv->parentNode;
                for ($depth = 0; $depth < 6 && $container; $depth++) {
                    $tag = $container->nodeName ?? '';
                    if (in_array($tag, ['tr', 'li', 'article', 'section'])) break;
                    $class = strtolower($container->getAttribute('class') ?? '');
                    if (str_contains($class, 'row') || str_contains($class, 'item')
                        || str_contains($class, 'procedure') || str_contains($class, 'anuncio')) break;
                    $container = $container->parentNode;
                }
                if (!$container || $container->nodeName === 'body') continue;

                // Gather leaf text nodes (no child elements) — these are the data fields
                $leafTexts = [];
                $leafNodes = $xpath->query('.//*[not(*) and normalize-space(text())]', $container);
                foreach ($leafNodes as $lf) {
                    $t = trim(preg_replace('/\s+/', ' ', $lf->textContent ?? ''));
                    // Skip the date itself and very short noise
                    if (strlen($t) > 3 && $t !== $prazo) $leafTexts[] = $t;
                }

                $ref      = $leafTexts[0] ?? '';
                $objeto   = $leafTexts[1] ?? '';
                $entidade = '';

                // Try a dedicated entidade/entity div
                $entNode = $xpath->query('.//*[contains(@class,"entidade") or contains(@class,"entity") or contains(@class,"adjudicante")]', $container)->item(0);
                if ($entNode) {
                    $entidade = trim(preg_replace('/\s+/', ' ', $entNode->textContent ?? ''));
                } elseif (count($leafTexts) > 2) {
                    $entidade = $leafTexts[2];
                }

                // Try a link
                $link = '';
                $anchor = $xpath->query('.//a[@href]', $container)->item(0);
                if ($anchor) {
                    $href = $anchor->getAttribute('href') ?? '';
                    if ($href && !str_starts_with($href, 'javascript') && !str_starts_with($href, '#')) {
                        $link = str_starts_with($href, 'http')
                            ? $href
                            : 'https://www.acingov.pt/acingovprod/2/' . ltrim($href, '/');
                    }
                }

                if (!$ref || strlen($ref) < 3) continue;
                if (isset($seen[$ref])) continue;
                $seen[$ref] = true;

                $line = "- REF: {$ref} | OBJETO: {$objeto}";
                if ($entidade) $line .= " | ENTIDADE: {$entidade}";
                if ($prazo)    $line .= " | PRAZO: {$prazo}";
                if ($link)     $line .= " | URL: {$link}";

                $lines[] = $line;
            }
        } catch (\Throwable $e) {
            Log::info('parseAcingovTable: ' . $e->getMessage());
        }

        return $lines;
    }

    // ─── base.gov.pt — busca via Tavily (anti-bot do portal mata HTTP direto) ──
    //
    // 2026-05-07: o IMPIC migrou o portal de /Base para /Base4 e a pesquisa
    // agora é AJAX (POST /Base4/pt/resultados/) protegida por F5 BIG-IP. Pedidos
    // programáticos recebem HTTP 200 com Content-Length: 0 (anti-scraping).
    //
    // Tested: GET homepage→200, POST resultados→200 0b (qualquer combinação de
    // cookies / headers / UA testada via curl no droplet falha).
    //
    // Solução: indexar via Tavily (Google index → não bate em F5) e pedir
    // `site:base.gov.pt`. Tavily devolve title+url+snippet — suficiente para
    // a Ana classificar oportunidades. Resultados podem ter latência de horas
    // vs o portal, mas o portal directo está in viable hoje.
    protected function fetchBaseGovPt(): string
    {
        if (!$this->searcher->isAvailable()) {
            return "(base.gov.pt: TAVILY_API_KEY não configurada — search via web index não disponível)";
        }

        // Queries agrupadas por área PartYard. Cada chamada Tavily devolve
        // 5 resultados; com 5 áreas = 25 contratos no máximo. site:base.gov.pt
        // restringe ao domínio. "contratos públicos" + área força matches em
        // títulos/URLs do portal.
        $queries = [
            'defesa-naval'   => 'site:base.gov.pt contratos públicos defesa OR naval OR marinha OR militar',
            'aviacao'        => 'site:base.gov.pt contratos públicos aeronáutica OR aviação OR helicóptero OR MRO',
            'motores-spares' => 'site:base.gov.pt contratos públicos "motor diesel" OR "peças sobressalentes" OR overhaul',
            'hospitalar'     => 'site:base.gov.pt contratos públicos "equipamento médico" OR esterilização OR hospitalar',
            'cyber-sim'      => 'site:base.gov.pt contratos públicos cibersegurança OR simulador OR "destruição de documentos"',
        ];

        $allLines = [];
        $seenUrls = [];
        $apiOk    = 0;

        foreach ($queries as $area => $query) {
            try {
                // 30d — alinhado com a janela de adjudicados que existia antes
                $raw = $this->searcher->search($query, 5, 'basic', 30);
                if (!$raw || str_contains($raw, '(No results found)') || str_contains($raw, 'failed')) {
                    continue;
                }
                $apiOk++;

                // Parse Tavily output (formato: "N. **Title** XX%\n  URL: ...\n  content")
                if (preg_match_all('/\d+\.\s+\*\*(.+?)\*\*.*?URL:\s*(\S+)\s*\n\s*(.+?)(?=\n\n|\n\d+\.|\z)/s', $raw, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $title   = trim(preg_replace('/\s+/', ' ', $m[1]));
                        $url     = trim($m[2]);
                        $snippet = trim(preg_replace('/\s+/', ' ', $m[3]));

                        if (!str_contains($url, 'base.gov.pt')) continue;
                        if (isset($seenUrls[$url])) continue;
                        $seenUrls[$url] = true;

                        // Extrai contractId do URL se for página de Detalhe
                        $contractId = '';
                        if (preg_match('#/[Dd]etalhe[^?]*\?[^=]*id=(\d+)|/[Dd]etalhe/Contratos/(\d+)#', $url, $im)) {
                            $contractId = $im[1] ?? $im[2] ?? '';
                        }

                        $line = "- OBJETO: " . mb_substr($title, 0, 180);
                        if ($contractId) $line .= " | ID: {$contractId}";
                        if ($snippet)    $line .= " | DETALHE: " . mb_substr($snippet, 0, 200);
                        $line .= " | URL: {$url}";
                        $allLines[] = $line;
                    }
                }
            } catch (\Throwable $e) {
                Log::info("base.gov.pt [Tavily/{$area}]: " . $e->getMessage());
            }
        }

        if (empty($allLines)) {
            return "(base.gov.pt: sem resultados via Tavily nos últimos 30 dias para os critérios navais/defesa)";
        }

        // Score por relevância PartYard (reaproveita scoreAcingovRelevance — mesma tabela)
        $scored = array_map(fn($l) => ['line' => $l, 'score' => $this->scoreAcingovRelevance($l)], $allLines);
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $finalLines = array_map(function ($r) {
            return $r['score'] > 0
                ? $r['line'] . " | RELEVANCE: {$r['score']}"
                : $r['line'];
        }, array_slice($scored, 0, 25));

        Log::info("base.gov.pt: Tavily ok — " . count($allLines) . " contratos, " . count($finalLines) . " devolvidos", [
            'queries_ok' => $apiOk . '/' . count($queries),
        ]);

        return "=== BASE.GOV.PT — Contratos (via Tavily/Google index, ordenados por relevância PartYard, " . now()->format('d/m/Y') . ") ===\n" . implode("\n", $finalLines);
    }

    // ─── EU Funding & Tenders Portal (ec.europa.eu) ────────────────────────
    //
    // Cobre os principais programas de financiamento UE relevantes para
    // a PartYard / HP-Group:
    //   • EDF — European Defence Fund (defesa, dual-use, cyber, quantum)
    //   • Horizon Europe — investigação aplicada, naval, materials
    //   • Digital Europe — AI, cyber, quantum, semicondutores
    //   • CEF — Connecting Europe Facility (maritime, transport)
    //   • EIC Accelerator — startups deep-tech
    //   • PESCO — Permanent Structured Cooperation defesa
    //
    // O portal ec.europa.eu/info/funding-tenders é JavaScript SPA;
    // direct HTTP não funciona. Usamos Tavily com site:ec.europa.eu
    // filtros por sector. Cada query devolve 8 resultados (ampliado vs
    // outros portais porque EU funding tem ciclos longos — vale a pena
    // ver mais com janela maior).
    protected function fetchEuFunding(?callable $heartbeat = null): string
    {
        if (!$this->searcher->isAvailable()) {
            return "(EU Funding: TAVILY_API_KEY não configurada)";
        }

        // 7 áreas sectoriais PartYard, restringidas ao portal oficial.
        $queries = [
            'edf-defesa'       => 'site:ec.europa.eu funding-tenders EDF European Defence Fund 2026 naval maritime defense',
            'quantum-cyber'    => 'site:ec.europa.eu funding-tenders quantum cybersecurity post-quantum cryptography call',
            'horizon-naval'    => 'site:ec.europa.eu funding-tenders Horizon Europe maritime ship vessel naval research 2026',
            'digital-ai'       => 'site:ec.europa.eu funding-tenders Digital Europe AI artificial intelligence semiconductor',
            'aerospace'        => 'site:ec.europa.eu funding-tenders aerospace aviation UAV drone unmanned',
            'cef-maritime'     => 'site:ec.europa.eu funding-tenders CEF Connecting Europe Facility transport port maritime',
            'eic-deeptech'     => 'site:ec.europa.eu funding-tenders EIC Accelerator deep tech startup grant 2026',
        ];

        $allLines = [];
        $seenUrls = [];
        $apiOk    = 0;

        foreach ($queries as $area => $query) {
            if ($heartbeat) $heartbeat("a pesquisar EU funding ({$area})");
            try {
                // 60 dias — calls EU duram meses, janela larga é segura
                $raw = $this->searcher->search($query, 8, 'basic', 60);
                if (!$raw || str_contains($raw, '(No results found)') || str_contains($raw, 'failed')) {
                    continue;
                }
                $apiOk++;

                // Parse Tavily output: "N. **Title** XX%\n  URL: ...\n  content"
                if (preg_match_all('/\d+\.\s+\*\*(.+?)\*\*.*?URL:\s*(\S+)\s*\n\s*(.+?)(?=\n\n|\n\d+\.|\z)/s', $raw, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $title   = trim(preg_replace('/\s+/', ' ', $m[1]));
                        $url     = trim($m[2]);
                        $snippet = trim(preg_replace('/\s+/', ' ', $m[3]));

                        if (!str_contains($url, 'ec.europa.eu')) continue;
                        if (isset($seenUrls[$url])) continue;
                        $seenUrls[$url] = true;

                        // Extract topic ID (ex: EDF-2026-RA-CYBER-QSTN, HORIZON-CL3-2026, etc)
                        $topicId = '';
                        if (preg_match('#topic-details/([A-Z0-9_-]+)#', $url, $tm)) {
                            $topicId = $tm[1];
                        }

                        // Extract program name from URL or title
                        $program = '';
                        if (preg_match('/\b(EDF|HORIZON|DIGITAL|CEF|EIC|PESCO|EU4HEALTH|ERASMUS)\b/i', $url . ' ' . $title, $pm)) {
                            $program = strtoupper($pm[1]);
                        }

                        $line  = "- ÁREA: {$area}";
                        if ($program) $line .= " | PROG: {$program}";
                        if ($topicId) $line .= " | TOPIC: {$topicId}";
                        $line .= " | TÍTULO: " . mb_substr($title, 0, 160);
                        $line .= " | URL: {$url}";
                        if (strlen($snippet) > 20) {
                            $line .= " | DESC: " . mb_substr($snippet, 0, 200);
                        }
                        $allLines[] = $line;
                    }
                }
            } catch (\Throwable $e) {
                Log::info("EU Funding [Tavily/{$area}]: " . $e->getMessage());
            }
        }

        if (empty($allLines)) {
            return "(EU Funding: sem resultados Tavily nos últimos 60 dias para os 7 sectores PartYard)";
        }

        // Score por relevância (reusa scoreAcingovRelevance — mesmas keywords PT/EN)
        $scored = array_map(fn($l) => ['line' => $l, 'score' => $this->scoreAcingovRelevance($l)], $allLines);
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $finalLines = array_map(function ($r) {
            return $r['score'] > 0
                ? $r['line'] . " | RELEVANCE: {$r['score']}"
                : $r['line'];
        }, array_slice($scored, 0, 30));

        Log::info("EU Funding: Tavily ok — " . count($allLines) . " calls, " . count($finalLines) . " devolvidos", [
            'queries_ok' => $apiOk . '/' . count($queries),
        ]);

        return "=== EU FUNDING & TENDERS — ec.europa.eu (via Tavily, ordenado por relevância PartYard, "
             . now()->format('d/m/Y') . ") ===\n"
             . "Cobre EDF · Horizon Europe · Digital Europe · CEF · EIC Accelerator · PESCO · EU4Health.\n"
             . implode("\n", $finalLines);
    }

    // ─── UNGM — direct public API ──────────────────────────────────────────
    protected function fetchUNGM(): string
    {
        $dateFrom = now()->subDays(14)->format('Y-m-d');
        $dateTo   = now()->format('Y-m-d');

        $searchGroups = [
            // ⚓ Naval & Marítimo
            'marine naval vessel ship spare parts propulsion',
            // 🛩️ Aviação & Aeronáutica
            'aircraft aviation MRO helicopter spare parts airframe',
            // 🚗 Defesa & Militar
            'defense military land vehicle army procurement',
            // 🎯 Simulação + 🛢️ Lubrificantes
            'simulation training system lubricant defense equipment',
            // 💻 IT & Cyber
            'cybersecurity information security defense IT',
        ];

        $seen       = [];
        $lines      = [];
        $kwRequests = 0;

        foreach ($searchGroups as $keywords) {
            if ($kwRequests >= 3) break; // max 3 UNGM requests — API is slow
            $kwRequests++;
            try {
                $resp = $this->httpClient->get(
                    'https://www.ungm.org/Public/Notice',
                    [
                        'query' => [
                            'noticeType'    => '0',     // 0 = all
                            'status'        => '0',     // 0 = active
                            'keyword'       => $keywords,
                            'pageIndex'     => '0',
                            'pageSize'      => '20',
                            'publishing_start' => $dateFrom,
                            'publishing_end'   => $dateTo,
                        ],
                        'headers' => [
                            'Accept'     => 'application/json, text/plain, */*',
                            'User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)',
                            'Referer'    => 'https://www.ungm.org/Public/Notice',
                        ],
                        'timeout' => 5,
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

    // ─── TED Europa — Official API v3 (no auth required) ─────────────────
    /**
     * Queries the EU TED (Tenders Electronic Daily) API v3 directly.
     * No API key needed — public endpoint.
     * Docs: https://docs.ted.europa.eu/api/
     *
     * Runs 3 keyword passes covering all PartYard business areas.
     * Returns up to 40 unique active notices with title, entity, CPV, deadline, value, link.
     */
    protected function fetchTEDEuropa(?callable $heartbeat = null): string
    {
        $endpoint = 'https://api.ted.europa.eu/v3/notices/search';

        // Keyword groups — one per PartYard business area
        $keywordGroups = [
            // ⚓ Naval & Maritime
            'maritime OR naval OR vessel OR ship OR propulsion OR "spare parts" OR "ship repair" OR "marine engine" OR MTU OR Caterpillar OR Wärtsilä OR MAN',
            // 🛩️ Aerospace & Defense MRO
            'aircraft OR aviation OR MRO OR helicopter OR "F-16" OR "C-130" OR airframe OR avionics OR "aircraft maintenance" OR "aircraft parts" OR "aeronautical"',
            // 🚗 Military land + simulation + cyber + lubricants
            'defense OR defence OR military OR NATO OR "armoured vehicle" OR simulator OR simulation OR cybersecurity OR lubricant OR "spare parts" OR "ground equipment"',
        ];

        $seen  = [];
        $lines = [];

        foreach ($keywordGroups as $idx => $keywords) {
            if ($heartbeat) $heartbeat('TED Europa — pesquisa ' . ($idx + 1) . '/3');
            try {
                $resp = $this->httpClient->post($endpoint, [
                    'headers' => [
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/json',
                        'User-Agent'   => 'ClawYard/1.0 (research@hp-group.org)',
                    ],
                    'json' => [
                        'query'  => $keywords,
                        'fields' => [
                            'publication-number',
                            'notice-type',
                            'title',
                            'contracting-authority-name',
                            'deadline-receipt-request',
                            'estimated-value-highest',
                            'publication-date',
                            'place-of-performance',
                            'cpv',
                            'procedure-type',
                        ],
                        'page'   => 1,
                        'limit'  => 20,
                        'scope'  => 'ACTIVE',   // only open/active notices
                        'sort'   => [['deadline-receipt-request', 'asc']], // soonest deadline first
                    ],
                    'timeout' => 12,
                ]);

                $data    = json_decode($resp->getBody()->getContents(), true);
                $notices = $data['notices'] ?? $data['results'] ?? [];

                if (!is_array($notices)) continue;

                foreach ($notices as $n) {
                    $id = $n['publication-number'] ?? '';
                    if ($id && isset($seen[$id])) continue;
                    if ($id) $seen[$id] = true;

                    // Title: TED returns array of multilingual strings; prefer EN then PT then first
                    $title = '';
                    $rawTitle = $n['title'] ?? [];
                    if (is_array($rawTitle)) {
                        $title = $rawTitle['ENG'] ?? $rawTitle['POR'] ?? reset($rawTitle) ?? '';
                    } else {
                        $title = (string) $rawTitle;
                    }

                    $entity   = $n['contracting-authority-name'] ?? 'N/A';
                    if (is_array($entity)) $entity = reset($entity) ?? 'N/A';

                    $deadline = $n['deadline-receipt-request'] ?? 'N/A';
                    // Normalize: TED returns ISO 8601 or dd-mm-yyyy
                    if ($deadline !== 'N/A' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $deadline, $dm)) {
                        $deadline = "{$dm[3]}/{$dm[2]}/{$dm[1]}";
                    }

                    $value   = $n['estimated-value-highest'] ?? '';
                    $pubDate = $n['publication-date'] ?? '';
                    if ($pubDate && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $pubDate, $pd)) {
                        $pubDate = "{$pd[3]}/{$pd[2]}/{$pd[1]}";
                    }

                    $noticeType = $n['notice-type'] ?? '';
                    $cpv        = $n['cpv']         ?? '';
                    if (is_array($cpv)) $cpv = implode(', ', array_slice($cpv, 0, 3));

                    $place = $n['place-of-performance'] ?? '';
                    if (is_array($place)) $place = reset($place) ?? '';

                    $link = $id ? "https://ted.europa.eu/en/notice/-/detail/{$id}" : '';

                    $line = "- [{$noticeType}] {$title} | ENTIDADE: {$entity}";
                    if ($place)    $line .= " | LOCAL: {$place}";
                    if ($cpv)      $line .= " | CPV: {$cpv}";
                    if ($pubDate)  $line .= " | PUBLICADO: {$pubDate}";
                    if ($deadline) $line .= " | PRAZO: {$deadline}";
                    if ($value)    $line .= " | VALOR: €{$value}";
                    if ($link)     $line .= " | URL: {$link}";

                    $lines[] = $line;
                }
            } catch (\Throwable $e) {
                Log::info("TED Europa [group {$idx}]: " . $e->getMessage());
            }
        }

        // Fallback: broader query if nothing found (TED may have different field names in future)
        if (empty($lines)) {
            try {
                if ($heartbeat) $heartbeat('TED Europa — fallback query');
                $resp = $this->httpClient->post($endpoint, [
                    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                    'json'    => [
                        'query'  => 'naval OR aircraft OR defense OR maritime OR military',
                        'fields' => ['publication-number', 'notice-type', 'title', 'contracting-authority-name', 'deadline-receipt-request', 'publication-date'],
                        'page'   => 1,
                        'limit'  => 25,
                        'scope'  => 'ACTIVE',
                    ],
                    'timeout' => 10,
                ]);
                $data    = json_decode($resp->getBody()->getContents(), true);
                $notices = $data['notices'] ?? $data['results'] ?? [];
                foreach ($notices as $n) {
                    $id    = $n['publication-number'] ?? '';
                    if ($id && isset($seen[$id])) continue;
                    if ($id) $seen[$id] = true;
                    $title = $n['title'] ?? 'N/A';
                    if (is_array($title)) $title = $title['ENG'] ?? reset($title) ?? 'N/A';
                    $entity   = $n['contracting-authority-name'] ?? 'N/A';
                    if (is_array($entity)) $entity = reset($entity) ?? 'N/A';
                    $deadline = $n['deadline-receipt-request'] ?? 'N/A';
                    if ($deadline !== 'N/A' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $deadline, $dm)) {
                        $deadline = "{$dm[3]}/{$dm[2]}/{$dm[1]}";
                    }
                    $link  = $id ? "https://ted.europa.eu/en/notice/-/detail/{$id}" : '';
                    $lines[] = "- {$title} | ENTIDADE: {$entity} | PRAZO: {$deadline}" . ($link ? " | URL: {$link}" : '');
                }
            } catch (\Throwable $e) {
                Log::info("TED Europa [fallback]: " . $e->getMessage());
            }
        }

        $lines = array_unique(array_slice($lines, 0, 50));

        if (empty($lines)) {
            return '(TED Europa: API sem resultados — possível alteração de schema; verifica https://docs.ted.europa.eu/api/)';
        }

        return "=== TED EUROPA (Official API v3) — Concursos UE Activos ===\n"
             . "Total: " . count($lines) . " avisos | Fonte: ted.europa.eu\n"
             . implode("\n", $lines);
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
                    $result = $this->searcher->search($query, 8, 'basic');
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
- Para cada concurso: entidade, objeto, valor, 📅 data publicação, ⏰ prazo limite, relevância, ação
- O campo ⏰ PRAZO é OBRIGATÓRIO em todos os contratos — usa N/A se não disponível
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->buildSystemWithBooks($message, $this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $result = $data['content'][0]['text'] ?? '';
        $this->publishSharedContext($result);
        return $result;
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                // Bug fix 2026-05-14: usava $message (undefined nesta função;
                // a assinatura é $prompt). Causava 500 silencioso em produção
                // depois do "Recolha concluída" — Dr.ª Ana Contratos
                // crashava após filtrar/ordenar e antes de redigir relatório.
                'system'     => $this->buildSystemWithBooks($prompt, $this->systemPrompt),
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
    /**
     * Detect if the user wants a portal search or just a direct question answer.
     */
    protected function needsPortalSearch(string $userText): bool
    {
        $lower = mb_strtolower($userText);
        $portalKeywords = [
            'concurso', 'concursos', 'portal', 'portais', 'pesquisa', 'pesquisar',
            'procura', 'procurar', 'novos', 'hoje', 'semana', 'últimos', 'ultimos',
            'acingov', 'vortal', 'base.gov', 'sam.gov', 'ungm', 'ted',
            'tender', 'tenders', 'oportunidade', 'oportunidades', 'licitação', 'licitacao',
            'adjudicação', 'adjudicacao', 'ajuste directo', 'ajuste direto', 'contratação pública',
            'relatório', 'relatorio', 'report', 'scan', 'scanning',
            'força aérea', 'marinha', 'exército', 'emgfa', 'nato',
        ];
        foreach ($portalKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

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
            if ($heartbeat) $heartbeat('');
        };

        $userText = is_array($message)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $message))
            : $message;

        // ── Direct question — skip portal search, answer immediately ─────────
        if (!$this->needsPortalSearch($userText)) {
            return $this->streamClaudeOnce($userText, $history, $onChunk, $heartbeat, 'a analisar');
        }

        $dateFrom = now()->subDays(5)->format('d/m/Y');
        $dateTo   = now()->format('d/m/Y');

        // ── Header ───────────────────────────────────────────────────────────
        $emit("## 📋 Dra. Ana Contratos — Relatório {$today}\n");
        $emit("Período: **{$dateFrom}** → **{$dateTo}** · Portais: Acingov · **TED Europa (API oficial)** · base.gov.pt · UNGM · SAM.gov · **EU Funding (ec.europa.eu — EDF/Horizon/Digital/CEF/EIC)**\n\n");

        $emit("⏳ A recolher dados dos portais...\n\n");

        // Tavily `days` filter — últimos 7 dias (mais tolerante do que 5 para apanhar mais resultados)
        $tavilyDays = 7;

        // Portal 1: Acingov — HTTP direto (login autenticado + fallback zona pública)
        $emit("  `1/6` 🇵🇹 Acingov...\n");
        if ($heartbeat) $heartbeat('a pesquisar Acingov');
        $acingovData = $this->fetchAcingov();

        // Portal 2: TED Europa — Official API v3 (direct, no auth needed)
        $emit("  `2/6` 🇪🇺 TED Europa (API oficial)...\n");
        $vortalData = $this->fetchTEDEuropa($heartbeat);

        // Portal 3: UNGM — direct public API
        $emit("  `3/6` 🌍 UNGM...\n");
        if ($heartbeat) $heartbeat('a pesquisar UNGM');
        $ungmData = $this->fetchUNGM();
        // Tavily fallback if direct API returns nothing
        if (strlen($ungmData) < 80 && $this->searcher->isAvailable()) {
            try {
                $ungmData = $this->searcher->search(
                    'site:ungm.org tender maritime naval aviation aircraft spare parts defense MRO 2026',
                    5, 'basic', $tavilyDays
                );
                if (strlen($ungmData) < 80) {
                    $ungmData = $this->searcher->search(
                        'UNGM "United Nations Global Marketplace" tender maritime naval aviation helicopter 2026 deadline',
                        5, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [UNGM Tavily fallback]: ' . $e->getMessage());
            }
        }

        // Portal 4: base.gov.pt — direct public API (no auth needed)
        $emit("  `4/6` 🇵🇹 base.gov.pt (adjudicados)...\n");
        if ($heartbeat) $heartbeat('a pesquisar base.gov.pt');
        $baseGovData = $this->fetchBaseGovPt();

        // Portal 5: SAM.gov
        $emit("  `5/6` 🇺🇸 SAM.gov...\n");
        if ($heartbeat) $heartbeat('a pesquisar SAM.gov');
        $samData = $this->fetchSamGov();

        // Portal 6: EU Funding & Tenders — ec.europa.eu (EDF/Horizon/Digital/CEF/EIC)
        $emit("  `6/6` 🇪🇺 EU Funding (EDF · Horizon · Digital Europe · CEF · EIC)...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar EU Funding portal');
        $euFundingData = $this->fetchEuFunding($heartbeat);

        $emit("✅ **Recolha concluída. A filtrar e ordenar por prazo...**\n\n");

        // ── Análise Claude — agrupado por portal, filtrado por prazo ──────────
        $emit("---\n### 🧠 Dra. Ana Contratos — Relatório por Fonte\n\n");
        if ($heartbeat) $heartbeat('Dra. Ana a filtrar por prazo');

        $today2m = now()->addMonths(2)->format('d/m/Y');

        $allData = implode("\n\n", array_filter(
            [
                '[FONTE: ACINGOV — Concursos Públicos Portugal]' . "\n" . $acingovData,
                '[FONTE: TED EUROPA — Concursos UE (API Oficial ted.europa.eu)]' . "\n" . $vortalData,
                '[FONTE: UNGM — UN Global Marketplace]'          . "\n" . $ungmData,
                '[FONTE: BASE.GOV.PT — Contratos Adjudicados PT]'. "\n" . $baseGovData,
                '[FONTE: SAM.GOV — US Federal Contracts]'        . "\n" . $samData,
                '[FONTE: EU FUNDING & TENDERS — ec.europa.eu (EDF · Horizon Europe · Digital Europe · CEF · EIC · PESCO)]' . "\n" . $euFundingData,
            ],
            fn($v) => strlen($v) > 50
        ));

        $analysisPrompt = <<<MSG
{$userText}

Data de hoje: {$dateTo}
Prazo máximo a considerar: {$today2m} (2 meses a partir de hoje)
Portais pesquisados: Acingov · Vortal/TED · base.gov.pt · UNGM · SAM.gov · EU Funding (EDF/Horizon/Digital Europe/CEF/EIC)

═══════════════════════════════════════════
REGRAS DE FILTRAGEM — APLICAR ANTES DE TUDO
═══════════════════════════════════════════
1. EXCLUIR contratos cujo prazo já passou (deadline < {$dateTo}) — MAS APENAS se o prazo é também POSTERIOR à data de publicação. Se o prazo for ANTERIOR à publicação, é erro de dados — mostra o contrato com ⚠️ *Data inconsistente*
2. NUNCA excluir um contrato por "irrelevância" ou "fora do core business" — mostra TUDO
3. MARCAR contratos com prazo > 2 meses ({$today2m}) com 📆 *Prazo distante (> 2 meses)*
4. INCLUIR contratos sem prazo definido (N/A) — mostrar com ⚠️ Prazo desconhecido
5. ORDENAR dentro de cada fonte: prazo mais próximo PRIMEIRO (urgente → menos urgente; prazo distante no fim; sem prazo no fim)
6. Dentro do mesmo portal, contratos das Forças Armadas PT (FAP/Marinha/Exército/EMGFA) aparecem SEMPRE em primeiro
7. ⚠️ PROIBIDO: Nunca escrever "Sem concursos" se existem dados na fonte — se o Acingov trouxe 7 contratos, mostras os 7

═══════════════════════════════════════════
ESTRUTURA DO RELATÓRIO — AGRUPADO POR FONTE
═══════════════════════════════════════════

### 🇵🇹 ACINGOV — Concursos Públicos Portugal
### 🇪🇺 TED EUROPA — Concursos UE (API Oficial)
### 🌍 UNGM — UN Global Marketplace
### 🇵🇹 BASE.GOV.PT — Contratos Adjudicados (inteligência competitiva)
### 🇺🇸 SAM.GOV — US Federal Contracts
### 🇪🇺 EU FUNDING & TENDERS — Programas de Financiamento (EDF · Horizon Europe · Digital Europe · CEF · EIC · PESCO)

Se uma fonte não tiver NENHUM dado (campo vazio), escreve: *Sem dados disponíveis nesta fonte.*
Se uma fonte trouxe dados mas todos têm prazo expirado, escreve: *N contratos encontrados — todos com prazo expirado.*
NUNCA escrever "Sem concursos" se existem contratos nos dados — mostra TODOS.

Para cada concurso dentro de cada fonte:
📋 **[Título / Objeto do contrato]**
🏛️ Entidade: [nome] | ⏰ Prazo: **[dd/mm/yyyy]** ← prazo de submissão
📅 Publicado: [data] | 💶 Valor: [€ estimado ou N/A]
🏢 Subsidiária: [Marine/Military/Defense Aerospace/SETQ/ARMITE/IndYard]
🎯 [🟢Alta/🟡Média/🔴Baixa] — [justificação 1 linha] | 🔗 [Link]

⚠️ Prazo urgente (< 7 dias): adicionar emoji 🚨 no início da linha do contrato
⚠️ Para base.gov.pt: adicionar 🏆 Adjudicatário e usar como intel — quem ganhou, a que preço

PARA A SECÇÃO EU FUNDING (ec.europa.eu), formato adaptado a calls de financiamento:
🎓 **[Título da call / topic]**
🏛️ Programa: **[EDF | Horizon Europe | Digital Europe | CEF | EIC | PESCO | EU4Health]** | Topic ID: **[ex: EDF-2026-RA-CYBER-QSTN]**
⏰ Submission deadline: [dd/mm/yyyy] | 💶 Budget total: [€ M] | Co-financiamento típico: [70%-100%]
🎯 Type of action: [RA Research Action | IA Innovation Action | LS Lump Sum | CSA Coordination]
🏢 Subsidiária candidata: [Marine/Military/Defense Aerospace/SETQ/ARMITE/IndYard]
🤝 Consortium needed: [Sim — N participantes mín. de M países UE | Não — single-applicant]
🎯 [🟢Alta/🟡Média/🔴Baixa] — [match com capabilities PartYard, 1 linha] | 🔗 [Link directo ao topic-details]

═══════════════════════════════════════════
RESUMO FINAL
═══════════════════════════════════════════

### 📊 Resumo Executivo
- Total encontrado: X | 🟢 N altas · 🟡 N médias · 🔴 N baixas
- Prazo ≤ 2 meses: N | Prazo > 2 meses (📆): N | Sem prazo (⚠️): N
- Por fonte: Acingov(N) · Vortal/TED(N) · UNGM(N) · base.gov.pt(N) · SAM.gov(N) · EU Funding(N)
- Das Forças Armadas PT (FAP/Marinha/Exército/EMGFA): N contratos
- Calls EU Funding em aberto: N (EDF: X · Horizon: Y · Digital Europe: Z · outros)
- Excluídos por prazo expirado: N

### 🏆 Top 5 Oportunidades — Candidatura Imediata
(ordenadas: prazo mais curto + relevância PartYard mais alta — concursos E calls financiamento misturados)

### 🎓 Top 3 EU Funding — Análise Estratégica
Para as 3 calls EU mais relevantes:
- TRL alvo · Match com I&D PartYard · Esforço de consortium · Risco de submissão · ROI esperado

### ⚡ Próximos Passos
(acções concretas esta semana, por subsidiária, com prazo)

--- DADOS DOS 6 PORTAIS ---
{$allData}
--- FIM ---
MSG;

        $analysis = $this->streamClaudeOnce($analysisPrompt, $history, $onChunk, $heartbeat, 'Dra. Ana a analisar');
        $full .= $analysis;

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'acingov'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
