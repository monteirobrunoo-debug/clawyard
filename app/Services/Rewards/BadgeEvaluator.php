<?php

namespace App\Services\Rewards;

use App\Models\RewardEvent;
use App\Models\UserPoints;

/**
 * Given a freshly-recorded RewardEvent + the resulting UserPoints
 * state, returns the list of badge keys the user has just unlocked.
 *
 * Why a separate evaluator instead of inline checks in RewardRecorder:
 *   • The recorder already does three writes in one transaction; the
 *     badge logic should be testable in isolation without spinning up
 *     a full transaction context.
 *   • Adding a new badge is a one-method change here, no recorder
 *     edits needed.
 *
 * Performance:
 *   • Per-event-type "trigger map" — only badges whose conditions
 *     COULD be unlocked by this event type are checked. E.g. a
 *     daily_login event never re-checks the import_champion badge.
 *   • The check method itself uses targeted COUNT() queries (or
 *     reads the in-memory UserPoints row), not a full table scan.
 *
 * Returns ONLY newly-earned badges — already-owned ones are filtered
 * so the recorder doesn't write them twice.
 */
class BadgeEvaluator
{
    /**
     * Map: event_type → list of badge keys that COULD be unlocked.
     * Anything in 'always' is checked on every event (e.g. level
     * badges, since any event can push the user across a threshold).
     *
     * @return array<string, array<int, string>>
     */
    private const TRIGGERS = [
        'always' => [
            BadgeCatalog::FIRST_STEPS,
            BadgeCatalog::LEVEL_JUNIOR,
            BadgeCatalog::LEVEL_SENIOR,
            BadgeCatalog::LEVEL_SPECIALIST,
            BadgeCatalog::LEVEL_MASTER,
            BadgeCatalog::LEVEL_LEGEND,
            BadgeCatalog::DAILY_GRINDER,
            BadgeCatalog::STREAK_MASTER,
        ],
        RewardEvent::TYPE_LEAD_REVIEWED => [BadgeCatalog::LEAD_SNIFFER],
        RewardEvent::TYPE_LEAD_WON      => [
            BadgeCatalog::CLOSER,
            BadgeCatalog::DEAL_MACHINE,
            BadgeCatalog::WHALE_HUNTER,
        ],
        RewardEvent::TYPE_AGENT_CHAT      => [
            BadgeCatalog::AGENT_FRIEND,
            BadgeCatalog::AGENT_POLYGLOT,
        ],
        RewardEvent::TYPE_AGENT_THUMBS_UP   => [BadgeCatalog::FEEDBACK_GIVER],
        RewardEvent::TYPE_AGENT_THUMBS_DOWN => [BadgeCatalog::FEEDBACK_GIVER],
        RewardEvent::TYPE_TENDER_IMPORTED   => [BadgeCatalog::IMPORT_CHAMPION],
    ];

    /**
     * Evaluate which badges are NEWLY unlocked for this user given
     * the latest event + their current points/streak state.
     *
     * @return array<int, string>  badge keys the user just earned
     */
    public function evaluate(UserPoints $points, RewardEvent $event): array
    {
        $owned = (array) ($points->badges ?? []);

        $candidates = array_unique(array_merge(
            self::TRIGGERS['always'] ?? [],
            self::TRIGGERS[$event->event_type] ?? [],
        ));

        $unlocked = [];
        foreach ($candidates as $badgeKey) {
            if (in_array($badgeKey, $owned, true)) continue;     // already owned
            if ($this->qualifies($badgeKey, $points, $event)) {
                $unlocked[] = $badgeKey;
            }
        }

        return $unlocked;
    }

    /**
     * The heart of C4 — one check per badge. Branches by key, not
     * a polymorphic dispatch, because the catalogue is small (~15
     * badges) and inline checks are easier to audit.
     */
    private function qualifies(string $badgeKey, UserPoints $points, RewardEvent $event): bool
    {
        $userId = $points->user_id;

        return match ($badgeKey) {
            // Engagement
            BadgeCatalog::FIRST_STEPS    => true,    // any event qualifies (already filtered "owned" above)
            BadgeCatalog::DAILY_GRINDER  => (int) $points->current_streak_days >= 7,
            BadgeCatalog::STREAK_MASTER  => (int) $points->current_streak_days >= 30,

            // Levels — UserPoints::level is already cached after the recorder bumped it.
            BadgeCatalog::LEVEL_JUNIOR     => (int) $points->level >= 1,
            BadgeCatalog::LEVEL_SENIOR     => (int) $points->level >= 2,
            BadgeCatalog::LEVEL_SPECIALIST => (int) $points->level >= 3,
            BadgeCatalog::LEVEL_MASTER     => (int) $points->level >= 4,
            BadgeCatalog::LEVEL_LEGEND     => (int) $points->level >= 5,

            // Sales
            BadgeCatalog::LEAD_SNIFFER => $this->hasAnyEvent($userId, RewardEvent::TYPE_LEAD_REVIEWED),
            BadgeCatalog::CLOSER       => $this->hasAnyEvent($userId, RewardEvent::TYPE_LEAD_WON),
            BadgeCatalog::DEAL_MACHINE => $this->countEvents($userId, RewardEvent::TYPE_LEAD_WON) >= 5,
            BadgeCatalog::WHALE_HUNTER => $event->event_type === RewardEvent::TYPE_LEAD_WON
                                          && (int) ($event->metadata['score'] ?? 0) >= 80,

            // Agents
            BadgeCatalog::AGENT_FRIEND   => $this->distinctAgents($userId) >= 3,
            BadgeCatalog::AGENT_POLYGLOT => $this->distinctAgents($userId) >= 10,
            BadgeCatalog::FEEDBACK_GIVER => $this->countThumbs($userId) >= 10,

            // Imports
            BadgeCatalog::IMPORT_CHAMPION => $this->countEvents($userId, RewardEvent::TYPE_TENDER_IMPORTED) >= 5,

            default => false,
        };
    }

    private function hasAnyEvent(int $userId, string $type): bool
    {
        return RewardEvent::query()
            ->where('user_id', $userId)
            ->where('event_type', $type)
            ->exists();
    }

    private function countEvents(int $userId, string $type): int
    {
        return (int) RewardEvent::query()
            ->where('user_id', $userId)
            ->where('event_type', $type)
            ->count();
    }

    /**
     * Distinct agent_keys this user has chatted with via TYPE_AGENT_CHAT.
     * Excludes thumbs and other non-chat events so the badge tracks
     * actual conversation breadth, not feedback volume.
     */
    private function distinctAgents(int $userId): int
    {
        return (int) RewardEvent::query()
            ->where('user_id', $userId)
            ->where('event_type', RewardEvent::TYPE_AGENT_CHAT)
            ->whereNotNull('agent_key')
            ->distinct('agent_key')
            ->count('agent_key');
    }

    /** Total thumbs (up + down) given by this user. */
    private function countThumbs(int $userId): int
    {
        return (int) RewardEvent::query()
            ->where('user_id', $userId)
            ->whereIn('event_type', [
                RewardEvent::TYPE_AGENT_THUMBS_UP,
                RewardEvent::TYPE_AGENT_THUMBS_DOWN,
            ])
            ->count();
    }
}
