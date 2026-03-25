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

        $postedFrom = now()->subDays(5)->format('m/d/Y');
        $postedTo   = now()->format('m/d/Y');

        // All NAICS in ONE request (collectionFormat: multi)
        $naicsCodes = ['336611', '336612', '488390', '334511', '541330', '541512', '332911'];
        $params     = 'api_key=' . $apiKey
            . '&postedFrom=' . urlencode($postedFrom)
            . '&postedTo='   . urlencode($postedTo)
            . '&limit=10&offset=0'
            . implode('', array_map(fn($n) => "&ncode={$n}", $naicsCodes));

        try {
            $resp    = $this->httpClient->get('https://api.sam.gov/opportunities/v2/search?' . $params,
                ['headers' => ['Accept' => 'application/json'], 'timeout' => 5]);
            $data    = json_decode($resp->getBody()->getContents(), true);
            $allOpps = $data['opportunitiesData'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('AcingovAgent SAM.gov: ' . $e->getMessage());
            return '(SAM.gov indisponível: ' . $e->getMessage() . ')';
        }

        if (empty($allOpps)) {
            return '(SAM.gov: sem oportunidades nos últimos 5 dias para os NAICS selecionados)';
        }

        // Deduplicate by noticeId
        $seen  = [];
        $lines = ["=== SAM.GOV — US Federal Opportunities (últimos 5 dias) ==="];

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

        // ── Header ───────────────────────────────────────────────────────────
        $emit("## 📋 Relatório de Contratos Públicos — {$today}\n");
        $emit("Portais: 🇺🇸 SAM.gov · 🇵🇹 base.gov.pt · Vortal · Acingov · 🌍 UNIDO · UNGM\n\n");

        // ── Portal 1: SAM.gov ────────────────────────────────────────────────
        $emit("---\n### 🇺🇸 Portal 1/3 — SAM.gov (US Federal)\n");
        $emit("⏳ A pesquisar contratos federais americanos...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar SAM.gov');

        $samData = $this->fetchSamGov();

        if (str_starts_with($samData, '(SAM.gov')) {
            $emit("> ⚠️ {$samData}\n\n");
        } else {
            // Stream SAM raw data lines formatted
            $lines = explode("\n", $samData);
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                if (str_starts_with($line, '===')) {
                    // skip section headers, already in our header
                    continue;
                }
                // Format each opportunity line
                if (str_starts_with($line, '- ID:')) {
                    // Parse fields: ID | TITLE | DEPT | TYPE | NAICS | POSTED | DEADLINE | VALUE | URL
                    preg_match('/TITLE:([^|]+)/', $line, $t);
                    preg_match('/DEPT:([^|]+)/', $line, $d);
                    preg_match('/DEADLINE:([^|]+)/', $line, $dl);
                    preg_match('/VALUE:\$([^|]+)/', $line, $v);
                    preg_match('/URL:(\S+)/', $line, $u);
                    $title    = trim($t[1]  ?? 'N/A');
                    $dept     = trim($d[1]  ?? 'N/A');
                    $deadline = trim($dl[1] ?? 'N/A');
                    $value    = isset($v[1]) ? '$' . trim($v[1]) : '';
                    $url      = trim($u[1]  ?? '');
                    $emit("**{$title}**\n");
                    $emit("📋 {$dept}" . ($value ? " · 💶 {$value}" : '') . ($deadline !== 'N/A' ? " · ⏰ {$deadline}" : '') . "\n");
                    if ($url) $emit("🔗 {$url}\n");
                    $emit("\n");
                }
            }
        }

        $emit("✅ **SAM.gov concluído.**\n\n");

        // ── Portal 2: base.gov.pt / Vortal / Acingov ─────────────────────────
        $emit("---\n### 🇵🇹 Portal 2/3 — base.gov.pt · Vortal · Acingov\n");
        $emit("⏳ A pesquisar concursos públicos portugueses...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar base.gov.pt / Vortal');

        $euData = '';
        if ($this->searcher->isAvailable()) {
            try {
                $euData = $this->searcher->search(
                    'acingov.gov.pt OR base.gov.pt OR vortal.biz concurso naval maritimo defesa 2026', 4, 'basic'
                );
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [EU/PT]: ' . $e->getMessage());
            }
        }

        if (empty($euData) || strlen($euData) < 50) {
            $emit("> ⚠️ Sem resultados nos portais portugueses neste momento.\n\n");
        } else {
            $emit($euData . "\n\n");
        }

        $emit("✅ **Portais PT concluídos.**\n\n");

        // ── Portal 3: UNIDO / UNGM ───────────────────────────────────────────
        $emit("---\n### 🌍 Portal 3/3 — UNIDO · UNGM (UN Global Marketplace)\n");
        $emit("⏳ A pesquisar tenders internacionais...\n\n");
        if ($heartbeat) $heartbeat('a pesquisar UNIDO / UNGM');

        $unData = '';
        if ($this->searcher->isAvailable()) {
            try {
                $unData = $this->searcher->search(
                    'ungm.org OR unido.org tender maritime naval defense 2026', 4, 'basic'
                );
            } catch (\Throwable $e) {
                Log::info('AcingovAgent [UN]: ' . $e->getMessage());
            }
        }

        if (empty($unData) || strlen($unData) < 50) {
            $emit("> ⚠️ Sem resultados nos portais UN neste momento.\n\n");
        } else {
            $emit($unData . "\n\n");
        }

        $emit("✅ **Portais UN concluídos.**\n\n");

        // ── Análise Claude — 1 única chamada ─────────────────────────────────
        $emit("---\n### 🧠 Dra. Ana Contratos — Análise & Classificação\n");
        $emit("⏳ A classificar oportunidades e preparar resumo executivo...\n\n");
        if ($heartbeat) $heartbeat('Dra. Ana a classificar oportunidades');

        $allData = implode("\n\n", array_filter([$samData, $euData, $unData], fn($v) => strlen($v) > 50));

        $analysisPrompt = <<<MSG
{$userText}

Analisa os concursos abaixo e apresenta:
1. Para cada concurso relevante: 📋 Entidade | 📌 Objeto | 💶 Valor | ⏰ Prazo | 🎯 Relevância (🟢Alta/🟡Média/🔴Baixa) | 💡 Ação | 🔗 Link
2. 📊 Resumo Executivo: X altas, Y médias, Z baixas
3. 🏆 Top 3 Oportunidades mais urgentes
4. ⚡ Próximos Passos concretos

SAM.gov = contratos federais EUA (DoD, Navy, Coast Guard) — alta prioridade PartYard Military
Foca em: peças navais, motores, defesa, portos, IT/cibersegurança, NATO

--- DADOS DOS 3 PORTAIS ---
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
