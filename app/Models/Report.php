<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'title', 'type', 'content', 'summary', 'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function typeBadge(): string
    {
        return match($this->type) {
            'aria'         => '🔐 ARIA Security',
            'quantum'      => '⚛️ Quantum Leap',
            'market'       => '📊 Market Intel',
            'sales'        => '💼 Sales',
            'email'        => '✉️ Email',
            'support'      => '🎧 Suporte',
            'orchestrator' => '🤖 Orchestrator',
            'briefing'     => '📊 Briefing Executivo',
            default        => '📄 Relatório',
        };
    }

    public function typeColor(): string
    {
        return match($this->type) {
            'aria'         => '#ff4444',
            'quantum'      => '#9933ff',
            'market'       => '#ffaa00',
            'sales'        => '#ff8800',
            'email'        => '#00cc66',
            'support'      => '#4499ff',
            'orchestrator' => '#aaaaaa',
            'briefing'     => '#00aaff',
            default        => '#76b900',
        };
    }
}
