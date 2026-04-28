<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user denormalised totals. ONE row per user — the row is created
 * lazily on the first event recorded for that user.
 *
 * Read path: dashboard hits this table (one indexed PK lookup) instead
 * of SUMming the reward_events log.
 *
 * Write path: RewardRecorder bumps the row inside the same transaction
 * that INSERTs the reward_event, so totals are always consistent with
 * the log.
 *
 * Levels: derived from total_points but cached on disk so the dashboard
 * doesn't re-compute on every render. Recomputed via levelFor() on
 * every recorder write — cheap, deterministic.
 */
class UserPoints extends Model
{
    protected $table = 'user_points';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'total_points',
        'level',
        'current_streak_days',
        'best_streak_days',
        'last_active_on',
        'badges',
    ];

    protected function casts(): array
    {
        return [
            'badges'              => 'array',
            'last_active_on'      => 'date',
            'total_points'        => 'integer',
            'level'               => 'integer',
            'current_streak_days' => 'integer',
            'best_streak_days'    => 'integer',
        ];
    }

    /**
     * Level thresholds — cumulative points needed to reach each level.
     * Index = level number. Tuned for H&P internal use:
     *   0 — Recruta            (default, on signup)
     *   1 — Junior             100 pts (~20 chats + 1 lead reviewed)
     *   2 — Senior             1k pts (~1 deal won + steady use)
     *   3 — Specialist         5k pts
     *   4 — Master             20k pts
     *   5 — Legend             50k pts
     *
     * Increase the gap as level rises so progression slows naturally.
     */
    public const LEVEL_THRESHOLDS = [0, 100, 1_000, 5_000, 20_000, 50_000];

    /**
     * Display names per level. Localise here when adding a Portuguese
     * variant for the dashboard.
     *
     * @return array<int, string>
     */
    public const LEVEL_NAMES = [
        0 => 'Recruta',
        1 => 'Junior',
        2 => 'Senior',
        3 => 'Specialist',
        4 => 'Master',
        5 => 'Legend',
    ];

    /** Compute the level a given total of points would unlock. */
    public static function levelFor(int $points): int
    {
        $level = 0;
        foreach (self::LEVEL_THRESHOLDS as $idx => $threshold) {
            if ($points >= $threshold) $level = $idx;
        }
        return $level;
    }

    /** Display name for the cached level. */
    public function levelName(): string
    {
        return self::LEVEL_NAMES[$this->level] ?? 'Recruta';
    }

    /**
     * Points still needed to reach the next level. Returns 0 when
     * the user is already at max level.
     */
    public function pointsToNextLevel(): int
    {
        $next = $this->level + 1;
        if (!isset(self::LEVEL_THRESHOLDS[$next])) return 0;
        return max(0, self::LEVEL_THRESHOLDS[$next] - $this->total_points);
    }

    /** Has the user earned a given badge key? */
    public function hasBadge(string $key): bool
    {
        return in_array($key, $this->badges ?? [], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
