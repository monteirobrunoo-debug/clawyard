<?php

namespace App\Agents\Traits;

use App\Support\PiiRedactor;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

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

        // SECURITY: never allow plaintext transport over a wire. A mis-typed
        // .env value (http:// instead of https://) would otherwise silently
        // downgrade every agent's outbound traffic. We auto-upgrade
        // http → https and reject any other scheme outright.
        //
        // EXCEPTION: loopback (127.0.0.1 / ::1 / localhost) is allowed over
        // plain HTTP because the packets never leave the machine — they go
        // from PHP-FPM directly into the local FastAPI redactor, which then
        // re-encrypts the hop to Anthropic. This is how the on-host
        // redacting proxy is wired in production. Anything non-loopback
        // stays https-only.
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($uri, PHP_URL_HOST));
        $isLoopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);

        if ($scheme === 'http' && !$isLoopback) {
            $uri = 'https://' . substr($uri, 7);
        } elseif ($scheme !== 'https' && $scheme !== 'http') {
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

    /**
     * Build a Guzzle client pre-configured for the Anthropic proxy, with
     * the HMAC signing middleware wired in when the split-VM topology is
     * active (PY_PROXY_SHARED_KEY in .env). In the loopback topology the
     * middleware is a no-op — same client, zero signing overhead.
     *
     * Agents that migrate to the split-VM setup should switch from
     *     new Client(['base_uri' => self::getAnthropicBaseUri(), ...])
     * to
     *     self::anthropicGuzzleClient([...])
     * to get automatic signing. The contract for `->post('/v1/messages',
     * ['headers' => ..., 'json' => ...])` is unchanged.
     */
    protected static function anthropicGuzzleClient(array $overrides = []): Client
    {
        $stack = HandlerStack::create();

        $sharedKey = (string) (getenv('PY_PROXY_SHARED_KEY') ?: config('services.anthropic.proxy_shared_key', ''));
        $sharedKeyNext = (string) (getenv('PY_PROXY_SHARED_KEY_NEXT') ?: config('services.anthropic.proxy_shared_key_next', ''));

        if ($sharedKey !== '') {
            // Signing middleware: adds X-PY-Timestamp + X-PY-Signature
            // (and X-PY-Signature-Next during rotation) keyed on the
            // request body. See llm-proxy/auth.py for the verifier.
            $stack->push(Middleware::mapRequest(function (RequestInterface $request) use ($sharedKey, $sharedKeyNext): RequestInterface {
                $body = (string) $request->getBody();
                // Rewind the body stream so Guzzle can read it again
                // after we've hashed it.
                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }
                $ts = (string) time();
                $payload = $ts . "\n" . $body;
                $sig = hash_hmac('sha256', $payload, $sharedKey);
                $signed = $request
                    ->withHeader('X-PY-Timestamp', $ts)
                    ->withHeader('X-PY-Signature', $sig);
                if ($sharedKeyNext !== '') {
                    $sigNext = hash_hmac('sha256', $payload, $sharedKeyNext);
                    $signed = $signed->withHeader('X-PY-Signature-Next', $sigNext);
                }
                return $signed;
            }), 'partyard.proxy_sign');
        }

        $defaults = [
            'base_uri'        => self::getAnthropicBaseUri(),
            'timeout'         => 120,
            'connect_timeout' => 10,
            'handler'         => $stack,
        ];
        // Custom TLS bundle for self-signed internal proxy cert.
        $caBundle = (string) (getenv('ANTHROPIC_PROXY_CA_BUNDLE') ?: config('services.anthropic.proxy_ca_bundle', ''));
        if ($caBundle !== '' && is_file($caBundle)) {
            $defaults['verify'] = $caBundle;
        }
        return new Client(array_replace($defaults, $overrides));
    }
}
