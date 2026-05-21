<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks an Anthropic Message Batches submission.
 *
 * @property string|null $batch_id
 * @property string $model
 * @property string $kind
 * @property int $request_count
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \Illuminate\Support\Carbon|null $polled_at
 * @property bool $results_collected
 * @property int|null $results_succeeded
 * @property int|null $results_errored
 * @property int|null $results_canceled
 * @property int|null $results_expired
 * @property string|null $cost_usd_estimated
 * @property string|null $cost_usd_actual
 * @property array|null $metadata
 */
class AnthropicBatch extends Model
{
    protected $fillable = [
        'batch_id', 'model', 'kind', 'request_count', 'status',
        'submitted_at', 'ended_at', 'polled_at',
        'results_collected', 'results_succeeded', 'results_errored',
        'results_canceled', 'results_expired',
        'cost_usd_estimated', 'cost_usd_actual',
        'metadata', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'request_count'      => 'integer',
            'results_collected'  => 'boolean',
            'results_succeeded'  => 'integer',
            'results_errored'    => 'integer',
            'results_canceled'   => 'integer',
            'results_expired'    => 'integer',
            'metadata'           => 'array',
            'submitted_at'       => 'datetime',
            'ended_at'           => 'datetime',
            'polled_at'          => 'datetime',
        ];
    }

    // ─── Status helpers ─────────────────────────────────────────────────

    public const STATUS_PENDING     = 'pending';
    public const STATUS_CREATED     = 'created';      // Anthropic accepted
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ENDED       = 'ended';        // results ready
    public const STATUS_CANCELED    = 'canceled';
    public const STATUS_FAILED      = 'failed';

    public function isReady(): bool
    {
        return $this->status === self::STATUS_ENDED && !$this->results_collected;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_ENDED, self::STATUS_CANCELED, self::STATUS_FAILED,
        ], true);
    }

    public function scopePending($q)
    {
        return $q->whereNotIn('status', [
            self::STATUS_ENDED, self::STATUS_CANCELED, self::STATUS_FAILED,
        ]);
    }

    public function scopeReadyToCollect($q)
    {
        return $q->where('status', self::STATUS_ENDED)
                 ->where('results_collected', false);
    }
}
