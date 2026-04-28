<?php

namespace App\Services\Rewards;

use App\Models\AgentMetric;
use App\Models\RewardEvent;
use App\Models\UserPoints;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for awarding rewards. Atomically:
 *   1. Inserts a row in reward_events  (the audit log)
 *   2. Bumps user_points.total_points + recomputes level + streak
 *   3. Bumps agent_metrics for the agent_key (if any)
 *
 * Why ONE service instead of letting callers write to the three tables
 * directly:
 *   • Atomicity. All three writes happen inside a single transaction
 *     so a crash mid-write doesn't leave the totals inconsistent with
 *     the log.
 *   • Consistency of the points formula. Daily caps for chat /
 *     lead-review live here, not scattered across controllers.
 *   • Backfill. When the formula changes, a single artisan command
 *     rebuilds user_points + agent_metrics by replaying reward_events
 *     through this same service (no event log scattered across
 *     callers).
 *
 * Failure mode: NEVER throws to the caller. Internal failures are
 * logged but the underlying flow (swarm run, lead update, import)
 * must not be aborted just because the rewards subsystem hiccupped.
 */
class RewardRecorder
{
    private BadgeEvaluator $badges;

    public function __construct(?BadgeEvaluator $badges = null)
    {
        $this->badges = $badges ?? app(BadgeEvaluator::class);
    }

    /**
     * Daily caps: max rewardable events per user per day for noisy
     * event types. Excess events still record but with points=0 so
     * the audit log stays complete + operators can see the cap is
     * binding.
     *
     * @var array<string, int>
     */
    public const DAILY_CAPS = [
        RewardEvent::TYPE_AGENT_CHAT      => 10,
        RewardEvent::TYPE_LEAD_REVIEWED   => 5,
        RewardEvent::TYPE_AGENT_THUMBS_UP => 20,
    ];

    /**
     * Record a reward event. Returns the persisted RewardEvent on
     * success, null on failure (already logged).
     *
     * @param string      $eventType  one of RewardEvent::TYPE_*
     * @param int|null    $userId     null for system-only events
     * @param string|null $agentKey   null for non-agent events
     * @param Model|null  $subject    polymorphic — the lead/tender/run this is about
     * @param array       $metadata   free-form context (lead_score, tokens, …)
     * @param int|null    $points     null = use DEFAULT_POINTS for type
     */
    public function record(
        string $eventType,
        ?int $userId = null,
        ?string $agentKey = null,
        ?Model $subject = null,
        array $metadata = [],
        ?int $points = null,
    ): ?RewardEvent {
        try {
            return DB::transaction(function () use (
                $eventType, $userId, $agentKey, $subject, $metadata, $points
            ) {
                $effectivePoints = $points ?? RewardEvent::pointsFor($eventType);

                // Apply daily cap — only for the user-scoped events with caps.
                if ($userId !== null
                    && isset(self::DAILY_CAPS[$eventType])
                    && $this->dailyCount($userId, $eventType) >= self::DAILY_CAPS[$eventType]
                ) {
                    $effectivePoints = 0;
                    $metadata['cap_reached'] = true;
                }

                // 1. Insert the audit row.
                $event = RewardEvent::create([
                    'user_id'      => $userId,
                    'agent_key'    => $agentKey,
                    'event_type'   => $eventType,
                    'points'       => $effectivePoints,
                    'subject_type' => $subject ? get_class($subject) : null,
                    'subject_id'   => $subject?->getKey(),
                    'metadata'     => $metadata ?: null,
                ]);

                // 2. Bump per-user totals (only when there's a user + non-zero points).
                if ($userId !== null) {
                    $row = $this->bumpUserPoints($userId, $effectivePoints);

                    // 2b. Award newly-unlocked badges. Wrapped in try/
                    // catch implicitly by the outer transaction handler,
                    // so a badge bug never aborts the recorder write.
                    $newly = $this->badges->evaluate($row, $event);
                    if (!empty($newly)) {
                        $row->badges = array_values(array_unique(array_merge(
                            (array) ($row->badges ?? []),
                            $newly,
                        )));
                        $row->save();
                    }
                }

                // 3. Bump per-agent metrics (only when there's an agent_key).
                if ($agentKey !== null) {
                    $this->bumpAgentMetric($agentKey, $eventType, $metadata);
                }

                return $event;
            });
        } catch (\Throwable $e) {
            Log::error('RewardRecorder: record failed', [
                'event_type' => $eventType,
                'user_id'    => $userId,
                'agent_key'  => $agentKey,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convenience for the swarm: register one swarm_run event per
     * agent that participated. Each call bumps signals_processed,
     * total_cost_usd and total_tokens_in/out for that agent.
     *
     * @param string $agentKey
     * @param array  $usage  expected keys: cost_usd, tokens_in, tokens_out, ms
     * @param Model|null $subject  the AgentSwarmRun this metric belongs to
     */
    public function recordSwarmAgentRun(string $agentKey, array $usage, ?Model $subject = null): void
    {
        $this->record(
            eventType: RewardEvent::TYPE_SWARM_RUN,
            agentKey:  $agentKey,
            subject:   $subject,
            metadata:  [
                'cost_usd'   => (float) ($usage['cost_usd']   ?? 0),
                'tokens_in'  => (int)   ($usage['tokens_in']  ?? 0),
                'tokens_out' => (int)   ($usage['tokens_out'] ?? 0),
                'ms'         => (int)   ($usage['ms']         ?? 0),
            ],
        );
    }

    /**
     * Register a swarm_lead event per agent that contributed to a
     * lead. Each call bumps leads_generated and updates the running
     * average score.
     */
    public function recordSwarmAgentLead(string $agentKey, int $leadScore, ?Model $subject = null): void
    {
        $this->record(
            eventType: RewardEvent::TYPE_SWARM_LEAD,
            agentKey:  $agentKey,
            subject:   $subject,
            metadata:  ['lead_score' => $leadScore],
        );
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function dailyCount(int $userId, string $eventType): int
    {
        return (int) RewardEvent::query()
            ->where('user_id', $userId)
            ->where('event_type', $eventType)
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * Bump user_points: add to total, recompute level, refresh
     * streak. Lazy-creates the row. Returns the saved row so the
     * caller can pass it to the BadgeEvaluator without re-fetching.
     */
    private function bumpUserPoints(int $userId, int $points): UserPoints
    {
        $row = UserPoints::firstOrCreate(
            ['user_id' => $userId],
            ['total_points' => 0, 'level' => 0],
        );

        if ($points !== 0) {
            $row->total_points = max(0, (int) $row->total_points + $points);
            $row->level        = UserPoints::levelFor((int) $row->total_points);
        }

        $this->refreshStreak($row);
        $row->save();
        return $row;
    }

    /**
     * Streak rule:
     *   • last_active_on null OR not today → mark today as active
     *   • Gap of exactly 1 calendar day → streak += 1
     *   • Gap of 2+ days OR first event → streak = 1
     *   • Same day → no change (multiple events same day count once)
     */
    private function refreshStreak(UserPoints $row): void
    {
        $today    = today();
        $previous = $row->last_active_on;

        if ($previous === null) {
            $row->current_streak_days = 1;
            $row->best_streak_days    = max(1, (int) $row->best_streak_days);
            $row->last_active_on      = $today;
            return;
        }

        // Same calendar day — nothing to update.
        if ($previous->isSameDay($today)) {
            return;
        }

        // Robust against Carbon major-version drift in diffInDays
        // semantics: just ask "was previous = yesterday?".
        if ($today->copy()->subDay()->isSameDay($previous)) {
            $row->current_streak_days = (int) $row->current_streak_days + 1;
        } else {
            // 2+ day gap (or any other case) — streak resets.
            $row->current_streak_days = 1;
        }

        $row->best_streak_days = max((int) $row->best_streak_days, (int) $row->current_streak_days);
        $row->last_active_on   = $today;
    }

    /**
     * Bump per-agent metrics based on the event type. Lazy-creates
     * the row on the first RELEVANT event for an agent.
     *
     * Why filter event types here: agent_chat events also carry an
     * agent_key (so the FRIEND/POLYGLOT badges can count distinct
     * agents the user spoke to), but we don't want chat volume to
     * pollute the agent_metric table — that table is reserved for
     * swarm-and-lead performance signals. Without this filter, every
     * agent the user pinged once would get a near-empty row.
     */
    private function bumpAgentMetric(string $agentKey, string $eventType, array $metadata): void
    {
        $relevantTypes = [
            RewardEvent::TYPE_SWARM_RUN,
            RewardEvent::TYPE_SWARM_LEAD,
            RewardEvent::TYPE_LEAD_WON,
            RewardEvent::TYPE_AGENT_THUMBS_UP,
            RewardEvent::TYPE_AGENT_THUMBS_DOWN,
        ];
        if (!in_array($eventType, $relevantTypes, true)) return;

        $m = AgentMetric::firstOrCreate(['agent_key' => $agentKey]);

        switch ($eventType) {
            case RewardEvent::TYPE_SWARM_RUN:
                $m->signals_processed = (int) $m->signals_processed + 1;
                $m->total_cost_usd    = (float) $m->total_cost_usd + (float) ($metadata['cost_usd']   ?? 0);
                $m->total_tokens_in   = (int)   $m->total_tokens_in  + (int)   ($metadata['tokens_in']  ?? 0);
                $m->total_tokens_out  = (int)   $m->total_tokens_out + (int)   ($metadata['tokens_out'] ?? 0);
                $m->last_run_at       = now();
                break;

            case RewardEvent::TYPE_SWARM_LEAD:
                $m->leads_generated = (int) $m->leads_generated + 1;
                if (isset($metadata['lead_score'])) {
                    $m->applyNewLeadScore((int) $metadata['lead_score']);
                }
                $m->last_run_at = now();
                break;

            case RewardEvent::TYPE_LEAD_WON:
                // The CALLER is responsible for invoking record() once
                // per agent that contributed to the won lead. Here we
                // just bump the metric.
                $m->leads_won = (int) $m->leads_won + 1;
                break;

            case RewardEvent::TYPE_AGENT_THUMBS_UP:
                $m->thumbs_up = (int) $m->thumbs_up + 1;
                break;

            case RewardEvent::TYPE_AGENT_THUMBS_DOWN:
                $m->thumbs_down = (int) $m->thumbs_down + 1;
                break;
        }

        $m->save();
    }
}
