<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SapService
{
    protected Client $http;
    protected string $baseUrl;
    protected string $company;
    protected string $username;
    protected string $password;
    protected string $lastError = '';   // last SAP API error, surfaced to agents

    const SESSION_CACHE_KEY  = 'sap_b1_session';
    const SESSION_TTL        = 25; // minutes (SAP default = 30 min)
    const SESSION_FAILED_KEY = 'sap_b1_login_failed';
    const SESSION_FAILED_TTL = 10; // minutes — negative cache to avoid multi-login hangs

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.sap.base_url', 'https://sld.partyard.privatcloud.biz/b1s/v1'), '/');
        $this->company  = config('services.sap.company',  'PARTYARD');
        $this->username = trim(config('services.sap.username', ''), '"\'');
        $this->password = trim(config('services.sap.password', ''), '"\'');

        $this->http = new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => false,
        ]);
    }

    // ─── Authentication ────────────────────────────────────────────────────────

    /**
     * Login to SAP B1 Service Layer.
     * NOTE: SAP B1 behind this reverse proxy returns the SessionId in the
     * response BODY (not in a B1SESSION cookie). We send it as B1SESSION
     * cookie on all subsequent requests.
     */
    public function login(): bool
    {
        try {
            $response = $this->http->post("{$this->baseUrl}/Login", [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'CompanyDB' => $this->company,
                    'UserName'  => $this->username,
                    'Password'  => $this->password,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // SAP returns SessionId in the body
            $sessionId = $data['SessionId'] ?? null;

            if ($sessionId) {
                Cache::put(self::SESSION_CACHE_KEY, $sessionId, now()->addMinutes(self::SESSION_TTL));
                Cache::forget(self::SESSION_FAILED_KEY); // clear negative cache on success
                return true;
            }

            Log::warning('SAP Login: no SessionId in response body', (array) $data);
            return false;

        } catch (\Exception $e) {
            Log::error('SAP Login failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensure we have a valid session. Login if needed.
     * Negative-caches failures for SESSION_FAILED_TTL minutes to prevent
     * repeated login attempts (each 30 s) when SAP is unreachable.
     */
    protected function ensureSession(): ?string
    {
        // Short-circuit: recent login failure — skip to avoid hanging
        if (Cache::has(self::SESSION_FAILED_KEY)) return null;

        $session = Cache::get(self::SESSION_CACHE_KEY);
        if (!$session) {
            $ok = $this->login();
            if (!$ok) {
                Cache::put(self::SESSION_FAILED_KEY, 1, now()->addMinutes(self::SESSION_FAILED_TTL));
                return null;
            }
            $session = Cache::get(self::SESSION_CACHE_KEY);
        }
        return $session ?: null;
    }

    /**
     * True when a valid SAP session is already cached (no login attempt needed).
     * Used by CrmAgent to skip SAP augmentation when SAP is not reachable/logged-in.
     */
    public function isSessionActive(): bool
    {
        if (Cache::has(self::SESSION_FAILED_KEY)) return false;
        return (bool) Cache::get(self::SESSION_CACHE_KEY);
    }

    /**
     * Test SAP connection and return a human-readable status string.
     * Also clears any negative cache so the next real request retries.
     */
    public function testConnection(): array
    {
        // Always retry fresh — clear negative cache before testing
        Cache::forget(self::SESSION_FAILED_KEY);
        Cache::forget(self::SESSION_CACHE_KEY);

        if (!$this->username || !$this->password) {
            return [
                'ok'      => false,
                'status'  => 'credentials_missing',
                'message' => 'SAP_B1_USER ou SAP_B1_PASSWORD não configurados no .env do servidor.',
            ];
        }

        try {
            // http_errors=false → Guzzle never throws on 4xx/5xx;
            // we read the response body ourselves to get the SAP error message.
            $response = $this->http->post("{$this->baseUrl}/Login", [
                'http_errors' => false,
                'headers'     => ['Content-Type' => 'application/json'],
                'json'        => [
                    'CompanyDB' => $this->company,
                    'UserName'  => $this->username,
                    'Password'  => $this->password,
                ],
            ]);

            $httpCode = $response->getStatusCode();
            $body     = (string) $response->getBody()->getContents();
            $data     = (array) (json_decode($body, true) ?? []);
            $session  = $data['SessionId'] ?? null;

            if ($session) {
                Cache::put(self::SESSION_CACHE_KEY, $session, now()->addMinutes(self::SESSION_TTL));
                return [
                    'ok'      => true,
                    'status'  => 'connected',
                    'message' => "✅ Ligação ao SAP B1 bem-sucedida. Empresa: {$this->company} | URL: {$this->baseUrl}",
                ];
            }

            // HTTP 503 = servidor SAP inacessível / em manutenção
            if ($httpCode === 503) {
                return [
                    'ok'      => false,
                    'status'  => 'unreachable',
                    'message' => "❌ SAP B1 Service Layer inacessível (HTTP 503 — servidor em baixo ou em manutenção).",
                    'hint'    => "Verifica se o servidor SAP ({$this->baseUrl}) está activo. Se usas VPN, confirma que está ligada.",
                ];
            }

            // HTTP 401/403 = credenciais erradas
            if (in_array($httpCode, [401, 403])) {
                $errMsg = $data['error']['message']['value'] ?? ($data['message'] ?? "Credenciais inválidas");
                return [
                    'ok'      => false,
                    'status'  => 'login_failed',
                    'message' => "❌ Login SAP recusado (HTTP {$httpCode}): {$errMsg}",
                    'hint'    => 'A password pode ter mudado. Actualiza SAP_B1_PASSWORD no .env do servidor.',
                ];
            }

            // Outro erro HTTP — mostra body raw para diagnóstico
            $errMsg = $data['error']['message']['value'] ?? ($data['message'] ?? null);
            $rawPreview = substr(strip_tags($body), 0, 300);
            return [
                'ok'      => false,
                'status'  => 'login_failed',
                'message' => "❌ Login SAP falhou (HTTP {$httpCode})" . ($errMsg ? ": {$errMsg}" : ''),
                'hint'    => "URL: {$this->baseUrl} | Empresa: {$this->company} | User: {$this->username}" . ($rawPreview ? " | Resposta: {$rawPreview}" : ''),
            ];

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return [
                'ok'      => false,
                'status'  => 'unreachable',
                'message' => "❌ SAP B1 inacessível — não foi possível estabelecer ligação TCP.",
                'hint'    => "Verifica se {$this->baseUrl} está acessível (firewall / VPN / DNS).",
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'status'  => 'error',
                'message' => "❌ Erro ao ligar ao SAP: " . $e->getMessage(),
                'hint'    => "URL configurada: {$this->baseUrl}",
            ];
        }
    }

    /**
     * Make an authenticated GET request.
     * Sends the SessionId as the B1SESSION cookie header.
     * Re-logins once on 401 (session expired).
     */
    protected function get(string $endpoint, array $query = [], bool $retry = true): ?array
    {
        $session = $this->ensureSession();
        if (!$session) return null;

        try {
            $url = "{$this->baseUrl}/{$endpoint}";
            if ($query) {
                $url .= '?' . http_build_query($query);
            }

            $response = $this->http->get($url, [
                'headers' => [
                    'Cookie'       => "B1SESSION={$session}",
                    'Content-Type' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 401 && $retry) {
                // Session expired — re-login once
                Cache::forget(self::SESSION_CACHE_KEY);
                $this->login();
                return $this->get($endpoint, $query, false);
            }
            Log::error('SAP GET failed: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            Log::error('SAP GET exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Make an authenticated POST request (create/insert records).
     * Mirrors the retry logic of get().
     */
    protected function post(string $endpoint, array $payload, bool $retry = true): ?array
    {
        $session = $this->ensureSession();
        if (!$session) {
            $this->lastError = 'Sem sessão SAP B1 activa — credenciais inválidas ou servidor SAP inacessível.';
            Log::warning("SAP POST [{$endpoint}]: no session available");
            return null;
        }

        try {
            // http_errors=false → never throws on 4xx/5xx; we read status manually
            $response = $this->http->post("{$this->baseUrl}/{$endpoint}", [
                'http_errors' => false,
                'headers'     => [
                    'Cookie'       => "B1SESSION={$session}",
                    'Content-Type' => 'application/json',
                    'Prefer'       => 'return=representation',
                ],
                'json' => $payload,
            ]);

            $status  = $response->getStatusCode();
            $body    = (string) $response->getBody();
            $decoded = $body !== '' ? json_decode($body, true) : null;

            // Session expired → re-login once
            if ($status === 401 && $retry) {
                Cache::forget(self::SESSION_CACHE_KEY);
                $this->login();
                return $this->post($endpoint, $payload, false);
            }

            // 2xx = success
            if ($status >= 200 && $status < 300) {
                // Return the entity if SAP sent one; otherwise a success marker
                // (SAP B1 sometimes returns 201 with empty body when Prefer is ignored)
                return (is_array($decoded) && !empty($decoded))
                    ? $decoded
                    : ['__created' => true];
            }

            // 4xx / 5xx — parse SAP error and store in $lastError
            $sapMsg = $decoded['error']['message']['value']
                   ?? $decoded['error']['message']
                   ?? $decoded['message']
                   ?? null;
            if (!$sapMsg) {
                $sapMsg = substr(strip_tags($body), 0, 300) ?: "HTTP {$status}";
            }
            $this->lastError = is_string($sapMsg) ? trim($sapMsg) : json_encode($sapMsg);
            Log::error("SAP POST failed [{$endpoint}] HTTP {$status}: {$this->lastError} | payload: " . json_encode($payload));
            return null;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("SAP POST exception [{$endpoint}]: " . $e->getMessage());
            return null;
        }
    }

    /** Returns the last SAP API error message (cleared on each createOpportunity call). */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Make an authenticated PATCH request (update existing records).
     * SAP B1 returns 204 No Content on success — we return ['ok' => true].
     */
    protected function patch(string $endpoint, array $payload, bool $retry = true): ?array
    {
        $session = $this->ensureSession();
        if (!$session) return null;

        try {
            $response = $this->http->patch("{$this->baseUrl}/{$endpoint}", [
                'http_errors' => false,
                'headers'     => [
                    'Cookie'       => "B1SESSION={$session}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            $code = $response->getStatusCode();

            if ($code === 401 && $retry) {
                Cache::forget(self::SESSION_CACHE_KEY);
                $this->login();
                return $this->patch($endpoint, $payload, false);
            }

            if ($code === 204 || $code === 200) return ['ok' => true];

            $body = (string) $response->getBody();
            Log::warning("SAP PATCH [{$endpoint}] HTTP {$code}: " . substr($body, 0, 300));
            return null;

        } catch (\Exception $e) {
            Log::error("SAP PATCH exception [{$endpoint}]: " . $e->getMessage());
            return null;
        }
    }

    // ─── Stock & Items ─────────────────────────────────────────────────────────

    public function searchItems(string $query, int $top = 10): array
    {
        $q    = addslashes($query);
        $data = $this->get('Items', [
            '$select' => 'ItemCode,ItemName,QuantityOnStock,QuantityOrderedFromVendors,QuantityOrderedByCustomers',
            '$filter' => "startswith(ItemCode,'{$q}') or contains(ItemName,'{$q}')",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }

    public function getItemStock(string $itemCode): ?array
    {
        return $this->get("Items('{$itemCode}')", [
            '$select' => 'ItemCode,ItemName,QuantityOnStock,QuantityOrderedFromVendors,QuantityOrderedByCustomers',
        ]) ?: null;
    }

    public function getLowStockItems(int $top = 20): array
    {
        $data = $this->get('Items', [
            '$select'  => 'ItemCode,ItemName,QuantityOnStock,MinInventory',
            '$filter'  => "QuantityOnStock le MinInventory and InventoryItem eq 'tYES'",
            '$top'     => $top,
            '$orderby' => 'QuantityOnStock asc',
        ]);
        return $data['value'] ?? [];
    }

    // ─── Orders & Invoices ─────────────────────────────────────────────────────

    public function getOpenSalesOrders(int $top = 10): array
    {
        $data = $this->get('Orders', [
            '$select'  => 'DocNum,CardCode,CardName,DocDate,DocDueDate,DocTotal,DocumentStatus',
            '$filter'  => "DocumentStatus eq 'bost_Open'",
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ]);
        return $data['value'] ?? [];
    }

    public function getRecentInvoices(int $top = 10): array
    {
        $data = $this->get('Invoices', [
            '$select'  => 'DocNum,CardCode,CardName,DocDate,DocDueDate,DocTotal,DocumentStatus',
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ]);
        return $data['value'] ?? [];
    }

    public function getOpenPurchaseOrders(int $top = 10): array
    {
        $data = $this->get('PurchaseOrders', [
            '$select'  => 'DocNum,CardCode,CardName,DocDate,DocDueDate,DocTotal,DocumentStatus',
            '$filter'  => "DocumentStatus eq 'bost_Open'",
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ]);
        return $data['value'] ?? [];
    }

    // ─── Business Partners ─────────────────────────────────────────────────────

    public function searchBusinessPartners(string $name, int $top = 5): array
    {
        $data = $this->get('BusinessPartners', [
            '$select' => 'CardCode,CardName,CardType,Phone1,EmailAddress,CreditLimit',
            '$filter' => "contains(CardName,'" . addslashes($name) . "')",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }

    public function getBusinessPartner(string $cardCode): ?array
    {
        return $this->get("BusinessPartners('{$cardCode}')") ?: null;
    }

    // ─── NSN / Military Part Number ────────────────────────────────────────────

    /**
     * Search PartYard's 72,562 military items by NSN code.
     * NSN format: 13 digits (e.g. 1290997479873) or XXXX-XX-XXX-XXXX.
     * In PartYard SAP, ItemCode = NSN directly.
     */
    public function searchByNSN(string $nsn, int $top = 5): array
    {
        $clean = preg_replace('/[^0-9]/', '', $nsn);
        $data  = $this->get('Items', [
            '$select' => 'ItemCode,ItemName,QuantityOnStock,QuantityOrderedFromVendors,QuantityOrderedByCustomers,Mainsupplier,SupplierCatalogNo,Manufacturer',
            '$filter' => "startswith(ItemCode,'{$clean}') or contains(ItemCode,'{$clean}')",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }

    // ─── Customer / Supplier specific queries ──────────────────────────────────

    public function getOrdersByCustomer(string $cardCode, int $top = 10): array
    {
        $safe = addslashes($cardCode);
        $data = $this->get('Orders', [
            '$select'  => 'DocNum,CardCode,CardName,DocDate,DocDueDate,DocTotal,DocCurrency,DocumentStatus,NumAtCard',
            '$filter'  => "CardCode eq '{$safe}' and DocumentStatus eq 'bost_Open'",
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ]);
        return $data['value'] ?? [];
    }

    public function getInvoicesByCustomer(string $cardCode, int $top = 10): array
    {
        $safe = addslashes($cardCode);
        $data = $this->get('Invoices', [
            '$select'  => 'DocNum,CardCode,CardName,DocDate,DocDueDate,DocTotal,DocCurrency,DocumentStatus,PaidToDate',
            '$filter'  => "CardCode eq '{$safe}'",
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ]);
        return $data['value'] ?? [];
    }

    public function getPurchaseOrdersBySupplier(string $cardCode, int $top = 10): array
    {
        $safe = addslashes($cardCode);
        $data = $this->get('PurchaseOrders', [
            '$select'  => 'DocNum,CardCode,CardName,DocDate,DocDueDate,DocTotal,DocCurrency,DocumentStatus',
            '$filter'  => "CardCode eq '{$safe}' and DocumentStatus eq 'bost_Open'",
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ]);
        return $data['value'] ?? [];
    }

    // ─── CRM / Sales Opportunities (Pipeline) ──────────────────────────────────

    /** Translate PartYard CRM stage ID to human label */
    protected function getStageLabel(int $stageId): string
    {
        static $labels = [
            1  => 'Prospecção',
            5  => 'Cotação de Compra',
            6  => 'Cotação de Venda',
            7  => 'Follow Up Vendas',
            8  => 'Possível Venda',
            9  => 'Ordem de Compra',
            10 => 'Ordem de Venda',
        ];
        return $labels[$stageId] ?? "Stage {$stageId}";
    }

    /**
     * Fetch sales opportunities from SAP B1 CRM.
     *
     * @param  int|null  $stageId     Filter by CRM stage (null = all stages)
     * @param  int|null  $salesPerson Filter by SAP employee code
     * @param  int       $top         Max records to return
     * @param  string    $status      'O'=Open, 'W'=Won, 'L'=Lost
     */
    public function getSalesOpportunities(
        ?int   $stageId     = null,
        ?int   $salesPerson = null,
        int    $top         = 100,
        string $status      = 'sos_Open'
    ): array {
        $filters = ["Status eq '{$status}'"];
        if ($stageId !== null)     $filters[] = "CurrentStageNo eq {$stageId}";
        if ($salesPerson !== null) $filters[] = "SalesPerson eq {$salesPerson}";

        $data = $this->get('SalesOpportunities', [
            '$select'  => 'SequentialNo,CardCode,CardName,SalesPerson,CurrentStageNo,MaxLocalTotal,WeightedSumLC,ClosingPercentage,PredictedClosingDate,StartDate,OpportunityName',
            '$filter'  => implode(' and ', $filters),
            '$top'     => $top,
            '$orderby' => 'MaxLocalTotal desc',
        ]);
        return $data['value'] ?? [];
    }

    /**
     * Build a formatted CRM pipeline summary grouped by stage and salesperson.
     * Used by buildContext() when CRM keywords are detected in the user message.
     */
    public function getPipelineSummary(string $status = 'O'): string
    {
        $opps = $this->getSalesOpportunities(null, null, 500, $status);
        if (empty($opps)) return '';

        $byStage       = [];
        $bySalesPerson = [];
        $grandTotal    = 0.0;
        $grandWeighted = 0.0;

        foreach ($opps as $opp) {
            $stageId  = (int) ($opp['CurrentStageNo'] ?? $opp['StageId'] ?? 0);
            $label    = $this->getStageLabel($stageId);
            $person   = (string) ($opp['SalesPerson'] ?? '?');
            $amount   = (float) ($opp['MaxLocalTotal']  ?? 0);
            $weighted = (float) ($opp['WeightedSumLC']  ?? 0);

            $grandTotal    += $amount;
            $grandWeighted += $weighted;

            // Aggregate by stage
            if (!isset($byStage[$stageId])) {
                $byStage[$stageId] = ['label' => $label, 'count' => 0, 'total' => 0.0, 'weighted' => 0.0];
            }
            $byStage[$stageId]['count']++;
            $byStage[$stageId]['total']    += $amount;
            $byStage[$stageId]['weighted'] += $weighted;

            // Aggregate by salesperson
            if (!isset($bySalesPerson[$person])) {
                $bySalesPerson[$person] = ['count' => 0, 'total' => 0.0, 'stages' => []];
            }
            $bySalesPerson[$person]['count']++;
            $bySalesPerson[$person]['total'] += $amount;
            if (!isset($bySalesPerson[$person]['stages'][$label])) {
                $bySalesPerson[$person]['stages'][$label] = ['count' => 0, 'total' => 0.0];
            }
            $bySalesPerson[$person]['stages'][$label]['count']++;
            $bySalesPerson[$person]['stages'][$label]['total'] += $amount;
        }

        ksort($byStage);
        uasort($bySalesPerson, fn($a, $b) => $b['total'] <=> $a['total']);

        $lines = [
            "💼 PIPELINE CRM — " . count($opps) . " oportunidades abertas"
            . " | Total: €" . number_format($grandTotal, 0, '.', ',')
            . " | Ponderado: €" . number_format($grandWeighted, 0, '.', ','),
        ];

        $lines[] = "\n📊 RESUMO POR FASE:";
        foreach ($byStage as $sid => $s) {
            $lines[] = "  " . str_pad($s['label'], 24) . "| "
                . str_pad((string) $s['count'], 4, ' ', STR_PAD_LEFT) . " opor."
                . " | €" . number_format($s['total'], 0, '.', ',')
                . " | peso €" . number_format($s['weighted'], 0, '.', ',');
        }

        $lines[] = "\n👥 PIPELINE POR VENDEDOR (código SAP):";
        foreach ($bySalesPerson as $person => $p) {
            $lines[] = "  👤 Vendedor #{$person} — {$p['count']} oport. | €" . number_format($p['total'], 0, '.', ',');
            foreach ($p['stages'] as $stage => $s) {
                $lines[] = "    ↳ {$stage}: {$s['count']} | €" . number_format($s['total'], 0, '.', ',');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Create a new Sales Opportunity in SAP B1 CRM.
     *
     * Supported keys in $data:
     *   CardCode (required)
     *   StageId (optional)       — maps to CurrentStageNo in SAP; defaults to 1 (Prospecção)
     *   OpportunityName / Name   — opportunity title (from email Subject)
     *   SalesPerson              — SAP EmployeeID (integer)
     *   ContactPerson            — SAP CntctCode (integer)
     *   MaxLocalTotal            — potential amount (line item); defaults to 1.0
     *   ExpectedClosingDate      — Y-m-d; OR use ClosingDays
     *   ClosingDays              — integer, calculates PredictedClosingDate = today + N days
     *   Remarks                  — free text notes
     */
    public function createOpportunity(array $data): ?array
    {
        $this->lastError = '';  // reset before each attempt

        // ── Resolve CardCode from CardName if CardCode is missing or looks like a name ──
        $cardCode = trim((string) ($data['CardCode'] ?? ''));
        $cardName = trim((string) ($data['CardName'] ?? ''));

        // A real SAP CardCode is short and has no spaces (e.g. "C00123", "OCNP01")
        // If CardCode is empty OR looks like a full company name (contains spaces / long),
        // try to resolve it by searching BusinessPartners by name.
        $looksLikeName = $cardName !== '' && (
            $cardCode === '' ||
            strlen($cardCode) > 15 ||
            str_word_count($cardCode) > 1
        );

        if ($looksLikeName) {
            try {
                $bps = $this->searchBusinessPartners($cardName, 1);
                if (!empty($bps)) {
                    $cardCode = $bps[0]['CardCode'];
                    $cardName = $bps[0]['CardName'];
                    Log::info("CRM: resolved CardCode '{$cardCode}' for '{$cardName}' via name lookup");
                }
            } catch (\Throwable $e) {
                Log::warning("CRM: CardCode name resolution failed: " . $e->getMessage());
            }
        }

        // ── Verified field names from GET /SalesOpportunities (live SAP B1 instance) ──
        // CardName        → READ-ONLY (derived from CardCode). Do NOT send.
        // Name            → INVALID.  Use OpportunityName.
        // ExpectedClosingDate → INVALID. Use PredictedClosingDate.
        // StageId         → INVALID for POST. Use CurrentStageNo.
        // Status          → must be 'sos_Open' (not 'O') for Em Aberto.
        // MaxLocalTotal   → calculated from SalesOpportunitiesLines. Send 0 or omit in header.
        //                   SAP validation requires at least 1 line with MaxLocalTotal > 0.

        $stageNo = isset($data['StageId']) ? (int) $data['StageId'] : 1;

        $payload = [
            'CardCode'       => $cardCode ?: null,
            'CurrentStageNo' => $stageNo,
            'StartDate'      => date('Y-m-d\T00:00:00\Z'),
            'Status'         => 'sos_Open',   // Em Aberto
        ];

        // Opportunity name / title from email Subject
        $oppName = trim((string) ($data['OpportunityName'] ?? $data['Name'] ?? ''));
        if ($oppName !== '') {
            $payload['OpportunityName'] = substr($oppName, 0, 100);
        }

        // Remarks = verbatim equipment / material text from the email
        if (!empty($data['Remarks'])) {
            $payload['Remarks'] = substr((string) $data['Remarks'], 0, 254);
        }

        // Potential Amount — SAP B1 requires a SalesOpportunitiesLines entry with MaxLocalTotal > 0.
        // Header MaxLocalTotal is read-only (calculated from lines). StageKey on the line also
        // drives CurrentStageNo — so we set it here to ensure the opportunity lands in the right stage.
        $amount = isset($data['MaxLocalTotal']) ? (float) $data['MaxLocalTotal'] : 0.0;
        $lineAmount = $amount > 0 ? $amount : 1.0;
        $payload['SalesOpportunitiesLines'] = [
            [
                'LineNum'       => 0,
                'StageKey'      => $stageNo,
                'MaxLocalTotal' => $lineAmount,
            ]
        ];

        // SalesPerson (SAP EmployeeID)
        if (!empty($data['SalesPerson'])) {
            $payload['SalesPerson'] = (int) $data['SalesPerson'];
        }

        // ContactPerson (SAP CntctCode)
        if (!empty($data['ContactPerson'])) {
            $payload['ContactPerson'] = (int) $data['ContactPerson'];
        }

        // Predicted Closing Date — explicit date OR today + ClosingDays
        if (!empty($data['ExpectedClosingDate'])) {
            $ts = strtotime($data['ExpectedClosingDate']);
            if ($ts) $payload['PredictedClosingDate'] = date('Y-m-d\T00:00:00\Z', $ts);
        } elseif (!empty($data['ClosingDays'])) {
            $days = max(1, (int) $data['ClosingDays']);
            $payload['PredictedClosingDate'] = date('Y-m-d\T00:00:00\Z', strtotime("+{$days} days"));
        }

        // Information Source — SAP BoSouType enum
        //   0=Word of Mouth  1=Cold Call  2=Advertising  3=Email  4=Trade Show
        //   5=Internet/Seminar  6=Other
        if (isset($data['InformationSource'])) {
            $payload['Source'] = (int) $data['InformationSource'];
        }

        // POST to SAP
        $filtered = array_filter($payload, fn($v) => $v !== null);
        $result   = $this->post('SalesOpportunities', $filtered);

        // Retry 1: Source field not supported on some SAP B1 versions — drop it
        if (!$result && isset($filtered['Source'])) {
            $originalError = $this->lastError;
            Log::warning("CRM: retrying without Source (SAP: {$originalError})");
            unset($filtered['Source']);
            $result = $this->post('SalesOpportunities', $filtered);
            if ($result)  $this->lastError = '';          // success — clear error
            elseif ($this->lastError === '') $this->lastError = $originalError;
        }

        // Retry 2: Inactive SalesPerson — remove and try again without assignment
        if (!$result && isset($filtered['SalesPerson'])
            && str_contains((string) $this->lastError, 'sales employee')) {
            $originalError = $this->lastError;
            Log::warning("CRM: retrying without SalesPerson (inactive: {$originalError})");
            unset($filtered['SalesPerson']);
            $result = $this->post('SalesOpportunities', $filtered);
            if ($result)  $this->lastError = '';
            elseif ($this->lastError === '') $this->lastError = $originalError;
        }

        // Retry 3: ContactPerson invalid — remove and try again
        if (!$result && isset($filtered['ContactPerson'])
            && str_contains((string) $this->lastError, 'contact')) {
            $originalError = $this->lastError;
            Log::warning("CRM: retrying without ContactPerson (SAP: {$originalError})");
            unset($filtered['ContactPerson']);
            $result = $this->post('SalesOpportunities', $filtered);
            if ($result)  $this->lastError = '';
            elseif ($this->lastError === '') $this->lastError = $originalError;
        }

        // SAP sometimes returns 201 with empty body (Prefer header ignored).
        // If we got a success marker but no SequentialNo, fetch the latest opp for this CardCode.
        if ($result && !isset($result['SequentialNo'])) {
            Log::info("CRM: SAP returned no entity body — fetching latest opp for CardCode={$cardCode}");
            try {
                $latest = $this->get('SalesOpportunities', [
                    '$filter'  => "CardCode eq '{$cardCode}'",
                    '$orderby' => 'CreateDate desc,SequentialNo desc',
                    '$top'     => 1,
                    '$select'  => 'SequentialNo,CardCode,OpportunityName,CurrentStageNo',
                ]);
                if (!empty($latest['value'][0]['SequentialNo'])) {
                    $result = $latest['value'][0];
                    Log::info("CRM: resolved SequentialNo={$result['SequentialNo']} from GET fallback");
                }
            } catch (\Throwable $e) {
                Log::warning("CRM: GET fallback for SequentialNo failed: " . $e->getMessage());
            }
        }

        // Post-creation PATCH: set ProjectCode = SequentialNo (shown in General tab)
        // Note: field is "ProjectCode" in OData (not "BusinessProject" — that name is invalid)
        if ($result && isset($result['SequentialNo'])) {
            $seqNo = $result['SequentialNo'];
            $this->patch("SalesOpportunities({$seqNo})", ['ProjectCode' => (string) $seqNo]);
        }

        return $result;
    }

    /**
     * Update an existing Sales Opportunity (e.g. change stage, value).
     */
    public function updateOpportunity(int $sequentialNo, array $data): bool
    {
        $payload = array_filter([
            'CurrentStageNo'      => isset($data['StageId']) ? (int) $data['StageId'] : null,
            'SalesPerson'         => isset($data['SalesPerson']) ? (int) $data['SalesPerson'] : null,
            'Remarks'             => $data['Remarks'] ?? null,
            'PredictedClosingDate' => !empty($data['ExpectedClosingDate'])
                ? date('Y-m-d\T00:00:00\Z', strtotime($data['ExpectedClosingDate']))
                : null,
        ], fn($v) => $v !== null);

        $result = $this->patch("SalesOpportunities({$sequentialNo})", $payload);
        return !empty($result['ok']);
    }

    /**
     * Fetch all SAP Sales Persons (the list used by CRM Opportunities).
     *
     * Uses the SalesPersons entity — NOT EmployeesInfo — because the
     * SalesOpportunities.SalesPerson field takes SalesEmployeeCode from this table.
     * Known persons: Ana Sobral, Bruno Monteiro, Catarina Aresta, Catarina Sequeira,
     * Claudia Leal, Eduardo Rio, Joao Murta, José Inácio, Luis Gomes,
     * Mónica Pereira, Olimpia Pires, Sonia Osorio, Victor Macedo.
     */
    public function getSalesEmployees(int $top = 30): array
    {
        $data = $this->get('SalesPersons', [
            '$select'  => 'SalesEmployeeCode,SalesEmployeeName,Active',
            '$filter'  => "Active eq 'tYES'",
            '$top'     => $top,
            '$orderby' => 'SalesEmployeeName asc',
        ]);

        // Normalise to common field names expected by callers
        return array_map(fn($r) => [
            'EmployeeID' => $r['SalesEmployeeCode'] ?? 0,
            'FirstName'  => $r['SalesEmployeeName'] ?? '',
            'LastName'   => '',
            'FullName'   => $r['SalesEmployeeName'] ?? '',
        ], $data['value'] ?? []);
    }

    /**
     * Search a Sales Person by partial name (used for SalesOpportunities.SalesPerson).
     * Returns rows with EmployeeID = SalesEmployeeCode.
     */
    public function searchSalesEmployee(string $name, int $top = 5): array
    {
        $safe = addslashes($name);
        $data = $this->get('SalesPersons', [
            '$select' => 'SalesEmployeeCode,SalesEmployeeName,Active',
            '$filter' => "contains(SalesEmployeeName,'{$safe}') and Active eq 'tYES'",
            '$top'    => $top,
        ]);

        return array_map(fn($r) => [
            'EmployeeID' => $r['SalesEmployeeCode'] ?? 0,
            'FirstName'  => $r['SalesEmployeeName'] ?? '',
            'LastName'   => '',
            'FullName'   => $r['SalesEmployeeName'] ?? '',
        ], $data['value'] ?? []);
    }

    /**
     * Auto-discover the UDF field name for "Status Oportunidade" in the OOPR table.
     * Caches for 24 h — only queries SAP once per deployment.
     *
     * Returns the API field name (e.g. "U_StatusOp") or null if not found.
     */
    public function discoverStatusOportunidadeField(): ?string
    {
        $cacheKey = 'sap_udf_status_oportunidade';

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?: null;  // '' stored = not found
        }

        try {
            $data = $this->get('UserFieldsMD', [
                '$filter' => "TableName eq 'OOPR'",
                '$select' => 'FieldID,FieldName,Description',
                '$top'    => 50,
            ]);

            foreach ($data['value'] ?? [] as $udf) {
                $desc = strtolower($udf['Description'] ?? '');
                if (str_contains($desc, 'status')) {
                    $apiField = 'U_' . $udf['FieldName'];
                    Cache::put($cacheKey, $apiField, now()->addHours(24));
                    Log::info("CRM: discovered Status Oportunidade UDF field: {$apiField}");
                    return $apiField;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("CRM: could not discover Status UDF: " . $e->getMessage());
        }

        Cache::put($cacheKey, '', now()->addHours(1));  // negative cache 1 h
        return null;
    }

    /**
     * Search a Business Partner by VAT / NIF / FederalTaxID.
     * Strips non-alphanumeric chars before searching.
     */
    public function searchBPByVAT(string $vat, int $top = 3): array
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $vat);
        $data  = $this->get('BusinessPartners', [
            '$select' => 'CardCode,CardName,CardType,FederalTaxID,Phone1,EmailAddress,CreditLimit',
            '$filter' => "contains(FederalTaxID,'{$clean}')",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }

    /**
     * Fetch contact persons registered under a Business Partner CardCode.
     */
    public function getContactPersons(string $cardCode, int $top = 15): array
    {
        $safe = addslashes($cardCode);
        $data = $this->get('ContactEmployees', [
            '$select' => 'CntctCode,Name,FirstName,LastName,E_Mail,Phone1,Position',
            '$filter' => "CardCode eq '{$safe}'",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }


    // ─── Structured table data for the SAP Documents UI ───────────────────────

    /**
     * Document type → SAP Service Layer endpoint mapping.
     */
    protected static array $docTypeMap = [
        'invoices'       => 'Invoices',
        'orders'         => 'Orders',
        'purchase_orders'=> 'PurchaseOrders',
        'quotations'     => 'Quotations',
        'deliveries'     => 'DeliveryNotes',
        'returns'        => 'Returns',
        'credit_notes'   => 'CreditNotes',
    ];

    /**
     * Human-readable document type labels (PT).
     */
    protected static array $docTypeLabels = [
        'invoices'        => 'Faturas',
        'orders'          => 'Encomendas',
        'purchase_orders' => 'Ordens de Compra',
        'quotations'      => 'Propostas',
        'deliveries'      => 'Entregas',
        'returns'         => 'Devoluções',
        'credit_notes'    => 'Notas de Crédito',
    ];

    /**
     * Fetch documents for the interactive SAP table view.
     *
     * Returns rows with normalised fields:
     *   doc_num, doc_status, sales_status, payment_status, payment_ok,
     *   doc_date, due_date, total, currency, card_name, card_code, doc_type
     */
    public function getDocumentsForTable(
        string  $docType    = 'invoices',
        int     $top        = 30,
        ?string $dateFrom   = null,
        ?string $dateTo     = null,
        ?string $cardFilter = null
    ): ?array {
        // Return null (not empty array) when SAP is unreachable — caller can distinguish
        if (!$this->ensureSession()) return null;

        $endpoint = self::$docTypeMap[$docType] ?? 'Invoices';

        // Only standard SAP B1 fields — no custom U_* fields that may not exist
        $select = implode(',', [
            'DocNum', 'DocEntry', 'CardCode', 'CardName',
            'DocumentStatus', 'DocDate', 'DocDueDate',
            'DocTotal', 'DocCurrency', 'PaidToDate',
            'NumAtCard', 'Ref2',
        ]);

        $filters = [];

        if ($dateFrom) {
            $filters[] = "DocDate ge '" . date('Y-m-d', strtotime($dateFrom)) . "'";
        }
        if ($dateTo) {
            $filters[] = "DocDate le '" . date('Y-m-d', strtotime($dateTo)) . "'";
        }
        if ($cardFilter) {
            $safe      = addslashes($cardFilter);
            $filters[] = "(contains(CardName,'{$safe}') or contains(CardCode,'{$safe}'))";
        }

        $query = [
            '$select'  => $select,
            '$top'     => $top,
            '$orderby' => 'DocDate desc',
        ];
        if ($filters) {
            $query['$filter'] = implode(' and ', $filters);
        }

        $data = $this->get($endpoint, $query);
        $rows = $data['value'] ?? [];

        return array_map(function (array $doc) use ($docType): array {
            // Document status
            $rawStatus = $doc['DocumentStatus'] ?? '';
            $docStatus = match ($rawStatus) {
                'bost_Open'  => 'Open',
                'bost_Close' => 'Closed',
                default      => $rawStatus ?: 'Unknown',
            };

            // Payment status — derived from PaidToDate vs DocTotal
            $paid  = (float) ($doc['PaidToDate'] ?? 0);
            $total = (float) ($doc['DocTotal']   ?? 0);
            $paymentOk = false;

            if ($docType === 'invoices' || $docType === 'credit_notes') {
                if ($total > 0 && $paid >= $total) {
                    $paymentStatus = 'Payment successful';
                    $paymentOk     = true;
                } elseif ($docStatus === 'Closed' && $total == 0) {
                    $paymentStatus = 'Payment successful';
                    $paymentOk     = true;
                } else {
                    $paymentStatus = 'Pending payment';
                }
            } else {
                // Orders / POs / Deliveries don't have a payment status
                $paymentStatus = $docStatus === 'Closed' ? 'Closed' : 'Open';
                $paymentOk     = ($docStatus === 'Closed');
            }

            // Sales order reference — NumAtCard is usually the customer's PO/ref
            $salesRef = trim((string)($doc['NumAtCard'] ?? $doc['Ref2'] ?? ''));

            return [
                'doc_num'        => $doc['DocNum']      ?? '',
                'doc_entry'      => $doc['DocEntry']    ?? '',
                'doc_status'     => $docStatus,
                'sales_status'   => $salesRef ?: null,
                'payment_status' => $paymentStatus,
                'payment_ok'     => $paymentOk,
                'doc_date'       => substr((string)($doc['DocDate']    ?? ''), 0, 10),
                'due_date'       => substr((string)($doc['DocDueDate'] ?? ''), 0, 10),
                'total'          => $total,
                'currency'       => $doc['DocCurrency'] ?? 'EUR',
                'card_name'      => $doc['CardName']    ?? '',
                'card_code'      => $doc['CardCode']    ?? '',
                'doc_type'       => $docType,
            ];
        }, $rows);
    }

    /**
     * Year range available in SAP documents (for the timeline slider).
     */
    public function getDocumentYearRange(string $docType = 'invoices'): array
    {
        $endpoint = self::$docTypeMap[$docType] ?? 'Invoices';

        $oldest = $this->get($endpoint, [
            '$select'  => 'DocDate',
            '$top'     => 1,
            '$orderby' => 'DocDate asc',
        ]);
        $newest = $this->get($endpoint, [
            '$select'  => 'DocDate',
            '$top'     => 1,
            '$orderby' => 'DocDate desc',
        ]);

        $minYear = (int) substr((string)($oldest['value'][0]['DocDate'] ?? date('Y')), 0, 4);
        $maxYear = (int) substr((string)($newest['value'][0]['DocDate'] ?? date('Y')), 0, 4);

        return ['min' => $minYear ?: (int)date('Y') - 10, 'max' => $maxYear ?: (int)date('Y')];
    }

    /**
     * All available doc types for the tab bar.
     */
    public static function getDocTypeLabels(): array
    {
        return self::$docTypeLabels;
    }

    // ─── Smart context builder ─────────────────────────────────────────────────

    /**
     * Analyse the user message and fetch relevant SAP data as a context string.
     */
    public function buildContext(string $message): string
    {
        if (!$this->username || !$this->password) {
            return "\n\n--- ERRO SAP B1 ---\nCredenciais não configuradas (SAP_B1_USER / SAP_B1_PASSWORD em falta no .env do servidor).\nDiz ao utilizador que as credenciais SAP não estão configuradas e que deve contactar o administrador.\n--- FIM ERRO ---\n";
        }

        // Test if we can get a session — detect login failures explicitly
        $session = $this->ensureSession();
        if (!$session) {
            // Do NOT call testConnection() here — it clears the negative cache
            // and causes a second login attempt, which locks the SAP account.
            return "\n\n--- ERRO LIGAÇÃO SAP B1 ---\n"
                . "❌ Login SAP recusado (HTTP 401): Fail to NONE-SSO login from SLD.\n"
                . "Sugestão: Verifica as credenciais SAP_B1_USER e SAP_B1_PASSWORD no .env do servidor.\n"
                . "User configurado: {$this->username}\n"
                . "\nDiz ao utilizador exactamente este erro para que possa resolver o problema de acesso ao SAP.\n--- FIM ERRO ---\n";
        }

        $context = [];

        // ── OVERVIEW SEMPRE CARREGADO ─────────────────────────────────────────
        // Independentemente das keywords, sempre buscar um resumo do estado actual
        // para que o Richard tenha sempre dados reais para responder.
        $overviewParts = [];

        $recentInvoices = $this->getRecentInvoices(8);
        if ($recentInvoices) {
            $rows = array_map(fn($i) => "  • #{$i['DocNum']} — {$i['CardName']} | {$i['DocDate']} | €" . number_format((float)$i['DocTotal'], 2, '.', ',') . " | " . ($i['DocumentStatus'] === 'bost_Open' ? 'Aberta' : 'Fechada'), $recentInvoices);
            $overviewParts[] = "🧾 ÚLTIMAS FATURAS (8):\n" . implode("\n", $rows);
        }

        $openOrders = $this->getOpenSalesOrders(8);
        if ($openOrders) {
            $rows = array_map(fn($o) => "  • #{$o['DocNum']} — {$o['CardName']} | {$o['DocDate']} | €" . number_format((float)$o['DocTotal'], 2, '.', ','), $openOrders);
            $overviewParts[] = "📋 ENCOMENDAS DE VENDA ABERTAS (8):\n" . implode("\n", $rows);
        }

        $openPOs = $this->getOpenPurchaseOrders(5);
        if ($openPOs) {
            $rows = array_map(fn($o) => "  • #{$o['DocNum']} — {$o['CardName']} | {$o['DocDate']} | €" . number_format((float)$o['DocTotal'], 2, '.', ','), $openPOs);
            $overviewParts[] = "🏭 ORDENS DE COMPRA ABERTAS (5):\n" . implode("\n", $rows);
        }

        if ($overviewParts) {
            $context[] = implode("\n\n", $overviewParts);
        }

        // ── SECÇÕES ADICIONAIS POR KEYWORD ────────────────────────────────────

        // --- Stock queries ---
        if (preg_match('/stock|saldo|quantidad|quantidade|inventari|armazém|warehouse|existên/i', $message)) {
            if (preg_match('/stock\s+(?:de\s+)?([A-Z0-9\-\.]{3,20})/i', $message, $m)) {
                $item = $this->getItemStock($m[1]);
                if ($item) {
                    $context[] = "📦 STOCK — {$item['ItemCode']} ({$item['ItemName']}):\n"
                        . "  Em stock: {$item['QuantityOnStock']}\n"
                        . "  Encomendado a fornecedores: {$item['QuantityOrderedFromVendors']}\n"
                        . "  Encomendado por clientes: {$item['QuantityOrderedByCustomers']}";
                }
            }
            $lowStock = $this->getLowStockItems(10);
            if ($lowStock) {
                $rows      = array_map(fn($i) => "  • {$i['ItemCode']} — {$i['ItemName']}: {$i['QuantityOnStock']} (mín: {$i['MinInventory']})", $lowStock);
                $context[] = "⚠️ ARTIGOS COM STOCK BAIXO:\n" . implode("\n", $rows);
            }
        }

        // --- Item search ---
        if (preg_match('/artigo|item|peça|part|referên|código/i', $message)) {
            if (preg_match('/(?:artigo|item|peça|part|referência|código)\s+["\']?([A-Za-z0-9\-\.]{3,20})["\']?/i', $message, $m)) {
                $items = $this->searchItems($m[1], 5);
                if ($items) {
                    $rows      = array_map(fn($i) => "  • {$i['ItemCode']} — {$i['ItemName']}: stock={$i['QuantityOnStock']}", $items);
                    $context[] = "🔍 ARTIGOS ENCONTRADOS:\n" . implode("\n", $rows);
                }
            }
        }

        // --- Business partner search ---
        if (preg_match('/cliente|client|fornecedor|supplier|parceiro|partner|empresa|devedor|debtor|credor|creditor|saldo.de|conta.corrente|current.account/i', $message)) {
            if (preg_match('/(?:cliente|client|fornecedor|supplier|parceiro|partner|empresa)\s+["\']?([A-Za-zÀ-ú\s]{3,30})["\']?/i', $message, $m)) {
                $bps = $this->searchBusinessPartners(trim($m[1]), 3);
                if ($bps) {
                    $rows      = array_map(fn($b) => "  • {$b['CardCode']} — {$b['CardName']} | Limite créd: €" . number_format((float)($b['CreditLimit'] ?? 0), 0, '.', ','), $bps);
                    $context[] = "👤 PARCEIROS ENCONTRADOS:\n" . implode("\n", $rows);
                }
            }
        }

        // --- NSN lookup (13-digit National Stock Number) ---
        if (preg_match('/\b(\d{4}[-\s]?\d{2}[-\s]?\d{3}[-\s]?\d{4}|\d{13})\b/', $message, $nsnMatch)) {
            $nsn      = preg_replace('/[^0-9]/', '', $nsnMatch[1]);
            $nsnItems = $this->searchByNSN($nsn, 5);
            if ($nsnItems) {
                $rows      = array_map(fn($i) =>
                    "  • {$i['ItemCode']} — {$i['ItemName']}\n"
                    . "    Stock: {$i['QuantityOnStock']}"
                    . " | A receber forn.: {$i['QuantityOrderedFromVendors']}"
                    . " | Encomendado cli.: {$i['QuantityOrderedByCustomers']}"
                    . (!empty($i['Mainsupplier']) ? " | Fornecedor: {$i['Mainsupplier']}" : ''),
                    $nsnItems
                );
                $context[] = "🎯 NSN ENCONTRADO ({$nsn}):\n" . implode("\n", $rows);
            } else {
                $context[] = "⚠️ NSN {$nsn}: não encontrado no catálogo PartYard (72.562 artigos).";
            }
        }

        // --- Known PartYard customer quick-lookup ---
        static $knownBPs = [
            'nspa'       => 'NSPA',
            'oceanpact'  => 'OCEANPACT',
            'sasu'       => 'SASU',
            'vbaf'       => 'VBAF',
            'increment'  => 'INCREMENT',
            'raytheon'   => 'RAYTHEON',
            'keysight'   => 'KEYSIGHT',
            'carleton'   => 'CARLETON',
            'vop'        => 'VOP',
        ];
        foreach ($knownBPs as $key => $name) {
            if (stripos($message, $key) !== false) {
                $bps = $this->searchBusinessPartners($name, 1);
                if ($bps && isset($bps[0]['CardCode'])) {
                    $bp       = $bps[0];
                    $cardCode = $bp['CardCode'];
                    $cardType = $bp['CardType'] ?? '';
                    if ($cardType === 'cCustomer') {
                        $orders = $this->getOrdersByCustomer($cardCode, 8);
                        if ($orders) {
                            $rows      = array_map(fn($o) =>
                                "  • #{$o['DocNum']} | {$o['DocDate']} | €" . number_format((float)$o['DocTotal'], 0, '.', ',') . " {$o['DocCurrency']} | " . ($o['DocumentStatus'] === 'bost_Open' ? 'Aberta' : 'Fechada'),
                                $orders
                            );
                            $context[] = "📦 ENCOMENDAS ABERTAS — {$bp['CardName']} ({$cardCode}):\n" . implode("\n", $rows);
                        }
                    } elseif ($cardType === 'cSupplier') {
                        $pos = $this->getPurchaseOrdersBySupplier($cardCode, 8);
                        if ($pos) {
                            $rows      = array_map(fn($o) =>
                                "  • #{$o['DocNum']} | {$o['DocDate']} | €" . number_format((float)$o['DocTotal'], 0, '.', ',') . " | " . ($o['DocumentStatus'] === 'bost_Open' ? 'Aberta' : 'Fechada'),
                                $pos
                            );
                            $context[] = "🏭 OC ABERTAS — {$bp['CardName']} ({$cardCode}):\n" . implode("\n", $rows);
                        }
                    }
                }
                break; // only lookup the first matched BP
            }
        }

        // --- CRM Pipeline / Sales Opportunities ---
        if (preg_match('/pipeline|oportunidad|cotaç[ãa]o|crm|prospec|vendedor|forecast|funil/i', $message)) {
            try {
                // Cotação de Compra detail (StageId = 5)
                if (preg_match('/cotaç[ãa]o.{0,8}compra|purchase.quot/i', $message)) {
                    $opps = $this->getSalesOpportunities(5, null, 50);
                    if ($opps) {
                        $rows      = array_map(fn($o) =>
                            "  • #{$o['SequentialNo']} {$o['CardName']}"
                            . " | €" . number_format((float)$o['MaxLocalTotal'], 0, '.', ',')
                            . " | Vend#{$o['SalesPerson']}"
                            . " | Fecho:" . substr((string)($o['PredictedClosingDate'] ?? ''), 0, 10),
                            $opps
                        );
                        $context[] = "📋 COTAÇÕES DE COMPRA (StageId=5) — " . count($opps) . " abertas:\n" . implode("\n", $rows);
                    }
                }

                // Cotação de Venda detail (StageId = 6)
                if (preg_match('/cotaç[ãa]o.{0,8}venda|sales.quot/i', $message)) {
                    $opps = $this->getSalesOpportunities(6, null, 50);
                    if ($opps) {
                        $rows      = array_map(fn($o) =>
                            "  • #{$o['SequentialNo']} {$o['CardName']}"
                            . " | €" . number_format((float)$o['MaxLocalTotal'], 0, '.', ',')
                            . " | Vend#{$o['SalesPerson']}"
                            . " | Fecho:" . substr((string)($o['PredictedClosingDate'] ?? ''), 0, 10),
                            $opps
                        );
                        $context[] = "💼 COTAÇÕES DE VENDA (StageId=6) — " . count($opps) . " abertas:\n" . implode("\n", $rows);
                    }
                }

                // Full pipeline summary by stage + salesperson
                $pipelineText = $this->getPipelineSummary();
                if ($pipelineText) {
                    $context[] = $pipelineText;
                }
            } catch (\Throwable $e) {
                Log::warning('SapService: pipeline fetch failed — ' . $e->getMessage());
                $context[] = "⚠️ Pipeline CRM: erro ao carregar dados (" . $e->getMessage() . ")";
            }
        }

        return "\n\n--- DADOS REAIS DO SAP B1 (PARTYARD) ---\n"
            . implode("\n\n", $context)
            . "\n--- FIM DADOS SAP ---\n";
    }
}
