<?php

namespace App\Http\Controllers;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

/**
 * #10 — TOTP 2FA setup & challenge.
 *
 * Routes (web, auth middleware):
 *   GET  /profile/2fa            show setup screen w/ QR + secret
 *   POST /profile/2fa/enable     confirm with 6-digit code → activate
 *   POST /profile/2fa/disable    confirm password → wipe secret
 *
 * Routes (web, NOT auth — challenge during login):
 *   GET  /login/2fa-challenge    enter 6-digit code (session has user_id)
 *   POST /login/2fa-challenge    validate, finalise login
 *
 * Recovery codes (10 one-time codes) generated on enable; user must
 * save them — used if phone is lost. Each is hashed in storage; matched
 * via constant-time compare on submission.
 */
class TwoFactorController extends Controller
{
    public function setup(Request $request)
    {
        $user = $request->user();
        $g    = new Google2FA();

        // Reuse pending secret if user clicked refresh on the setup screen.
        $secret = $request->session()->get('2fa_pending_secret');
        if (!$secret) {
            $secret = $g->generateSecretKey(32);
            $request->session()->put('2fa_pending_secret', $secret);
        }

        $issuer = config('app.name', 'ClawYard');
        $uri    = $g->getQRCodeUrl($issuer, (string) $user->email, $secret);

        // Generate inline SVG QR (no JS lib, no external request).
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $writer   = new Writer($renderer);
        $qrSvg    = $writer->writeString($uri);

        return view('auth.2fa-setup', [
            'secret'  => $secret,
            'qrSvg'   => $qrSvg,
            'already' => (bool) $user->two_factor_confirmed_at,
        ]);
    }

    public function enable(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);
        $user   = $request->user();
        $secret = $request->session()->get('2fa_pending_secret');
        abort_unless($secret, 400, 'Secret expired — start setup again.');

        $g  = new Google2FA();
        $ok = $g->verifyKey($secret, $request->input('code'), 2 /* window */);
        if (!$ok) return back()->withErrors(['code' => 'Código inválido — tenta de novo.']);

        // Generate 10 recovery codes (8 chars each, alphanumeric uppercase).
        $recovery = collect(range(1, 10))
            ->map(fn() => strtoupper(bin2hex(random_bytes(4))))
            ->all();

        $user->forceFill([
            'two_factor_secret'         => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recovery)),
            'two_factor_confirmed_at'   => now(),
        ])->save();

        $request->session()->forget('2fa_pending_secret');

        \App\Models\AuditLog::record('user.2fa_enabled', $user);

        return view('auth.2fa-recovery-codes', ['codes' => $recovery]);
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();
        abort_unless(\Hash::check($request->input('password'), $user->password), 422, 'Password incorrecta.');

        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();

        \App\Models\AuditLog::record('user.2fa_disabled', $user);

        return redirect()->route('profile.edit')->with('success', '2FA desactivado.');
    }

    public function challengeForm(Request $request)
    {
        if (!$request->session()->has('2fa_user_id')) {
            // Session expired / direct hit / refreshed — send them back to
            // login (don't 410, which renders the auth nav layout and 500s
            // because the user is intentionally logged out at this stage).
            return redirect()->route('login')->with('error', 'Sessão OTP expirou. Faz login novamente.');
        }
        $mode = $request->session()->get('2fa_mode', 'totp');
        $user = \App\Models\User::find($request->session()->get('2fa_user_id'));
        return view('auth.2fa-challenge', [
            'mode'         => $mode,
            'maskedEmail'  => $this->maskEmail($user?->email),
        ]);
    }

    /**
     * Handles both TOTP (authenticator app) and email-OTP modes.
     *
     * Mode is decided at login time by AuthenticatedSessionController and
     * stashed in session ('2fa_mode' = 'totp' | 'email'). Recovery codes
     * are only meaningful in TOTP mode — users without an app rely on the
     * email channel + "resend" flow instead.
     */
    public function challenge(Request $request)
    {
        $request->validate(['code' => 'required|string|max:8']);
        $userId = $request->session()->get('2fa_user_id');
        abort_unless($userId, 410);

        $user = \App\Models\User::findOrFail($userId);
        $mode = $request->session()->get('2fa_mode', 'totp');
        $code = trim((string) $request->input('code'));

        $ok = false;
        if ($mode === 'email') {
            $ok = app(\App\Services\LoginOtpService::class)->verify($user, $code);
        } else {
            // TOTP mode
            $g      = new Google2FA();
            $secret = Crypt::decryptString($user->two_factor_secret);
            $ok     = $g->verifyKey($secret, $code, 2);
            if (!$ok && $user->two_factor_recovery_codes) {
                $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];
                if (in_array(strtoupper($code), array_map('strtoupper', $codes), true)) {
                    $remaining = array_values(array_filter($codes, fn($c) => strtoupper($c) !== strtoupper($code)));
                    $user->forceFill([
                        'two_factor_recovery_codes' => Crypt::encryptString(json_encode($remaining)),
                    ])->save();
                    $ok = true;
                    \App\Models\AuditLog::record('user.2fa_recovery_used', $user, ['remaining' => count($remaining)]);
                }
            }
        }

        if (!$ok) {
            \App\Models\AuditLog::record('user.2fa_failed', $user, ['mode' => $mode]);
            return back()->withErrors(['code' => 'Código incorrecto ou expirado.']);
        }

        $remember = (bool) $request->session()->get('2fa_remember', false);
        $request->session()->forget(['2fa_user_id', '2fa_mode', '2fa_remember']);
        \Auth::loginUsingId($userId, $remember);
        $request->session()->regenerate();

        \App\Models\AuditLog::record('user.login', $user, ['mode' => $mode]);

        return redirect()->intended('/dashboard');
    }

    /**
     * POST /login/2fa-resend — resend email OTP (rate-limited inside the service).
     */
    public function resend(Request $request)
    {
        $userId = $request->session()->get('2fa_user_id');
        $mode   = $request->session()->get('2fa_mode', 'totp');
        abort_unless($userId && $mode === 'email', 410);

        $user = \App\Models\User::findOrFail($userId);
        $sent = app(\App\Services\LoginOtpService::class)->issueAndMail($user, $request->ip());

        return back()->with($sent ? 'success' : 'error',
            $sent ? 'Novo código enviado para o teu email.' :
                    'Limite de envios atingido — espera alguns minutos.');
    }

    private function maskEmail(?string $email): string
    {
        if (!$email) return '';
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if (!$domain) return $email;
        $maskedLocal = mb_substr($local, 0, 2) . str_repeat('•', max(1, mb_strlen($local) - 2));
        return "{$maskedLocal}@{$domain}";
    }
}
