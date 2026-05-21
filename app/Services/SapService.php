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

        // SECURITY: SAP B1 Service Layer often ships with a self-signed cert.
        // We expose the switch via config so production can pin the CA bundle
        // (SAP_TLS_VERIFY=/etc/ssl/certs/sap-ca.pem) instead of disabling TLS
        // entirely. Default is true — operators must opt in to weaken it.
        $this->http = new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => config('services.sap.tls_verify', true),
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

    // ─── HR / Employees (Dr.ª Ana Sobral) ──────────────────────────────────────
    //
    // SAP B1 ServiceLayer expõe employees via o endpoint EmployeesInfo (backed
    // por OHEM). Estes métodos são uma camada minimalista para a HrAgent —
    // retornam campos que NÃO são PII sensíveis (nomes, departamento, função,
    // contactos work). Salário e dados de baixa não são fetched aqui — esses
    // ficam reservados a queries explicitas com permissão admin.

    /**
     * Lista colaboradores activos (campos seguros — sem salário).
     * Útil para "quantos colaboradores temos?", "lista do departamento X".
     */
    public function getEmployees(int $top = 100): array
    {
        $data = $this->get('EmployeesInfo', [
            '$select'  => 'EmployeeID,FirstName,LastName,Position,DepartmentID,JobTitle,'
                        . 'Active,StartDate,WorkPhone,Email,Manager,Branch,Salary',
            '$filter'  => 'Active eq \'tYES\'',
            '$top'     => $top,
            '$orderby' => 'LastName asc,FirstName asc',
        ]);
        return $data['value'] ?? [];
    }

    /**
     * Procura colaborador por nome (substring case-insensitive).
     */
    public function searchEmployees(string $name, int $top = 10): array
    {
        $safe = addslashes($name);
        $data = $this->get('EmployeesInfo', [
            '$select'  => 'EmployeeID,FirstName,LastName,Position,DepartmentID,JobTitle,'
                        . 'Active,WorkPhone,Email,Manager,StartDate',
            '$filter'  => "substringof(tolower('{$safe}'),tolower(FirstName)) "
                        . "or substringof(tolower('{$safe}'),tolower(LastName))",
            '$top'     => $top,
        ]);
        return $data['value'] ?? [];
    }

    /**
     * Fetch um colaborador específico por EmployeeID.
     */
    public function getEmployeeById(int $employeeId): ?array
    {
        $data = $this->get("EmployeesInfo({$employeeId})", [
            '$select' => 'EmployeeID,FirstName,LastName,Position,DepartmentID,JobTitle,'
                       . 'Active,StartDate,EndDate,WorkPhone,Email,Manager,Branch,Salary',
        ]);
        return $data ?: null;
    }

    /**
     * Lista departamentos (OUDP via Departments endpoint).
     */
    public function getDepartments(): array
    {
        $data = $this->get('Departments', [
            '$select'  => 'Code,Name',
            '$top'     => 50,
            '$orderby' => 'Name asc',
        ]);
        return $data['value'] ?? [];
    }

    /**
     * Constrói bloco de contexto SAP HR baseado em keywords no prompt.
     * Detecta automaticamente que dados fetch para evitar chamadas
     * SAP desnecessárias em queries que não precisam.
     *
     * Failure-safe: se SAP estiver offline, devolve string vazia (o agente
     * cai na info embebida no system prompt + livros).
     */
    public function buildHrContext(string $message, ?callable $heartbeat = null): string
    {
        $hb = function (string $status) use ($heartbeat) {
            if ($heartbeat) $heartbeat($status);
        };

        if (!$this->username || !$this->password) {
            return "\n\n--- SAP B1 ---\nCredenciais SAP não configuradas — uso só fontes documentais.\n--- FIM ---\n";
        }
        if (!$this->ensureSession()) {
            return "\n\n--- SAP B1 ---\nLogin SAP recusado — uso só fontes documentais. Avisa o admin para verificar credenciais.\n--- FIM ---\n";
        }

        $lower    = mb_strtolower($message);
        $context  = [];

        $wantsList = preg_match(
            '/\b(colaboradores|funcionarios|funcionários|headcount|equipa|equipas|'
            . 'staff|lista|quantos|quantas|total|efectivos|empregados)\b/u',
            $lower
        );

        if ($wantsList) {
            $hb('a buscar colaboradores SAP');
            try {
                $emps = $this->getEmployees(120);
                if (!empty($emps)) {
                    // Group by department for a useful overview
                    $byDept = [];
                    foreach ($emps as $e) {
                        $d = $e['DepartmentID'] ?? 0;
                        $byDept[$d] = ($byDept[$d] ?? 0) + 1;
                    }
                    $depts = $this->getDepartments();
                    $deptNames = [];
                    foreach ($depts as $d) {
                        $deptNames[$d['Code'] ?? ''] = $d['Name'] ?? '(s/n)';
                    }
                    $rows = [];
                    foreach ($byDept as $code => $count) {
                        $name = $deptNames[$code] ?? "Dept #{$code}";
                        $rows[] = "  • {$name}: {$count}";
                    }
                    $context[] = "👥 HEADCOUNT SAP (OHEM/EmployeesInfo, " . count($emps) . " activos):\n"
                               . implode("\n", $rows);
                }
            } catch (\Throwable $e) {
                Log::warning('SAP HR list failed: ' . $e->getMessage());
            }
        }

        // Detect a person name like "do Bruno", "da Mónica", "do Daniel"
        if (preg_match(
            '/\b(?:d[oae]s?|sobre|info|informa[çc][ãa]o|dados)\s+([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]+)?)/u',
            $message,
            $m
        )) {
            $name = trim($m[1]);
            $hb("a procurar SAP: {$name}");
            try {
                $hits = $this->searchEmployees($name, 5);
                if (!empty($hits)) {
                    $rows = array_map(function ($e) {
                        $fn = trim(($e['FirstName'] ?? '') . ' ' . ($e['LastName'] ?? ''));
                        $jt = $e['JobTitle']  ?? ($e['Position'] ?? '?');
                        $st = $e['StartDate'] ?? '';
                        return "  • {$fn} — {$jt}" . ($st ? " (desde {$st})" : '');
                    }, $hits);
                    $context[] = "🔎 SAP — colaboradores que correspondem a '{$name}':\n"
                               . implode("\n", $rows);
                }
            } catch (\Throwable $e) {
                Log::warning('SAP HR search failed: ' . $e->getMessage());
            }
        }

        if (empty($context)) return '';

        return "\n\n--- DADOS SAP B1 (HR) ---\n" . implode("\n\n", $context)
             . "\n\nPRIVACIDADE: nunca reveles salário individual sem autorização explícita. "
             . "Quando perguntarem 'quanto ganha X', responde com a média do departamento.\n"
             . "--- FIM ---\n";
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
    /**
     * Search SAP B1 SalesOpportunities by OpportunityName containing
     * a needle string. Used by the auto-linker to match tenders without
     * sap_opportunity_number to existing SAP opportunities by reference.
     *
     * Returns raw opportunity records (top 5 by default). Case-sensitive
     * — SAP B1's OData layer doesn't honour tolower() in $filter.
     */
    public function searchOpportunitiesByName(string $needle, int $top = 5): array
    {
        $needle = addslashes($needle);
        $data = $this->get('SalesOpportunities', [
            '$select' => 'SequentialNo,CardCode,OpportunityName,PredictedClosingDate,Status,Remarks',
            '$filter' => "contains(OpportunityName,'{$needle}')",
            '$top'    => $top,
        ]);
        return $data['value'] ?? [];
    }

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
     * Fetch a single opportunity with its Stages tab expanded.
     *
     * In the SAP B1 UI this corresponds to the "Níveis" (Stages) separator:
     * each opportunity has a historical trail of stage transitions stored in
     * SalesOpportunitiesLines. The LAST row (highest LineNum or most recent
     * StartDate/CloseDate) represents the real current state — sometimes the
     * header `CurrentStageNo` lags behind because it only reflects the open
     * line.
     *
     * @param  int  $seqNo  SequentialNo (DocEntry) of the opportunity
     * @return array|null   decoded opportunity payload with SalesOpportunitiesLines
     */
    public function getOpportunityWithStages(int $seqNo): ?array
    {
        try {
            // SAP B1 ServiceLayer is fussy about the (key) URL form for
            // GET — observed in production 2026-04-29 returning empty
            // payloads without error. Using $filter=SequentialNo eq N
            // hits the collection endpoint and reliably returns the
            // single matching row.
            //
            // 2026-04-29 follow-up: $expand=SalesOpportunitiesLines on
            // top of $filter ALSO breaks this customer's instance —
            // returns empty value[]. Splitting into a base fetch + a
            // separate $expand call when callers actually need the
            // lines (most don't — status + remarks are top-level).
            $data = $this->get('SalesOpportunities', [
                '$filter' => "SequentialNo eq {$seqNo}",
                '$top'    => 1,
            ]);
            $rows = $data['value'] ?? [];
            return !empty($rows[0]) && isset($rows[0]['SequentialNo']) ? $rows[0] : null;
        } catch (\Throwable $e) {
            Log::warning("SapService: getOpportunityWithStages({$seqNo}) failed — " . $e->getMessage());
            return null;
        }
    }

    /**
     * Format an opportunity + its Níveis (stage lines) as a block to inject
     * into the prompt. The last stage line is always highlighted because it
     * is the one that reflects the TRUE current state of the opportunity.
     */
    public function formatOpportunityStages(array $opp): string
    {
        $seq   = $opp['SequentialNo']    ?? '?';
        $card  = $opp['CardName']        ?? '?';
        $name  = $opp['OpportunityName'] ?? '';
        $curr  = (int) ($opp['CurrentStageNo'] ?? 0);
        $max   = (float) ($opp['MaxLocalTotal'] ?? 0);

        $lines = $opp['SalesOpportunitiesLines'] ?? [];
        // Sort by LineNum ascending so the last element is the most recent level
        if (is_array($lines) && count($lines) > 1) {
            usort($lines, fn($a, $b) => ((int) ($a['LineNum'] ?? 0)) <=> ((int) ($b['LineNum'] ?? 0)));
        }

        $rows = [];
        foreach ($lines as $i => $l) {
            $rows[] = sprintf(
                "    %2d) Stage=%s (%s) | Vend=%s | Início=%s | Fecho=%s | %%=%s | €%s%s",
                (int) ($l['LineNum'] ?? $i),
                (string) ($l['StageKey'] ?? '?'),
                $this->getStageLabel((int) ($l['StageKey'] ?? 0)),
                (string) ($l['SalesEmployee'] ?? '?'),
                substr((string) ($l['StartDate'] ?? ''), 0, 10),
                substr((string) ($l['CloseDate'] ?? ($l['ClosedDate'] ?? '')), 0, 10) ?: '—',
                (string) ($l['PercentageRate'] ?? ''),
                number_format((float) ($l['MaxLocalTotal'] ?? 0), 0, '.', ','),
                !empty($l['Remarks']) ? ' | obs: ' . trim((string) $l['Remarks']) : ''
            );
        }

        $last     = !empty($lines) ? end($lines) : null;
        $lastTxt  = '—';
        if ($last) {
            $lastStage = (int) ($last['StageKey'] ?? 0);
            $lastTxt   = sprintf(
                "Stage=%d (%s) | LineNum=%s | Início=%s | Fecho=%s | %%=%s | €%s%s",
                $lastStage,
                $this->getStageLabel($lastStage),
                (string) ($last['LineNum'] ?? '?'),
                substr((string) ($last['StartDate'] ?? ''), 0, 10),
                substr((string) ($last['CloseDate'] ?? ($last['ClosedDate'] ?? '')), 0, 10) ?: '—',
                (string) ($last['PercentageRate'] ?? ''),
                number_format((float) ($last['MaxLocalTotal'] ?? 0), 0, '.', ','),
                !empty($last['Remarks']) ? ' | obs: ' . trim((string) $last['Remarks']) : ''
            );
        }

        $out  = "🎯 OPORTUNIDADE #{$seq} — {$card}" . ($name ? " — «{$name}»" : '') . "\n";
        $out .= "   Cabeçalho: CurrentStageNo={$curr} (" . $this->getStageLabel($curr) . ")"
             .  " | MaxLocalTotal=€" . number_format($max, 0, '.', ',') . "\n";
        $out .= "   ⬇️ Separador NÍVEIS (SalesOpportunitiesLines) — " . count($lines) . " linha(s):\n";
        $out .= $rows ? implode("\n", $rows) . "\n" : "    (sem linhas)\n";
        $out .= "   ⭐ ÚLTIMO ESTADO (última linha do separador Níveis) → {$lastTxt}";
        return $out;
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

    // ─── Pipeline Margins (Cotação de Venda vs Compra) ─────────────────────
    //
    // Para cada oportunidade aberta, extrai:
    //   • Cotação de Venda (StageKey 6) — máximo das linhas StageKey 6/7/8/10
    //   • Cotação de Compra (StageKey 5) — máximo das linhas StageKey 5/9
    //   • Diff = Venda - Compra (margem absoluta)
    //   • Margin% = Diff / Compra × 100 (% sobre custo)
    //
    // Caso o $expand=SalesOpportunitiesLines não devolva linhas (alguns
    // tenants do B1 falham este expand silenciosamente), fallback: o
    // header MaxLocalTotal é tratado como o lado de Compra e Venda fica 0
    // → flag "estimativa" no output.
    //
    // Performance: 1 chamada para listar opps + N chamadas para detalhe.
    // Top default 30 (≤30s típico). Para histórico, usa stage filter.

    /**
     * @param int|null $stageFilter  Opcional: filtra a uma fase (ex: 6 = só
     *                               Cotação de Venda). null = todas as
     *                               oportunidades abertas.
     * @param int      $top          Max opportunidades a analisar (default 30)
     * @return array<array{seq:int,name:string,opportunity:string,
     *                     sale_total:float,purchase_total:float,
     *                     diff:float,margin_pct:?float,stage:string,
     *                     estimated:bool}>
     */
    public function getPipelineMargins(?int $stageFilter = null, int $top = 30): array
    {
        $opps = $this->getSalesOpportunities($stageFilter, null, $top, 'sos_Open');
        if (empty($opps)) return [];

        $rows = [];
        foreach ($opps as $opp) {
            $seqNo = (int) ($opp['SequentialNo'] ?? 0);
            if ($seqNo <= 0) continue;

            $sale     = 0.0;
            $purchase = 0.0;
            $estimated = false;

            try {
                // Try $expand first — works on most tenants.
                $detail = $this->get("SalesOpportunities({$seqNo})", [
                    '$select' => 'SequentialNo,SalesOpportunitiesLines',
                    '$expand' => 'SalesOpportunitiesLines',
                ]);
                $lines = $detail['SalesOpportunitiesLines'] ?? [];

                foreach ($lines as $l) {
                    $sk    = (int) ($l['StageKey'] ?? 0);
                    $total = (float) ($l['MaxLocalTotal'] ?? 0);
                    // Sales-side stages: Cotação Venda (6), Follow Up (7),
                    // Possível Venda (8), Ordem de Venda (10).
                    if (in_array($sk, [6, 7, 8, 10], true)) {
                        $sale = max($sale, $total);
                    }
                    // Purchase-side: Cotação Compra (5), Ordem Compra (9).
                    if (in_array($sk, [5, 9], true)) {
                        $purchase = max($purchase, $total);
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("getPipelineMargins: line fetch failed #{$seqNo} — " . $e->getMessage());
            }

            // Fallback se expand falhar ou sem dados: header MaxLocalTotal
            // representa o valor da oportunidade — assumir Compra (conforme
            // pedido do utilizador: "total na oportunidade supostamente de
            // ordem de compra").
            if ($sale === 0.0 && $purchase === 0.0) {
                $purchase = (float) ($opp['MaxLocalTotal'] ?? 0);
                $estimated = true;
            }

            $diff = $sale - $purchase;
            $marginPct = $purchase > 0 ? round($diff / $purchase * 100, 1) : null;

            $rows[] = [
                'seq'            => $seqNo,
                'name'           => (string) ($opp['CardName'] ?? '?'),
                'opportunity'    => (string) ($opp['OpportunityName'] ?? ''),
                'sale_total'     => $sale,
                'purchase_total' => $purchase,
                'diff'           => $diff,
                'margin_pct'     => $marginPct,
                'stage'          => $this->getStageLabel((int) ($opp['CurrentStageNo'] ?? 0)),
                'sales_person'   => (string) ($opp['SalesPerson'] ?? '?'),
                'closing_date'   => substr((string) ($opp['PredictedClosingDate'] ?? ''), 0, 10),
                'estimated'      => $estimated,
            ];
        }

        // Ordena por margem absoluta descendente (maior oportunidade no topo).
        usort($rows, fn($a, $b) => $b['diff'] <=> $a['diff']);
        return $rows;
    }

    /**
     * Formata os resultados de getPipelineMargins() em bloco texto pronto a
     * injectar no prompt do Richard SAP. Sumário no fim com totais agregados.
     */
    public function formatPipelineMargins(array $margins): string
    {
        if (empty($margins)) {
            return "💰 ANÁLISE DE MARGENS — pipeline vazio ou sem dados.";
        }

        $rows = ["💰 MARGENS POR OPORTUNIDADE (Cotação Venda - Compra)\n"];
        $rows[] = sprintf(
            "  %-6s %-30s %-12s %12s %12s %12s %7s  %s",
            'SeqNo', 'Cliente', 'Fase', 'Venda €', 'Compra €', 'Margem €', 'Margem%', 'Fecho'
        );
        $rows[] = str_repeat('─', 130);

        $totalSale = 0.0;
        $totalPurchase = 0.0;
        $countEstimated = 0;

        foreach ($margins as $m) {
            $totalSale     += $m['sale_total'];
            $totalPurchase += $m['purchase_total'];
            if ($m['estimated']) $countEstimated++;

            $marginPctStr = $m['margin_pct'] !== null
                ? number_format($m['margin_pct'], 1) . '%'
                : '—';
            $estimTag = $m['estimated'] ? ' ⚠️' : '';

            $rows[] = sprintf(
                "  #%-5d %-30s %-12s %12s %12s %12s %7s  %s%s",
                $m['seq'],
                mb_strimwidth($m['name'], 0, 30, '…'),
                mb_strimwidth($m['stage'], 0, 12, '…'),
                number_format($m['sale_total'], 0, '.', ','),
                number_format($m['purchase_total'], 0, '.', ','),
                number_format($m['diff'], 0, '.', ','),
                $marginPctStr,
                $m['closing_date'] ?: '—',
                $estimTag
            );
        }

        $totalDiff = $totalSale - $totalPurchase;
        $totalMarginPct = $totalPurchase > 0
            ? round($totalDiff / $totalPurchase * 100, 1)
            : 0;

        $rows[] = str_repeat('─', 130);
        $rows[] = sprintf(
            "  TOTAIS (%d opps): Venda €%s | Compra €%s | Margem €%s (%s%%)",
            count($margins),
            number_format($totalSale, 0, '.', ','),
            number_format($totalPurchase, 0, '.', ','),
            number_format($totalDiff, 0, '.', ','),
            number_format($totalMarginPct, 1)
        );

        if ($countEstimated > 0) {
            $rows[] = "\n  ⚠️ {$countEstimated} opp(s) sem detalhe de fases — usado MaxLocalTotal como Compra (Venda=0).";
            $rows[] = "      Para análise completa, expandir SalesOpportunitiesLines em SAP.";
        }

        return implode("\n", $rows);
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
        $cardCode   = trim((string) ($data['CardCode'] ?? ''));
        $cardName   = trim((string) ($data['CardName'] ?? ''));
        $federalVat = trim((string) ($data['FederalTaxID'] ?? ''));

        // ── Priority 1: VAT/CNPJ lookup (most precise — handles BR prefix) ──
        // If FederalTaxID is provided (e.g. "BR02.709.449/0001-59"), search SAP by
        // FederalTaxID FIRST — more reliable than name matching.
        // Tried even if CardCode was supplied but looks wrong.
        $cardCodeLooksValid = $cardCode !== '' && strlen($cardCode) <= 15 && str_word_count($cardCode) === 1;
        if ($federalVat !== '' && !$cardCodeLooksValid) {
            try {
                $bps = $this->searchBPByVAT($federalVat, 3);
                if (!empty($bps)) {
                    // If cardName provided, try to find the best match by name similarity
                    $best = $bps[0];
                    if ($cardName !== '' && count($bps) > 1) {
                        foreach ($bps as $bp) {
                            if (stripos($bp['CardName'], substr($cardName, 0, 8)) !== false) {
                                $best = $bp;
                                break;
                            }
                        }
                    }
                    $cardCode = $best['CardCode'];
                    $cardName = $best['CardName'];
                    Log::info("CRM: resolved CardCode '{$cardCode}' for VAT '{$federalVat}' → '{$cardName}'");
                }
            } catch (\Throwable $e) {
                Log::warning("CRM: VAT lookup failed for '{$federalVat}': " . $e->getMessage());
            }
        }

        // ── Priority 2: Name lookup (fallback when no VAT or VAT didn't match) ──
        // A real SAP CardCode is short and has no spaces (e.g. "C00123", "OCNP01")
        // If CardCode is still empty OR looks like a full company name (contains spaces / long),
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

        // SalesPerson (SAP SalesEmployeeCode = OSLP.SlpCode)
        if (!empty($data['SalesPerson'])) {
            $payload['SalesPerson'] = (int) $data['SalesPerson'];

            // ── Proprietário (OwnerCode) = mesmo recurso humano que o Vendedor ──
            // Pedido do utilizador 2026-05-07: ao abrir oportunidades via MARTA
            // o campo "Proprietário" ficava em branco no SAP. Política: o
            // proprietário é sempre o vendedor da OP. OwnerCode → OHEM.empID,
            // SalesPerson → OSLP.SlpCode. EmployeesInfo faz a ponte (cached 24h).
            $ownerEmpId = $this->resolveOwnerCodeForSalesPerson((int) $data['SalesPerson']);
            if ($ownerEmpId !== null) {
                $payload['OwnerCode'] = $ownerEmpId;
            }
        }

        // ContactPerson (SAP CntctCode)
        if (!empty($data['ContactPerson'])) {
            $payload['ContactPerson'] = (int) $data['ContactPerson'];
        }

        // ── UDF "Status Oportunidade" — sempre "Em aberto" ao criar via MARTA ──
        // Pedido do utilizador 2026-05-07: o separador inferior do form (UDF
        // custom OOPR.U_StatusOp ou similar) ficava vazio. Política: ao criar
        // oportunidade por aqui, o estado custom arranca em "Em aberto".
        // Discover é cacheado 24h e o retry abaixo lida com SAP a rejeitar
        // o campo se a UDF não existir nesta instância.
        [$statusUdfField, $statusUdfOpenValue] = $this->discoverStatusOportunidadeField();
        if ($statusUdfField && $statusUdfOpenValue !== null) {
            $payload[$statusUdfField] = $statusUdfOpenValue;
        }

        // Predicted Closing Date — explicit date OR today + ClosingDays.
        //
        // SAP B1 rejects past dates with "Date deviates from permissible
        // range [OOPR.PredDate]". We've seen the LLM produce a date with
        // last year's calendar (the email it was reading was a year old)
        // and SAP refuse the whole opportunity create — confusing for the
        // operator because the JSON looked fine. Defensive snap-forward:
        // any date in the past is replaced with today + ClosingDays
        // (default 30) so the create succeeds and the operator can fix
        // the date in SAP if it really needed to be a different future
        // value.
        $todayTs = strtotime('today');
        $defaultDays = max(1, (int) ($data['ClosingDays'] ?? 30));
        $finalTs = null;

        if (!empty($data['ExpectedClosingDate'])) {
            $ts = strtotime($data['ExpectedClosingDate']);
            if ($ts && $ts >= $todayTs) {
                $finalTs = $ts;
            } else {
                Log::warning('SapService: ExpectedClosingDate is past, snapping forward', [
                    'requested'    => $data['ExpectedClosingDate'],
                    'closing_days' => $defaultDays,
                ]);
                $finalTs = strtotime("+{$defaultDays} days");
            }
        } elseif (!empty($data['ClosingDays'])) {
            $finalTs = strtotime("+{$defaultDays} days");
        }

        if ($finalTs) {
            $payload['PredictedClosingDate'] = date('Y-m-d\T00:00:00\Z', $finalTs);
        }

        // Information Source — PartYard custom material categories
        // (Engine Spares=1, Electrical=2, Pumps=3, … — see getOpportunitySources())
        // InformationSourceName is for display only — not sent to SAP
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

        // Retry 1b: Status UDF rejeitado (UDF não existe nesta instância OU
        // valor "Em aberto" não está nos ValidValues). Dropa o campo e
        // invalida cache para próxima criação re-descobrir.
        if (!$result && $statusUdfField && isset($filtered[$statusUdfField])) {
            $err = (string) $this->lastError;
            $statusErr = str_contains($err, $statusUdfField)
                || str_contains(strtolower($err), 'valid value')
                || str_contains(strtolower($err), 'user defined field');
            if ($statusErr) {
                $originalError = $this->lastError;
                Log::warning("CRM: retrying without Status UDF '{$statusUdfField}' (SAP: {$originalError})");
                unset($filtered[$statusUdfField]);
                Cache::forget('sap_udf_status_oportunidade_v2'); // re-descobre na próxima
                $result = $this->post('SalesOpportunities', $filtered);
                if ($result)  $this->lastError = '';
                elseif ($this->lastError === '') $this->lastError = $originalError;
            }
        }

        // Retry 1c: OwnerCode inválido (ex.: empID resolvido não está activo
        // ou a instância não usa Owner em SalesOpportunities).
        if (!$result && isset($filtered['OwnerCode'])
            && (str_contains(strtolower((string) $this->lastError), 'owner')
                || str_contains(strtolower((string) $this->lastError), 'employee'))) {
            $originalError = $this->lastError;
            Log::warning("CRM: retrying without OwnerCode (SAP: {$originalError})");
            unset($filtered['OwnerCode']);
            $result = $this->post('SalesOpportunities', $filtered);
            if ($result)  $this->lastError = '';
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

        // Retry 4: Business partner removed/inactive — try to find active BP by CardName
        if (!$result && str_contains((string) $this->lastError, 'removed')
            && $cardName !== '') {
            $originalError = $this->lastError;
            Log::warning("CRM: BP '{$cardCode}' marked as removed — searching active BP by name '{$cardName}'");
            try {
                // Search only ACTIVE business partners by name
                $bps = $this->get('BusinessPartners', [
                    '$filter' => "startswith(CardName,'" . addslashes(substr($cardName, 0, 20)) . "') and Frozen eq 'tNO'",
                    '$select' => 'CardCode,CardName',
                    '$top'    => 3,
                ]);
                $activeBps = $bps['value'] ?? [];
                if (!empty($activeBps)) {
                    $newCardCode = $activeBps[0]['CardCode'];
                    Log::info("CRM: found active BP '{$newCardCode}' for removed BP '{$cardCode}'");
                    $filtered['CardCode'] = $newCardCode;
                    // store resolved code so error message can show it
                    $cardCode = $newCardCode;
                    $result   = $this->post('SalesOpportunities', $filtered);
                    if ($result)  $this->lastError = '';
                    elseif ($this->lastError === '') $this->lastError = $originalError;
                } else {
                    $this->lastError = $originalError . " | Nenhum BP ativo encontrado para '{$cardName}' — verifica no SAP.";
                }
            } catch (\Throwable $e) {
                Log::warning("CRM: active BP lookup failed: " . $e->getMessage());
                $this->lastError = $originalError;
            }
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

        // Post-creation: create a SAP Project with Code = SequentialNo, then link it
        // to the opportunity via ProjectCode (Business Project field in General tab).
        // Two-step required: project must exist before it can be referenced.
        if ($result && isset($result['SequentialNo'])) {
            $seqNo    = $result['SequentialNo'];
            $projCode = (string) $seqNo;
            // Project Name = Opportunity Name (email subject), trimmed to 100 chars
            $oppName  = trim((string) ($data['OpportunityName'] ?? ''));
            $projName = $oppName !== '' ? substr($oppName, 0, 100) : "Oportunidade #{$seqNo}";
            try {
                // Step 1 — create the project (ignore 409 conflict if it already exists)
                $this->post('Projects', [
                    'Code'   => $projCode,
                    'Name'   => $projName,
                    'Active' => 'tYES',
                ]);
                // Step 2 — link project to opportunity
                $this->patch("SalesOpportunities({$seqNo})", ['ProjectCode' => $projCode]);
                Log::info("CRM: ProjectCode={$projCode} linked to opportunity #{$seqNo}");
            } catch (\Throwable $e) {
                Log::warning("CRM: ProjectCode PATCH failed for #{$seqNo}: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Update an existing Sales Opportunity (e.g. change stage, value).
     */
    public function updateOpportunity(int $sequentialNo, array $data): bool
    {
        // Same past-date snap-forward guard as createOpportunity. SAP B1
        // rejects PredictedClosingDate < today on PATCH too.
        $predicted = null;
        if (!empty($data['ExpectedClosingDate'])) {
            $ts = strtotime($data['ExpectedClosingDate']);
            $todayTs = strtotime('today');
            if ($ts && $ts >= $todayTs) {
                $predicted = date('Y-m-d\T00:00:00\Z', $ts);
            } else {
                $days = max(1, (int) ($data['ClosingDays'] ?? 30));
                Log::warning('SapService: updateOpportunity past date, snapping forward', [
                    'requested'    => $data['ExpectedClosingDate'],
                    'closing_days' => $days,
                ]);
                $predicted = date('Y-m-d\T00:00:00\Z', strtotime("+{$days} days"));
            }
        }

        // 2026-05-20 BUG FIX: alinhar com createOpportunity (linha ~1150)
        // que já trunca Remarks a 254 chars. A coluna SAP B1 OOPR.Remarks
        // é nvarchar(254); enviar mais que isso falha em silêncio (HTTP
        // 200/204 mas com payload rejeitado) ou trunca em sítio aleatório.
        // Reportado pelo Bruno: "este processo do jose.inacio não
        // sicronizou" — tender 316 / Opp 17545 tinha 10.072 chars em
        // notas vindas da Marta, o PATCH passou mas o operador não viu
        // nada de útil em SAP B1 Remarks. Ver MIGRATION 2026-05-20.
        $remarks = $data['Remarks'] ?? null;
        if ($remarks !== null) {
            $remarks = (string) $remarks;
            $origLen = mb_strlen($remarks);
            if ($origLen > 254) {
                // Trunca preservando o início do texto (header com a
                // assinatura "[Marta CRM · …]" e os primeiros factos
                // críticos). Sufixo "…" para indicar truncagem.
                $remarks = mb_substr($remarks, 0, 251) . '...';
                Log::info('SapService: updateOpportunity Remarks truncado', [
                    'seq_no'    => $sequentialNo,
                    'orig_len'  => $origLen,
                    'sent_len'  => mb_strlen($remarks),
                ]);
            }
        }

        $payload = array_filter([
            'CurrentStageNo'       => isset($data['StageId']) ? (int) $data['StageId'] : null,
            'SalesPerson'          => isset($data['SalesPerson']) ? (int) $data['SalesPerson'] : null,
            'Remarks'              => $remarks,
            'PredictedClosingDate' => $predicted,
        ], fn($v) => $v !== null);

        $result = $this->patch("SalesOpportunities({$sequentialNo})", $payload);
        $ok = !empty($result['ok']);

        // 2026-05-20: log info no SUCESSO também (antes só logava falhas).
        // Permite auditar o que foi para SAP e quando.
        if ($ok) {
            Log::info('SapService: updateOpportunity OK', [
                'seq_no'      => $sequentialNo,
                'fields_sent' => array_keys($payload),
                'remarks_len' => isset($payload['Remarks']) ? mb_strlen((string) $payload['Remarks']) : 0,
            ]);
        }

        return $ok;
    }

    /**
     * 2026-05-21 BUG FIX: "quando carrregados info nas notas e actuliza nos
     * remarks do sap, se actulizaamos está a apagar os remarks no SAP".
     *
     * O update direct via updateOpportunity SUBSTITUI o campo Remarks
     * inteiro. Se o user no Dia 2 mete notas mais curtas que no Dia 1,
     * SAP perde info do Dia 1.
     *
     * Esta helper faz MERGE seguro:
     *   1. Lê o Remarks actual em SAP
     *   2. Se $newAddition já está como substring em current → noop (return ok)
     *   3. Se SAP está vazio → push $newAddition
     *   4. Se SAP tem conteúdo:
     *        a) Se o novo conteúdo (do tender.notes) CONTÉM o que está em SAP
     *           → é uma extensão, push novo (truncado a 254)
     *        b) Caso contrário → MERGE: SAP_current + " · " + $newAddition,
     *           truncado mantendo o MAIS RECENTE (do fim, não do início)
     *
     * Resultado: nunca se perde info dia-a-dia. O campo cresce até 254
     * chars e depois faz FIFO mantendo o mais recente.
     *
     * @param int    $sequentialNo  SAP opportunity SequentialNo
     * @param string $newAddition   O novo conteúdo a juntar (full notes do tender)
     */
    public function appendRemarks(int $sequentialNo, string $newAddition): bool
    {
        $newAddition = trim($newAddition);
        if ($newAddition === '') {
            return true; // noop graceful
        }

        $current = '';
        try {
            $opp = $this->getOpportunityWithStages($sequentialNo);
            $current = trim((string) ($opp['Remarks'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('SapService::appendRemarks: failed to read current, falling back to overwrite', [
                'seq_no' => $sequentialNo,
                'error'  => $e->getMessage(),
            ]);
            // Falha a ler → cai no path antigo (overwrite). Melhor isso
            // do que falhar tudo.
            return $this->updateOpportunity($sequentialNo, ['Remarks' => $newAddition]);
        }

        // Caso 1: já lá está como substring
        if ($current !== '' && str_contains($current, $newAddition)) {
            Log::info('SapService::appendRemarks: addition already present, noop', [
                'seq_no'       => $sequentialNo,
                'current_len'  => mb_strlen($current),
                'addition_len' => mb_strlen($newAddition),
            ]);
            return true;
        }

        // Caso 2: SAP vazio
        if ($current === '') {
            return $this->updateOpportunity($sequentialNo, ['Remarks' => $newAddition]);
        }

        // Caso 3a: $newAddition é superset (contém todo o SAP actual)
        if (str_contains($newAddition, $current)) {
            // Extension natural — push novo (truncate happens em updateOpportunity)
            Log::info('SapService::appendRemarks: new contains current, replacing safely', [
                'seq_no'         => $sequentialNo,
                'current_len'    => mb_strlen($current),
                'new_len'        => mb_strlen($newAddition),
            ]);
            return $this->updateOpportunity($sequentialNo, ['Remarks' => $newAddition]);
        }

        // Caso 3b: divergem — merge concatenado com FIFO (mais recente fica)
        $merged = $current . ' · ' . $newAddition;
        // SAP limit 254 — se exceder, mantém o FIM (mais recente) cortando o início
        if (mb_strlen($merged) > 254) {
            $merged = '…' . mb_substr($merged, -253);
        }
        Log::info('SapService::appendRemarks: merging current + addition', [
            'seq_no'      => $sequentialNo,
            'current_len' => mb_strlen($current),
            'addition_len'=> mb_strlen($newAddition),
            'merged_len'  => mb_strlen($merged),
        ]);
        return $this->updateOpportunity($sequentialNo, ['Remarks' => $merged]);
    }

    /**
     * Hardcoded fallback list of PartYard Sales Persons.
     * Used when SAP B1 is offline / returns empty. EmployeeID 0 means
     * "no SAP code yet" — Marta should still be able to validate the name
     * via searchSalesEmployee() before creating the opportunity.
     *
     * Match the SAP screenshot 2026-05-03 — keep alphabetical (matches
     * SAP's default $orderby).
     */
    public const SALES_EMPLOYEES_FALLBACK = [
        'Ana Sobral',
        'Bruno Monteiro',
        'Catarina Aresta',
        'Catarina Sequeira',
        'Claudia Leal',
        'Eduardo Rio',
        'Joao Murta',
        'José Inácio',
        'Luis Gomes',
        'Mónica Pereira',
        'Olimpia Pires',
        'Pedro Duarte',
        'Sonia Osorio',
        'Victor Macedo',
    ];

    /**
     * Fetch all SAP Sales Persons (the list used by CRM Opportunities).
     *
     * Uses the SalesPersons entity — NOT EmployeesInfo — because the
     * SalesOpportunities.SalesPerson field takes SalesEmployeeCode from this table.
     * Cached 24h — these rarely change. Falls back to SALES_EMPLOYEES_FALLBACK
     * when SAP is unreachable so Marta keeps showing the correct names.
     */
    /** Cache key prefix used by getSalesEmployees — exposed for admin refresh. */
    public const SALES_EMPLOYEES_CACHE_PREFIX = 'sap_sales_employees_v2:';

    public function getSalesEmployees(int $top = 30): array
    {
        $cacheKey = self::SALES_EMPLOYEES_CACHE_PREFIX . $top;

        // 60 min cache: balance between SAP session pressure and freshness.
        // New vendor added in SAP → visible to Marta within 1h, OR immediately
        // via the "↻ Refresh SAP catalog" button in /admin/panel.
        return Cache::remember($cacheKey, 60, function () use ($top) {
            try {
                $data = $this->get('SalesPersons', [
                    '$select'  => 'SalesEmployeeCode,SalesEmployeeName,Active',
                    '$filter'  => "Active eq 'tYES'",
                    '$top'     => $top,
                    '$orderby' => 'SalesEmployeeName asc',
                ]);

                $rows = $data['value'] ?? [];
                if (!empty($rows)) {
                    return array_map(fn($r) => [
                        'EmployeeID' => $r['SalesEmployeeCode'] ?? 0,
                        'FirstName'  => $r['SalesEmployeeName'] ?? '',
                        'LastName'   => '',
                        'FullName'   => $r['SalesEmployeeName'] ?? '',
                    ], $rows);
                }
                Log::warning('SAP: SalesPersons returned empty — using fallback');
            } catch (\Throwable $e) {
                Log::warning('SAP: getSalesEmployees failed — ' . $e->getMessage());
            }

            // Fallback: known PartYard names. EmployeeID=0 sinaliza "sem
            // código SAP no contexto" — Marta deve confirmar via search
            // antes de criar a oportunidade.
            return array_map(fn($name) => [
                'EmployeeID' => 0,
                'FirstName'  => $name,
                'LastName'   => '',
                'FullName'   => $name,
            ], self::SALES_EMPLOYEES_FALLBACK);
        });
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
     * Caches for 24 h — só interroga o SAP uma vez por deploy.
     *
     * Devolve [field, value] onde field = nome da coluna API (ex.
     * "U_StatusOp") e value = código do "Em aberto" descoberto a partir
     * dos ValidValuesMD do UDF. Se não encontrar UDF de status, devolve
     * [null, null]. Se encontrar campo mas não achar valor "aberto",
     * devolve [field, null] — o caller fica responsável por decidir se
     * envia algo.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function discoverStatusOportunidadeField(): array
    {
        $cacheKey = 'sap_udf_status_oportunidade_v2';

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?: [null, null];
        }

        try {
            $data = $this->get('UserFieldsMD', [
                '$filter' => "TableName eq 'OOPR'",
                '$select' => 'FieldID,FieldName,Description,ValidValuesMD',
                '$top'    => 50,
            ]);

            foreach ($data['value'] ?? [] as $udf) {
                $desc = strtolower($udf['Description'] ?? '');
                $name = strtolower($udf['FieldName'] ?? '');
                // Match descrição PT/BR ("Status Oportunidade"/"Estado Oportunidade")
                // ou nome técnico do campo. Excluímos "status" do core (não vem aqui
                // porque já filtrámos por OOPR + UDF only).
                if (
                    str_contains($desc, 'status') || str_contains($desc, 'estado')
                    || str_contains($name, 'status') || str_contains($name, 'estado')
                ) {
                    $apiField = 'U_' . $udf['FieldName'];

                    // Procurar valor "Em aberto" / "Aberto" / "Open" nos ValidValuesMD
                    $openValue = null;
                    foreach (($udf['ValidValuesMD'] ?? []) as $vv) {
                        $vDesc = strtolower($vv['Description'] ?? '');
                        if (str_contains($vDesc, 'aberto') || str_contains($vDesc, 'open')) {
                            $openValue = (string) ($vv['Value'] ?? '');
                            break;
                        }
                    }
                    // Fallback: se UDF não tem ValidValues definidos (alfanumérico
                    // livre), assume que o texto "Em aberto" é o valor a inserir.
                    if ($openValue === null && empty($udf['ValidValuesMD'])) {
                        $openValue = 'Em aberto';
                    }

                    $tuple = [$apiField, $openValue];
                    Cache::put($cacheKey, $tuple, now()->addHours(24));
                    Log::info("CRM: Status Oportunidade UDF descoberto", [
                        'field' => $apiField,
                        'value' => $openValue,
                    ]);
                    return $tuple;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("CRM: could not discover Status UDF: " . $e->getMessage());
        }

        Cache::put($cacheKey, [null, null], now()->addHours(1));  // negative cache 1 h
        return [null, null];
    }

    /**
     * Resolve OwnerCode (OHEM.empID) for a given SalesPerson (OSLP.SlpCode).
     * O campo "Proprietário" no SAP B1 referencia OHEM, não OSLP — quando o
     * utilizador escolhe um vendedor na MARTA, queremos que o documento fique
     * "owned" pelo mesmo recurso humano. Cache 24 h por SlpCode.
     *
     * Devolve null se não encontrar (vendedor sem registo HR linkado).
     */
    public function resolveOwnerCodeForSalesPerson(int $slpCode): ?int
    {
        if ($slpCode <= 0) return null;

        $cacheKey = "sap_owner_for_slp_{$slpCode}";
        if (Cache::has($cacheKey)) {
            $v = Cache::get($cacheKey);
            return $v ? (int) $v : null;
        }

        try {
            $data = $this->get('EmployeesInfo', [
                '$filter' => "SalesPersonCode eq {$slpCode}",
                '$select' => 'EmployeeID,SalesPersonCode,FirstName,LastName',
                '$top'    => 1,
            ]);

            $row = $data['value'][0] ?? null;
            $empId = $row && isset($row['EmployeeID']) ? (int) $row['EmployeeID'] : 0;

            Cache::put($cacheKey, $empId, now()->addHours(24));
            if ($empId > 0) {
                Log::info("CRM: OwnerCode resolved", ['slpCode' => $slpCode, 'empId' => $empId]);
                return $empId;
            }
        } catch (\Throwable $e) {
            Log::warning("CRM: resolveOwnerCodeForSalesPerson failed", [
                'slpCode' => $slpCode,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Search a Business Partner by VAT / NIF / CNPJ / FederalTaxID.
     *
     * Tries multiple format variants so "BR02.709.449/0001-59" matches SAP regardless
     * of how the FederalTaxID was entered (with/without prefix, with/without separators).
     *
     * Variant priority:
     *   1. Full cleaned string          e.g. "BR02709449000159"
     *   2. Digits-only (no country)     e.g. "02709449000159"
     *   3. Country prefix + digits      e.g. "BR" + "02709449000159"
     *   4. Short unique digits (last 8) e.g. "49000159"
     */
    public function searchBPByVAT(string $vat, int $top = 3): array
    {
        // Strip separators, keep alphanumeric
        $full  = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($vat));
        // Separate country prefix (2 alpha) from digits
        $country = '';
        $digits  = $full;
        if (preg_match('/^([A-Z]{2})(\d+)$/', $full, $m)) {
            $country = $m[1];
            $digits  = $m[2];
        }

        $tried   = [];
        $results = [];

        $tryFilter = function(string $token) use ($top, &$tried, &$results) {
            if ($token === '' || in_array($token, $tried)) return;
            $tried[] = $token;
            try {
                $data = $this->get('BusinessPartners', [
                    '$select' => 'CardCode,CardName,CardType,FederalTaxID',
                    '$filter' => "contains(FederalTaxID,'{$token}')",
                    '$top'    => $top,
                ]);
                $rows = $data['value'] ?? [];
                if (!empty($rows)) {
                    $results = array_merge($results, $rows);
                }
            } catch (\Throwable $e) {
                // ignore per-variant errors
            }
        };

        $tryFilter($full);                       // BR02709449000159
        if ($digits !== $full) {
            $tryFilter($country . $digits);      // same as full but re-composed
            $tryFilter($digits);                 // 02709449000159 (no country)
        }
        // Last 8 digits as tiebreaker
        if (strlen($digits) >= 8) {
            $tryFilter(substr($digits, -8));
        }

        // Deduplicate by CardCode
        $seen = [];
        $unique = [];
        foreach ($results as $r) {
            if (!isset($seen[$r['CardCode']])) {
                $seen[$r['CardCode']] = true;
                $unique[] = $r;
            }
        }
        return array_slice($unique, 0, $top);
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


    /**
     * Get the list of valid InformationSource (Source) values configured in SAP B1.
     * Returns array of ['code' => int, 'name' => string].
     * Cached for 24 h — these rarely change.
     */
    /** Cache key used by getOpportunitySources — exposed for admin refresh. */
    public const OPP_SOURCES_CACHE_KEY = 'sap_opp_sources';

    public function getOpportunitySources(): array
    {
        // 60 min — same trade-off as getSalesEmployees. Manual refresh
        // available in /admin/panel for immediate updates.
        return Cache::remember(self::OPP_SOURCES_CACHE_KEY, 60, function () {
            try {
                $session = $this->ensureSession();
                if (!$session) return [];

                // SAP B1 stores custom Source descriptions in SalesOpportunitySources
                $response = $this->http->get("{$this->baseUrl}/SalesOpportunitySources", [
                    'http_errors' => false,
                    'headers'     => ['Cookie' => "B1SESSION={$session}"],
                    'query'       => ['$select' => 'Num,Description', '$orderby' => 'Num asc'],
                ]);

                if ($response->getStatusCode() === 200) {
                    $data = json_decode($response->getBody()->getContents(), true);
                    $rows = $data['value'] ?? [];
                    if (!empty($rows)) {
                        return array_map(fn($r) => [
                            'code' => (int) ($r['Num'] ?? $r['SequenceNo'] ?? 0),
                            'name' => $r['Description'] ?? $r['Name'] ?? '',
                        ], $rows);
                    }
                }

                // Fallback: try querying via OData metadata or known PartYard categories
                Log::warning('SAP: SalesOpportunitySources endpoint returned no data — using fallback list');
            } catch (\Throwable $e) {
                Log::warning('SAP: getOpportunitySources failed — ' . $e->getMessage());
            }

            // Hardcoded fallback based on PartYard's SAP configuration (from UI screenshot)
            return [
                ['code' => 1,  'name' => 'Engine Spares/Vehicle Spares'],
                ['code' => 2,  'name' => 'Electrical Equip/Power Systems'],
                ['code' => 3,  'name' => 'Pump Spares, Separators'],
                ['code' => 4,  'name' => 'Hydraulic Equipment'],
                ['code' => 5,  'name' => 'REPAIR services/MRO Overhaul'],
                ['code' => 6,  'name' => 'HSM Shredder/Military Secure'],
                ['code' => 7,  'name' => 'OUTROS/Defense Miscellaneous'],
                ['code' => 8,  'name' => 'Pneumatic Equipment/Systems'],
                ['code' => 9,  'name' => 'Batteries/Military-Grade Batteries'],
                ['code' => 10, 'name' => 'APRESTOS/Combat Outfits & Field Gear'],
                ['code' => 11, 'name' => 'Electronical Equip/Avionics'],
                ['code' => 12, 'name' => 'Galley Equipment/Field Kitchen'],
                ['code' => 13, 'name' => 'Fire Security Systems'],
                ['code' => 14, 'name' => 'Lubricant Oils/MIL-SPEC Lubricants'],
                ['code' => 15, 'name' => 'Radar Systems/C4ISR & Radar'],
                ['code' => 16, 'name' => 'Containers/Mobile Command & Control'],
                ['code' => 17, 'name' => 'Marine chemicals/Decontamination'],
                ['code' => 18, 'name' => 'Burner Spares/Heating Systems'],
                ['code' => 19, 'name' => 'Mechanical Equip/Weapon & Platform'],
                ['code' => 20, 'name' => 'Decontamination & Specialty Military'],
                ['code' => 21, 'name' => 'IT equipment & Software Licenses'],
            ];
        });
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
    public function buildContext(string $message, ?callable $heartbeat = null): string
    {
        $hb = function (string $status) use ($heartbeat) {
            if ($heartbeat) $heartbeat($status);
        };

        if (!$this->username || !$this->password) {
            return "\n\n--- ERRO SAP B1 ---\nCredenciais não configuradas (SAP_B1_USER / SAP_B1_PASSWORD em falta no .env do servidor).\nDiz ao utilizador que as credenciais SAP não estão configuradas e que deve contactar o administrador.\n--- FIM ERRO ---\n";
        }

        // Test if we can get a session — detect login failures explicitly
        $hb('a autenticar SAP B1');
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

        // ── OVERVIEW — cached 3 min to avoid 3× sequential SAP calls per message ──
        // Each SAP call can take 5-15s; caching prevents stream timeouts.
        $cacheKey     = 'sap_overview_' . md5($this->company);
        $overviewText = Cache::remember($cacheKey, now()->addMinutes(3), function () use ($hb) {
            $parts = [];

            $hb('a buscar faturas SAP');
            $recentInvoices = $this->getRecentInvoices(8);
            if ($recentInvoices) {
                $rows  = array_map(fn($i) => "  • #{$i['DocNum']} — {$i['CardName']} | {$i['DocDate']} | €" . number_format((float)$i['DocTotal'], 2, '.', ',') . " | " . ($i['DocumentStatus'] === 'bost_Open' ? 'Aberta' : 'Fechada'), $recentInvoices);
                $parts[] = "🧾 ÚLTIMAS FATURAS (8):\n" . implode("\n", $rows);
            }

            $hb('a buscar encomendas SAP');
            $openOrders = $this->getOpenSalesOrders(8);
            if ($openOrders) {
                $rows  = array_map(fn($o) => "  • #{$o['DocNum']} — {$o['CardName']} | {$o['DocDate']} | €" . number_format((float)$o['DocTotal'], 2, '.', ','), $openOrders);
                $parts[] = "📋 ENCOMENDAS DE VENDA ABERTAS (8):\n" . implode("\n", $rows);
            }

            $hb('a buscar ordens de compra SAP');
            $openPOs = $this->getOpenPurchaseOrders(5);
            if ($openPOs) {
                $rows  = array_map(fn($o) => "  • #{$o['DocNum']} — {$o['CardName']} | {$o['DocDate']} | €" . number_format((float)$o['DocTotal'], 2, '.', ','), $openPOs);
                $parts[] = "🏭 ORDENS DE COMPRA ABERTAS (5):\n" . implode("\n", $rows);
            }

            return implode("\n\n", $parts);
        });

        if ($overviewText) {
            $context[] = $overviewText;
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

        // --- CRM: Specific opportunity by SequentialNo (e.g. "oportunidade 456", "opp #123") ---
        //   When the user references a concrete opportunity, fetch it with the
        //   "Níveis" (Stages) tab expanded — SalesOpportunitiesLines — so the
        //   agent can report the LAST line as the true current state.
        if (preg_match('/(?:oportunidad[ea]|opp(?:ortunity)?|oppty|#)\s*[#:]?\s*(\d{1,7})/iu', $message, $oppMatch)) {
            try {
                $seqNo = (int) $oppMatch[1];
                if ($seqNo > 0) {
                    $opp = $this->getOpportunityWithStages($seqNo);
                    if ($opp) {
                        $context[] = $this->formatOpportunityStages($opp);
                    } else {
                        $context[] = "⚠️ Oportunidade #{$seqNo}: não encontrada no SAP B1.";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('SapService: opportunity detail fetch failed — ' . $e->getMessage());
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

                // Margin analysis per opportunity: Cotação Venda vs Compra.
                // Dispara em keywords explícitas — caro porque faz N+1 calls
                // (uma por opportunity para expandir SalesOpportunitiesLines).
                if (preg_match(
                    '/(margem|margens|diferen[çc]a|venda.{0,4}vs.{0,4}compra|compra.{0,4}vs.{0,4}venda|lucro|margin|profit|pricing.gap)/i',
                    $message
                )) {
                    if ($heartbeat) $heartbeat('a calcular margens por oportunidade');
                    $margins = $this->getPipelineMargins(null, 50);
                    if ($margins) {
                        $context[] = $this->formatPipelineMargins($margins);
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
