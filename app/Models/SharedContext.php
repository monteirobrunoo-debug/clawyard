<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedContext extends Model
{
    protected $fillable = [
        'user_id',
        'agent_key', 'agent_name', 'context_key', 'summary', 'tags', 'expires_at',
        'change_type', 'similarity_score', 'change_note', 'previous_summary',
    ];

    protected $casts = [
        'tags'             => 'array',
        'expires_at'       => 'datetime',
        'similarity_score' => 'float',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
