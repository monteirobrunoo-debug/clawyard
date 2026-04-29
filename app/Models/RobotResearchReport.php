<?php

namespace App\Models;

use App\Services\AgentCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * One research-council session. Persisted so the /robot/research
 * timeline can show what the agents have been up to + the operator
 * can audit each council's reasoning, costs, and conclusions.
 */
class RobotResearchReport extends Model
{
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETE  = 'complete';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'topic',
        'status',
        'leading_agent',
        'participants',
        'findings',
        'final_summary',
        'proposals',
        'total_cost_usd',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'participants'   => 'array',
            'findings'       => 'array',
            'proposals'      => 'array',
            'total_cost_usd' => 'decimal:4',
            'completed_at'   => 'datetime',
        ];
    }

    /** Display metadata for the lead agent. */
    public function leadMeta(): ?array
    {
        return AgentCatalog::find($this->leading_agent);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING   => '⏳ A pesquisar',
            self::STATUS_COMPLETE  => '✓ Concluído',
            self::STATUS_CANCELLED => '❌ Cancelado',
            default                => $this->status,
        };
    }
}
