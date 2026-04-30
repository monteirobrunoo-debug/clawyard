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
        // Outreach pipeline (added 2026-04-30 — see migration
        // 2026_04_30_000001_add_outreach_columns_to_lead_opportunities)
        'outreach_status',
        'outreach_draft_subject', 'outreach_draft_body',
        'outreach_to_email', 'outreach_to_name',
        'outreach_drafted_at', 'outreach_approved_at', 'outreach_sent_at',
        'outreach_approved_by_user_id', 'outreach_sent_by_user_id',
        'outreach_reject_reason', 'outreach_draft_cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at'             => 'datetime',
            'score'                    => 'integer',
            'outreach_drafted_at'      => 'datetime',
            'outreach_approved_at'     => 'datetime',
            'outreach_sent_at'         => 'datetime',
            'outreach_draft_cost_usd'  => 'decimal:5',
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

    // ── Outreach state machine ───────────────────────────────────────────
    // Independent of the lead's own status — see migration for rationale.
    public const OUTREACH_NONE          = 'none';
    public const OUTREACH_DRAFT_PENDING = 'draft_pending';
    public const OUTREACH_APPROVED      = 'approved';
    public const OUTREACH_SENT          = 'sent';
    public const OUTREACH_REJECTED      = 'rejected';

    public const OUTREACH_STATUSES = [
        self::OUTREACH_NONE,
        self::OUTREACH_DRAFT_PENDING,
        self::OUTREACH_APPROVED,
        self::OUTREACH_SENT,
        self::OUTREACH_REJECTED,
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

    /** Leads eligible for an automated outreach draft.
     *  Confident only (score > 70) and never drafted — repeat draftings
     *  would waste tokens and confuse the manager with stale text.
     *  We also skip leads already in a downstream lead-status. */
    public function scopeNeedsOutreachDraft(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_CONFIDENT)
                 ->where('outreach_status', self::OUTREACH_NONE)
                 ->whereNull('outreach_drafted_at');
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
