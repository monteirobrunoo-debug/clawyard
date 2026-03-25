<?php

namespace App\Agents;

use GuzzleHttp\Client;
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

        // Keyword searches — broad enough to return results across all relevant areas.
        // We don't filter by NAICS here; Claude classifies by area afterwards.
        // Try multiple keyword groups and merge results.
        $keywordGroups = [
            'marine OR naval OR ship OR vessel OR maritime OR propulsion OR coast guard',
            'defense OR military OR army OR navy OR NATO OR weapon OR ammunition',
            'engine OR motor OR spare parts OR maintenance OR repair OR overhaul',
            'cybersecurity OR IT services OR network OR software OR technology',
            'logistics OR supply chain OR transportation OR warehouse OR parts',
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
                    . '&limit=10&offset=0';

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

        $lines = ["=== SAM.GOV — US Federal Opportunities (últimos {$usedDays} dias) ==="];

        foreach ($allOpps as $opp) {
            $id = $opp['noticeId'] ?? $opp['solicitationNumber'] ?? '';
            if ($id && isset($seen[$id])) continue;
            if ($id) $seen[$id] = true;

            $title    = $opp['title']                ?? 'N/A';
            $dept     = $opp['departmentName']       ?? ($opp['organizationHierarchy'][0]['name'] ?? 'N/A');
            $type     = $opp['type']                 ?? 'N/A';
            $naics    = $opp['naicsCode']            ?? 'N/A';
            $deadline = $opp['responseDeadLine']     ?? ($opp['archiveDate'] ?? 'N/A');
            $posted   = $opp['postedDate']           ?? 'N/A';
            $value    = $opp['award']['amount']      ?? '';
            $link     = $opp['uiLink']               ?? ($id ? "https://sam.gov/opp/{$id}/view" : '');

            $lines[] = "- ID:{$id} | TITLE:{$title} | DEPT:{$dept} | TYPE:{$type} | NAICS:{$naics} | POSTED:{$posted} | DEADLINE:{$deadline}" . ($value ? " | VALUE:\${$value}" : '') . " | URL:{$link}";
        }

        return implode("\n", $lines);
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

        // Portal 1: Acingov
        // Nota: site: operator não funciona em portais fechados. Usamos queries naturais
        // que o Tavily consegue indexar (notícias, relatórios, agregadores de contratos PT).
        $emit("  `1/5` 🇵🇹 Acingov...\n");
        if ($heartbeat) $heartbeat('a pesquisar Acingov');
        $acingovData = '';
        if ($this->searcher->isAvailable()) {
            try {
                // Tenta com vários ângulos para maximizar resultados
                $acingovData = $this->searcher->search(
                    'acingov concurso publico portugal naval maritimo defesa pecas sobressalentes 2026',
                    5, 'basic', $tavilyDays
                );
                if (strlen($acingovData) < 80) {
                    $acingovData = $this->searcher->search(
                        'acingov.gov.pt concurso ajuste direto consulta prévia naval marinha 2026',
                        5, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [Acingov]: ' . $e->getMessage());
            }
        }

        // Portal 2: Vortal
        $emit("  `2/5` 🇵🇹 Vortal...\n");
        if ($heartbeat) $heartbeat('a pesquisar Vortal');
        $vortalData = '';
        if ($this->searcher->isAvailable()) {
            try {
                $vortalData = $this->searcher->search(
                    'vortal concurso publico portugal naval maritimo defesa equipamento 2026',
                    5, 'basic', $tavilyDays
                );
                if (strlen($vortalData) < 80) {
                    $vortalData = $this->searcher->search(
                        'vortal.biz tender procurement portugal maritime defense spare parts',
                        5, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [Vortal]: ' . $e->getMessage());
            }
        }

        // Portal 3: UNGM
        $emit("  `3/5` 🌍 UNGM...\n");
        if ($heartbeat) $heartbeat('a pesquisar UNGM');
        $ungmData = '';
        if ($this->searcher->isAvailable()) {
            try {
                $ungmData = $this->searcher->search(
                    'ungm.org tender maritime naval spare parts defense 2026',
                    5, 'basic', $tavilyDays
                );
                if (strlen($ungmData) < 80) {
                    $ungmData = $this->searcher->search(
                        'UN Global Marketplace tender maritime naval equipment defense procurement 2026',
                        5, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [UNGM]: ' . $e->getMessage());
            }
        }

        // Portal 4: base.gov.pt
        $emit("  `4/5` 🇵🇹 base.gov.pt (adjudicados)...\n");
        if ($heartbeat) $heartbeat('a pesquisar base.gov.pt');
        $baseGovData = '';
        if ($this->searcher->isAvailable()) {
            try {
                $baseGovData = $this->searcher->search(
                    'base.gov.pt contratos adjudicados naval maritimo defesa manutenção motores 2026',
                    5, 'basic', $tavilyDays
                );
                if (strlen($baseGovData) < 80) {
                    $baseGovData = $this->searcher->search(
                        'base.gov.pt adjudicação contrato marinha portuguesa porto peças sobressalentes',
                        5, 'basic', $tavilyDays
                    );
                }
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [base.gov.pt]: ' . $e->getMessage());
            }
        }

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
                '[ACINGOV]' . $acingovData,
                '[VORTAL]' . $vortalData,
                '[UNGM]' . $ungmData,
                '[BASE.GOV.PT - Adjudicados]' . $baseGovData,
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
- INCLUI APENAS contratos dos últimos 5 dias ({$dateFrom}–{$dateTo})
- Se não houver contratos numa área, omite essa secção
- Usa SEMPRE os links reais dos dados fornecidos
- SAM.gov = alta prioridade PartYard Military (DoD, Navy, Coast Guard)
- base.gov.pt = inteligência competitiva — quem ganhou, a que preço

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
