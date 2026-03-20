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

    const SESSION_CACHE_KEY = 'sap_b1_session';
    const SESSION_TTL       = 25; // minutes (SAP default = 30 min)

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
     */
    protected function ensureSession(): ?string
    {
        $session = Cache::get(self::SESSION_CACHE_KEY);
        if (!$session) {
            $this->login();
            $session = Cache::get(self::SESSION_CACHE_KEY);
        }
        return $session ?: null;
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

    // ─── Smart context builder ─────────────────────────────────────────────────

    /**
     * Analyse the user message and fetch relevant SAP data as a context string.
     */
    public function buildContext(string $message): string
    {
        if (!$this->username || !$this->password) {
            return '';
        }

        $context = [];

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

        // --- Sales orders ---
        if (preg_match('/encomenda|order|pedido|venda/i', $message)) {
            $orders = $this->getOpenSalesOrders(5);
            if ($orders) {
                $rows      = array_map(fn($o) => "  • #{$o['DocNum']} — {$o['CardName']} | {$o['DocDate']} | €{$o['DocTotal']}", $orders);
                $context[] = "📋 ENCOMENDAS ABERTAS (últimas 5):\n" . implode("\n", $rows);
            }
        }

        // --- Purchase orders (extended: accounts payable terms) ---
        if (preg_match('/compra|purchase|fornecedor|supplier|PO |conta.a.pagar|accounts.payable|pagamento|payment|despesa|custo|cost/i', $message)) {
            $pos = $this->getOpenPurchaseOrders(5);
            if ($pos) {
                $rows      = array_map(fn($o) => "  • #{$o['DocNum']} — {$o['CardName']} | {$o['DocDate']} | €{$o['DocTotal']}", $pos);
                $context[] = "🏭 ORDENS DE COMPRA ABERTAS (últimas 5):\n" . implode("\n", $rows);
            }
        }

        // --- Invoices (extended: finance/accounting terms) ---
        if (preg_match('/fatura|invoice|factura|recibo|conta.a.receber|accounts.receivable|faturação|billing|receita|revenue|cobrar|cobrança/i', $message)) {
            $invoices = $this->getRecentInvoices(5);
            if ($invoices) {
                $rows      = array_map(fn($i) => "  • #{$i['DocNum']} — {$i['CardName']} | {$i['DocDate']} | €{$i['DocTotal']}", $invoices);
                $context[] = "🧾 FATURAS RECENTES (últimas 5):\n" . implode("\n", $rows);
            }
        }

        // --- Business partner (extended: financial/audit terms) ---
        if (preg_match('/cliente|client|fornecedor|supplier|parceiro|partner|empresa|devedor|debtor|credor|creditor|saldo.de|conta.corrente|current.account/i', $message)) {
            if (preg_match('/(?:cliente|client|fornecedor|supplier|parceiro|partner|empresa)\s+["\']?([A-Za-zÀ-ú\s]{3,30})["\']?/i', $message, $m)) {
                $bps = $this->searchBusinessPartners(trim($m[1]), 3);
                if ($bps) {
                    $rows      = array_map(fn($b) => "  • {$b['CardCode']} — {$b['CardName']} | Saldo: €{$b['Balance']}", $bps);
                    $context[] = "👤 PARCEIROS ENCONTRADOS:\n" . implode("\n", $rows);
                }
            }
        }

        if (empty($context)) {
            return '';
        }

        return "\n\n--- DADOS REAIS DO SAP B1 (PARTYARD) ---\n"
            . implode("\n\n", $context)
            . "\n--- FIM DADOS SAP ---\n";
    }
}
