<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AgentShare extends Model
{
    protected $fillable = [
        'token', 'portal_token', 'agent_key', 'client_name', 'client_email',
        'password_hash', 'custom_title', 'welcome_message',
        'show_branding', 'allow_sap_access', 'is_active', 'expires_at', 'created_by',
        'usage_count', 'last_used_at',
        'require_otp', 'lock_to_device',
        'notify_on_access', 'notify_email', 'notify_whatsapp',
        'revoked_at', 'revoked_reason',
    ];

    protected $casts = [
        'show_branding'    => 'boolean',
        'allow_sap_access' => 'boolean',
        'is_active'        => 'boolean',
        'require_otp'      => 'boolean',
        'lock_to_device'   => 'boolean',
        'notify_on_access' => 'boolean',
        'expires_at'       => 'datetime',
        'last_used_at'     => 'datetime',
        'revoked_at'       => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function otps(): HasMany
    {
        return $this->hasMany(AgentShareOtp::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AgentShareAccessLog::class)->latest();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    public static function generateToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    public static function generatePortalToken(): string
    {
        do {
            $token = Str::random(24);
        } while (static::where('portal_token', $token)->exists());

        return $token;
    }

    public function getPortalUrl(): ?string
    {
        if (!$this->portal_token) return null;
        $base = rtrim(config('app.share_url', config('app.url')), '/');
        return $base . '/p/' . $this->portal_token;
    }

    /**
     * Every sibling share that belongs to the same client portal bundle
     * (including this one). Returns an empty collection if the share is
     * standalone (no portal_token).
     */
    public function portalSiblings()
    {
        if (!$this->portal_token) return collect();
        return static::where('portal_token', $this->portal_token)->get();
    }

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->revoked_at) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(?string $reason = null): void
    {
        $this->update([
            'revoked_at'     => now(),
            'revoked_reason' => $reason,
            'is_active'      => false,
        ]);
    }

    public function checkPassword(string $password): bool
    {
        if (!$this->password_hash) return true; // no password set
        return password_verify($password, $this->password_hash);
    }

    public function recordUsage(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }

    public function getUrl(): string
    {
        // Use SHARE_URL if set (e.g. https://clawyard.partyard.eu), otherwise APP_URL
        $base = rtrim(config('app.share_url', config('app.url')), '/');
        return $base . '/a/' . $this->token;
    }

    // Agent display info
    public static function agentMeta(): array
    {
        return [
            'auto'         => ['name' => 'Auto',               'emoji' => '🤖', 'color' => '#76b900', 'photo' => null],
            'sales'        => ['name' => 'Marco Sales',        'emoji' => '💼', 'color' => '#3b82f6', 'photo' => '/images/agents/sales.png'],
            'support'      => ['name' => 'Marcus Suporte',     'emoji' => '🔧', 'color' => '#f59e0b', 'photo' => '/images/agents/support.png'],
            'email'        => ['name' => 'Daniel Email',       'emoji' => '📧', 'color' => '#8b5cf6', 'photo' => '/images/agents/email.png'],
            'sap'          => ['name' => 'Richard SAP',        'emoji' => '📊', 'color' => '#06b6d4', 'photo' => '/images/agents/sap.png'],
            'document'     => ['name' => 'Comandante Doc',     'emoji' => '📄', 'color' => '#94a3b8', 'photo' => '/images/agents/document.png'],
            'capitao'      => ['name' => 'Capitão Porto',      'emoji' => '⚓', 'color' => '#0ea5e9', 'photo' => '/images/agents/maritime.png'],
            'aria'         => ['name' => 'ARIA Security',      'emoji' => '🔐', 'color' => '#ef4444', 'photo' => '/images/agents/aria.png'],
            'quantum'      => ['name' => 'Prof. Quantum Leap', 'emoji' => '⚛️', 'color' => '#22d3ee', 'photo' => '/images/agents/quantum.png'],
            'finance'      => ['name' => 'Dr. Luís Financeiro','emoji' => '💰', 'color' => '#10b981', 'photo' => '/images/agents/finance.png'],
            'research'     => ['name' => 'Marina Research',    'emoji' => '🔍', 'color' => '#f97316', 'photo' => '/images/agents/research.png'],
            'acingov'      => ['name' => 'Dra. Ana Contratos', 'emoji' => '🏛️', 'color' => '#f59e0b', 'photo' => '/images/agents/acingov.png'],
            'engineer'     => ['name' => 'Eng. Victor I&D',    'emoji' => '🔩', 'color' => '#f97316', 'photo' => '/images/agents/engineer.png'],
            'patent'       => ['name' => 'Dra. Sofia IP',      'emoji' => '🏛️', 'color' => '#8b5cf6', 'photo' => '/images/agents/patent.png'],
            'energy'       => ['name' => 'Eng. Sofia Energia', 'emoji' => '⚡', 'color' => '#10b981', 'photo' => '/images/agents/energy.png'],
            'kyber'        => ['name' => 'KYBER Encryption',   'emoji' => '🔒', 'color' => '#76b900', 'photo' => '/images/agents/kyber.png'],
            'claude'       => ['name' => 'Bruno AI',           'emoji' => '🧠', 'color' => '#a855f7', 'photo' => '/images/agents/claude.png'],
            'nvidia'       => ['name' => 'Carlos NVIDIA',      'emoji' => '⚡', 'color' => '#76b900', 'photo' => '/images/agents/nvidia.png'],
            'qnap'         => ['name' => 'Arquivo PartYard',   'emoji' => '🗄️', 'color' => '#f59e0b', 'photo' => null],
            'thinking'     => ['name' => 'Prof. Deep Thought', 'emoji' => '🧠', 'color' => '#a855f7', 'photo' => null],
            'batch'        => ['name' => 'Max Batch',          'emoji' => '📦', 'color' => '#06b6d4', 'photo' => '/images/agents/batch.png'],
            'computer'     => ['name' => 'RoboDesk',           'emoji' => '🖥️', 'color' => '#22c55e', 'photo' => null],
            'vessel'       => ['name' => 'Capitão Vasco',      'emoji' => '⚓', 'color' => '#0ea5e9', 'photo' => '/images/agents/vessel.png'],
            // shipping (Logística/PartYard) removed — UPS is a skill embedded in other agents
        ];
    }
}
