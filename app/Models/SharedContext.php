<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedContext extends Model
{
    protected $fillable = [
        'agent_key', 'agent_name', 'context_key', 'summary', 'tags', 'expires_at',
    ];

    protected $casts = [
        'tags'       => 'array',
        'expires_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
