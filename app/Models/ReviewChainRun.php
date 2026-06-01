<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewChainRun extends Model
{
    protected $fillable = [
        'tender_id', 'initiated_by_user_id',
        'status', 'steps', 'overall_approved', 'stopped_at_step',
        'cost_usd', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'steps'           => 'array',
        'overall_approved'=> 'boolean',
        'cost_usd'        => 'decimal:4',
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
