<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * Política 2FA (2026-05-12 — segurança pós-incidente setq):
     *
     *   • TODOS os utilizadores fazem OTP após password.
     *   • Quem tem TOTP authenticator app activado (two_factor_confirmed_at)
     *     → introduz código do Google Authenticator / 1Password / etc.
     *   • Quem NÃO tem TOTP setup → recebe código de 6 dígitos por email
     *     (válido 10 min, uso único, rate-limited a 3 envios / 15 min).
     *
     * Password sozinha NUNCA é suficiente. OTP é sempre exigido.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        $remember   = $request->boolean('remember');
        $hasTotpApp = $user->two_factor_confirmed_at && $user->two_factor_secret;

        // Drop the just-authenticated session immediately. The credentials
        // pass, but we don't grant the session until OTP is verified.
        Auth::guard('web')->logout();
        $request->session()->regenerateToken();
        $request->session()->put('2fa_user_id',   $user->id);
        $request->session()->put('2fa_remember',  $remember);
        $request->session()->put('2fa_mode',      $hasTotpApp ? 'totp' : 'email');

        // For email-OTP users, generate + send the code now. The challenge
        // view will display "código enviado para email@xxx" and accept it.
        if (!$hasTotpApp) {
            app(\App\Services\LoginOtpService::class)->issueAndMail($user, $request->ip());
        }

        return redirect()->route('login.2fa');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
