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
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // #10 — 2FA challenge gate.
        //
        // If the user has confirmed 2FA, we intercept BEFORE finalising the
        // session. The credentials were already validated by ->authenticate();
        // we now immediately log them back out, stash their id + remember
        // flag in the session, and redirect to /login/2fa-challenge where
        // they must provide a valid TOTP code (or a one-time recovery code)
        // before TwoFactorController::challenge() calls Auth::loginUsingId().
        //
        // Users WITHOUT 2FA enabled (two_factor_confirmed_at = NULL) fall
        // through and finish the login normally — same UX as before.
        $user = Auth::user();
        if ($user && $user->two_factor_confirmed_at && $user->two_factor_secret) {
            $remember = $request->boolean('remember');

            Auth::guard('web')->logout();
            // Don't invalidate the full session here — we need to carry
            // 2fa_user_id across the redirect. Regenerate the token only.
            $request->session()->regenerateToken();
            $request->session()->put('2fa_user_id', $user->id);
            $request->session()->put('2fa_remember', $remember);

            return redirect()->route('login.2fa');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
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
