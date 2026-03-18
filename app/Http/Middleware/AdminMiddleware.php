<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, string $role = 'admin')
    {
        if (!auth()->check()) {
            return redirect('/login');
        }

        $user = auth()->user();

        if (!$user->is_active) {
            auth()->logout();
            return redirect('/login')->withErrors(['email' => 'Conta desativada.']);
        }

        if ($role === 'admin' && !$user->isAdmin()) {
            abort(403, 'Acesso negado.');
        }

        if ($role === 'manager' && !$user->isManager()) {
            abort(403, 'Acesso negado.');
        }

        return $next($request);
    }
}
