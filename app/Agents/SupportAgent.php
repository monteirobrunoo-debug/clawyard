<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\ShippingSkillTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\SapService;
use Illuminate\Support\Facades\Log;

class SupportAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use ShippingSkillTrait;
    protected string $contextKey  = 'support_intel';
    protected array  $contextTags = ['avaria','diagnóstico','motor','reparação','suporte','falha','MTU','CAT','técnico'];
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';
    protected Client     $client;
    protected SapService $sap;

    // Only web-search for specific fault codes or bulletins
    protected array $webSearchKeywords = [
        'fault code', 'código de avaria', 'error code', 'bulletin', 'boletim',
        'service manual', 'technical notice', 'tsb', 'recall', 'upgrade',
    ];

    // SAP lookup to check if recommended part is in stock
    protected array $sapKeywords = [
        'peça', 'part', 'referência', 'reference', 'stock', 'disponível',
        'available', 'encomenda', 'order', 'substituição', 'replacement',
    ];

    public function __construct()
    {
        $persona = 'You are Marcus, the Technical Support Specialist at PartYard Marine (www.partyard.eu) — marine spare parts and engineering services, Setúbal, Portugal.';

        $specialty = <<<'SPECIALTY'
TECHNICAL EXPERTISE BY BRAND:
- MTU — Series 2000, 4000, 8000, 396 — propulsão marítima e grupos geradores; fault codes, ECU, overhaul intervals
- Caterpillar (CAT) — C series, 3500 series — marítimo e auxiliar; CAT ET diagnostics, governor systems
- MAK — M20, M25, M32, M43 — diesel médio-velocidade; fuel injection, timing, bearing clearances
- Jenbacher — Série J — motores a gás; lambda control, ignition systems, cogeneration
- Cummins — QSMC, QSB, QSK — marine series; Insite diagnostics, fault codes
- Wärtsilä — RT-flex, W20, W46 — common rail, camshaft, turbo diagnostics
- SKF — SternTube seals (type 395, 460): shaft alignment, seal replacement, bearing pre-load
- Schottel — SRP, STT, STP: oil pressure, blade pitch, feedback system troubleshooting

DIAGNOSTIC PROTOCOL:
1. Pedir: modelo/série do motor, horas de operação, códigos de avaria, sintomas
2. Verificar: últimas manutenções, consumo de óleo/combustível, temperatura de escape
3. Diagnosticar: causa raiz com referência a especificações técnicas correctas
4. Recomendar: peça específica com referência OEM + procedimento de substituição
5. Escalar: quando necessário visita de engenheiro de campo

YOUR SUPPORT ROLE:
- Diagnose and troubleshoot with precision
- Reference correct torque specs, clearances, maintenance intervals
- Suggest the correct OEM spare part reference when applicable
- Be precise, calm and technically accurate
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::technical($persona, $specialty)
        );

        // Every customer-facing agent gets the UPS shipping skill so it can
        // give cost estimates when asked — see app/Services/ShippingRateService.
        $this->systemPrompt .= $this->shippingSkillPromptBlock();

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
                Log::warning('SupportAgent: SAP context failed — ' . $e->getMessage());
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data   = json_decode($response->getBody()->getContents(), true);
        $result = $data['content'][0]['text'] ?? '';
        $this->publishSharedContext($result);
        return $result;
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
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

        $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string { return 'support'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
