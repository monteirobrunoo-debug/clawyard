<?php

namespace Tests\Feature\Rewards;

use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use App\Services\Rewards\BadgeCatalog;
use App\Services\Rewards\BadgeEvaluator;
use App\Services\Rewards\RewardRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C4 — badge unlocking semantics.
 *
 *   • BadgeCatalog metadata is consistent (every TYPE_* constant
 *     has a matching display row).
 *   • BadgeEvaluator returns ONLY newly-earned badges (skips
 *     already-owned).
 *   • RewardRecorder integration: a real chain of events moves
 *     the user through level/streak/sales badges naturally.
 *
 * The evaluator is unit-tested against synthetic UserPoints + event
 * shapes; the recorder integration is end-to-end.
 */
class BadgeEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'      => 'B-' . uniqid(),
            'email'     => 'b+' . uniqid() . '@partyard.eu',
            'password'  => 'x',
            'role'      => 'user',
            'is_active' => true,
        ]);
    }

    // ── BadgeCatalog ─────────────────────────────────────────────────────────

    public function test_catalog_has_metadata_for_every_declared_constant(): void
    {
        $declared = [];
        $r = new \ReflectionClass(BadgeCatalog::class);
        foreach ($r->getConstants() as $name => $value) {
            if (is_string($value)) $declared[] = $value;
        }

        $catalog = BadgeCatalog::all();
        foreach ($declared as $key) {
            $this->assertArrayHasKey($key, $catalog,
                "BadgeCatalog::{$key} constant must have a metadata row");
            $this->assertNotEmpty($catalog[$key]['emoji']);
            $this->assertNotEmpty($catalog[$key]['label']);
            $this->assertNotEmpty($catalog[$key]['tier']);
        }
    }

    public function test_by_tier_groups_badges_correctly(): void
    {
        $tiers = BadgeCatalog::byTier();
        $this->assertArrayHasKey('engagement', $tiers);
        $this->assertArrayHasKey('level',      $tiers);
        $this->assertArrayHasKey('sales',      $tiers);
        $this->assertArrayHasKey('agents',     $tiers);
        $this->assertArrayHasKey('imports',    $tiers);
        $this->assertCount(5, $tiers);
    }

    // ── BadgeEvaluator ───────────────────────────────────────────────────────

    public function test_first_steps_unlocks_on_any_first_event(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 1, 'level' => 0]);
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_DAILY_LOGIN, 'points' => 1,
        ]);

        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::FIRST_STEPS, $unlocked);
    }

    public function test_already_owned_badges_are_filtered_from_unlocked_list(): void
    {
        $u = $this->user();
        $row = UserPoints::create([
            'user_id'      => $u->id,
            'total_points' => 1,
            'badges'       => [BadgeCatalog::FIRST_STEPS],   // already owns it
        ]);
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_DAILY_LOGIN, 'points' => 1,
        ]);

        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertNotContains(BadgeCatalog::FIRST_STEPS, $unlocked,
            'evaluator must not re-emit badges the user already owns');
    }

    public function test_level_badges_unlock_at_thresholds(): void
    {
        $u = $this->user();
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_LEAD_WON, 'points' => 100,
        ]);

        // Level 2 (1k pts) — should unlock junior + senior, not specialist
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 1500, 'level' => 2]);
        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::LEVEL_JUNIOR, $unlocked);
        $this->assertContains(BadgeCatalog::LEVEL_SENIOR, $unlocked);
        $this->assertNotContains(BadgeCatalog::LEVEL_SPECIALIST, $unlocked);
        $this->assertNotContains(BadgeCatalog::LEVEL_MASTER,     $unlocked);
        $this->assertNotContains(BadgeCatalog::LEVEL_LEGEND,     $unlocked);
    }

    public function test_streak_badges_unlock_at_7_and_30_days(): void
    {
        $u = $this->user();
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_DAILY_LOGIN, 'points' => 1,
        ]);

        // Day 7 streak — unlock daily_grinder, NOT streak_master.
        $row7 = UserPoints::create([
            'user_id' => $u->id, 'total_points' => 7, 'current_streak_days' => 7,
        ]);
        $u7 = (new BadgeEvaluator())->evaluate($row7, $event);
        $this->assertContains(BadgeCatalog::DAILY_GRINDER, $u7);
        $this->assertNotContains(BadgeCatalog::STREAK_MASTER, $u7);

        // Day 30 streak — both badges (we forgot to award daily_grinder
        // earlier, so the evaluator catches up).
        $u30 = $this->user();
        $row30 = UserPoints::create([
            'user_id' => $u30->id, 'total_points' => 30, 'current_streak_days' => 30,
        ]);
        $event30 = RewardEvent::create([
            'user_id' => $u30->id, 'event_type' => RewardEvent::TYPE_DAILY_LOGIN, 'points' => 1,
        ]);
        $u30unlocked = (new BadgeEvaluator())->evaluate($row30, $event30);
        $this->assertContains(BadgeCatalog::DAILY_GRINDER, $u30unlocked);
        $this->assertContains(BadgeCatalog::STREAK_MASTER, $u30unlocked);
    }

    public function test_closer_unlocks_on_first_lead_won(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 50, 'level' => 0]);
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_LEAD_WON, 'points' => 50,
            'metadata' => ['score' => 65],
        ]);

        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::CLOSER, $unlocked);
        $this->assertNotContains(BadgeCatalog::DEAL_MACHINE, $unlocked,
            'deal_machine needs 5 wins, not 1');
        $this->assertNotContains(BadgeCatalog::WHALE_HUNTER, $unlocked,
            'whale_hunter needs score ≥ 80 — this lead is 65');
    }

    public function test_whale_hunter_unlocks_only_on_high_score_win(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 50]);
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_LEAD_WON, 'points' => 50,
            'metadata' => ['score' => 85],
        ]);

        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::WHALE_HUNTER, $unlocked);
    }

    public function test_deal_machine_unlocks_after_5_wins(): void
    {
        $u = $this->user();
        // Pre-seed 4 historical wins (no badges awarded yet for simplicity).
        for ($i = 0; $i < 4; $i++) {
            RewardEvent::create([
                'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_LEAD_WON, 'points' => 50,
            ]);
        }
        // 5th win — the trigger.
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_LEAD_WON, 'points' => 50,
            'metadata' => ['score' => 60],
        ]);

        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 250]);
        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::DEAL_MACHINE, $unlocked);
    }

    public function test_agent_friend_unlocks_after_3_distinct_agents(): void
    {
        $u = $this->user();
        // Two distinct agents already chatted — not enough.
        RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_AGENT_CHAT,
            'agent_key' => 'sales', 'points' => 1,
        ]);
        RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_AGENT_CHAT,
            'agent_key' => 'vessel', 'points' => 1,
        ]);
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_AGENT_CHAT,
            'agent_key' => 'crm', 'points' => 1,
        ]);

        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 3]);
        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::AGENT_FRIEND, $unlocked);
        $this->assertNotContains(BadgeCatalog::AGENT_POLYGLOT, $unlocked);
    }

    public function test_feedback_giver_counts_thumbs_up_and_down_together(): void
    {
        $u = $this->user();
        // 9 thumbs already (5 up + 4 down) — not enough.
        for ($i = 0; $i < 5; $i++) {
            RewardEvent::create([
                'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_AGENT_THUMBS_UP,
                'agent_key' => 'sales', 'points' => 2,
            ]);
        }
        for ($i = 0; $i < 4; $i++) {
            RewardEvent::create([
                'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_AGENT_THUMBS_DOWN,
                'agent_key' => 'sales', 'points' => 1,
            ]);
        }
        // 10th thumb — the trigger.
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_AGENT_THUMBS_DOWN,
            'agent_key' => 'sales', 'points' => 1,
        ]);

        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 14]);
        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertContains(BadgeCatalog::FEEDBACK_GIVER, $unlocked);
    }

    public function test_evaluator_skips_irrelevant_badge_types(): void
    {
        // A daily_login event must NOT trigger the import_champion check.
        // We prove this by NOT seeding any tender_imported events but
        // still verifying import_champion isn't in the unlocked list.
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 1]);
        $event = RewardEvent::create([
            'user_id' => $u->id, 'event_type' => RewardEvent::TYPE_DAILY_LOGIN, 'points' => 1,
        ]);

        $unlocked = (new BadgeEvaluator())->evaluate($row, $event);
        $this->assertNotContains(BadgeCatalog::IMPORT_CHAMPION, $unlocked);
        $this->assertNotContains(BadgeCatalog::CLOSER, $unlocked);
        $this->assertNotContains(BadgeCatalog::AGENT_FRIEND, $unlocked);
    }

    // ── Integration with RewardRecorder ─────────────────────────────────────

    public function test_recorder_persists_newly_earned_badges_into_user_points(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        // First event — should immediately award FIRST_STEPS.
        $rec->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);

        $points = UserPoints::find($u->id);
        $this->assertContains(BadgeCatalog::FIRST_STEPS, $points->badges ?? [],
            'FIRST_STEPS must be persisted into user_points.badges by the recorder');
    }

    public function test_level_promotion_through_recorder_awards_level_badge(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        // Push the user past the LEVEL_JUNIOR threshold (100 pts).
        // Use a points override so we don't have to fire 100 events.
        $rec->record(
            eventType: RewardEvent::TYPE_LEAD_WON,
            userId:    $u->id,
            points:    200,
        );

        $points = UserPoints::find($u->id);
        $this->assertSame(1, $points->level);
        $this->assertContains(BadgeCatalog::LEVEL_JUNIOR, $points->badges ?? []);
    }

    public function test_recorder_does_not_re_award_owned_badges(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        $rec->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);
        $rec->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);   // should be a no-op for streak (same day)

        $points = UserPoints::find($u->id);
        $owned = $points->badges ?? [];
        // first_steps should appear EXACTLY once even though both events checked it.
        $this->assertSame(1, count(array_filter($owned, fn($b) => $b === BadgeCatalog::FIRST_STEPS)));
    }
}
