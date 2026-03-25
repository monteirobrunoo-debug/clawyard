<?php

namespace App\Providers;

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
