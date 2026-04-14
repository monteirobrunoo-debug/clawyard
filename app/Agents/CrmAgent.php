<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Services\SapService;
use App\Services\PromptLibrary;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

/**
 * CrmAgent — "Marta"
 *
 * Conversational agent for creating & managing SAP B1 Sales Opportunities.
 * Workflow:
 *   1. Gather fields from natural language (customer, stage, value, close date, salesperson)
 *   2. Validate customer against SAP BusinessPartners
 *   3. Show markdown summary + output a hidden ```json_opp{...}``` block
 *   4. When user types SIM/confirma, detect the pending block from history and call SAP POST
 *   5. Return SAP SequentialNo as confirmation
 */
class CrmAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;

    protected Client     $client;
    protected SapService $sap;

    public function __construct()
    {
        $persona = 'You are Marta, the CRM & pipeline specialist at PartYard (Setúbal, Portugal) — military/naval spare parts and technical services.';

        $specialty = <<<'SPECIALTY'
## Your Role
You are Marta, the CRM pipeline specialist at PartYard. You create **Sales Opportunities in SAP B1 CRM** by analysing customer emails and requests. You extract the data, confirm with the user, and trigger the SAP creation automatically.

## PRIMARY USE CASE — Email to Opportunity
When the user pastes a **customer email or inquiry**, you must:
1. Extract ALL relevant opportunity fields from the email text
2. Show a clean confirmation table (DO NOT ask for each field individually)
3. Emit the `json_opp` block immediately
4. Ask for a single **SIM** to create in SAP

Fields to extract from emails:
- **Customer name / company** → map to CardCode (from SAP context, or ask if not found)
- **Product / service requested** → include in Remarks
- **Quoted or estimated value** → MaxLocalTotal (if not in email, estimate or ask)
- **Requested delivery / deadline** → ExpectedClosingDate
- **CRM Stage** → infer from email tone: inquiry=Prospecção(1), price request=Cotação de Compra(5), proposal=Cotação de Venda(6), follow-up=Follow Up Vendas(7), ready to order=Possível Venda(8)
- **Salesperson / account manager** → SalesPerson (from context or ask)
- **Reference numbers, PO numbers, vessel info** → include in Remarks

## PartYard CRM Stages
| StageId | Stage | When to use |
|---------|-------|-------------|
| 1  | Prospecção | Cold lead, first contact |
| 5  | Cotação de Compra | Customer requests a purchase quote |
| 6  | Cotação de Venda | PartYard submits sales quotation |
| 7  | Follow Up Vendas | Following up on sent quotation |
| 8  | Possível Venda | Customer likely to confirm |
| 9  | Ordem de Compra | Purchase order received |
| 10 | Ordem de Venda | Sales order confirmed |

## Known PartYard Customers
If SAP context is injected with a customer's CardCode, use it. Otherwise:
- **NSPA** (NATO Support & Procurement Agency, Luxembourg)
- **OCEANPACT** (Brazilian maritime services)
- **SASU VBAF** (French naval/air entity)
- **INCREMENT** (Partner/reseller)
- **VOP CZ** (Czech defence contractor)

## Opportunity Fields Reference
| Field | Type | Notes |
|-------|------|-------|
| CardCode | string | SAP customer code — REQUIRED |
| StageId | integer | 1–10 — REQUIRED |
| MaxLocalTotal | float | Expected value EUR — REQUIRED (estimate if not in email) |
| SalesPerson | integer | SAP employee ID (0 if unknown) |
| ExpectedClosingDate | date | YYYY-MM-DD |
| Remarks | string | Email subject + key details |

## Confirmation Table Format
Always present this table before the json_opp block:

| Campo SAP | Valor Extraído |
|-----------|---------------|
| 🏢 Cliente | NSPA (CardCode: C001) |
| 📋 Fase CRM | Cotação de Compra (StageId: 5) |
| 💶 Valor Esperado | €50.000 |
| 📅 Data de Fecho | 2026-06-30 |
| 👔 Vendedor | #3 (ou Não atribuído) |
| 📝 Referência/Email | [subject or key ref] |

Then: "✅ Confirmas a criação desta oportunidade? Escreve **SIM** para criar no SAP B1."

## CRITICAL — json_opp Block (NEVER omit this)
End EVERY response where you're ready to create with:

```json_opp
{
  "CardCode": "C001",
  "CardName": "NSPA",
  "StageName": "Cotação de Compra",
  "StageId": 5,
  "MaxLocalTotal": 50000.00,
  "SalesPerson": 0,
  "ExpectedClosingDate": "2026-06-30",
  "Remarks": "Email: RFQ for MTU spare parts NSN 1290... Vessel: MV Atlantic"
}
```

Rules:
- `SalesPerson` = 0 if not known
- `MaxLocalTotal` = number only (no €, no commas)
- `ExpectedClosingDate` = YYYY-MM-DD
- `Remarks` = email subject + reference numbers + vessel name + key info (max 300 chars)
- CardCode from SAP context only — NEVER invent

## Updating Existing Opportunities
For updates, emit:

```json_opp_update
{
  "SequentialNo": 1234,
  "StageId": 7,
  "MaxLocalTotal": 60000,
  "Remarks": "Updated after call with client"
}
```

## Language
- Default: Portuguese (PT)
- Switch to English if the email or user message is in English
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

    // ─── Opportunity detection helpers ────────────────────────────────────────

    /**
     * Extract a pending opportunity JSON block from an assistant message.
     */
    protected function extractPendingOpp(string $text): ?array
    {
        if (preg_match('/```json_opp\s*(\{.*?\})\s*```/si', $text, $m)) {
            $data = json_decode(trim($m[1]), true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    /**
     * Extract a pending UPDATE block from an assistant message.
     */
    protected function extractPendingUpdate(string $text): ?array
    {
        if (preg_match('/```json_opp_update\s*(\{.*?\})\s*```/si', $text, $m)) {
            $data = json_decode(trim($m[1]), true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    /**
     * True if the user message is a confirmation ("SIM", "yes", "confirma", etc.)
     */
    protected function isConfirmation(string $message): bool
    {
        return (bool) preg_match(
            '/^\s*(sim|s|yes|y|confirma(r)?|ok|criar|cria|go|proceed|confirmo|afirmativo)\s*\.?$/i',
            trim($message)
        );
    }

    /**
     * Scan conversation history (reversed) for the most recent pending opportunity.
     * Returns the parsed JSON array or null.
     */
    protected function findPendingInHistory(array $history): ?array
    {
        foreach (array_reverse($history) as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') continue;
            $content = is_string($msg['content']) ? $msg['content'] : '';
            $opp = $this->extractPendingOpp($content);
            if ($opp) return ['type' => 'create', 'data' => $opp];
            $upd = $this->extractPendingUpdate($content);
            if ($upd) return ['type' => 'update', 'data' => $upd];
            break; // only check the most recent assistant message
        }
        return null;
    }

    // ─── SAP context augmentation ─────────────────────────────────────────────

    /**
     * If a known PartYard customer is mentioned, inject their SAP CardCode into context.
     * This lets Claude populate the json_opp block with the correct CardCode.
     */
    protected function augmentWithCustomerContext(string|array $message, ?callable $heartbeat = null): string|array
    {
        $text = $this->messageText($message);

        static $knownBPs = [
            'nspa'      => 'NSPA',
            'oceanpact' => 'OCEANPACT',
            'sasu'      => 'SASU',
            'vbaf'      => 'VBAF',
            'increment' => 'INCREMENT',
            'raytheon'  => 'RAYTHEON',
            'keysight'  => 'KEYSIGHT',
            'carleton'  => 'CARLETON',
            'vop'       => 'VOP',
        ];

        foreach ($knownBPs as $key => $name) {
            if (stripos($text, $key) === false) continue;
            try {
                if ($heartbeat) $heartbeat('a verificar cliente SAP...');
                $bps = $this->sap->searchBusinessPartners($name, 2);
                if ($bps) {
                    $lines = ["--- CLIENTE SAP ---"];
                    foreach ($bps as $bp) {
                        $lines[] = "CardCode: {$bp['CardCode']} | CardName: {$bp['CardName']} | Tipo: "
                            . ($bp['CardType'] === 'cCustomer' ? 'Cliente' : 'Fornecedor');
                    }
                    $lines[] = "--- FIM ---";
                    return $this->appendToMessage($message, "\n\n" . implode("\n", $lines) . "\n");
                }
            } catch (\Throwable $e) {
                Log::warning("CrmAgent: BP lookup failed — " . $e->getMessage());
            }
            break; // only lookup first match per message
        }
        return $message;
    }

    /**
     * Inject list of SAP sales employees if user asks about salespeople.
     */
    protected function augmentWithSalespeople(string|array $message, ?callable $heartbeat = null): string|array
    {
        $text = $this->messageText($message);
        if (!preg_match('/vendedor|sales.?person|operador|quem.*vend|comercial/i', $text)) {
            return $message;
        }
        try {
            if ($heartbeat) $heartbeat('a carregar vendedores SAP...');
            $employees = $this->sap->getSalesEmployees(15);
            if ($employees) {
                $lines = ["--- VENDEDORES SAP ---"];
                foreach ($employees as $e) {
                    $lines[] = "ID: {$e['EmployeeID']} | {$e['FirstName']} {$e['LastName']}";
                }
                $lines[] = "--- FIM ---";
                return $this->appendToMessage($message, "\n\n" . implode("\n", $lines) . "\n");
            }
        } catch (\Throwable $e) {
            Log::warning("CrmAgent: employees fetch failed — " . $e->getMessage());
        }
        return $message;
    }

    // ─── Confirmation execution ────────────────────────────────────────────────

    protected function executeConfirmation(array $pending): string
    {
        $type = $pending['type'];
        $data = $pending['data'];

        if ($type === 'create') {
            $result = $this->sap->createOpportunity($data);
            if ($result && isset($result['SequentialNo'])) {
                return "✅ **Oportunidade #{$result['SequentialNo']} criada com sucesso no SAP B1!**\n\n"
                    . "| Campo | Valor |\n|-------|-------|\n"
                    . "| 🆔 ID SAP | **#{$result['SequentialNo']}** |\n"
                    . "| 👤 Cliente | " . ($data['CardName'] ?? $data['CardCode']) . " (`" . ($data['CardCode'] ?? '?') . "`) |\n"
                    . "| 📋 Fase | " . ($data['StageName'] ?? 'StageId ' . ($data['StageId'] ?? '?')) . " |\n"
                    . "| 💶 Valor | **€" . number_format((float) ($data['MaxLocalTotal'] ?? 0), 0, '.', ',') . "** |\n"
                    . "| 📅 Fecho previsto | " . ($data['ExpectedClosingDate'] ?? '—') . " |\n"
                    . "| 👔 Vendedor | " . (($data['SalesPerson'] ?? 0) ? "#{$data['SalesPerson']}" : 'Não atribuído') . " |\n"
                    . "\n_Podes ver esta oportunidade no SAP B1 → CRM → Sales Opportunities._";
            }
            return "❌ **Erro ao criar oportunidade.**\n\n"
                . "- Verifica se o CardCode `" . ($data['CardCode'] ?? '?') . "` existe\n"
                . "- StageId `" . ($data['StageId'] ?? '?') . "` deve ser um dos: 1, 5, 6, 7, 8, 9, 10\n"
                . "- Ligação SAP B1 activa\n\n"
                . "_Descreve a oportunidade novamente para tentar outra vez._";
        }

        if ($type === 'update') {
            $seqNo = (int) ($data['SequentialNo'] ?? 0);
            if (!$seqNo) {
                return "❌ Número de oportunidade (SequentialNo) em falta. Indica o ID SAP da oportunidade a actualizar.";
            }
            $ok = $this->sap->updateOpportunity($seqNo, $data);
            if ($ok) {
                return "✅ **Oportunidade #{$seqNo} actualizada com sucesso!**\n\nCampos actualizados: " . implode(', ', array_keys(array_diff_key($data, ['SequentialNo' => 1])));
            }
            return "❌ Erro ao actualizar oportunidade #{$seqNo}. Verifica se o ID existe no SAP.";
        }

        return "❌ Operação desconhecida. Tenta descrever novamente o que queres fazer.";
    }

    // ─── Claude streaming ─────────────────────────────────────────────────────

    protected function callClaude(string|array $message, array $history, callable $onChunk, ?callable $heartbeat): string
    {
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 4096,
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
                $heartbeat('a processar...');
                $lastBeat = time();
            }
        }

        return $full;
    }

    // ─── Public interface ─────────────────────────────────────────────────────

    public function chat(string|array $message, array $history = []): string
    {
        $rawText = $this->messageText($message);

        // Confirmation flow
        if ($this->isConfirmation($rawText)) {
            $pending = $this->findPendingInHistory($history);
            if ($pending) {
                return $this->executeConfirmation($pending);
            }
        }

        // Normal flow
        $message  = $this->augmentWithCustomerContext($message);
        $message  = $this->augmentWithSalespeople($message);
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 4096,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $rawText = $this->messageText($message);

        // ── Confirmation flow ──────────────────────────────────────────────────
        if ($this->isConfirmation($rawText)) {
            $pending = $this->findPendingInHistory($history);
            if ($pending) {
                if ($heartbeat) $heartbeat('a criar oportunidade no SAP B1...');
                $result = $this->executeConfirmation($pending);
                $onChunk($result);
                return $result;
            }
        }

        // ── Normal conversation ────────────────────────────────────────────────
        $message = $this->augmentWithCustomerContext($message, $heartbeat);
        $message = $this->augmentWithSalespeople($message, $heartbeat);

        return $this->callClaude($message, $history, $onChunk, $heartbeat);
    }

    public function getName(): string  { return 'crm'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
