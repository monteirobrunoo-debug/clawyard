<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, string $role = 'admin')
    {
        // SECURITY (C7): API clients hitting a protected admin endpoint without
        // auth / admin rights must receive a 401/403 JSON response, not a 302
        // redirect to the login page — otherwise a naive client will follow the
        // redirect and POST document payloads to the login form.
        $isApi = $request->is('api/*') || $request->wantsJson() || $request->expectsJson();

        if (!auth()->check()) {
            return $isApi
                ? response()->json(['error' => 'Unauthenticated.'], 401)
                : redirect('/login');
        }

        $user = auth()->user();

        if (!$user->is_active) {
            auth()->logout();
            return $isApi
                ? response()->json(['error' => 'Account disabled.'], 403)
                : redirect('/login')->withErrors(['email' => 'Conta desativada.']);
        }

        if ($role === 'admin' && !$user->isAdmin()) {
            return $isApi
                ? response()->json(['error' => 'Forbidden — admin role required.'], 403)
                : abort(403, 'Acesso negado.');
        }

        if ($role === 'manager' && !$user->isManager()) {
            return $isApi
                ? response()->json(['error' => 'Forbidden — manager role required.'], 403)
                : abort(403, 'Acesso negado.');
        }

        return $next($request);
    }
}
