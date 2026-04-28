<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a multi-agent chain. See migration for design.
 *
 * Lifecycle helpers (`markRunning`, `appendStep`, etc.) keep the
 * controller / orchestrator code thin — they encapsulate the
 * cost tally + chain_log append in a single call so we never
 * forget to bump the cost when an agent gets called.
 */
class AgentSwarmRun extends Model
{
    protected $fillable = [
        'signal_type', 'signal_id', 'signal_hash', 'signal_payload',
        'chain_name', 'status',
        'started_at', 'finished_at',
        'chain_log', 'cost_usd',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'signal_payload' => 'array',
            'chain_log'      => 'array',
            'cost_usd'       => 'decimal:4',
            'started_at'     => 'datetime',
            'finished_at'    => 'datetime',
        ];
    }

    // ── Status vocabulary ────────────────────────────────────────────────
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_ABORTED = 'aborted';   // budget exceeded

    public const TERMINAL_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_ABORTED,
    ];

    // ── Relations ────────────────────────────────────────────────────────
    public function leads(): HasMany
    {
        return $this->hasMany(LeadOpportunity::class, 'swarm_run_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    // ── Lifecycle helpers ────────────────────────────────────────────────
    /**
     * Stable hash for idempotency. The same (type, id) pair always
     * produces the same hash, so the orchestrator can do
     * AgentSwarmRun::firstOrCreate(['signal_hash' => …]) and not
     * re-process a signal it has already chewed on.
     */
    public static function hashFor(string $type, ?string $id): string
    {
        return hash('sha256', $type . '|' . ($id ?? ''));
    }

    public function markRunning(): self
    {
        $this->status     = self::STATUS_RUNNING;
        $this->started_at = $this->started_at ?: now();
        $this->save();
        return $this;
    }

    public function markDone(): self
    {
        $this->status      = self::STATUS_DONE;
        $this->finished_at = now();
        $this->save();
        return $this;
    }

    public function markFailed(string $reason = ''): self
    {
        $this->status      = self::STATUS_FAILED;
        $this->finished_at = now();
        $this->appendStep([
            'event'   => 'failed',
            'reason'  => $reason,
        ]);
        return $this;
    }

    public function markAborted(string $reason = 'budget_exceeded'): self
    {
        $this->status      = self::STATUS_ABORTED;
        $this->finished_at = now();
        $this->appendStep([
            'event'  => 'aborted',
            'reason' => $reason,
        ]);
        return $this;
    }

    /**
     * Append one step to chain_log AND bump cost_usd in the same
     * save() — keeps the audit trail and the running total in sync
     * even if the next step crashes.
     *
     * @param array $step  shape: { agent?, phase?, output?, tokens_in?,
     *                              tokens_out?, cost_usd?, ms?, … }
     */
    public function appendStep(array $step): self
    {
        $log = $this->chain_log ?? [];
        $step['at'] = $step['at'] ?? now()->toIso8601String();
        $log[] = $step;
        $this->chain_log = $log;
        if (isset($step['cost_usd'])) {
            $this->cost_usd = (float) $this->cost_usd + (float) $step['cost_usd'];
        }
        $this->save();
        return $this;
    }
}
