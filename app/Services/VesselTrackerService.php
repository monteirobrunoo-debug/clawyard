<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * VesselTrackerService — autenticação e extracção de dados do
 * vesseltracker.com (AIS realtime, shipowner directory, vessel specs).
 *
 * USE CASE PartYard:
 *   • Identificar armadores activos por região (porto, zona AIS)
 *   • Extrair vessel name + IMO + engine make/model + owner contacts
 *   • Pipeline outreach: armador → contact → email → Marta CRM opportunity
 *
 * SECURITY:
 *   • Credenciais vêm SEMPRE de env (VESSELTRACKER_USERNAME / _PASSWORD)
 *   • Nunca persistidas em DB ou logs
 *   • Session cookie cached em Laravel Cache (TTL 1h)
 *   • Failure-safe: sem credentials → retorna mensagem clara, sem stack trace
 *
 * IMPLEMENTAÇÃO ACTUAL:
 *   Endpoints exactos do vesseltracker.com não são públicos — esta classe
 *   tem 2 caminhos:
 *     1) Login HTTP + scrape (placeholder com TODOs até confirmar endpoints)
 *     2) Tavily fallback (site:vesseltracker.com — funciona para info pública)
 *   O agente cai sempre no Tavily quando o caminho autenticado falha.
 *
 * Quando o user confirmar quais endpoints retornam JSON (Dev Tools no
 * browser autenticado), substituímos o stub por chamadas reais.
 */
class VesselTrackerService
{
    private const BASE_URL    = 'https://www.vesseltracker.com';
    private const LOGIN_PATH  = '/login';      // POST — confirm in browser DevTools
    private const SESSION_TTL = 3600;          // 1h
    private const CACHE_KEY   = 'vesseltracker:session';

    private ?string $username;
    private ?string $password;
    private Client  $http;
    private CookieJar $cookies;
    private string  $lastError = '';

    public function __construct()
    {
        $this->username = env('VESSELTRACKER_USERNAME') ?: config('services.vesseltracker.username');
        $this->password = env('VESSELTRACKER_PASSWORD') ?: config('services.vesseltracker.password');
        $this->cookies  = new CookieJar();
        $this->http     = new Client([
            'base_uri'        => self::BASE_URL,
            'timeout'         => 30,
            'connect_timeout' => 10,
            'cookies'         => $this->cookies,
            'allow_redirects' => true,
            'headers'         => [
                'User-Agent'      => 'Mozilla/5.0 (compatible; ClawYard-PartYard/1.0; +https://partyard.eu)',
                'Accept-Language' => 'en-US,en;q=0.9,pt;q=0.8',
            ],
        ]);
    }

    public function isAvailable(): bool
    {
        return !empty($this->username) && !empty($this->password);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Faz login no portal e cacheia a sessão. Devolve true se OK.
     *
     * TODO: confirmar endpoint /login exacto via DevTools.
     * Sites AIS profissionais costumam ter:
     *   POST /login   (form-data: email, password)
     *   POST /api/auth/login   (JSON)
     */
    public function login(): bool
    {
        if (!$this->isAvailable()) {
            $this->lastError = 'VESSELTRACKER_USERNAME/PASSWORD em falta no .env do servidor.';
            return false;
        }

        // Tenta reutilizar sessão cached primeiro
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && !empty($cached['cookies'])) {
            foreach ($cached['cookies'] as $cookieArray) {
                $this->cookies->setCookie(\GuzzleHttp\Cookie\SetCookie::fromString($cookieArray));
            }
            // Probe para confirmar que a sessão ainda é válida
            if ($this->ping()) return true;
            Cache::forget(self::CACHE_KEY);
        }

        try {
            $response = $this->http->post(self::LOGIN_PATH, [
                'form_params' => [
                    'email'    => $this->username,
                    'password' => $this->password,
                ],
                'http_errors' => false,
            ]);

            $code = $response->getStatusCode();
            $body = (string) $response->getBody();

            // Detecção heurística de login bem-sucedido:
            //   • status 200 e ausência de "invalid credentials"
            //   • OR status 302 redirect para dashboard
            $isLoggedIn = ($code === 200 || $code === 302)
                && stripos($body, 'invalid') === false
                && stripos($body, 'wrong password') === false
                && stripos($body, 'login failed') === false;

            if (!$isLoggedIn) {
                $this->lastError = "Login vesseltracker.com recusado (HTTP {$code}). "
                                 . "Verifica VESSELTRACKER_USERNAME / PASSWORD no .env.";
                Log::warning("VesselTrackerService: login failed", ['code' => $code]);
                return false;
            }

            // Cache cookies para reutilizar nas próximas chamadas
            $jarItems = [];
            foreach ($this->cookies as $c) {
                $jarItems[] = (string) $c;
            }
            Cache::put(self::CACHE_KEY, ['cookies' => $jarItems], self::SESSION_TTL);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = 'VesselTracker login exception: ' . $e->getMessage();
            Log::warning($this->lastError);
            return false;
        }
    }

    /**
     * Probe leve para verificar se a sessão actual é válida.
     */
    public function ping(): bool
    {
        try {
            $r = $this->http->get('/', ['http_errors' => false]);
            $body = (string) $r->getBody();
            // Página landing autenticada normalmente contém "logout" ou nome do user
            return stripos($body, 'logout') !== false || stripos($body, 'sign out') !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function testConnection(): array
    {
        if (!$this->isAvailable()) {
            return [
                'ok'      => false,
                'message' => '❌ VESSELTRACKER_USERNAME / PASSWORD em falta no .env do servidor.',
            ];
        }

        $ok = $this->login();
        return [
            'ok'      => $ok,
            'message' => $ok
                ? "✅ Ligação vesseltracker.com OK como {$this->username}"
                : "❌ {$this->lastError}",
        ];
    }

    /**
     * Constrói bloco de contexto para o agente.
     *
     * Estratégia híbrida:
     *   1. Se login OK + endpoint autenticado existir → fetch directo
     *   2. Fallback Tavily com site:vesseltracker.com — info pública
     *      (vessel positions, owner names em listings públicos)
     *
     * Heurística para detecção:
     *   • Termos de armador/owner → procura shipowner directory
     *   • Termos de porto (rotterdam, antwerp, lisboa) → vessels in port
     *   • Termos de motor (mtu, mak, caterpillar) → vessel + machinery
     */
    public function buildContext(string $message, ?callable $heartbeat = null): string
    {
        $msg = mb_strtolower($message);

        // Decide intent
        $wantsOwners = preg_match(
            '/\b(armador|armadores|shipowner|ship.?owner|owner|operator|fleet)\b/u',
            $msg
        );
        $wantsPort = preg_match(
            '/\b(porto|port|rotterdam|antwerp|hamburg|lisboa|setubal|leixões|sines|pireu|piraeus|setúbal|valencia|algeciras|niter[oó]i|rio.de.janeiro)\b/u',
            $msg
        );
        $wantsMachinery = preg_match(
            '/\b(motor|engine|mtu|caterpillar|c[ae]t|mak|wartsila|w[äa]rtsil[äa]|man|schottel|jenbacher|skf|propulsion|propulsor)\b/u',
            $msg
        );

        if (!$wantsOwners && !$wantsPort && !$wantsMachinery) {
            return '';
        }

        // Fallback Tavily para conteúdo público enquanto endpoints autenticados
        // não estão mapeados. Isto JÁ devolve listings reais — Tavily indexa
        // muitas páginas públicas do vesseltracker.com.
        $searcher = new WebSearchService();
        if (!$searcher->isAvailable()) {
            return "\n\n--- VESSELTRACKER ---\nTavily indisponível e endpoints autenticados ainda não mapeados.\nUse o portal directamente: " . self::BASE_URL . "\n--- FIM ---\n";
        }

        $queries = [];
        if ($wantsOwners) {
            $queries[] = 'shipowner directory contact email fleet';
        }
        if ($wantsPort) {
            // Extract port name (rough)
            if (preg_match('/\b(rotterdam|antwerp|hamburg|lisboa|setúbal|setubal|leixões|leixoes|sines|pireu|piraeus|valencia|algeciras|niter[oó]i|rio.de.janeiro)\b/iu', $message, $portMatch)) {
                $port = $portMatch[1];
                $queries[] = "vessels in port {$port} AIS";
            }
        }
        if ($wantsMachinery) {
            if (preg_match('/\b(mtu|caterpillar|cat|mak|wartsila|w[äa]rtsil[äa]|man|schottel|jenbacher|skf|cummins)\b/iu', $message, $mm)) {
                $engine = strtolower($mm[1]);
                $queries[] = "vessel {$engine} engine propulsion fleet owner";
            }
        }

        $blocks = [];
        foreach ($queries as $q) {
            if ($heartbeat) $heartbeat("VesselTracker: {$q}");
            try {
                $raw = $searcher->search("site:vesseltracker.com {$q}", 6, 'basic', 60);
                if ($raw && !str_starts_with($raw, '(')) {
                    $blocks[] = "### {$q}\n{$raw}";
                }
            } catch (\Throwable $e) {
                Log::warning('VesselTracker Tavily failed: ' . $e->getMessage());
            }
        }

        if (empty($blocks)) {
            return '';
        }

        return "\n\n--- DADOS VESSELTRACKER.COM ---\n"
             . "Portal AIS realtime + shipowner directory. Auth credentials configuradas: "
             . ($this->isAvailable() ? '✓' : '✗')
             . "\n\n"
             . implode("\n\n", $blocks)
             . "\n\nPRÓXIMO PASSO PARTYARD: para cada armador/vessel relevante, "
             . "(1) abrir o link directo no portal autenticado para confirmar contactos,"
             . " (2) gerar email outreach via Daniel Email, "
             . "(3) registar como lead no SAP CRM via Marta.\n--- FIM ---\n";
    }
}
