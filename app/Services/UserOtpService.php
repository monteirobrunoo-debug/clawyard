<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOtpCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * IP-based OTP for internal clawyard users.
 *
 * Lifecycle:
 *   1. User logs in OR session IP changes → middleware sees mismatch →
 *      redirects to /otp/challenge.
 *   2. Challenge controller calls issue() → generates a 6-digit code,
 *      stores bcrypt hash, emails plaintext to user.email, expires 10min.
 *   3. User submits code → verify() → on match, sets users.last_verified_ip
 *      = current IP, last_otp_at = now(). Subsequent requests from the
 *      same IP skip the challenge.
 *
 * Brute-force protection:
 *   • 5-attempt cap per code (matches() increments attempts).
 *   • 10-minute TTL — short enough that online guessing has < 0.001%
 *     chance per code (10⁶ codes × 5 attempts = 5×10⁻⁵ × 600s).
 *   • Bcrypt hash at rest so DB dump is useless.
 */
class UserOtpService
{
    /** Generate, store, and email a new OTP for the user at this IP. */
    public function issue(User $user, string $ip, ?string $userAgent = null): UserOtpCode
    {
        // Invalidate prior live codes for this user — only one challenge
        // active at a time, prevents stack-replay if user re-requests.
        UserOtpCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'updated_at' => now()]);

        $code = $this->generateCode();
        $otp  = UserOtpCode::create([
            'user_id'    => $user->id,
            'code_hash'  => UserOtpCode::hashCode($code),
            'ip'         => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 250) : null,
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->sendEmail($user, $code, $ip);

        Log::info('UserOtpService: issued code', [
            'user_id' => $user->id,
            'ip'      => $ip,
            'expires' => $otp->expires_at->toIso8601String(),
        ]);

        return $otp;
    }

    /**
     * Verify a code submitted by the user. On success:
     *   • marks the OTP row as used
     *   • updates users.last_verified_ip + last_otp_at
     *   • returns true
     *
     * On failure (wrong code / expired / over attempts), returns false
     * and increments the attempt counter so the cap can kick in.
     */
    public function verify(User $user, string $code, string $ip): bool
    {
        $otp = UserOtpCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$otp) return false;

        if (!$otp->isAlive()) {
            return false;
        }

        $otp->increment('attempts');

        if (!$otp->matches($code)) {
            Log::warning('UserOtpService: bad code', [
                'user_id'  => $user->id,
                'ip'       => $ip,
                'attempts' => $otp->attempts,
            ]);
            return false;
        }

        $otp->forceFill(['used_at' => now()])->save();

        $user->forceFill([
            'last_verified_ip' => $ip,
            'last_otp_at'      => now(),
        ])->save();

        Log::info('UserOtpService: verified', [
            'user_id' => $user->id,
            'ip'      => $ip,
        ]);

        return true;
    }

    /** Cryptographically random 6-digit code (no leading-zero bias). */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Plain-text email with the code. Subject + body intentionally
     * minimal so it lands well across mail clients (no HTML rendering
     * surprises) and is hard to mis-classify as marketing.
     */
    private function sendEmail(User $user, string $code, string $ip): void
    {
        try {
            $subject = 'ClawYard — código de verificação';
            $body    = <<<TXT
Olá {$user->name},

O teu código de verificação ClawYard é:

   {$code}

Este código é válido durante 10 minutos. Foi pedido a partir do IP
{$ip}. Se não foste tu, ignora este email — alguém tentou aceder
à tua conta a partir de outro endereço.

—
ClawYard Security · automatic message
TXT;

            Mail::raw($body, function ($msg) use ($user, $subject) {
                $msg->to($user->email, $user->name)
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            // Email failure must NOT block the issue() flow — the code
            // is still in the DB. Operator can resend via the UI.
            Log::error('UserOtpService: mail send failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }
    }
}
