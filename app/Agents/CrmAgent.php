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
 * Conversational agent for creating SAP B1 Sales Opportunities from pasted emails.
 *
 * Workflow:
 *   1. User pastes a customer email
 *   2. Marta extracts all fields automatically (subject→name, VAT→BP, contact person, days to close…)
 *   3. Marta asks for SalesPerson by name if not found in email
 *   4. Shows confirmation table with all SAP fields
 *   5. User types SIM → system calls SAP POST /SalesOpportunities
 *   6. Returns SequentialNo confirmation
 */
class CrmAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;

    protected Client     $client;
    protected SapService $sap;

    public function __construct()
    {
        $persona = 'You are Marta, the CRM & Sales Pipeline specialist at PartYard (Setúbal, Portugal) — military and naval spare parts and technical services.';

        $specialty = <<<'SPECIALTY'
## Your Role
You create **SAP B1 Sales Opportunities** from customer emails pasted by the user. You extract all required fields from the email automatically, validate the customer (BP) via SAP data injected into context, and ask for anything that's missing — especially the **Vendedor (Sales Employee)** by name.

## PRIMARY FLOW — Email → Opportunity
When the user pastes an email or inquiry:

### Step 1 — Extract these fields from the email:
| Field | How to extract | SAP Field |
|-------|----------------|-----------|
| **Customer name** | Email sender company, signature, domain | CardCode (from SAP context) |
| **VAT / NIF** | Look for "NIF:", "VAT:", "PT\d{9}", tax numbers | FederalTaxID (for BP validation) |
| **Contact person** | Email sender name ("From: John Smith") | ContactPerson (CntctCode from SAP) |
| **Opportunity name** | Email Subject line → use as title | OpportunityName |
| **CRM Stage** | Infer from email tone (see table below) | StageId |
| **Closing days** | Urgency phrases, deadlines, delivery dates | ClosingDays (integer) |
| **Potential Amount** | Any value mentioned; default = **1** | MaxLocalTotal (default 1) |
| **Notes** | Reference numbers, vessel name, PO, NSN | Remarks |

### Step 2 — Always ask for:
- **Vendedor (Sales Employee)**: "📋 Qual o vendedor responsável? (ex: João Silva)" — ALWAYS ask if not in email or SAP context

### Step 3 — After getting salesperson name, show confirmation table:
| Campo SAP | Valor |
|-----------|-------|
| 🏢 Cliente (CardCode) | NSPA (C001) |
| 🆔 NIF / VAT | PT123456789 |
| 👤 Pessoa de Contacto | John Smith (código SAP: 3) |
| 👔 Vendedor | João Silva (EmployeeID: 5) |
| 📌 Nome Oportunidade | [email subject] |
| 📋 Fase CRM | Cotação de Compra (StageId: 5) |
| ✅ Status | Em Aberto (O) |
| 💶 Valor Potencial | €1 (actualizar após proposta) |
| 📅 Fecho previsto | em 30 dias (2026-05-14) |
| 📝 Notas | [vessel, ref, PO…] |

Then: "✅ Confirmas a criação? Escreve **SIM** para criar no SAP B1."

## CRM Stage Inference
| Email tone | StageId | Stage |
|------------|---------|-------|
| First contact, cold outreach | 1 | Prospecção |
| Client requests price / RFQ | 5 | Cotação de Compra |
| PartYard submits quotation | 6 | Cotação de Venda |
| Follow-up on sent quote | 7 | Follow Up Vendas |
| Client near decision | 8 | Possível Venda |
| PO received | 9 | Ordem de Compra |
| Order confirmed | 10 | Ordem de Venda |

## Closing Days Guidelines
- "urgent" / "ASAP" / "urgente" → 7 days
- "end of week" → 5 days
- "end of month" / "fim do mês" → 30 days
- "Q2" / "próximo trimestre" → 60 days
- Specific date → calculate days from today
- No deadline mentioned → ask "Em quantos dias prevês fechar esta oportunidade?"

## Known PartYard Customers
If SAP context is injected with CardCode, use it directly. Otherwise:
- NSPA, OCEANPACT, SASU VBAF, INCREMENT, VOP CZ

## CRITICAL — json_opp Block
At the END of your confirmation response, you MUST include this block (even if some fields are 0 or null):

```json_opp
{
  "CardCode": "C001",
  "CardName": "NSPA",
  "FederalTaxID": "PT123456789",
  "ContactPerson": 3,
  "ContactPersonName": "John Smith",
  "SalesPerson": 5,
  "SalesEmployeeName": "João Silva",
  "OpportunityName": "RFQ MTU 2000 Series Spare Parts",
  "StageId": 5,
  "StageName": "Cotação de Compra",
  "Status": "O",
  "MaxLocalTotal": 1,
  "ClosingDays": 30,
  "ExpectedClosingDate": "2026-05-14",
  "Remarks": "From: john@nspa.nato.int | RFQ MTU 2000 | Vessel: MV Atlantic | PO: 2026-0123"
}
```

Rules:
- `Status` is ALWAYS "O" (Em Aberto)
- `MaxLocalTotal` defaults to **1** (not 0, not null)
- `ClosingDays` is the number of days from today until expected close
- `SalesPerson` = SAP EmployeeID (from context). If 0 = not assigned
- `ContactPerson` = SAP CntctCode (from context). If 0 = not found
- `OpportunityName` = email Subject (trim to 100 chars)
- `Remarks` = "From: [sender] | [key refs] | Vessel: [name] | PO: [ref]" (max 250 chars)
- NEVER invent CardCode or employee IDs — use only what's in SAP context

## Updating Opportunities
For updates, ask for the SequentialNo, then emit:

```json_opp_update
{
  "SequentialNo": 1234,
  "StageId": 7,
  "MaxLocalTotal": 50000,
  "Remarks": "Updated after call"
}
```

## Language
- Default: Portuguese (PT)
- Switch to English if email/user writes in English
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

    // ─── Opportunity detection helpers ───────────────────────────────────────

    protected function extractPendingOpp(string $text): ?array
    {
        if (preg_match('/```json_opp\s*(\{.*?\})\s*```/si', $text, $m)) {
            $data = json_decode(trim($m[1]), true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    protected function extractPendingUpdate(string $text): ?array
    {
        if (preg_match('/```json_opp_update\s*(\{.*?\})\s*```/si', $text, $m)) {
            $data = json_decode(trim($m[1]), true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    protected function isConfirmation(string $message): bool
    {
        return (bool) preg_match(
            '/^\s*(sim|s|yes|y|confirma(r)?|ok|criar|cria|go|proceed|confirmo|afirmativo)\s*\.?$/i',
            trim($message)
        );
    }

    protected function findPendingInHistory(array $history): ?array
    {
        foreach (array_reverse($history) as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') continue;
            $content = is_string($msg['content']) ? $msg['content'] : '';
            $opp = $this->extractPendingOpp($content);
            if ($opp) return ['type' => 'create', 'data' => $opp];
            $upd = $this->extractPendingUpdate($content);
            if ($upd) return ['type' => 'update', 'data' => $upd];
            break;
        }
        return null;
    }

    // ─── SAP context augmentation ────────────────────────────────────────────

    /**
     * Build CRM context for the message.
     *
     * PERFORMANCE RULE: Only runs SAP calls when a session is already cached
     * (isSessionActive). This prevents hanging 30-second timeouts when SAP is
     * not yet logged-in. Claude handles the conversation without SAP context
     * in that case and asks the user for the needed fields directly.
     */
    protected function augmentWithCrmContext(string|array $message, ?callable $heartbeat = null): string|array
    {
        $text  = $this->messageText($message);
        $extra = '';

        // Skip all SAP calls if no cached session — avoids blocking on login/timeouts
        if (!$this->sap->isSessionActive()) {
            return $message;
        }

        // ── 1. Customer by VAT/NIF ────────────────────────────────────────────
        if (preg_match('/\b(PT\d{9})\b/', $text, $vatMatch)) {
            try {
                if ($heartbeat) $heartbeat('a verificar NIF no SAP...');
                $bps = $this->sap->searchBPByVAT($vatMatch[1], 2);
                if ($bps) {
                    $extra .= "\n--- CLIENTE POR NIF ({$vatMatch[1]}) ---\n";
                    foreach ($bps as $bp) {
                        $extra .= "CardCode: {$bp['CardCode']} | CardName: {$bp['CardName']}"
                            . (!empty($bp['FederalTaxID']) ? " | NIF: {$bp['FederalTaxID']}" : '')
                            . " | Tipo: " . ($bp['CardType'] === 'cCustomer' ? 'Cliente' : 'Fornecedor') . "\n";
                    }
                    $extra .= "--- FIM ---\n";

                    // Fetch contact persons for first BP found
                    $cardCode = $bps[0]['CardCode'];
                    $contacts = $this->sap->getContactPersons($cardCode, 10);
                    if ($contacts) {
                        $extra .= "\n--- CONTACTOS DE {$bps[0]['CardName']} ({$cardCode}) ---\n";
                        foreach ($contacts as $c) {
                            $cname = trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? '')) ?: ($c['Name'] ?? '?');
                            $extra .= "CntctCode: {$c['CntctCode']} | {$cname} | {$c['E_Mail']}\n";
                        }
                        $extra .= "--- FIM ---\n";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("CrmAgent: VAT lookup failed — " . $e->getMessage());
            }
        }

        // ── 2. Customer by known name (only if VAT lookup found nothing) ───────
        if ($extra === '') {
            static $knownBPs = [
                'nspa' => 'NSPA', 'oceanpact' => 'OCEANPACT', 'sasu' => 'SASU',
                'vbaf' => 'VBAF', 'increment' => 'INCREMENT', 'raytheon' => 'RAYTHEON',
                'keysight' => 'KEYSIGHT', 'carleton' => 'CARLETON', 'vop' => 'VOP',
            ];
            foreach ($knownBPs as $key => $name) {
                if (stripos($text, $key) === false) continue;
                try {
                    if ($heartbeat) $heartbeat('a verificar cliente SAP...');
                    $bps = $this->sap->searchBusinessPartners($name, 1);
                    if ($bps) {
                        $bp = $bps[0];
                        $extra .= "\n--- CLIENTE SAP ---\n";
                        $extra .= "CardCode: {$bp['CardCode']} | CardName: {$bp['CardName']}"
                            . (!empty($bp['FederalTaxID']) ? " | NIF: {$bp['FederalTaxID']}" : '')
                            . " | Tipo: " . ($bp['CardType'] === 'cCustomer' ? 'Cliente' : 'Fornecedor') . "\n";
                        $extra .= "--- FIM ---\n";

                        // Contact persons
                        $contacts = $this->sap->getContactPersons($bp['CardCode'], 10);
                        if ($contacts) {
                            $extra .= "\n--- CONTACTOS DE {$bp['CardName']} ---\n";
                            foreach ($contacts as $c) {
                                $cname = trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? '')) ?: ($c['Name'] ?? '?');
                                $extra .= "CntctCode: {$c['CntctCode']} | {$cname} | {$c['E_Mail']}\n";
                            }
                            $extra .= "--- FIM ---\n";
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("CrmAgent: BP lookup failed — " . $e->getMessage());
                }
                break; // only one BP per message
            }
        }

        // ── 3. Sales employee lookup by name ──────────────────────────────────
        // Triggered when user provides "vendedor: João Silva" or similar
        if (preg_match('/vendedor[:\s]+([A-Za-zÀ-ú]{2,}(?:\s[A-Za-zÀ-ú]{2,})+)/i', $text, $empMatch)) {
            $empName = trim($empMatch[1]);
            try {
                if ($heartbeat) $heartbeat('a localizar vendedor no SAP...');
                $employees = $this->sap->searchSalesEmployee($empName, 5);
                if ($employees) {
                    $extra .= "\n--- VENDEDOR SAP: {$empName} ---\n";
                    foreach ($employees as $e) {
                        $extra .= "EmployeeID: {$e['EmployeeID']} | {$e['FirstName']} {$e['LastName']}\n";
                    }
                    $extra .= "--- FIM ---\n";
                }
            } catch (\Throwable $e) {
                Log::warning("CrmAgent: employee search failed — " . $e->getMessage());
            }
        }

        // ── 4. Full salespeople list — only on explicit request and no match yet ──
        $hasEmployeeContext = strpos($extra, 'EmployeeID') !== false;
        if (!$hasEmployeeContext && preg_match('/lista.*vendedor|vendedores disponíveis|quais.*vendedor/i', $text)) {
            try {
                if ($heartbeat) $heartbeat('a carregar vendedores SAP...');
                $employees = $this->sap->getSalesEmployees(20);
                if ($employees) {
                    $extra .= "\n--- VENDEDORES SAP ---\n";
                    foreach ($employees as $e) {
                        $extra .= "EmployeeID: {$e['EmployeeID']} | {$e['FirstName']} {$e['LastName']}\n";
                    }
                    $extra .= "--- FIM ---\n";
                }
            } catch (\Throwable $e) {
                Log::warning("CrmAgent: employees list failed — " . $e->getMessage());
            }
        }

        return $extra !== '' ? $this->appendToMessage($message, "\n" . $extra) : $message;
    }

    // ─── Confirmation execution ───────────────────────────────────────────────

    protected function executeConfirmation(array $pending): string
    {
        $type = $pending['type'];
        $data = $pending['data'];

        if ($type === 'create') {
            $result = $this->sap->createOpportunity($data);

            if ($result && isset($result['SequentialNo'])) {
                $closingDate = !empty($data['ExpectedClosingDate'])
                    ? $data['ExpectedClosingDate']
                    : (!empty($data['ClosingDays']) ? date('Y-m-d', strtotime('+' . (int)$data['ClosingDays'] . ' days')) : '—');

                return "✅ **Oportunidade #{$result['SequentialNo']} criada no SAP B1!**\n\n"
                    . "| Campo | Valor |\n|-------|-------|\n"
                    . "| 🆔 ID SAP | **#{$result['SequentialNo']}** |\n"
                    . "| 📌 Nome | " . ($data['OpportunityName'] ?? '—') . " |\n"
                    . "| 🏢 Cliente | " . ($data['CardName'] ?? '') . " (`" . ($data['CardCode'] ?? '?') . "`) |\n"
                    . (!empty($data['FederalTaxID']) ? "| 🆔 NIF/VAT | {$data['FederalTaxID']} |\n" : '')
                    . "| 👤 Contacto | " . ($data['ContactPersonName'] ?? (($data['ContactPerson'] ?? 0) ? "#{$data['ContactPerson']}" : '—')) . " |\n"
                    . "| 👔 Vendedor | " . ($data['SalesEmployeeName'] ?? (($data['SalesPerson'] ?? 0) ? "#{$data['SalesPerson']}" : '—')) . " |\n"
                    . "| 📋 Fase | " . ($data['StageName'] ?? 'StageId ' . ($data['StageId'] ?? '?')) . " |\n"
                    . "| ✅ Status | Em Aberto |\n"
                    . "| 💶 Valor Potencial | €" . number_format((float)($data['MaxLocalTotal'] ?? 1), 0, '.', ',') . " |\n"
                    . "| 📅 Fecho previsto | {$closingDate}" . (!empty($data['ClosingDays']) ? " (" . $data['ClosingDays'] . " dias)" : '') . " |\n"
                    . "\n_Acede ao SAP B1 → CRM → Sales Opportunities para ver a oportunidade._";
            }

            return "❌ **Erro ao criar oportunidade no SAP B1.**\n\n"
                . "Verificar:\n"
                . "- CardCode `" . ($data['CardCode'] ?? '?') . "` existe no SAP\n"
                . "- StageId `" . ($data['StageId'] ?? '?') . "` válido (1, 5–10)\n"
                . "- Ligação SAP B1 activa\n\n"
                . "_Descreve novamente para tentar outra vez._";
        }

        if ($type === 'update') {
            $seqNo = (int) ($data['SequentialNo'] ?? 0);
            if (!$seqNo) return "❌ SequentialNo em falta. Indica o ID SAP da oportunidade.";
            $ok = $this->sap->updateOpportunity($seqNo, $data);
            if ($ok) {
                $changed = implode(', ', array_keys(array_diff_key($data, ['SequentialNo' => 1])));
                return "✅ **Oportunidade #{$seqNo} actualizada!**\nCampos: {$changed}";
            }
            return "❌ Erro ao actualizar oportunidade #{$seqNo}. Verifica se o ID existe no SAP.";
        }

        return "❌ Operação desconhecida.";
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

        if ($this->isConfirmation($rawText)) {
            $pending = $this->findPendingInHistory($history);
            if ($pending) return $this->executeConfirmation($pending);
        }

        $message  = $this->augmentWithCrmContext($message);
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

        // ── Confirmation flow ─────────────────────────────────────────────────
        if ($this->isConfirmation($rawText)) {
            $pending = $this->findPendingInHistory($history);
            if ($pending) {
                if ($heartbeat) $heartbeat('a criar oportunidade no SAP B1...');
                $result = $this->executeConfirmation($pending);
                $onChunk($result);
                return $result;
            }
        }

        // ── Normal conversation ───────────────────────────────────────────────
        $message = $this->augmentWithCrmContext($message, $heartbeat);
        return $this->callClaude($message, $history, $onChunk, $heartbeat);
    }

    public function getName(): string  { return 'crm'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
