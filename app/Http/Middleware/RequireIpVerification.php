<?php

namespace App\Http\Middleware;

use App\Services\UserOtpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces an IP-bound OTP challenge whenever the authenticated user's
 * current request IP differs from `users.last_verified_ip`.
 *
 *   • Logged-out users → ignored (Authenticate middleware handles them).
 *   • Logged-in user, IP matches → pass through.
 *   • Logged-in user, IP mismatch (or never verified) → redirect to
 *     /otp/challenge unless we're already there.
 *
 * Allowlist:
 *   • /otp/* paths (so the challenge UI itself loads)
 *   • /logout (so the user can always escape)
 *   • /up (Laravel health check)
 *
 * The middleware is best-effort: any exception falls through to the
 * normal pipeline so a bug here can never lock out the whole site.
 */
class RequireIpVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $next($request);
            }

            // Bypass paths — these MUST work for the OTP flow itself.
            $path = '/' . ltrim($request->path(), '/');
            if (str_starts_with($path, '/otp/')
                || $path === '/otp'
                || $path === '/logout'
                || $path === '/up') {
                return $next($request);
            }

            $currentIp = (string) $request->ip();
            $verifiedIp = (string) ($user->last_verified_ip ?? '');

            if ($verifiedIp !== '' && $verifiedIp === $currentIp) {
                return $next($request);
            }

            // Issue a fresh code automatically on the FIRST blocked
            // request — saves the user a click. Subsequent redirects
            // (e.g. they reload the challenge page) reuse the live
            // code if any (issue() invalidates and re-creates).
            if (!session()->has('otp_pending_for_user')) {
                app(UserOtpService::class)->issue(
                    $user,
                    $currentIp,
                    $request->userAgent()
                );
                session()->put('otp_pending_for_user', $user->id);
                session()->put('otp_intended_url', $request->fullUrl());
            }

            // For AJAX/JSON callers, return 401 with a clear hint instead
            // of a redirect (chat SSE breaks if redirected mid-stream).
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'error'    => 'otp_required',
                    'redirect' => route('otp.challenge'),
                ], 401);
            }

            return redirect()->route('otp.challenge');
        } catch (\Throwable $e) {
            // Never lock the whole site over a middleware bug.
            \Log::error('RequireIpVerification middleware failure: ' . $e->getMessage());
            return $next($request);
        }
    }
}
