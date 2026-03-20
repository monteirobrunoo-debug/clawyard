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
        // Already in process env? Nothing to do.
        if (getenv('ANTHROPIC_API_KEY')) {
            return;
        }

        // Try from config (may be stale if OPcache issue)
        $key = config('services.anthropic.api_key');

        // Ultimate fallback: read .env directly
        if (!$key) {
            $envFile = base_path('.env');
            if (file_exists($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
                        $key = trim(substr($line, 18));
                        break;
                    }
                }
            }
        }

        if ($key) {
            // Inject into process environment — getenv() now works in all agents
            putenv("ANTHROPIC_API_KEY={$key}");
            $_ENV['ANTHROPIC_API_KEY']    = $key;
            $_SERVER['ANTHROPIC_API_KEY'] = $key;
        }
    }
}
