<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per reward-earning action. Source of truth for both
 * `user_points` and `agent_metrics` — both tables can be regenerated
 * by replaying this log.
 *
 * Authoring: rows are written by `App\Services\Rewards\RewardRecorder`
 * (added in C2). Models / controllers do NOT new-up RewardEvent
 * directly; they call `RewardRecorder::record(...)` which encapsulates
 * the points formula + caps + denormalised total updates in one
 * transaction.
 *
 * Read paths:
 *   • dashboard "your last 30 days" → query by user_id + created_at
 *   • agent metric backfill        → query by agent_key + event_type
 *   • lead provenance (/leads/X)   → query by subject_type+subject_id
 */
class RewardEvent extends Model
{
    /**
     * Event taxonomy. Strings (not enum) so adding a type doesn't
     * require a migration. RewardRecorder reads these constants to
     * map type → default points.
     *
     * Naming convention: <noun>_<past-tense-verb>.
     */
    public const TYPE_TENDER_IMPORTED   = 'tender_imported';     // 5 pts — import file processed
    public const TYPE_LEAD_REVIEWED     = 'lead_reviewed';       // 1 pt — opened a lead detail (cap 5/day)
    public const TYPE_LEAD_QUALIFIED    = 'lead_qualified';      // 10 pts — moved status to confident
    public const TYPE_LEAD_CONTACTED    = 'lead_contacted';      // 5 pts — marked "contactado"
    public const TYPE_LEAD_WON          = 'lead_won';            // 50 pts — closed a deal
    public const TYPE_AGENT_CHAT        = 'agent_chat';          // 1 pt — used an agent (cap 10/day)
    public const TYPE_AGENT_THUMBS_UP   = 'agent_thumbs_up';     // 2 pts (user) + agent metric
    public const TYPE_AGENT_THUMBS_DOWN = 'agent_thumbs_down';   // 1 pt (user, for honest signal) + agent metric
    public const TYPE_AGENT_SHARE       = 'agent_share';         // 3 pts — shared a conversation
    public const TYPE_DAILY_LOGIN       = 'daily_login';         // 1 pt — first action of the day (streak fuel)
    public const TYPE_TENDER_ASSIGNED   = 'tender_assigned';     // 2 pts — assigned a collaborator
    public const TYPE_SWARM_RUN         = 'swarm_run';           // 0 pts — agent metric only (no human earned)
    public const TYPE_SWARM_LEAD        = 'swarm_lead';          // 0 pts — agent metric only

    /**
     * Default points per event type. RewardRecorder may override
     * (e.g. 0 when a daily cap is exceeded). Negative values reserved
     * for admin corrections.
     *
     * @return array<string, int>
     */
    public const DEFAULT_POINTS = [
        self::TYPE_TENDER_IMPORTED   => 5,
        self::TYPE_LEAD_REVIEWED     => 1,
        self::TYPE_LEAD_QUALIFIED    => 10,
        self::TYPE_LEAD_CONTACTED    => 5,
        self::TYPE_LEAD_WON          => 50,
        self::TYPE_AGENT_CHAT        => 1,
        self::TYPE_AGENT_THUMBS_UP   => 2,
        self::TYPE_AGENT_THUMBS_DOWN => 1,
        self::TYPE_AGENT_SHARE       => 3,
        self::TYPE_DAILY_LOGIN       => 1,
        self::TYPE_TENDER_ASSIGNED   => 2,
        self::TYPE_SWARM_RUN         => 0,
        self::TYPE_SWARM_LEAD        => 0,
    ];

    protected $fillable = [
        'user_id',
        'agent_key',
        'event_type',
        'points',
        'subject_type',
        'subject_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'points'     => 'integer',
            'subject_id' => 'integer',
        ];
    }

    /** Look up the default points for an event type. */
    public static function pointsFor(string $eventType): int
    {
        return self::DEFAULT_POINTS[$eventType] ?? 0;
    }

    /** All known event types — used by the admin filter UI. */
    public static function knownTypes(): array
    {
        return array_keys(self::DEFAULT_POINTS);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic relation to whatever this event is ABOUT
     * (lead, tender, swarm_run, message). Resolution requires
     * `subject_type` to be a registered Laravel morph map alias OR
     * a fully-qualified class name. We use the alias map below to
     * keep DB compact + decouple from class moves.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
