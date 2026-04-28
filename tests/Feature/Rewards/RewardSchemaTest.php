<?php

namespace Tests\Feature\Rewards;

use App\Models\AgentMetric;
use App\Models\LeadOpportunity;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C1 — schema lock-in. Pins the contract that C2's RewardRecorder
 * will write against. Specifically:
 *   • RewardEvent rows persist with cast'd metadata + correct points
 *     defaults from the constants.
 *   • UserPoints level/threshold logic is deterministic.
 *   • AgentMetric helpers (winRate, costPerLead, trustPct) handle
 *     the zero-state without dividing by zero.
 *   • User → points() / rewardEvents() relations resolve.
 *
 * No event-firing logic here — that's C2. Just schema + helpers.
 */
class RewardSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'     => 'Test User',
            'email'    => 'test+' . uniqid() . '@partyard.eu',
            'password' => 'x',
            'role'     => 'user',
        ]);
    }

    // ── RewardEvent ─────────────────────────────────────────────────────────

    public function test_reward_event_persists_with_metadata_cast(): void
    {
        $u = $this->user();

        $e = RewardEvent::create([
            'user_id'    => $u->id,
            'agent_key'  => 'sales',
            'event_type' => RewardEvent::TYPE_LEAD_WON,
            'points'     => RewardEvent::pointsFor(RewardEvent::TYPE_LEAD_WON),
            'metadata'   => ['lead_score' => 82, 'customer' => 'NSPA'],
        ]);

        $fresh = RewardEvent::find($e->id);
        $this->assertSame(50, $fresh->points);
        $this->assertSame(['lead_score' => 82, 'customer' => 'NSPA'], $fresh->metadata,
            'metadata column must round-trip as an array');
        $this->assertSame('sales', $fresh->agent_key);
    }

    public function test_reward_event_default_points_table_has_no_unknown_keys(): void
    {
        // Every constant in DEFAULT_POINTS must correspond to a
        // declared TYPE_* constant — otherwise a typo in a constant
        // would silently break point assignment.
        $declaredTypes = [];
        $reflection = new \ReflectionClass(RewardEvent::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'TYPE_')) $declaredTypes[$value] = $name;
        }

        foreach (array_keys(RewardEvent::DEFAULT_POINTS) as $eventType) {
            $this->assertArrayHasKey($eventType, $declaredTypes,
                "DEFAULT_POINTS key '{$eventType}' must match a TYPE_* constant");
        }
    }

    public function test_reward_event_unknown_type_returns_zero_points(): void
    {
        $this->assertSame(0, RewardEvent::pointsFor('something_undefined'));
    }

    public function test_reward_event_known_types_returns_all_declared_types(): void
    {
        $known = RewardEvent::knownTypes();
        $this->assertContains(RewardEvent::TYPE_LEAD_WON, $known);
        $this->assertContains(RewardEvent::TYPE_AGENT_CHAT, $known);
        $this->assertCount(count(RewardEvent::DEFAULT_POINTS), $known);
    }

    public function test_reward_event_polymorphic_subject_can_be_a_lead(): void
    {
        $u = $this->user();
        // Need a lead — create the swarm run first since lead requires swarm_run_id.
        $run = \App\Models\AgentSwarmRun::create([
            'signal_type' => 'tender',
            'signal_id'   => '1',
            'signal_hash' => \App\Models\AgentSwarmRun::hashFor('tender', '1'),
            'chain_name'  => 'tender_to_lead',
            'status'      => \App\Models\AgentSwarmRun::STATUS_DONE,
        ]);
        $lead = LeadOpportunity::create([
            'swarm_run_id'       => $run->id,
            'title'              => 'Test lead',
            'summary'            => '...',
            'score'              => 75,
            'source_signal_type' => 'tender',
            'status'             => 'review',
        ]);

        $e = RewardEvent::create([
            'user_id'      => $u->id,
            'event_type'   => RewardEvent::TYPE_LEAD_QUALIFIED,
            'points'       => 10,
            'subject_type' => LeadOpportunity::class,
            'subject_id'   => $lead->id,
        ]);

        $resolved = $e->fresh()->subject;
        $this->assertNotNull($resolved);
        $this->assertSame($lead->id, $resolved->id);
    }

    public function test_user_relations_resolve(): void
    {
        $u = $this->user();
        RewardEvent::create([
            'user_id'    => $u->id,
            'event_type' => RewardEvent::TYPE_DAILY_LOGIN,
            'points'     => 1,
        ]);

        $this->assertSame(1, $u->rewardEvents()->count());
    }

    public function test_points_row_creates_lazily_on_demand(): void
    {
        $u = $this->user();
        $this->assertNull($u->points,
            'points() returns null before any reward_event for the user');

        $row = $u->pointsRow();
        $this->assertInstanceOf(UserPoints::class, $row);
        $this->assertSame(0, $row->total_points);
        $this->assertSame(1, UserPoints::count());

        // Calling again must NOT create a second row.
        $u->pointsRow();
        $this->assertSame(1, UserPoints::count());
    }

    // ── UserPoints ──────────────────────────────────────────────────────────

    public function test_level_for_buckets(): void
    {
        $this->assertSame(0, UserPoints::levelFor(0));
        $this->assertSame(0, UserPoints::levelFor(99));
        $this->assertSame(1, UserPoints::levelFor(100));
        $this->assertSame(1, UserPoints::levelFor(999));
        $this->assertSame(2, UserPoints::levelFor(1_000));
        $this->assertSame(3, UserPoints::levelFor(5_000));
        $this->assertSame(4, UserPoints::levelFor(20_000));
        $this->assertSame(5, UserPoints::levelFor(50_000));
        $this->assertSame(5, UserPoints::levelFor(1_000_000),
            'very high totals stay at max level (no array overflow)');
    }

    public function test_level_name_returns_localised_label(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 1500, 'level' => 2]);
        $this->assertSame('Senior', $row->levelName());
    }

    public function test_points_to_next_level_zero_at_max(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 50_000, 'level' => 5]);
        $this->assertSame(0, $row->pointsToNextLevel(),
            'top level returns 0 — there is no next');
    }

    public function test_points_to_next_level_correct_at_mid_tier(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id, 'total_points' => 850, 'level' => 1]);
        $this->assertSame(150, $row->pointsToNextLevel(),
            'level 1 user with 850 pts needs 150 to hit 1000');
    }

    public function test_has_badge_handles_null_badges(): void
    {
        $u = $this->user();
        $row = UserPoints::create(['user_id' => $u->id]);
        $this->assertFalse($row->hasBadge('first_lead'));

        $row->badges = ['first_lead'];
        $row->save();
        $this->assertTrue($row->fresh()->hasBadge('first_lead'));
    }

    // ── AgentMetric ─────────────────────────────────────────────────────────

    public function test_agent_metric_zero_state_helpers_return_null_not_division_by_zero(): void
    {
        $m = AgentMetric::create(['agent_key' => 'sales']);

        $this->assertNull($m->winRate(),     'winRate is NULL when no leads generated');
        $this->assertNull($m->costPerLead(), 'costPerLead is NULL when no leads generated');
        $this->assertNull($m->trustPct(),    'trustPct is NULL when no feedback');
        $this->assertSame(0.0, $m->avgScore());
    }

    public function test_agent_metric_win_rate_and_cost_per_lead(): void
    {
        $m = AgentMetric::create([
            'agent_key'       => 'sales',
            'leads_generated' => 20,
            'leads_won'       => 5,
            'total_cost_usd'  => 1.2345,
        ]);

        $this->assertSame(25.0,    $m->winRate(),     '5/20 = 25%');
        $this->assertSame(0.0617, $m->costPerLead(), '1.2345 / 20 ≈ 0.0617');
    }

    public function test_agent_metric_trust_pct(): void
    {
        $m = AgentMetric::create([
            'agent_key' => 'vessel',
            'thumbs_up' => 8,
            'thumbs_down' => 2,
        ]);
        $this->assertSame(80.0, $m->trustPct(), '8/(8+2) = 80%');
    }

    public function test_agent_metric_apply_new_lead_score_running_mean(): void
    {
        // Welford-style running mean — three leads with scores 60, 80, 70.
        // Expected: (60+80+70)/3 = 70 → avg_score_x100 = 7000.
        $m = AgentMetric::create(['agent_key' => 'sales']);

        // First lead.
        $m->leads_generated = 1;
        $m->applyNewLeadScore(60);
        $this->assertSame(6000, $m->avg_score_x100);

        // Second.
        $m->leads_generated = 2;
        $m->applyNewLeadScore(80);
        $this->assertSame(7000, $m->avg_score_x100);

        // Third.
        $m->leads_generated = 3;
        $m->applyNewLeadScore(70);
        $this->assertSame(7000, $m->avg_score_x100);
        $this->assertSame(70.0, $m->avgScore());
    }

    public function test_agent_metric_meta_pulls_from_catalog(): void
    {
        $m = AgentMetric::create(['agent_key' => 'sales']);
        $meta = $m->meta();
        $this->assertNotNull($meta);
        $this->assertSame('Marco Sales', $meta['name'],
            'metric must surface the AgentCatalog persona');
    }

    public function test_agent_metric_unknown_agent_key_returns_null_meta(): void
    {
        $m = AgentMetric::create(['agent_key' => 'no-such-agent']);
        $this->assertNull($m->meta());
    }
}
