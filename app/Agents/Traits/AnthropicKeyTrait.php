<?php

namespace App\Agents\Traits;

use App\Support\PiiRedactor;

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

    /**
     * Upstream base URI. Single choke point — flip ANTHROPIC_BASE_URL in .env
     * and every agent reroutes to the company Digital Ocean proxy instead of
     * api.anthropic.com. The proxy can then log, redact and audit traffic
     * before it leaves our infrastructure.
     *
     * Falls back to the public endpoint when the env var is empty / unset.
     */
    protected static function getAnthropicBaseUri(): string
    {
        // Prefer config() — respects config:cache on production boxes.
        $uri = (string) config('services.anthropic.base_uri', '');
        if ($uri === '') {
            $uri = (string) (getenv('ANTHROPIC_BASE_URL') ?: '');
        }
        if ($uri === '') {
            return 'https://api.anthropic.com';
        }
        $uri = rtrim($uri, '/');

        // SECURITY: never allow plaintext transport. A mis-typed .env value
        // (http:// instead of https://) would otherwise silently downgrade
        // every agent's outbound traffic. We auto-upgrade http → https and
        // reject any other scheme outright.
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        if ($scheme === 'http') {
            $uri = 'https://' . substr($uri, 7);
        } elseif ($scheme !== 'https') {
            // Unknown scheme (or missing) — refuse and fall back to default
            // so the agent keeps working instead of making garbage requests.
            return 'https://api.anthropic.com';
        }
        return $uri;
    }

    /**
     * Scrub PII from a message payload before it leaves the server. Off by
     * default; turn on with ANTHROPIC_REDACT_PII=true (or the equivalent
     * config key). Binary blocks (PDF/image) are untouched — only text
     * blocks are redacted.
     */
    protected static function redactOutbound(array $messages): array
    {
        if (!config('services.anthropic.redact_pii', false)) {
            return $messages;
        }
        return PiiRedactor::scrubMessages($messages);
    }

    protected function apiHeaders(bool $withPdf = false): array
    {
        $headers = [
            'x-api-key'         => self::getAnthropicKey(),
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ];
        // Enable PDF document blocks (required for file_type=application/pdf)
        if ($withPdf) {
            $headers['anthropic-beta'] = 'pdfs-2024-09-25';
        }
        return $headers;
    }

    /**
     * Detect if message contains a PDF document block and return headers accordingly.
     */
    protected function headersForMessage(string|array $message): array
    {
        if (is_array($message)) {
            foreach ($message as $block) {
                if (($block['type'] ?? '') === 'document'
                    && str_contains($block['source']['media_type'] ?? '', 'pdf')) {
                    return $this->apiHeaders(true);
                }
            }
        }
        return $this->apiHeaders();
    }
}
