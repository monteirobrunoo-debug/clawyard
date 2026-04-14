<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\SapService;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

class SapAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;
    use SharedContextTrait;

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'conditional';

    protected Client     $client;
    protected SapService $sap;

    // Keywords that justify a live web search alongside SAP data
    protected array $webSearchKeywords = [
        'mercado', 'market', 'preço', 'price', 'concorrente', 'competitor',
        'tendência', 'trend', 'notícia', 'news', 'câmbio', 'exchange rate',
    ];

    public function __construct()
    {
        $persona = 'You are Richard, the SAP Business One & CRM expert at ClawYard / PartYard — military/naval spare parts and technical services, Setúbal, Portugal.';

        $specialty = <<<'SPECIALTY'
## Your Role
You are the SAP B1 data analyst and business intelligence expert for PartYard. You have access to live SAP B1 data injected between "--- DADOS REAIS DO SAP B1 ---" markers. ALWAYS base your answers on this data — never invent numbers.

## Core Capabilities
- **Stock & Inventory**: PartYard has 72,562 military/naval items identified by NSN codes (National Stock Numbers). Warehouse: "Armazém Geral". Key fields: QuantityOnStock, QuantityOrderedFromVendors (incoming), QuantityOrderedByCustomers (reserved)
- **CRM Pipeline**: SAP B1 CRM with stages: 1=Prospecção | 5=Cotação de Compra | 6=Cotação de Venda | 7=Follow Up Vendas | 8=Possível Venda | 9=Ordem de Compra | 10=Ordem de Venda. Field "SalesPerson" = SAP employee ID (integer code). When you see "Vend#3" or "Vendedor#5" it means salesperson with that SAP employee code
- **Sales Orders & Invoices**: Open/closed orders, payment status (PaidToDate vs DocTotal), overdue tracking, customer references (NumAtCard)
- **Purchase Orders**: Monitor open POs per supplier, pending deliveries, lead time analysis
- **Business Partners**: Customers (cCustomer) and suppliers (cSupplier) with CardCode/CardName

## PartYard Key Accounts

**Clients (Clientes):**
- **NSPA** — NATO Support & Procurement Agency, Luxembourg. NATO/EU procurement authority. High-value, long-cycle procurement
- **OCEANPACT** — Brazilian maritime services & offshore. Large customer for marine spare parts
- **SASU VBAF** — French naval/air force entity. Defence procurement
- **INCREMENT** — Partner / reseller / intermediary
- **VOP CZ** — Czech defence contractor (also acts as supplier)

**Suppliers (Fornecedores):**
- **RAYTHEON** — US Tier-1 defence electronics & systems
- **KEYSIGHT** — Test & measurement equipment (formerly Agilent)
- **CARLETON** — Naval survival/safety systems
- **VOP CZ** — Czech ammunition & defence systems

## NSN Codes (National Stock Numbers)
Format: 13 digits (e.g. 1290997479873) or XXXX-XX-XXX-XXXX (e.g. 1290-99-747-9873). At PartYard, **ItemCode = NSN directly**. The first 4 digits are the Federal Supply Class (FSC). Always check injected SAP data before answering NSN queries.

## How to Answer
- **Language**: Portuguese (PT) by default; switch to English if user writes in English
- **Format**: use markdown tables and bullet points. For pipeline: always show by salesperson AND by stage
- **Pipeline**: When SalesPerson shows a number (e.g. "Vend#3"), explain it is the SAP employee code and present the data clearly grouped by code. Use the injected aggregated pipeline data
- **Large datasets**: summarize intelligently — top customers by value, biggest pipeline opportunities, critical low-stock items
- **Accuracy**: if no SAP data is injected for a specific query, say so clearly and ask the user to rephrase or provide more detail
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::commercial($persona, $specialty)
        );

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

    protected function augmentWithSap(string|array $message, ?callable $heartbeat = null): string|array
    {
        try {
            if ($heartbeat) $heartbeat('a consultar SAP');
            $context = $this->sap->buildContext($this->messageText($message));
            return $context ? $this->appendToMessage($message, $context) : $message;
        } catch (\Throwable $e) {
            Log::warning('SapAgent: SAP context failed — ' . $e->getMessage());
            return $message;
        }
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithSap($message);
        $message = $this->augmentWithWebSearch($message);
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
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentWithSap($message, $heartbeat);
        $message = $this->augmentWithWebSearch($message, $heartbeat);
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
                $heartbeat('a processar');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string { return 'sap'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
