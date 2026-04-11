<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'session_id', 'channel', 'agent', 'phone', 'email', 'name', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function getHistoryAttribute(): array
    {
        // Keep last 40 messages (20 turns) to stay well within context limits
        return $this->messages()
            ->orderBy('created_at', 'desc')
            ->limit(40)
            ->get()
            ->reverse()
            ->values()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }
}
