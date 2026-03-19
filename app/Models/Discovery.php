<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discovery extends Model
{
    protected $fillable = [
        'source', 'reference_id', 'title', 'authors', 'summary',
        'category', 'activity_types', 'priority', 'relevance_score',
        'opportunity', 'recommendation', 'url', 'published_date',
    ];

    protected $casts = [
        'activity_types' => 'array',
        'published_date' => 'date',
    ];

    // Activity type categories with labels and icons
    public static array $activityCategories = [
        'propulsion'    => ['label' => 'Propulsão Naval',        'icon' => '⚓', 'color' => '#4499ff'],
        'maintenance'   => ['label' => 'Manutenção Preditiva',   'icon' => '🔧', 'color' => '#ffaa00'],
        'defense'       => ['label' => 'Defesa & Naval Militar', 'icon' => '🛡️', 'color' => '#ff4444'],
        'seals'         => ['label' => 'Vedantes & Rolamentos',  'icon' => '⚙️', 'color' => '#76b900'],
        'digital'       => ['label' => 'Plataforma Digital',     'icon' => '💻', 'color' => '#cc66ff'],
        'energy'        => ['label' => 'Energia & Combustível',  'icon' => '⚡', 'color' => '#ff9900'],
        'materials'     => ['label' => 'Materiais & Fabrico',    'icon' => '🏭', 'color' => '#66ccff'],
        'quantum'       => ['label' => 'Quantum & Computação',   'icon' => '⚛️', 'color' => '#dd44ff'],
        'supply_chain'  => ['label' => 'Supply Chain & Logística','icon' => '📦', 'color' => '#44ddaa'],
        'ai_ml'         => ['label' => 'AI & Machine Learning',  'icon' => '🤖', 'color' => '#ff6699'],
        'other'         => ['label' => 'Outro',                  'icon' => '📄', 'color' => '#555'],
    ];

    public static array $priorityLabels = [
        'act_now'   => ['label' => 'Actuar Já',       'color' => '#ff4444', 'badge' => '🔴'],
        'monitor'   => ['label' => 'Monitorizar',     'color' => '#ffaa00', 'badge' => '🟠'],
        'watch'     => ['label' => 'Observar',        'color' => '#ffff00', 'badge' => '🟡'],
        'awareness' => ['label' => 'Conhecimento',    'color' => '#76b900', 'badge' => '🟢'],
    ];

    public static array $sourceLabels = [
        'arxiv'          => ['label' => 'arXiv',          'icon' => '📰', 'color' => '#ff6600'],
        'uspto'          => ['label' => 'USPTO',          'icon' => '🏛️', 'color' => '#4499ff'],
        'google_patents' => ['label' => 'Google Patents', 'icon' => '🔍', 'color' => '#44aa66'],
    ];

    public function priorityBadge(): string
    {
        return self::$priorityLabels[$this->priority]['badge'] ?? '🟢';
    }

    public function sourceIcon(): string
    {
        return self::$sourceLabels[$this->source]['icon'] ?? '📄';
    }
}
