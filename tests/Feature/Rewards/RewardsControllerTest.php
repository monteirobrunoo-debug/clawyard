<?php

namespace Tests\Feature\Rewards;

use App\Models\AgentMetric;
use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C3 — read-path controllers.
 *
 *   GET /rewards/me          — auth required, any user, shows their data
 *   GET /rewards/leaderboard — auth required, manager+ only
 *   GET /agents/{key}        — agent_metric injected when present
 */
class RewardsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'user', ?string $name = null): User
    {
        $uid = uniqid();
        return User::create([
            'name'      => $name ?? 'U-' . $role . '-' . $uid,
            'email'     => $role . '+' . $uid . '@partyard.eu',
            'password'  => 'x',
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    // ── /rewards/me ─────────────────────────────────────────────────────────

    public function test_me_redirects_unauthenticated_to_login(): void
    {
        $this->get(route('rewards.me'))->assertRedirect(route('login'));
    }

    public function test_me_lazy_creates_points_row_for_brand_new_user(): void
    {
        $u = $this->user();
        $this->assertSame(0, UserPoints::count());

        $this->actingAs($u)->get(route('rewards.me'))
            ->assertOk()
            ->assertSee('Os teus rewards');

        $this->assertSame(1, UserPoints::count(),
            'visiting /rewards/me must lazy-create the points row');
    }

    public function test_me_renders_points_level_and_streak(): void
    {
        $u = $this->user();
        UserPoints::create([
            'user_id'             => $u->id,
            'total_points'        => 1500,
            'level'               => 2,
            'current_streak_days' => 4,
            'best_streak_days'    => 7,
        ]);

        $this->actingAs($u)->get(route('rewards.me'))
            ->assertOk()
            ->assertSee('1,500')
            ->assertSee('Senior')
            ->assertSee('4')
            ->assertSee('7');
    }

    public function test_me_lists_recent_events_with_points(): void
    {
        $u = $this->user();
        RewardEvent::create([
            'user_id'    => $u->id,
            'event_type' => RewardEvent::TYPE_LEAD_WON,
            'points'     => 50,
        ]);
        RewardEvent::create([
            'user_id'    => $u->id,
            'event_type' => RewardEvent::TYPE_AGENT_CHAT,
            'agent_key'  => 'sales',
            'points'     => 1,
        ]);

        $this->actingAs($u)->get(route('rewards.me'))
            ->assertOk()
            ->assertSee('lead won')
            ->assertSee('agent chat')
            ->assertSee('+50')
            ->assertSee('+1');
    }

    public function test_me_only_shows_current_users_events(): void
    {
        $alice = $this->user();
        $bob   = $this->user();

        RewardEvent::create([
            'user_id'    => $alice->id,
            'event_type' => RewardEvent::TYPE_LEAD_WON,
            'points'     => 50,
            'metadata'   => ['note' => 'ALICE_PRIVATE_EVENT'],
        ]);
        RewardEvent::create([
            'user_id'    => $bob->id,
            'event_type' => RewardEvent::TYPE_LEAD_QUALIFIED,
            'points'     => 10,
            'metadata'   => ['note' => 'BOB_PRIVATE_EVENT'],
        ]);

        $this->actingAs($bob)->get(route('rewards.me'))
            ->assertOk()
            ->assertDontSee('ALICE_PRIVATE_EVENT')
            ->assertSee('+10');
    }

    // ── /rewards/leaderboard ────────────────────────────────────────────────

    public function test_leaderboard_forbidden_to_regular_users(): void
    {
        $regular = $this->user();
        $this->actingAs($regular)->get(route('rewards.leaderboard'))->assertForbidden();
    }

    public function test_leaderboard_visible_to_managers(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->get(route('rewards.leaderboard'))
            ->assertOk()
            ->assertSee('Leaderboard');
    }

    public function test_leaderboard_ranks_by_total_points_desc(): void
    {
        $mgr   = $this->user('manager', name: 'M_GR_VIEWER');
        $alice = $this->user(name: 'AL_LOW_SCORE');
        $bob   = $this->user(name: 'BO_HIGH_SCORE');

        UserPoints::create(['user_id' => $alice->id, 'total_points' => 500,  'level' => 1]);
        UserPoints::create(['user_id' => $bob->id,   'total_points' => 1500, 'level' => 2]);

        $resp = $this->actingAs($mgr)->get(route('rewards.leaderboard'))->assertOk();
        $body = $resp->getContent();

        $bobPos   = strpos($body, 'BO_HIGH_SCORE');
        $alicePos = strpos($body, 'AL_LOW_SCORE');
        $this->assertNotFalse($bobPos);
        $this->assertNotFalse($alicePos);
        $this->assertLessThan($alicePos, $bobPos,
            'Bob (1500 pts) must appear before Alice (500 pts)');
    }

    public function test_leaderboard_excludes_inactive_users(): void
    {
        $mgr  = $this->user('manager');
        $live = $this->user();
        $dead = User::create([
            'name'      => 'INACTIVE_USER',
            'email'     => 'dead+'.uniqid().'@partyard.eu',
            'password'  => 'x',
            'role'      => 'user',
            'is_active' => false,
        ]);

        UserPoints::create(['user_id' => $live->id, 'total_points' => 100, 'level' => 1]);
        UserPoints::create(['user_id' => $dead->id, 'total_points' => 9999, 'level' => 5]);

        $this->actingAs($mgr)->get(route('rewards.leaderboard'))
            ->assertOk()
            ->assertDontSee('INACTIVE_USER',
                false /* not strict — name should be absent from rendered HTML */);
    }

    public function test_leaderboard_shows_top_agents_panel(): void
    {
        $mgr = $this->user('manager');
        AgentMetric::create([
            'agent_key'         => 'sales',
            'signals_processed' => 10,
            'leads_generated'   => 5,
            'leads_won'         => 2,
        ]);

        $this->actingAs($mgr)->get(route('rewards.leaderboard'))
            ->assertOk()
            ->assertSee('Top agentes')
            ->assertSee('Marco Sales');   // pulled from AgentCatalog
    }

    // ── /agents/{key} ───────────────────────────────────────────────────────

    public function test_agent_profile_renders_metric_card_when_present(): void
    {
        $u = $this->user();
        AgentMetric::create([
            'agent_key'         => 'sales',
            'signals_processed' => 8,
            'leads_generated'   => 3,
            'leads_won'         => 1,
            'total_cost_usd'    => 0.12,
        ]);

        $this->actingAs($u)->get(route('agents.profile', 'sales'))
            ->assertOk()
            ->assertSee('Swarm performance')
            ->assertSee('Signals processed')
            ->assertSee('Leads generated');
    }

    public function test_agent_profile_renders_no_data_pill_when_metric_missing(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get(route('agents.profile', 'sales'))
            ->assertOk()
            ->assertSee('ainda não correu em nenhuma chain');
    }
}
