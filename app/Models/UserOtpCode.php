<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * Short-lived 6-digit OTP issued to an internal clawyard user when
 * their session IP differs from `users.last_verified_ip`.
 *
 * Mirrors AgentShareOtp (used for external client portal access) so
 * we benefit from the same security defaults: bcrypt at rest, 10min
 * TTL, 5-attempt cap, hash_equals constant-time comparison.
 */
class UserOtpCode extends Model
{
    protected $fillable = [
        'user_id', 'code_hash', 'ip', 'user_agent',
        'attempts', 'expires_at', 'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'attempts'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still alive: not used, not expired, not over the attempt cap. */
    public function isAlive(): bool
    {
        return !$this->used_at
            && $this->expires_at->isFuture()
            && $this->attempts < 5;
    }

    /** Check the user-supplied code against the stored bcrypt hash. */
    public function matches(string $code): bool
    {
        return Hash::check(trim($code), $this->code_hash);
    }

    public static function hashCode(string $code): string
    {
        return Hash::make(trim($code));
    }
}
