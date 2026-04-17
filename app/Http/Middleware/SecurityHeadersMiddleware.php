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
        // IMPORTANT: the blade views rely heavily on inline event handlers
        // (onclick=, onchange=, onmouseover=) which ONLY work with
        // 'unsafe-inline' and are NOT covered by nonce-based CSP. If we add
        // a nonce to script-src alongside 'unsafe-inline', CSP3 browsers
        // switch to "strict" mode and IGNORE 'unsafe-inline' — which breaks
        // every inline handler (clicking an agent card stops responding).
        //
        // So we keep 'unsafe-inline' for now (no nonce) and only harden
        // everything else: drop 'unsafe-eval', lock object/base/form/frame,
        // and keep connect-src open enough for fetch/SSE calls made by the
        // chat UI. A proper migration to addEventListener + strict CSP is
        // tracked as a follow-up.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self' https://api.anthropic.com https://integrate.api.nvidia.com",
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
