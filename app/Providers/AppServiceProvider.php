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
        // Always resolve from config first (dotenv-parsed, quotes stripped correctly)
        $key = config('services.anthropic.api_key');

        // Fallback: read .env directly (strip quotes in case dotenv had issues)
        if (!$key) {
            $envFile = base_path('.env');
            if (file_exists($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
                        $key = trim(substr($line, 18), " \t\n\r\0\x0B\"'");
                        break;
                    }
                }
            }
        }

        // Always override process env to ensure correct value (never trust stale putenv)
        if ($key) {
            putenv("ANTHROPIC_API_KEY={$key}");
            $_ENV['ANTHROPIC_API_KEY']    = $key;
            $_SERVER['ANTHROPIC_API_KEY'] = $key;
        }
    }
}
