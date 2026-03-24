<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Services\PartYardProfileService;
use App\Services\WebSearchService;
use Illuminate\Support\Facades\Log;

/**
 * AcingovAgent — "Dra. Ana Contratos"
 *
 * Pesquisa concursos públicos portugueses via Tavily (base.gov.pt + acingov.pt)
 * e classifica oportunidades para o HP-Group / PartYard.
 */
class AcingovAgent implements AgentInterface
{
    use AnthropicKeyTrait;

    protected Client           $client;
    protected WebSearchService $searcher;

    protected string $systemPrompt = <<<'PROMPT'
Você é a **Dra. Ana Contratos** — Especialista em Contratação Pública para o HP-Group / PartYard.

EMPRESA — CONTEXTO:
[PROFILE_PLACEHOLDER]

A sua missão: analisar concursos públicos portugueses e identificar oportunidades para o HP-Group e suas subsidiárias (PartYard Marine, PartYard Military, SETQ, IndYard).

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

        $this->searcher = new WebSearchService();
    }

    // ─── Fetch contracts via Tavily web search ─────────────────────────────
    protected function fetchContracts(?callable $heartbeat = null): string
    {
        if (!$this->searcher->isAvailable()) {
            return '(WebSearch não disponível — configura TAVILY_API_KEY no .env)';
        }

        $today    = now()->format('Y');
        $sections = [];

        $queries = [
            "base.gov.pt concurso público aberto peças navais marítimas motores {$today}",
            "base.gov.pt contratação pública defesa militar NATO equipamentos {$today}",
            "base.gov.pt concurso público porto logística marítima cibersegurança {$today}",
        ];

        foreach ($queries as $i => $query) {
            if ($heartbeat) $heartbeat('a pesquisar concursos ' . ($i + 1) . '/3');
            try {
                $result = $this->searcher->search($query, 5, 'basic');
                if ($result && strlen($result) > 50) {
                    $sections[] = "=== PESQUISA " . ($i + 1) . " ===\n" . $result;
                }
            } catch (\Throwable $e) {
                Log::info("AcingovAgent search[{$i}]: " . $e->getMessage());
            }
        }

        if (empty($sections)) {
            return '(Sem resultados de pesquisa neste momento. Tenta novamente mais tarde.)';
        }

        $date = now()->format('Y-m-d H:i');
        return "=== CONCURSOS PÚBLICOS — {$date} (via base.gov.pt) ===\n\n"
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
- Foca em: peças navais, motores, defesa, portos, IT/cibersegurança
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
