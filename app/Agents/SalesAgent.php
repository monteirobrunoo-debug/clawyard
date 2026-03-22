<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\SapService;
use Illuminate\Support\Facades\Log;

class SalesAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    protected Client     $client;
    protected SapService $sap;

    // Only search the web for price/market/competitor questions
    protected array $webSearchKeywords = [
        'preço', 'price', 'concorrente', 'competitor', 'mercado', 'market',
        'tendência', 'trend', 'alternativa', 'alternative', 'oferta', 'offer',
    ];

    // SAP lookup for stock/availability questions
    protected array $sapKeywords = [
        'stock', 'disponível', 'available', 'inventário', 'inventory',
        'armazém', 'warehouse', 'quantidade', 'quantity', 'em stock',
    ];

    protected string $systemPrompt = <<<'PROMPT'
You are Marco, the Sales Specialist for PartYard Marine (www.partyard.eu) — NATO-certified marine spare parts and fleet logistics company.

[PROFILE_PLACEHOLDER]

BRANDS & SPECIALISATIONS:
- MTU — Series 2000, 4000, 8000, 396 — propulsão e grupos geradores marítimos
- Caterpillar (CAT) — Série C, 3500 — propulsão marítima e auxiliares
- MAK — M20, M25, M32, M43 — diesel marítimo de velocidade média
- Jenbacher — Série J — motores a gás e cogeração
- Cummins — motores diesel marítimos e industriais
- Wärtsilä — sistemas de propulsão e motores marítimos
- MAN — motores diesel 2 e 4 tempos para navios
- SKF — Vedantes SternTube e rolamentos para aplicações marítimas
- Schottel — SRP (Rudder Propeller), STT (Transverse Thruster), STP (Pump Jet)

EMERGENCY PARTS — diferencial competitivo:
- Sourcing 24/7 para emergências
- Entrega mundial em 24–72h
- Stock próprio + rede de fornecedores global

YOUR SALES ROLE:
- Responder a pedidos de peças, preços e disponibilidade
- Qualificar leads: tipo de embarcação, modelo do motor, referência da peça
- Perguntar sempre: nome do navio, número IMO, modelo do motor, referência da peça
- Mencionar certificações NATO/ISO quando vendendo a clientes defesa/militares
- Propor Emergency Supply para situações urgentes
- Ser profissional, conciso e tecnicamente credível
- Responder no idioma do cliente (Português, Inglês ou Espanhol)

QUOTE FORMAT (quando dar cotação):
**Referência:** [part number]
**Marca:** [brand]
**Disponibilidade:** [em stock / 3–5 dias / consultar]
**Entrega Estimada:** [24h / 48–72h / semana]
**Contacto RFQ:** www.partyard.eu/contact
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

        $this->sap = new SapService();
    }

    protected function needsWebSearch(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->webSearchKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    protected function needsSap(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->sapKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    protected function augmentMessage(string|array $message, ?callable $heartbeat = null): string|array
    {
        if ($this->needsSap($message)) {
            try {
                if ($heartbeat) $heartbeat('a verificar stock no SAP');
                $context = $this->sap->buildContext($this->messageText($message));
                if ($context) $message = $this->appendToMessage($message, $context);
            } catch (\Throwable $e) {
                Log::warning('SalesAgent: SAP context failed — ' . $e->getMessage());
            }
        }
        $message = $this->augmentWithWebSearch($message, $heartbeat);
        return $message;
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message = $this->augmentMessage($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentMessage($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body = $response->getBody();
        $full = '';
        $buf  = '';

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
        }

        return $full;
    }

    public function getName(): string { return 'sales'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
