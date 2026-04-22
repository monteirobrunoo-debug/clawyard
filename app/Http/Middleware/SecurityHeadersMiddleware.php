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
        // HSTS: 1 year + preload flag so Chrome/Firefox bundle this domain
        // into their HSTS preload list on next ingest. 'preload' requires a
        // site-wide commitment to HTTPS — which we enforce in AppServiceProvider.
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        // Defence in depth — any stray http:// subresource gets auto-upgraded
        // by the browser before the request leaves the machine.
        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
            $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        }

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
        // connect-src must include whatever ANTHROPIC_BASE_URL points at —
        // otherwise the browser blocks fetch() when we reroute through the
        // company Digital Ocean proxy. Same goes for the NVIDIA endpoint.
        $connectExtras = [];
        $extra = config('services.anthropic.base_uri');
        if ($extra) {
            $origin = parse_url((string) $extra, PHP_URL_SCHEME) . '://' . parse_url((string) $extra, PHP_URL_HOST);
            if ($origin && $origin !== '://') $connectExtras[] = $origin;
        }
        $nvidia = config('services.nvidia.base_url');
        if ($nvidia) {
            $origin = parse_url((string) $nvidia, PHP_URL_SCHEME) . '://' . parse_url((string) $nvidia, PHP_URL_HOST);
            if ($origin && $origin !== '://') $connectExtras[] = $origin;
        }
        // Always include the canonical upstreams so a misconfigured env
        // doesn't accidentally break fetch on a direct-to-Anthropic install.
        $connectExtras = array_unique(array_merge($connectExtras, [
            'https://api.anthropic.com',
            'https://integrate.api.nvidia.com',
        ]));

        $csp = implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' is retained because Blade views still use
            // onclick= / inline <script> handlers. Explicitly REJECT
            // 'unsafe-eval' + data: scripts — those buy nothing and open
            // XSS-to-RCE bridges. A full strict-CSP migration (nonces +
            // addEventListener) is tracked separately.
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self' " . implode(' ', $connectExtras),
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            // Upgrade any accidental plaintext subresource to https so a
            // stray "http://..." URL in user content can't leak the session.
            "upgrade-insecure-requests",
            // NOTE: block-all-mixed-content is deprecated in CSP3 — the
            // HSTS preload + upgrade-insecure-requests above achieve the
            // same effect on current browsers. Dropped to keep the header
            // byte-budget tight.
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
