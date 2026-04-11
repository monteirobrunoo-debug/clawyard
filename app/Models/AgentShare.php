<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AgentShare extends Model
{
    protected $fillable = [
        'token', 'agent_key', 'client_name', 'client_email',
        'password_hash', 'custom_title', 'welcome_message',
        'show_branding', 'is_active', 'expires_at', 'created_by',
        'usage_count', 'last_used_at',
    ];

    protected $casts = [
        'show_branding' => 'boolean',
        'is_active'     => 'boolean',
        'expires_at'    => 'datetime',
        'last_used_at'  => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    public static function generateToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
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
        return url('/a/' . $this->token);
    }

    // Agent display info
    public static function agentMeta(): array
    {
        return [
            'auto'         => ['name' => 'Auto',               'emoji' => '🤖', 'color' => '#76b900'],
            'sales'        => ['name' => 'Marco Sales',        'emoji' => '💼', 'color' => '#3b82f6'],
            'support'      => ['name' => 'Marcus Suporte',     'emoji' => '🔧', 'color' => '#f59e0b'],
            'email'        => ['name' => 'Daniel Email',       'emoji' => '📧', 'color' => '#8b5cf6'],
            'sap'          => ['name' => 'Richard SAP',        'emoji' => '📊', 'color' => '#06b6d4'],
            'document'     => ['name' => 'Comandante Doc',     'emoji' => '📄', 'color' => '#94a3b8'],
            'capitao'      => ['name' => 'Capitão Porto',      'emoji' => '⚓', 'color' => '#0ea5e9'],
            'aria'         => ['name' => 'ARIA Security',      'emoji' => '🔐', 'color' => '#ef4444'],
            'quantum'      => ['name' => 'Prof. Quantum Leap', 'emoji' => '⚛️', 'color' => '#22d3ee'],
            'finance'      => ['name' => 'Dr. Luís Financeiro','emoji' => '💰', 'color' => '#10b981'],
            'research'     => ['name' => 'Marina Research',    'emoji' => '🔍', 'color' => '#f97316'],
            'acingov'      => ['name' => 'Dra. Ana Contratos', 'emoji' => '🏛️', 'color' => '#f59e0b'],
            'engineer'     => ['name' => 'Eng. Victor I&D',    'emoji' => '🔩', 'color' => '#f97316'],
            'patent'       => ['name' => 'Dra. Sofia IP',      'emoji' => '🏛️', 'color' => '#8b5cf6'],
            'energy'       => ['name' => 'Eng. Sofia Energia', 'emoji' => '⚡', 'color' => '#10b981'],
            'kyber'        => ['name' => 'KYBER Encryption',   'emoji' => '🔒', 'color' => '#76b900'],
            'claude'       => ['name' => 'Bruno AI',           'emoji' => '🧠', 'color' => '#a855f7'],
            'nvidia'       => ['name' => 'Carlos NVIDIA',      'emoji' => '⚡', 'color' => '#76b900'],
        ];
    }
}
