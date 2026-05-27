<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin'       => \App\Http\Middleware\AdminMiddleware::class,
            'verified.ip' => \App\Http\Middleware\RequireIpVerification::class,
        ]);
        // Security headers on all web responses
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
            // IP-bound OTP gate — runs after StartSession+Authenticate
            // so we know the user. The middleware bypasses itself for
            // /otp/* and /logout so the challenge UI is reachable.
            \App\Http\Middleware\RequireIpVerification::class,
        ]);
        // Allow API routes to read web session (for session-based auth),
        // and append security headers so JSON/SSE responses also carry
        // HSTS + X-Content-Type-Options + Referrer-Policy.
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry — captura todas as exceções unhandled em produção.
        // DSN configurado via SENTRY_LARAVEL_DSN no .env. Sem DSN = no-op.
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();
