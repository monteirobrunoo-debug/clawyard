<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'last_login_at', 'allowed_agents', 'allowed_nav', 'extra_permissions', 'last_verified_ip', 'last_otp_at', 'weekly_digest_enabled'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'allowed_agents'    => 'array',
            // Per-user nav-link whitelist. null = role defaults,
            // [] = blocked, [...] = explicit whitelist.
            // Controlled via /admin/nav-access matrix.
            'allowed_nav'       => 'array',
            // 2026-05-19: grants finos de permissões SEM promover o user
            // a manager/admin. Array de gate names ['tenders.import',
            // 'tenders.assign']. null = sem grants extra. Verificado em
            // AppServiceProvider::registerTenderGates via hasExtraPermission().
            'extra_permissions' => 'array',
            // IP-bound OTP trust state. last_verified_ip = the IP the
            // user successfully OTP'd from; cleared on Login/Logout so
            // every fresh session re-verifies. See RequireIpVerification
            // middleware + UserOtpService.
            'last_otp_at'           => 'datetime',
            // Weekly Friday digest opt-in. Default true (set in migration).
            'weekly_digest_enabled' => 'boolean',
        ];
    }

    // ── Nav-section visibility ────────────────────────────────────────────
    /**
     * All configurable nav sections. 'default_roles' lists which roles
     * see the section when allowed_nav is null (role-based defaults).
     * The admin panel is NOT in this list — it stays hardcoded to isAdmin().
     */
    public const NAV_SECTIONS = [
        'briefing'    => ['emoji' => '📊', 'label' => 'Briefing',    'default_roles' => ['guest','user','manager','admin']],
        'tenders'     => ['emoji' => '📋', 'label' => 'Concursos',   'default_roles' => ['guest','user','manager','admin']],
        'rewards'     => ['emoji' => '🏆', 'label' => 'Rewards',     'default_roles' => ['user','manager','admin']],
        'marketplace' => ['emoji' => '🛒', 'label' => 'Marketplace', 'default_roles' => ['user','manager','admin']],
        'discoveries' => ['emoji' => '🔬', 'label' => 'Discoveries', 'default_roles' => ['user','manager','admin']],
        'patents'     => ['emoji' => '🏛️', 'label' => 'Patents',     'default_roles' => ['user','manager','admin']],
        'reports'     => ['emoji' => '📁', 'label' => 'Reports',     'default_roles' => ['user','manager','admin']],
        'schedules'   => ['emoji' => '🗓️', 'label' => 'Schedule',    'default_roles' => ['user','manager','admin']],
        'shares'      => ['emoji' => '👥', 'label' => 'Shared',      'default_roles' => ['user','manager','admin']],
        'robot'       => ['emoji' => '🤖', 'label' => 'Robot',       'default_roles' => ['manager','admin']],
        'council'     => ['emoji' => '🔬', 'label' => 'Council',     'default_roles' => ['manager','admin']],
        'intel'       => ['emoji' => '🔗', 'label' => 'Intel Bus',   'default_roles' => ['manager','admin']],
        'activity'    => ['emoji' => '🤖', 'label' => 'Activity',    'default_roles' => ['manager','admin']],
        'stats'       => ['emoji' => '📈', 'label' => 'Stats',       'default_roles' => ['manager','admin']],
        'mission'     => ['emoji' => '🛰️', 'label' => 'Mission',     'default_roles' => ['manager','admin']],
    ];

    /**
     * Can this user see a nav section?
     *
     *   • Admin: always yes.
     *   • allowed_nav=null: use NAV_SECTIONS default_roles for the section.
     *   • allowed_nav=[...]: explicit whitelist — only listed keys visible.
     *   • allowed_nav=[]: blocked from all nav.
     */
    public function canSeeNav(string $section): bool
    {
        if ($this->isAdmin()) return true;

        $allowed = $this->allowed_nav;
        if ($allowed === null) {
            $meta = self::NAV_SECTIONS[$section] ?? null;
            if (!$meta) return false;
            return in_array($this->role, $meta['default_roles'], true);
        }

        return in_array($section, (array) $allowed, true);
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
        // HR team preset — Ana Sobral, Ana Costa, Beatriz Rodrigues.
        // Inclui Dr.ª Ana Sobral (RH), Dr. Luís (Finance) para folha de
        // salários, Document para análise contratos, e Research para
        // benchmarks de mercado salarial.
        'hr_team' => [
            'hr', 'finance', 'document', 'research', 'briefing',
        ],
        // PME D2C Portugal — pacote para clientes B2C como a DLoren Wfit.
        // 9 agentes que entregam valor a qualquer e-commerce/fashion PT
        // sem ter de re-treinar contexto PartYard-específico.
        'd2c_pme_pt' => [
            'marketing', 'finance', 'hr', 'email', 'research',
            'shipping', 'aria', 'document', 'briefing', 'claude',
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
     * 2026-05-19: verifica se o user tem um gate específico via
     * extra_permissions (grants finos, sem promoção a manager).
     *
     * Política:
     *   • admins têm tudo (early-return true)
     *   • caso contrário, lê a coluna extra_permissions JSON
     *
     * Exemplo de uso em Gate::define:
     *   fn(User $u) => $u->isManager() || $u->hasExtraPermission('tenders.import')
     */
    public function hasExtraPermission(string $gate): bool
    {
        if ($this->isAdmin()) return true;
        $perms = (array) ($this->extra_permissions ?? []);
        return in_array($gate, $perms, true);
    }

    /**
     * Adiciona um grant ao extra_permissions sem duplicar.
     * Útil em admin commands / seeders.
     */
    public function grantExtraPermission(string $gate): self
    {
        $perms = (array) ($this->extra_permissions ?? []);
        if (!in_array($gate, $perms, true)) {
            $perms[] = $gate;
            $this->extra_permissions = array_values($perms);
            $this->save();
        }
        return $this;
    }

    public function revokeExtraPermission(string $gate): self
    {
        $perms = array_values(array_filter(
            (array) ($this->extra_permissions ?? []),
            fn($g) => $g !== $gate
        ));
        $this->extra_permissions = $perms ?: null;
        $this->save();
        return $this;
    }

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
