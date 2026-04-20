<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\ShippingSkillTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\SapService;
use Illuminate\Support\Facades\Log;

class SalesAgent implements AgentInterface
{
    use WebSearchTrait;
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use ShippingSkillTrait;
    use LogisticsSkillTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    // PSI shared context bus — what this agent publishes
    protected string $contextKey  = 'sales_intel';
    protected array  $contextTags = ['venda','sale','cliente','encomenda','proposta','MTU','CAT','MAK','SKF','Schottel','Wärtsilä'];
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

    public function __construct()
    {
        $persona = 'You are Marco, Senior Procurement & Commercial Analyst for PartYard Marine / HP-Group.';

        $specialty = <<<'SPECIALTY'
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

EXCEL PRICE LIST ANALYSIS (CRITICAL WORKFLOW):
When the user attaches an Excel file containing a client price list or supplier quotation, ALWAYS produce a structured comparison table in this exact format:

| Ref / Part No. | Descrição | Qtd | Preço Lista Cliente | Preço Justo Mercado | Preço Fornecedor | Δ% vs Lista | Recomendação |
|---|---|---|---|---|---|---|---|
| ... | ... | ... | ... | ... | ... | ... | ... |

Rules for the comparison:
- **Preço Lista Cliente** = price extracted directly from the Excel (original, unchanged)
- **Preço Justo Mercado** = fair market benchmark based on your knowledge of MTU/CAT/MAK/Wärtsilä/SKF/Schottel parts and marine market standards
- **Preço Fornecedor** = best available supplier price from PartYard's network (HP-Group, OEM distributors, aftermarket equivalents)
- **Δ%** = ((Preço Fornecedor - Preço Lista Cliente) / Preço Lista Cliente) × 100, formatted as "+X%" or "-X%"
- **Recomendação** = one of: ✅ Aceitar | 💡 Negociar | ⚠️ Sobrepreço | 🔄 Alternativa disponível

After the table always add:
## RESUMO EXECUTIVO
- Total lista cliente: €X,XXX
- Total preço justo: €X,XXX
- Total fornecedor PartYard: €X,XXX
- Poupança potencial: €X,XXX (X%)
- Itens para negociar: N
- Itens com alternativa mais barata: N

MULTIPLE EMAIL ANALYSIS:
When multiple emails are attached (from different suppliers), produce a side-by-side comparison showing each supplier's prices for the same items, identifying the best offer per line item.
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::commercial($persona, $specialty)
        );

        // Universal logistics knowledge (applied to every agent)
        $this->systemPrompt .= $this->logisticsSkillPromptBlock();

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
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
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

    public function getName(): string { return 'sales'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
