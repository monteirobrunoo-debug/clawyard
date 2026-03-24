<?php

namespace App\Agents;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

/**
 * AcingovAgent — "Dra. Ana Contratos"
 *
 * Acede ao portal de contratação pública Acingov, lista concursos públicos
 * abertos e classifica oportunidades para o HP-Group / PartYard.
 */
class AcingovAgent implements AgentInterface
{
    use AnthropicKeyTrait;

    protected Client    $client;
    protected Client    $httpClient;
    protected CookieJar $cookies;

    protected string $loginUrl    = 'https://www.acingov.pt/acingovprod/2/login_c/loginAcingov';
    protected string $baseUrl     = 'https://www.acingov.pt/acingovprod/2/index.php';
    protected string $username;
    protected string $password;

    protected string $systemPrompt = <<<'PROMPT'
Você é a **Dra. Ana Contratos** — Especialista em Contratação Pública para o HP-Group / PartYard.

EMPRESA — CONTEXTO:
[PROFILE_PLACEHOLDER]

A sua missão: analisar concursos públicos do portal Acingov e identificar oportunidades concretas para o HP-Group e suas subsidiárias (PartYard Marine, PartYard Military, SETQ, IndYard).

CRITÉRIOS DE CLASSIFICAÇÃO DE OPORTUNIDADES:

🟢 ALTA PRIORIDADE — Candidatura imediata:
- Peças sobressalentes navais / marítimas (motores MTU, Caterpillar, MAK, Jenbacher)
- Manutenção de frotas marítimas e equipamentos portuários
- Fornecimento de peças para Marinha Portuguesa / autoridades portuárias
- Contratos de defesa / NATO / equipamentos militares
- Sistemas de propulsão naval (Schottel, SKF SternTube)
- Cybersegurança e IT para organismos públicos (SETQ)

🟡 MÉDIA PRIORIDADE — Avaliar com parceiro:
- Logística e supply chain para infraestruturas portuárias
- Manutenção de geradores e motores de grande porte
- Equipamentos industriais (rolamentos, vedantes, componentes mecânicos)
- Serviços de engenharia e consultoria técnica
- Fornecimento de peças para ferroviário / aviação (áreas adjacentes)

🔴 BAIXA RELEVÂNCIA — Monitorizar apenas:
- Obras de construção civil
- Serviços de limpeza e segurança
- IT genérico (sem componente naval/defesa)
- Alimentação e outros serviços de apoio

FORMAT DE RESPOSTA:
Para cada concurso encontrado, apresenta:
- 📋 **Entidade**: quem lançou o concurso
- 📌 **Objeto**: descrição do que se pretende contratar
- 💶 **Valor Base**: valor estimado do contrato
- ⏰ **Prazo**: data limite de submissão de proposta
- 🎯 **Relevância PartYard**: Alta / Média / Baixa + justificação
- 💡 **Ação Recomendada**: candidatar / avaliar parceria / monitorizar / ignorar
- 🔗 **Link**: URL directo no Acingov

No final, apresenta:
- 📊 **Resumo Executivo**: X oportunidades altas, Y médias, Z baixas
- 🏆 **Top 3 Oportunidades**: as 3 mais urgentes com deadline mais próximo
- ⚡ **Próximos Passos**: acções concretas para as oportunidades altas

REGRAS:
- Fundamenta SEMPRE a classificação nos produtos/serviços reais do HP-Group
- Alerta para prazos urgentes (< 7 dias)
- Identifica se a PartYard Defense pode responder a concursos de defesa
- Responde sempre em Português
PROMPT;

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->username = config('services.acingov.username', '');
        $this->password = config('services.acingov.password', '');

        $this->cookies = new CookieJar();

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->httpClient = new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => false,
            'cookies'         => $this->cookies,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pt-PT,pt;q=0.9,en;q=0.8',
            ],
            'allow_redirects' => true,
        ]);
    }

    // ─── Login no Acingov ──────────────────────────────────────────────────
    protected function login(): bool
    {
        if (!$this->username || !$this->password) {
            Log::warning('AcingovAgent: credenciais não configuradas (ACINGOV_USERNAME / ACINGOV_PASSWORD)');
            return false;
        }

        try {
            // Load home page first to get any CSRF tokens / session cookie
            $this->httpClient->get($this->baseUrl);

            // POST login (AJAX endpoint)
            $response = $this->httpClient->post($this->loginUrl, [
                'form_params' => [
                    'username_login' => $this->username,
                    'password_login' => $this->password,
                ],
                'headers' => [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer'          => $this->baseUrl,
                    'Content-Type'     => 'application/x-www-form-urlencoded',
                    'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                ],
            ]);

            $body = $response->getBody()->getContents();
            $json = json_decode($body, true);

            // Check for successful login
            if (isset($json['success']) && $json['success']) return true;
            if (isset($json['redirect'])) return true;
            if (str_contains($body, 'logout') || str_contains($body, 'bem-vindo') || str_contains($body, 'welcome')) return true;

            Log::warning('AcingovAgent: login response — ' . substr($body, 0, 300));
            return false;
        } catch (\Throwable $e) {
            Log::warning('AcingovAgent: login failed — ' . $e->getMessage());
            return false;
        }
    }

    // ─── Fetch contracts list ──────────────────────────────────────────────
    protected function fetchContracts(): string
    {
        if (!$this->login()) {
            return '(Erro: login no Acingov falhou. Verifica ACINGOV_USERNAME e ACINGOV_PASSWORD no .env do servidor.)';
        }

        $contractsData = '';

        // Try multiple possible URL patterns for contracts/procedures
        $urls = [
            $this->baseUrl . '/procedimentos',
            $this->baseUrl . '/concursos',
            $this->baseUrl . '/anuncios',
            $this->baseUrl . '/base/consulta_contratos',
            'https://www.acingov.pt/acingovprod/2/index.php/procedimentos/listar',
            'https://www.acingov.pt/acingovprod/2/index.php/anuncio/listar',
        ];

        foreach ($urls as $url) {
            try {
                $response = $this->httpClient->get($url, [
                    'headers' => ['Referer' => $this->baseUrl],
                ]);
                $html = $response->getBody()->getContents();

                if (strlen($html) > 500 && !str_contains($html, 'login') && !str_contains($html, 'acesso negado')) {
                    $contractsData = $this->parseContractsHtml($html, $url);
                    if ($contractsData && strlen($contractsData) > 100) break;
                }
            } catch (\Throwable $e) {
                Log::info("AcingovAgent: tried {$url} — " . $e->getMessage());
                continue;
            }
        }

        if (!$contractsData) {
            // Try the portal search for open tenders
            try {
                $response = $this->httpClient->post(
                    'https://www.acingov.pt/acingovprod/2/index.php/anuncio/pesquisar',
                    [
                        'form_params' => [
                            'estado'   => 'aberto',
                            'tipo'     => '',
                            'entidade' => '',
                        ],
                        'headers' => [
                            'X-Requested-With' => 'XMLHttpRequest',
                            'Referer'          => $this->baseUrl,
                        ],
                    ]
                );
                $html = $response->getBody()->getContents();
                $contractsData = $this->parseContractsHtml($html, 'pesquisa');
            } catch (\Throwable $e) {
                Log::warning('AcingovAgent: search request failed — ' . $e->getMessage());
            }
        }

        return $contractsData ?: '(Não foi possível obter a lista de contratos. O Acingov pode ter alterado a estrutura das páginas ou exigir autenticação adicional.)';
    }

    // ─── Parse HTML → structured text ─────────────────────────────────────
    protected function parseContractsHtml(string $html, string $source): string
    {
        // Remove scripts, styles, comments
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html);
        $html = preg_replace('/<!--[\s\S]*?-->/', '', $html);

        // Extract table rows or list items that look like contracts
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (strlen($text) < 100) return '';

        // Return first 8000 chars of meaningful content
        return "=== DADOS DE {$source} ===\n" . substr($text, 0, 8000);
    }

    // ─── Build message with live contracts data ────────────────────────────
    protected function buildContractsMessage(string|array $userMessage): string
    {
        $contracts = $this->fetchContracts();
        $today     = now()->format('Y-m-d');

        $user = is_array($userMessage)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $userMessage))
            : $userMessage;

        return <<<MSG
{$user}

--- DADOS ACINGOV FETCHED TODAY ({$today}) ---

{$contracts}

--- END DATA ---

Por favor analisa todos os concursos/contratos acima e classifica cada um por relevância para o HP-Group / PartYard.
REGRAS:
- Usa APENAS os dados reais acima — não inventes concursos
- Para cada concurso identifica: entidade, objeto, valor, prazo, relevância, ação recomendada
- Foca especialmente em: peças navais, manutenção de motores, defesa, portos, IT/cybersegurança
- Se os dados não contiverem concursos claros, explica o que foi encontrado e sugere próximos passos
MSG;
    }

    // ─── Detect if message is a contracts request ──────────────────────────
    protected function isContractsRequest(string|array $message): bool
    {
        $text = is_array($message)
            ? implode(' ', array_map(fn($c) => $c['text'] ?? '', $message))
            : $message;

        $keywords = [
            'acingov', 'concurso', 'concursos', 'contrato', 'contratos',
            'ajuste directo', 'ajuste direto', 'procedimento', 'procedimentos',
            'base.gov', 'contratação pública', 'licitação', 'proposta',
            'concurso público', 'lista', 'listar', 'ver', 'mostrar', 'fetch',
            'hoje', 'hoje', 'novos', 'abertos', 'oportunidades',
        ];

        $lower = strtolower($text);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return true; // default: always fetch contracts for this agent
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
        if ($heartbeat) $heartbeat('a fazer login no Acingov');
        $finalMessage = $this->buildContractsMessage($message);
        if ($heartbeat) $heartbeat('a analisar concursos');

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
                $heartbeat('a classificar concursos');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string  { return 'acingov'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
