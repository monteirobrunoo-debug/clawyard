<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead surfaced by an agent swarm. See migration for design notes.
 *
 * Three score buckets drive the email digest + UI badge:
 *   draft     score < 30  → quiet, never emailed
 *   review    30..70      → "needs human triage" badge
 *   confident > 70        → fast-tracked
 *
 * Status transitions are open (no state machine) but the typical
 * flow is: draft|review|confident → contacted → won|lost. UI
 * surfaces "discarded" as the explicit kill switch when an admin
 * decides a lead isn't worth chasing — we keep the row for
 * post-hoc analysis ("how many of our drafts were actually duds?").
 */
class LeadOpportunity extends Model
{
    protected $fillable = [
        'swarm_run_id', 'title', 'summary', 'score',
        'customer_hint', 'equipment_hint',
        'source_signal_type', 'source_signal_id',
        'status', 'assigned_user_id',
        'notes', 'contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at' => 'datetime',
            'score'        => 'integer',
        ];
    }

    // ── Status vocabulary ────────────────────────────────────────────────
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_CONFIDENT = 'confident';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_WON       = 'won';
    public const STATUS_LOST      = 'lost';
    public const STATUS_DISCARDED = 'discarded';

    public const ACTIONABLE_STATUSES = [
        self::STATUS_REVIEW,
        self::STATUS_CONFIDENT,
        self::STATUS_CONTACTED,
    ];

    // ── Relations ────────────────────────────────────────────────────────
    public function swarmRun(): BelongsTo
    {
        return $this->belongsTo(AgentSwarmRun::class, 'swarm_run_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────
    /** Leads worth surfacing to humans (review + confident). */
    public function scopeActionable(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIONABLE_STATUSES);
    }

    /** Leads ready for the daily email — score ≥30 + still untriaged. */
    public function scopeForDigest(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_REVIEW, self::STATUS_CONFIDENT])
                 ->orderByDesc('score');
    }

    // ── Status helper ────────────────────────────────────────────────────
    /**
     * Map a numeric score to the entry-point status (one of
     * draft/review/confident). Used by the synthesiser when it
     * inserts the lead — keeps the boundaries in one place.
     */
    public static function statusForScore(int $score): string
    {
        if ($score < 30) return self::STATUS_DRAFT;
        if ($score > 70) return self::STATUS_CONFIDENT;
        return self::STATUS_REVIEW;
    }
}
