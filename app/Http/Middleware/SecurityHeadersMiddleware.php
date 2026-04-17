<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Content Security Policy
        //
        // SECURITY: the blade views contain many inline <script> and inline
        // event handlers. Removing 'unsafe-inline' entirely would break the
        // UI, so instead we:
        //   1. Generate a per-request nonce that legitimate inline scripts
        //      can opt into (<script nonce="{{ csp_nonce() }}">).
        //   2. Drop 'unsafe-eval' outright — no code in the repo uses eval()
        //      or new Function(); Alpine.js/Vue compilers are not present.
        //   3. Lock connect-src to self + Anthropic/NVIDIA APIs (SSE).
        //   4. frame-ancestors 'none' prevents clickjacking.
        //
        // The nonce is exposed as config('csp.nonce') for views to pull via
        // the csp_nonce() helper if/when inline scripts are migrated off
        // 'unsafe-inline'. Until then 'unsafe-inline' is kept as a transitional
        // compatibility shim (nonce takes precedence in modern browsers, so
        // once nonces are adopted browsers ignore 'unsafe-inline').
        $nonce = base64_encode(random_bytes(16));
        config(['csp.nonce' => $nonce]);

        $scriptSrc = "'self' 'nonce-{$nonce}' 'unsafe-inline'";

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src {$scriptSrc}",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self' https://api.anthropic.com https://integrate.api.nvidia.com",
            "media-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
