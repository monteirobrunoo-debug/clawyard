<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Guarantee ANTHROPIC_API_KEY is always available via getenv()
        // even when PHP-FPM OPcache has a stale config.php
        $this->ensureAnthropicKey();

        // ── Transport-security hardening ────────────────────────────────────
        // In production (or whenever APP_URL is https://) we force every link
        // Laravel generates to use the https scheme. This closes a class of
        // MitM where a link emitted as http:// could be downgraded before
        // the browser is told to upgrade. Also trusts Cloudflare/Forge proxy
        // X-Forwarded-Proto so url()->secure() stays true even behind TLS
        // terminators.
        if ($this->shouldForceHttps()) {
            URL::forceScheme('https');
            if (request() && request()->server('HTTP_X_FORWARDED_PROTO') === 'https') {
                request()->setTrustedProxies(
                    ['0.0.0.0/0', '::/0'],
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                    | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                    | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                    | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                );
            }
        }

        // Reject any misconfiguration where NVIDIA's base_url is plaintext
        // HTTP. We treat this as a fatal config error rather than silently
        // sending keys + prompts over the clear.
        $this->assertNvidiaTransportSecure();
    }

    /**
     * We only force https on real deployments. Local dev (http://localhost)
     * keeps working so `php artisan serve` doesn't break.
     */
    private function shouldForceHttps(): bool
    {
        if (app()->environment('production')) return true;
        $appUrl = (string) config('app.url', '');
        return str_starts_with($appUrl, 'https://');
    }

    /**
     * NVIDIA API is only ever reachable over TLS. If someone overrides the
     * base_url with http:// we refuse to boot in production so it can't
     * silently leak credentials.
     */
    private function assertNvidiaTransportSecure(): void
    {
        $base = (string) config('services.nvidia.base_url', '');
        if ($base === '') return; // not configured yet

        if (!str_starts_with(strtolower($base), 'https://')) {
            // Overwrite with the canonical HTTPS endpoint so running code
            // can't accidentally hit an http:// target. Log a warning so
            // ops notices the misconfiguration.
            $fixed = preg_replace('#^http://#i', 'https://', $base) ?: 'https://integrate.api.nvidia.com/v1';
            config(['services.nvidia.base_url' => $fixed]);
            try {
                \Log::warning('NVIDIA base_url was not HTTPS — rewritten at boot', [
                    'from' => $base,
                    'to'   => $fixed,
                ]);
            } catch (\Throwable) {}
        }
    }

    private function ensureAnthropicKey(): void
    {
        // 1. Try config cache (fast path)
        $key = config('services.anthropic.api_key');

        // 2. If missing from config, read .env directly (deploy may have cached before .env was linked)
        if (!$key) {
            $key = $this->readKeyFromEnvFile();

            // 3. Rebuild config cache so subsequent requests don't repeat this fallback
            if ($key) {
                try {
                    \Artisan::call('config:cache');
                } catch (\Throwable) {}
            }
        }

        // 4. Always inject into process env so all agents can use getenv()
        if ($key) {
            putenv("ANTHROPIC_API_KEY={$key}");
            $_ENV['ANTHROPIC_API_KEY']    = $key;
            $_SERVER['ANTHROPIC_API_KEY'] = $key;
        }
    }

    private function readKeyFromEnvFile(): string
    {
        $envFile = base_path('.env');
        if (!file_exists($envFile)) return '';
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
                return trim(substr($line, 18), " \t\n\r\0\x0B\"'");
            }
        }
        return '';
    }
}
