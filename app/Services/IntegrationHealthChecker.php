<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight liveness probe for every external integration clawyard
 * depends on. Each check returns:
 *
 *   [
 *     'ok'        => bool,
 *     'state'     => 'up' | 'down' | 'not_configured' | 'degraded',
 *     'detail'    => 'human-readable status line',
 *     'latency_ms'=> int|null,
 *   ]
 *
 * The full report is cached for 60s to keep repeated dashboard
 * refreshes from hammering Anthropic / SAP. Use ->forceRefresh()
 * to bypass the cache.
 *
 * Why bake this in clawyard rather than a separate uptime monitor:
 *   • The admin already has SAP / Tavily / Anthropic credentials in
 *     this app's .env — running probes from here uses the SAME
 *     network path and credentials a real request would, catching
 *     issues an external monitor (probing public URLs) would miss
 *     (firewall, IP whitelist, expired keys, etc.).
 */
class IntegrationHealthChecker
{
    private const CACHE_KEY = 'admin_panel:integration_health:v2';
    private const TTL_SECS  = 60;

    public function report(bool $forceRefresh = false): array
    {
        if ($forceRefresh) Cache::forget(self::CACHE_KEY);

        return Cache::remember(self::CACHE_KEY, self::TTL_SECS, function () {
            return [
                'database'    => $this->checkDatabase(),
                'cache'       => $this->checkCache(),
                'queue'       => $this->checkQueue(),
                'mail'        => $this->checkMail(),
                'sap_b1'      => $this->checkSap(),
                'hp_history'  => $this->checkHpHistory(),
                'tavily'      => $this->checkTavily(),
                'llm_proxy'   => $this->checkLlmProxy(),
                'anthropic'   => $this->checkAnthropic(),
                'epo'         => $this->checkEpo(),
                'whatsapp'    => $this->checkWhatsApp(),
            ];
        });
    }

    // ── Individual probes ──────────────────────────────────────────────

    private function checkDatabase(): array
    {
        $started = microtime(true);
        try {
            DB::select('SELECT 1');
            return $this->upResult($started, 'PostgreSQL responde');
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => 'DB inacessível: ' . $e->getMessage(), 'latency_ms' => null];
        }
    }

    private function checkCache(): array
    {
        $started = microtime(true);
        try {
            Cache::put('__health_probe__', '1', 5);
            $ok = Cache::get('__health_probe__') === '1';
            Cache::forget('__health_probe__');
            return $ok
                ? $this->upResult($started, 'Driver: ' . config('cache.default'))
                : ['ok' => false, 'state' => 'down', 'detail' => 'Cache não devolveu o valor escrito', 'latency_ms' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => $e->getMessage(), 'latency_ms' => null];
        }
    }

    private function checkQueue(): array
    {
        $started = microtime(true);
        try {
            $pending = DB::table('jobs')->count();
            $failed  = DB::table('failed_jobs')->count();
            $detail  = "Pending: {$pending} · Failed: {$failed}";
            // "Degraded" if there's a non-trivial failed-jobs pile, but
            // we don't want to mark the whole stack down for cosmetic reasons.
            $state = $failed > 50 ? 'degraded' : 'up';
            return [
                'ok'         => $state === 'up',
                'state'      => $state,
                'detail'     => $detail,
                'latency_ms' => $this->elapsedMs($started),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => $e->getMessage(), 'latency_ms' => null];
        }
    }

    private function checkMail(): array
    {
        $mailer = (string) config('mail.default');
        if ($mailer === 'log') {
            return ['ok' => true, 'state' => 'degraded', 'detail' => 'Mailer = log (apenas logging, não envia)', 'latency_ms' => 0];
        }
        $host = config('mail.mailers.smtp.host');
        $port = config('mail.mailers.smtp.port');
        if ($mailer !== 'smtp' || !$host) {
            return ['ok' => true, 'state' => 'up', 'detail' => "Driver: {$mailer}", 'latency_ms' => 0];
        }
        // TCP probe — ~3s timeout. Doesn't validate auth, just that
        // the SMTP host is reachable (cheap: catches "host down").
        $started = microtime(true);
        $errno   = 0;
        $errstr  = '';
        $sock    = @fsockopen($host, (int) $port, $errno, $errstr, 3);
        if (!$sock) {
            return ['ok' => false, 'state' => 'down', 'detail' => "TCP {$host}:{$port} → {$errstr}", 'latency_ms' => null];
        }
        @fclose($sock);
        return $this->upResult($started, "SMTP {$host}:{$port}");
    }

    private function checkSap(): array
    {
        $url  = (string) config('services.sap.url');
        $user = (string) config('services.sap.username');
        if ($url === '' || $user === '') {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'SAP_B1_URL/USER em falta no .env', 'latency_ms' => null];
        }
        $started = microtime(true);
        try {
            // ServiceLayer responds with 200 on /Login (POST) but we
            // don't want to actually login on every health probe. The
            // root index does HEAD/GET → 4xx but proves reachability.
            $r = Http::timeout(5)->withOptions(['verify' => (bool) config('services.sap.tls_verify', true)])
                ->get(rtrim($url, '/') . '/');
            return [
                'ok'         => true,
                'state'      => 'up',
                'detail'     => "ServiceLayer responde (HTTP {$r->status()})",
                'latency_ms' => $this->elapsedMs($started),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => 'Timeout/erro: ' . mb_substr($e->getMessage(), 0, 120), 'latency_ms' => null];
        }
    }

    private function checkHpHistory(): array
    {
        if (!config('services.hp_history.enabled', false)) {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'HP_HISTORY_ENABLED=false', 'latency_ms' => null];
        }
        $base = (string) config('services.hp_history.base_url', '');
        if ($base === '') {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'HP_HISTORY_BASE_URL em falta', 'latency_ms' => null];
        }
        $started = microtime(true);
        try {
            $r = Http::timeout(5)->get(rtrim($base, '/') . '/healthz');
            $body = $r->json();
            $up = $r->successful() && (($body['status'] ?? '') === 'ok');
            return [
                'ok'         => $up,
                'state'      => $up ? 'up' : 'down',
                'detail'     => $up ? 'pgvector + FastAPI a responder' : "HTTP {$r->status()}",
                'latency_ms' => $this->elapsedMs($started),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => mb_substr($e->getMessage(), 0, 120), 'latency_ms' => null];
        }
    }

    private function checkTavily(): array
    {
        if (!config('services.tavily.api_key')) {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'TAVILY_API_KEY em falta', 'latency_ms' => null];
        }
        // We don't burn an actual search call on every probe. Just
        // confirm the key is present and TLS to api.tavily.com works.
        $started = microtime(true);
        try {
            $r = Http::timeout(5)->withHeaders(['User-Agent' => 'Clawyard/health'])
                ->get('https://api.tavily.com/');
            return [
                'ok'         => true,
                'state'      => 'up',
                'detail'     => "Endpoint acessível (HTTP {$r->status()})",
                'latency_ms' => $this->elapsedMs($started),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => mb_substr($e->getMessage(), 0, 120), 'latency_ms' => null];
        }
    }

    private function checkLlmProxy(): array
    {
        $base = (string) config('services.anthropic.base_uri', '');
        if ($base === '' || str_contains($base, 'api.anthropic.com')) {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'A usar Anthropic directo (sem proxy PII)', 'latency_ms' => null];
        }
        $started = microtime(true);
        try {
            $r = Http::timeout(5)->get(rtrim($base, '/') . '/');
            return [
                'ok'         => true,
                'state'      => 'up',
                'detail'     => "Proxy responde (HTTP {$r->status()})",
                'latency_ms' => $this->elapsedMs($started),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => mb_substr($e->getMessage(), 0, 120), 'latency_ms' => null];
        }
    }

    private function checkAnthropic(): array
    {
        if (!config('services.anthropic.api_key')) {
            return ['ok' => false, 'state' => 'not_configured', 'detail' => 'ANTHROPIC_API_KEY em falta', 'latency_ms' => null];
        }
        // Just confirm key presence + that the (proxy or direct) base
        // URL responds. We DON'T spend tokens on a real /v1/messages
        // call here — too expensive on every probe.
        $base = config('services.anthropic.base_uri', 'https://api.anthropic.com');
        $started = microtime(true);
        try {
            $r = Http::timeout(5)->get(rtrim($base, '/') . '/');
            return [
                'ok'         => true,
                'state'      => 'up',
                'detail'     => 'Key presente · base ' . parse_url($base, PHP_URL_HOST),
                'latency_ms' => $this->elapsedMs($started),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'down', 'detail' => mb_substr($e->getMessage(), 0, 120), 'latency_ms' => null];
        }
    }

    private function checkEpo(): array
    {
        if (!config('services.epo.consumer_key')) {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'EPO_CONSUMER_KEY em falta', 'latency_ms' => null];
        }
        return ['ok' => true, 'state' => 'up', 'detail' => 'Credenciais OAuth configuradas', 'latency_ms' => null];
    }

    private function checkWhatsApp(): array
    {
        if (!config('services.meta.whatsapp_token')) {
            return ['ok' => true, 'state' => 'not_configured', 'detail' => 'META_WHATSAPP_TOKEN em falta', 'latency_ms' => null];
        }
        return ['ok' => true, 'state' => 'up', 'detail' => 'Token Meta configurado · webhook /api/whatsapp', 'latency_ms' => null];
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function upResult(float $started, string $detail): array
    {
        return [
            'ok'         => true,
            'state'      => 'up',
            'detail'     => $detail,
            'latency_ms' => $this->elapsedMs($started),
        ];
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
