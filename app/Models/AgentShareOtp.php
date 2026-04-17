<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function matches(string $code): bool
    {
        return hash_equals($this->code_hash, hash('sha256', trim($code)));
    }
}
