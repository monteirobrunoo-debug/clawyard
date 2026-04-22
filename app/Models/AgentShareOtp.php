<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class AgentShareOtp extends Model
{
    protected $fillable = [
        'agent_share_id', 'email', 'session_id',
        'code_hash', 'attempts', 'expires_at', 'used_at', 'ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'attempts'   => 'integer',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(AgentShare::class, 'agent_share_id');
    }

    public function isAlive(): bool
    {
        return !$this->used_at && $this->expires_at->isFuture() && $this->attempts < 5;
    }

    /**
     * Verify a user-supplied code against the stored hash.
     *
     * Supports two hash formats so the upgrade is backwards-compatible:
     *   - bcrypt (`$2y$...`)  → current hardened format, verified via Hash::check
     *   - sha256 (64-hex)     → legacy, verified via hash_equals
     * Legacy rows will naturally age out (OTPs expire after 10 min), so no
     * migration is required — after the first day only bcrypt rows remain.
     */
    public function matches(string $code): bool
    {
        $code = trim($code);

        if (str_starts_with($this->code_hash, '$2')) {
            return Hash::check($code, $this->code_hash);
        }

        // Legacy SHA-256 row — keep hash_equals to avoid timing leaks.
        return hash_equals($this->code_hash, hash('sha256', $code));
    }

    /**
     * Build the storage hash for a freshly-minted OTP. Uses bcrypt so a
     * DB dump can't be brute-forced off-box — the 10-min TTL + 5-attempt
     * cap already limit online guessing, but at-rest is what matters for
     * compliance reviews.
     */
    public static function hashCode(string $code): string
    {
        return Hash::make(trim($code));
    }
}
