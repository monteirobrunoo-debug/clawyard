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

    const SESSION_CACHE_KEY  = 'sap_b1_session';
    const SESSION_TTL        = 25; // minutes (SAP default = 30 min)
    const SESSION_FAILED_KEY = 'sap_b1_login_failed';
    const SESSION_FAILED_TTL = 2;  // minutes — negative cache to avoid multi-login hangs

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.sap.base_url', 'https://sld.partyard.privatcloud.biz/b1s/v1'), '/');
        $this->company  = config('services.sap.company',  'PARTYARD');
        $this->username = config('services.sap.username', '');
        $this->password = config('services.sap.password', '');

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

            Log::warning('SAP Login: no SessionId in response body', $data);
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
            $body     = $response->getBody()->getContents();
            $data     = json_decode($body, true) ?? [];
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

            // Outro erro HTTP
            $errMsg = $data['error']['message']['value'] ?? ($data['message'] ?? substr($body, 0, 200));
            return [
                'ok'      => false,
                'status'  => 'login_failed',
                'message' => "❌ Login SAP falhou (HTTP {$httpCode}): {$errMsg}",
                'hint'    => "URL: {$this->baseUrl} | Empresa: {$this->company} | User: {$this->username}",
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
            '$select' => 'CardCode,CardName,CardType,Phone1,EmailAddress,Balance,CreditLimit',
            '$filter' => "contains(CardName,'" . addslashes($name) . "')",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }

    public function getBusinessPartner(string $cardCode): ?array
    {
        return $this->get("BusinessPartners('{$cardCode}')") ?: null;
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
    ): array {
        $endpoint = self::$docTypeMap[$docType] ?? 'Invoices';

        $select = implode(',', [
            'DocNum', 'DocEntry', 'CardCode', 'CardName',
            'DocumentStatus', 'DocDate', 'DocDueDate',
            'DocTotal', 'DocCurrency', 'PaidToDate',
            'NumAtCard', 'Ref2', 'Comments',
            'U_SalesOrderRef',          // custom field if exists — graceful fallback
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
            // Try once more with fresh cache to get a proper error message
            $diag = $this->testConnection();
            $hint = $diag['hint'] ?? '';
            return "\n\n--- ERRO LIGAÇÃO SAP B1 ---\n"
                . ($diag['message'] ?? 'Não foi possível ligar ao SAP B1.')
                . ($hint ? "\nSugestão: {$hint}" : '')
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
                    $rows      = array_map(fn($b) => "  • {$b['CardCode']} — {$b['CardName']} | Saldo: €{$b['Balance']}", $bps);
                    $context[] = "👤 PARCEIROS ENCONTRADOS:\n" . implode("\n", $rows);
                }
            }
        }

        return "\n\n--- DADOS REAIS DO SAP B1 (PARTYARD) ---\n"
            . implode("\n\n", $context)
            . "\n--- FIM DADOS SAP ---\n";
    }
}
