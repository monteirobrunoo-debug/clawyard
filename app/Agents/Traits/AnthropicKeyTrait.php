<?php

namespace App\Agents\Traits;

trait AnthropicKeyTrait
{
    /**
     * Get Anthropic API key — tries every available source to bypass OPcache issues.
     */
    protected static function getAnthropicKey(): string
    {
        // 1. Try config() — works when config cache is fresh
        $key = config('services.anthropic.api_key');
        if ($key) return $key;

        // 2. Try getenv() — works when env vars are inherited by FPM
        $key = getenv('ANTHROPIC_API_KEY');
        if ($key) return $key;

        // 3. Try $_ENV / $_SERVER — set by some FPM configurations
        $key = $_ENV['ANTHROPIC_API_KEY'] ?? $_SERVER['ANTHROPIC_API_KEY'] ?? null;
        if ($key) return $key;

        // 4. Read .env file directly — ultimate fallback, always works
        $envFile = base_path('.env');
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
                    return trim(substr($line, 18));
                }
            }
        }

        return '';
    }

    protected function apiHeaders(): array
    {
        return [
            'x-api-key'         => self::getAnthropicKey(),
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ];
    }
}
