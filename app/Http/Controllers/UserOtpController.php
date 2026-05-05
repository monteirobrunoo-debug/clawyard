<?php

namespace App\Http\Controllers;

use App\Services\UserOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Endpoints powering the "verify this IP" page.
 *
 *   GET  /otp/challenge  → form
 *   POST /otp/verify     → consume code; redirect back to intended URL
 *   POST /otp/resend     → re-issue (rate-limited by issue() invalidation)
 */
class UserOtpController extends Controller
{
    public function __construct(private UserOtpService $service) {}

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('login');

        // Already verified for this IP? Skip the challenge.
        $currentIp  = (string) $request->ip();
        $verifiedIp = (string) ($user->last_verified_ip ?? '');
        if ($verifiedIp !== '' && $verifiedIp === $currentIp) {
            $next = session()->pull('otp_intended_url') ?: '/dashboard';
            return redirect($next);
        }

        return view('auth.otp-challenge', [
            'email_masked' => $this->maskEmail((string) $user->email),
            'current_ip'   => $currentIp,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('login');

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'O código tem de ter 6 dígitos.',
        ]);

        $ok = $this->service->verify($user, $data['code'], (string) $request->ip());
        if (!$ok) {
            return back()->withErrors(['code' => 'Código inválido, expirado, ou já usado. Pede um novo.']);
        }

        session()->forget('otp_pending_for_user');
        $next = session()->pull('otp_intended_url') ?: '/dashboard';
        return redirect($next)->with('status', '✅ IP verificado com sucesso.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('login');

        $this->service->issue(
            $user,
            (string) $request->ip(),
            $request->userAgent()
        );

        return back()->with('status', '📧 Novo código enviado para o teu email.');
    }

    /** "joao.silva@partyard.eu" → "j****a.silva@partyard.eu" */
    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        if (mb_strlen($local) <= 2) return $email;
        $first = mb_substr($local, 0, 1);
        $last  = mb_substr($local, -1, 1);
        return $first . str_repeat('*', max(1, mb_strlen($local) - 2)) . $last . '@' . $domain;
    }
}
