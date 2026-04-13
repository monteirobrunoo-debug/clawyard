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
            'sales'        => '💼 Marco Sales',
            'email'        => '✉️ Daniel Email',
            'support'      => '🎧 Marcus Suporte',
            'sap'          => '🏭 Richard SAP',
            'document'     => '📂 Comandante Doc',
            'finance'      => '💰 Dr. Luís Financeiro',
            'research'     => '🔬 Marina Research',
            'capitao'      => '⚓ Capitão Porto',
            'acingov'      => '⚖️ Dra. Ana Contratos',
            'engineer'     => '🔧 Eng. Victor I&D',
            'patent'       => '📜 Dra. Sofia IP',
            'energy'       => '⚡ Eng. Sofia Energia',
            'kyber'        => '🔒 KYBER Encryption',
            'qnap'         => '🗄️ Arquivo PartYard',
            'thinking'     => '🧠 Prof. Deep Thought',
            'batch'        => '⚡ Max Batch',
            'computer'     => '🖥️ RoboDesk',
            'vessel'       => '🚢 Capitão Vasco',
            'claude'       => '🤖 Bruno AI',
            'nvidia'       => '🟢 Carlos NVIDIA',
            'orchestrator' => '🤖 All Agents',
            'briefing'     => '📊 Briefing Executivo',
            'auto'         => '🐾 ClawYard Auto',
            'mildef'       => '🎖️ Cor. Rodrigues Defesa',
            default        => '📄 Relatório',
        };
    }

    public function typeColor(): string
    {
        return match($this->type) {
            'aria'         => '#ff4444',
            'quantum'      => '#9933ff',
            'market'       => '#ffaa00',
            'sales'        => '#3b82f6',
            'email'        => '#8b5cf6',
            'support'      => '#f59e0b',
            'sap'          => '#ef4444',
            'document'     => '#06b6d4',
            'finance'      => '#10b981',
            'research'     => '#6366f1',
            'capitao'      => '#0ea5e9',
            'acingov'      => '#f59e0b',
            'engineer'     => '#84cc16',
            'patent'       => '#a78bfa',
            'energy'       => '#fbbf24',
            'kyber'        => '#6b7280',
            'qnap'         => '#64748b',
            'thinking'     => '#818cf8',
            'batch'        => '#76b900',
            'computer'     => '#94a3b8',
            'vessel'       => '#0284c7',
            'claude'       => '#f97316',
            'nvidia'       => '#76b900',
            'orchestrator' => '#aaaaaa',
            'briefing'     => '#00aaff',
            'auto'         => '#76b900',
            'mildef'       => '#6b3fa0',
            default        => '#76b900',
        };
    }
}
