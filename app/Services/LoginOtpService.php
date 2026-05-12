<?php

namespace App\Services;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Email-OTP service for users without an authenticator app.
 *
 * Storage strategy: hashed code in Cache (TTL 10 min), not in DB.
 * Hash means a DB dump wouldn't reveal active codes; cache wipe
 * (e.g. redis restart) just forces a new code on next attempt.
 *
 * Rate limits:
 *   • Send: 3 codes / 15 minutes per user_id  (prevents email bombing)
 *   • Verify: 5 wrong codes / 10 minutes per user_id  (prevents brute force)
 */
class LoginOtpService
{
    private const CACHE_KEY_PREFIX = 'login_otp:';
    private const TTL_MINUTES      = 10;
    private const SEND_LIMIT       = 3;
    private const SEND_WINDOW      = 60 * 15;     // 15 min
    private const VERIFY_LIMIT     = 5;
    private const VERIFY_WINDOW    = 60 * 10;     // 10 min

    public function issueAndMail(User $user, ?string $ip = null): bool
    {
        // Send rate limit
        $sendKey = 'otp-send:' . $user->id;
        if (RateLimiter::tooManyAttempts($sendKey, self::SEND_LIMIT)) {
            Log::warning("LoginOtp: send rate-limited for user {$user->id}");
            return false;
        }
        RateLimiter::hit($sendKey, self::SEND_WINDOW);

        $code = (string) random_int(100000, 999999);
        Cache::put(
            self::CACHE_KEY_PREFIX . $user->id,
            Hash::make($code),
            now()->addMinutes(self::TTL_MINUTES)
        );

        try {
            Mail::to($user->email)->send(
                new LoginOtpMail(
                    userName:   (string) ($user->name ?? $user->email),
                    code:       $code,
                    ttlMinutes: self::TTL_MINUTES,
                    ip:         $ip ?? ''
                )
            );
            Log::info("LoginOtp: code sent to user {$user->id} via email");
            return true;
        } catch (\Throwable $e) {
            Log::error("LoginOtp: mail send failed for user {$user->id}: " . $e->getMessage());
            // Don't drop the cache entry — admin can resend manually
            return false;
        }
    }

    /**
     * Validates the user-submitted code against the hashed value in cache.
     * One-shot: a successful verify deletes the cache entry so the same
     * code cannot be replayed even within the TTL window.
     */
    public function verify(User $user, string $code): bool
    {
        $verifyKey = 'otp-verify:' . $user->id;
        if (RateLimiter::tooManyAttempts($verifyKey, self::VERIFY_LIMIT)) {
            Log::warning("LoginOtp: verify rate-limited for user {$user->id}");
            return false;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $user->id;
        $stored   = Cache::get($cacheKey);
        if (!$stored) return false;

        $ok = Hash::check($code, $stored);
        if ($ok) {
            Cache::forget($cacheKey);
            RateLimiter::clear($verifyKey);
            return true;
        }
        RateLimiter::hit($verifyKey, self::VERIFY_WINDOW);
        return false;
    }

    public function clear(User $user): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $user->id);
        RateLimiter::clear('otp-send:' . $user->id);
        RateLimiter::clear('otp-verify:' . $user->id);
    }
}
