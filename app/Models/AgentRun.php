<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    protected $fillable = [
        'tender_id', 'user_id', 'agent_key', 'purpose',
        'iterations', 'input_tokens', 'output_tokens', 'thinking_tokens',
        'cost_usd', 'tool_trace', 'final_text', 'thinking_text',
        'status', 'error', 'started_at', 'finished_at', 'duration_ms',
    ];

    protected $casts = [
        'tool_trace'   => 'array',
        'cost_usd'     => 'float',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public const STATUS_RUNNING     = 'running';
    public const STATUS_DONE        = 'done';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_COST_CAPPED = 'cost_capped';

    public function tender(): BelongsTo  { return $this->belongsTo(Tender::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
}
