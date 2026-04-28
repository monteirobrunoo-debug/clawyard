<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'last_login_at', 'allowed_agents'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            // Per-user agent whitelist. Same NULL/[]/array semantics as
            // TenderCollaborator::allowed_sources — see the migration
            // for the full rationale.
            'allowed_agents'    => 'array',
        ];
    }

    // ── Agent-access presets ──────────────────────────────────────────────
    /**
     * Bundles of agent keys that an admin can apply to a user with one
     * click instead of toggling 22 checkboxes individually.
     *
     * `null` here means "no restriction" (sees every agent). The
     * NULL allowed_agents column has the same effect.
     *
     * Customise per business: when a new persona shows up, add a key
     * here and the matrix UI gets a new preset button automatically.
     */
    public const AGENT_PRESETS = [
        'full_access' => null,    // unrestricted — same as NULL column
        'blocked'     => [],      // explicitly blocked from everything
        'vendor_spares' => [
            'sales', 'support', 'sap', 'document', 'qnap', 'shipping',
        ],
        'vendor_full' => [
            'sales', 'support', 'email', 'crm', 'sap', 'capitao', 'vessel',
            'briefing', 'document', 'qnap', 'shipping', 'acingov',
        ],
        'engineering' => [
            'engineer', 'patent', 'quantum', 'energy', 'research', 'document', 'thinking',
        ],
        'security' => [
            'aria', 'kyber', 'computer', 'batch',
        ],
        'read_only' => [
            'research', 'briefing', 'document',
        ],
    ];

    /**
     * Back-fill the user_id on any TenderCollaborator that already had
     * this email, so a newly-provisioned account immediately sees
     * "Os meus concursos" on the dashboard without a separate "link
     * user" step. Symmetric with the hook on TenderCollaborator that
     * links on email change.
     */
    protected static function booted(): void
    {
        static::created(function (self $u) {
            if ($u->email) {
                \App\Models\TenderCollaborator::where('email', $u->email)
                    ->whereNull('user_id')
                    ->update(['user_id' => $u->id]);
            }
        });

        static::updated(function (self $u) {
            if ($u->wasChanged('email') && $u->email) {
                \App\Models\TenderCollaborator::where('email', $u->email)
                    ->whereNull('user_id')
                    ->update(['user_id' => $u->id]);
            }
        });
    }

    public function isAdmin(): bool     { return $this->role === 'admin'; }
    public function isManager(): bool   { return in_array($this->role, ['admin', 'manager']); }
    public function isGuest(): bool     { return $this->role === 'guest'; }

    /**
     * Per-agent access check. Mirrors TenderCollaborator::canSeeSource:
     *
     *   • allowed_agents NULL  → unrestricted (sees every agent).
     *     Backwards-compatible default — never breaks an existing user.
     *   • allowed_agents []    → explicitly blocked from every agent.
     *     A guest account that should only browse static pages.
     *   • allowed_agents [...] → whitelist. Any key not in the array
     *     is hidden from the picker and rejected at /api/chat with 403.
     *
     * Admins get a hard yes regardless — they need to validate behaviour
     * across all agents during onboarding / debugging.
     */
    public function canUseAgent(string $agentKey): bool
    {
        if ($this->isAdmin()) return true;
        $allowed = $this->allowed_agents;
        if ($allowed === null) return true;
        return in_array($agentKey, (array) $allowed, true);
    }

    /**
     * Apply one of the AGENT_PRESETS to this user. Throws if the preset
     * key is unknown — better to fail loudly than silently no-op.
     */
    public function applyAgentPreset(string $preset): self
    {
        if (!array_key_exists($preset, self::AGENT_PRESETS)) {
            throw new \InvalidArgumentException("Unknown agent preset: {$preset}");
        }
        $this->allowed_agents = self::AGENT_PRESETS[$preset];
        $this->save();
        return $this;
    }

    public function conversationCount(): int
    {
        return \App\Models\Conversation::where('session_id', 'like', 'u' . $this->id . '_%')->count();
    }

    public function getRoleBadgeAttribute(): string
    {
        return match($this->role) {
            'admin'   => '🔴 Admin',
            'manager' => '🟡 Manager',
            'user'    => '🟢 User',
            'guest'   => '⚪ Guest',
            default   => $this->role,
        };
    }

    /**
     * Per-user denormalised reward totals — one row max. Returns null
     * for users who haven't earned a single event yet (the row is
     * created lazily by RewardRecorder). Use `pointsRow()` for a
     * non-null view that auto-creates the row when needed.
     */
    public function points(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\UserPoints::class, 'user_id');
    }

    /**
     * Auto-create the points row if missing. Useful from the
     * dashboard read path so a brand-new user sees zeros instead
     * of a NULL relation.
     */
    public function pointsRow(): \App\Models\UserPoints
    {
        return \App\Models\UserPoints::firstOrCreate(
            ['user_id' => $this->id],
            ['total_points' => 0, 'level' => 0],
        );
    }

    /** All reward events ever earned by this user. */
    public function rewardEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\RewardEvent::class, 'user_id');
    }
}
