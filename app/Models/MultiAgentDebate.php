<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultiAgentDebate extends Model
{
    protected $fillable = [
        'tender_id', 'initiated_by_user_id', 'topic', 'agent_keys',
        'status', 'rounds', 'synthesis', 'disagreements',
        'confidence_pct', 'cost_usd', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'agent_keys'      => 'array',
        'rounds'          => 'array',
        'disagreements'   => 'array',
        'cost_usd'        => 'decimal:4',
        'confidence_pct'  => 'integer',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
