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

    // ─── Fetch SAM.gov federal contracts (US DoD / NATO aligned) ──────────
    protected function fetchSamGov(?callable $heartbeat = null): string
    {
        $apiKey = config('services.samgov.api_key');
        if (!$apiKey) return '(SAM.gov: configura SAM_GOV_API_KEY no .env)';

        if ($heartbeat) $heartbeat('a pesquisar SAM.gov (US Federal)');

        // NAICS codes relevant to PartYard/HP-Group:
        // 336611 Ship Building & Repairing
        // 336612 Boat Building
        // 488390 Other Support Activities for Water Transportation
        // 334511 Search, Detection, Navigation, Guidance Instruments (Defense)
        // 541330 Engineering Services
        // 541512 Computer Systems Design (SETQ)
        // 332911 Industrial Valves/Parts
        $naicsCodes = ['336611', '336612', '488390', '334511', '541330', '541512', '332911'];

        $postedFrom = now()->subDays(5)->format('m/d/Y');
        $postedTo   = now()->format('m/d/Y');

        $allOpps = [];

        foreach ($naicsCodes as $ncode) {
            try {
                $url = 'https://api.sam.gov/opportunities/v2/search?' . http_build_query([
                    'api_key'    => $apiKey,
                    'ncode'      => $ncode,
                    'postedFrom' => $postedFrom,
                    'postedTo'   => $postedTo,
                    'limit'      => '5',
                    'offset'     => '0',
                ]);

                $resp = $this->httpClient->get($url, ['headers' => ['Accept' => 'application/json']]);
                $data = json_decode($resp->getBody()->getContents(), true);
                $opps = $data['opportunitiesData'] ?? [];

                foreach ($opps as $opp) {
                    $allOpps[] = $opp;
                }
            } catch (\Throwable $e) {
                Log::info("AcingovAgent SAM.gov [{$ncode}]: " . $e->getMessage());
            }
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

        // 2. EU/UN portals via Tavily
        if ($this->searcher->isAvailable()) {
            $portals = [
                'base.gov.pt' => 'base.gov.pt concurso aberto naval motor defesa porto 2026',
                'Vortal'      => 'vortal.biz tender naval marine propulsion defense 2026',
                'UNIDO'       => 'procurement.unido.org tender maritime naval industrial 2026',
                'UNGM'        => 'ungm.org tender maritime naval defense propulsion 2026',
            ];
            $total = count($portals);
            $i     = 1;
            foreach ($portals as $label => $query) {
                if ($heartbeat) $heartbeat("a pesquisar {$label} ({$i}/{$total})");
                try {
                    $result = $this->searcher->search($query, 5, 'basic');
                    if ($result && strlen($result) > 50) {
                        $sections[] = "=== {$label} ===\n" . $result;
                    }
                } catch (\Throwable $e) {
                    Log::info("AcingovAgent [{$label}]: " . $e->getMessage());
                }
                $i++;
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

    // ─── stream() ──────────────────────────────────────────────────────────
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $finalMessage = $this->buildContractsMessage($message, $heartbeat);
        if ($heartbeat) $heartbeat('a classificar oportunidades PartYard');

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $finalMessage],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($finalMessage),
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
            if ($heartbeat && (time() - $lastBeat) >= 10) {
                $heartbeat('a analisar concursos');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string  { return 'acingov'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
