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

A sua missão: analisar concursos públicos de 6 portais (base.gov.pt, Acingov, Vortal, UNIDO, UNGM e **SAM.gov** — contratos federais dos EUA) e identificar oportunidades para o HP-Group e todas as suas subsidiárias.

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
### 🇪🇺 VORTAL / TED EUROPA
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

REGRAS:
- Usa APENAS dados reais — nunca inventes concursos
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

            // Step 3: Search by defense/military keywords — collect up to 20 unique results
            $keywords = [
                // Defesa & Forças Armadas
                'defesa', 'militar', 'marinha', 'força aérea', 'exército', 'NATO', 'armamento',
                // Aviação & Aeronáutica
                'aeronave', 'aviação', 'helicóptero', 'aeronáutica', 'MRO',
                // Naval & Marítimo
                'navio', 'naval', 'propulsão', 'embarcação',
                // Motores & Peças
                'motor', 'peças', 'sobressalentes', 'manutenção', 'revisão geral', 'overhaul',
                // Equipamentos Médicos
                'equipamento médico', 'médico', 'hospitalar', 'dispositivos médicos',
                // Destruição de Documentos & Segurança
                'destruição de documentos', 'destruidoras', 'fragmentação', 'trituração',
                // Simulação & IT
                'simulação', 'simulador', 'cibersegurança', 'tecnologia',
                // Lubrificantes & Químicos
                'lubrificantes', 'óleos', 'fluidos',
            ];
            $seen  = [];
            $lines = [];

            $kwRequests = 0;
            foreach ($keywords as $kw) {
                if (count($lines) >= 20 || $kwRequests >= 8) break; // max 8 HTTP requests
                $kwRequests++;
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

            // If keywords returned nothing, fall back to latest 20 unfiltered
            if (empty($lines)) {
                try {
                    $resp = $client->get($baseUrl . 'procedimentos_fornecedor/procedimentos_fornecedor_c');
                    $html = $resp->getBody()->getContents();
                    if (stripos($html, 'name="user"') === false) {
                        $lines = $this->parseAcingovTable($html, $seen, true);
                    }
                } catch (\Throwable $e) {
                    Log::info('Acingov [auth/fallback]: ' . $e->getMessage());
                }
            }

            $lines = array_slice($lines, 0, 20);
            if (empty($lines)) return '';
            return "=== ACINGOV — Concursos (autenticado, " . now()->format('d/m/Y') . ") ===\n" . implode("\n", $lines);

        } catch (\Throwable $e) {
            Log::info('Acingov [login]: ' . $e->getMessage());
            return '';
        }
    }

    protected function fetchAcingovPublic(): string
    {
        $baseUrl  = 'https://www.acingov.pt/acingovprod/2/zonaPublica/zona_publica_c/indexProcedimentos';
        $keywords = [
            // Defesa & Forças Armadas
            'defesa', 'militar', 'marinha', 'força aérea', 'exército', 'NATO', 'armamento',
            // Aviação & Aeronáutica
            'aeronave', 'aviação', 'helicóptero', 'aeronáutica', 'MRO',
            // Naval & Marítimo
            'navio', 'naval', 'propulsão', 'embarcação',
            // Motores & Peças
            'motor', 'peças', 'sobressalentes', 'manutenção', 'revisão geral', 'overhaul',
            // Equipamentos Médicos
            'equipamento médico', 'médico', 'hospitalar', 'dispositivos médicos',
            // Destruição de Documentos & Segurança
            'destruição de documentos', 'destruidoras', 'fragmentação', 'trituração',
            // Simulação & IT
            'simulação', 'simulador', 'cibersegurança', 'tecnologia',
            // Lubrificantes & Químicos
            'lubrificantes', 'óleos', 'fluidos',
        ];
        $seen  = [];
        $lines = [];

        $kwRequests = 0;
        foreach ($keywords as $kw) {
            if (count($lines) >= 20 || $kwRequests >= 8) break; // max 8 HTTP requests
            $kwRequests++;
            try {
                $resp = $this->httpClient->get($baseUrl, [
                    'query'   => ['procedure_search' => $kw],
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)', 'Accept' => 'text/html'],
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

        // Fallback: latest 20 unfiltered if keywords returned nothing
        if (empty($lines)) {
            try {
                $resp  = $this->httpClient->get($baseUrl, [
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; HP-Group/1.0)', 'Accept' => 'text/html'],
                    'timeout' => 10, 'verify' => false,
                ]);
                $lines = $this->parseAcingovTable($resp->getBody()->getContents(), $seen, false);
            } catch (\Throwable $e) {
                Log::info('Acingov [public/fallback]: ' . $e->getMessage());
            }
        }

        $lines = array_slice($lines, 0, 20);
        if (empty($lines)) return '';
        return "=== ACINGOV — Concursos (zona pública, " . now()->format('d/m/Y') . ") ===\n" . implode("\n", $lines);
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

    // ─── base.gov.pt — direct public API (awarded contracts) ──────────────
    protected function fetchBaseGovPt(): string
    {
        $dateFrom = now()->subDays(30)->format('d-m-Y'); // 30 days — adjudicados têm latência
        $dateTo   = now()->format('d-m-Y');

        // Multiple keyword passes — all PartYard business areas
        $keywords = [
            // ⚓ Naval & Marítimo
            'naval', 'marítimo', 'maritimo', 'marinha', 'propulsão naval', 'porto',
            // 🛩️ Aviação & Aeronáutica
            'aeronave', 'aviação', 'aeronáutica', 'manutenção aeronáutica', 'helicóptero', 'aeroporto',
            // 🚗 Defesa & Militar
            'defesa', 'militar', 'armamento', 'viaturas militares', 'forças armadas',
            // 🔧 Motores & Peças
            'motor diesel', 'peças sobressalentes', 'manutenção motores',
            // 🎯 Simulação & Treino
            'simulação', 'simulador', 'treino militar',
            // 💻 Cibersegurança (SETQ)
            'cibersegurança', 'segurança informática',
        ];
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
                    'ted.europa.eu OR vortal tender Portugal 2026 naval maritime aviation defense spare parts MRO procurement',
                    8, 'basic', $tavilyDays
                );
                if (strlen($vortalData) < 80) {
                    $vortalData = $this->searcher->search(
                        'TED tenders Portugal 2026 naval defesa aviação aeronave equipamento maritimo',
                        8, 'basic', $tavilyDays
                    );
                }
                if (strlen($vortalData) < 80) {
                    $vortalData = $this->searcher->search(
                        'European tender 2026 maritime naval aircraft MRO spare parts Portugal defense NATO procurement',
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
        $emit("  `4/5` 🇵🇹 base.gov.pt (adjudicados)...\n");
        if ($heartbeat) $heartbeat('a pesquisar base.gov.pt');
        $baseGovData = $this->fetchBaseGovPt();

        // Portal 5: SAM.gov
        $emit("  `5/5` 🇺🇸 SAM.gov...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar SAM.gov');
        $samData = $this->fetchSamGov();

        $emit("✅ **Recolha concluída. A filtrar e ordenar por prazo...**\n\n");

        // ── Análise Claude — agrupado por portal, filtrado por prazo ──────────
        $emit("---\n### 🧠 Dra. Ana Contratos — Relatório por Fonte\n\n");
        if ($heartbeat) $heartbeat('Dra. Ana a filtrar por prazo');

        $today2m = now()->addMonths(2)->format('d/m/Y');

        $allData = implode("\n\n", array_filter(
            [
                '[FONTE: ACINGOV — Concursos Públicos Portugal]' . "\n" . $acingovData,
                '[FONTE: VORTAL / TED Europa — Concursos UE]'    . "\n" . $vortalData,
                '[FONTE: UNGM — UN Global Marketplace]'          . "\n" . $ungmData,
                '[FONTE: BASE.GOV.PT — Contratos Adjudicados PT]'. "\n" . $baseGovData,
                '[FONTE: SAM.GOV — US Federal Contracts]'        . "\n" . $samData,
            ],
            fn($v) => strlen($v) > 50
        ));

        $analysisPrompt = <<<MSG
{$userText}

Data de hoje: {$dateTo}
Prazo máximo a considerar: {$today2m} (2 meses a partir de hoje)
Portais pesquisados: Acingov · Vortal/TED · base.gov.pt · UNGM · SAM.gov

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
### 🇪🇺 VORTAL / TED EUROPA — Concursos UE
### 🌍 UNGM — UN Global Marketplace
### 🇵🇹 BASE.GOV.PT — Contratos Adjudicados (inteligência competitiva)
### 🇺🇸 SAM.GOV — US Federal Contracts

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

═══════════════════════════════════════════
RESUMO FINAL
═══════════════════════════════════════════

### 📊 Resumo Executivo
- Total encontrado: X | 🟢 N altas · 🟡 N médias · 🔴 N baixas
- Prazo ≤ 2 meses: N | Prazo > 2 meses (📆): N | Sem prazo (⚠️): N
- Por fonte: Acingov(N) · Vortal/TED(N) · UNGM(N) · base.gov.pt(N) · SAM.gov(N)
- Das Forças Armadas PT (FAP/Marinha/Exército/EMGFA): N contratos
- Excluídos por prazo expirado: N

### 🏆 Top 5 Oportunidades — Candidatura Imediata
(ordenadas: prazo mais curto + relevância PartYard mais alta)

### ⚡ Próximos Passos
(acções concretas esta semana, por subsidiária, com prazo)

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
