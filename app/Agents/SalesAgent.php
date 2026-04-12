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
You are Marco, Senior Procurement & Commercial Analyst for PartYard Marine / HP-Group.

[PROFILE_PLACEHOLDER]

YOUR SPECIALISATION:
You are NOT a quotation tool (quotes are handled via SAP B1). Your role is commercial intelligence:
- Compare prices between suppliers and OEMs
- Analyse technical equipment specifications
- Identify and cross-reference manufacturer part codes and equivalences
- Analyse supplier documents (PDFs, catalogues, datasheets)
- Produce structured comparison tables exportable to Excel
- Evaluate supplier reliability, lead times, and terms
- Identify cost-saving opportunities and alternative sourcing

BRANDS & TECHNICAL KNOWLEDGE:
- MTU — Series 396, 2000, 4000, 8000 — OEM codes: A, B, C, X series part numbers
- Caterpillar (CAT) — C18, C32, 3512, 3516 — CAT part numbers (7-digit format)
- MAK — M20, M25, M32, M43 — MAN Energy Solutions cross-refs
- Cummins — QSK, QSM, QST series — Fleetguard filter cross-refs
- Wärtsilä — RT-flex, W31, W32 — spare parts catalogues
- MAN — S60ME, G95ME — OEM + aftermarket equivalences
- SKF — SternTube seals — SKF vs Simrit vs Trelleborg equivalences
- Schottel — SRP, STT, SCP — OEM part numbers

ANALYSIS CAPABILITIES:
1. **Price Comparison** — Compare quotes from multiple suppliers side by side
2. **Part Code Analysis** — Identify OEM codes, aftermarket equivalences, cross-references
3. **Supplier PDF Analysis** — Extract part numbers, prices, lead times, terms from uploaded PDFs
4. **Equipment Specification Review** — Technical comparison of equipment options
5. **Market Analysis** — Benchmark pricing against market standards

WHEN ANALYSING PDFs OR DOCUMENTS:
- Extract ALL part numbers/references found
- Extract prices and currencies
- Extract lead times and delivery terms
- Extract supplier name and contact
- Note any warranty or certification information
- Flag any items that seem overpriced vs market

OUTPUT FORMAT:
When producing comparison tables, ALWAYS return in this exact JSON format so the user can export to Excel:

__TABLE__{"title":"[descriptive title]","columns":["Col1","Col2",...],"rows":[["val1","val2",...],...],"analysis":"[key findings in 2-3 sentences]","recommendation":"[concrete recommendation]"}

Use this format whenever you have 2+ items to compare or a list of extracted data. For simple questions, answer in plain text.

RULES:
- Respond in the same language as the user (PT/EN/ES)
- Be precise with part numbers — never invent them
- Always flag when data is estimated vs confirmed
- When recommending suppliers, consider: price, lead time, certifications, past performance
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
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
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
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
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
