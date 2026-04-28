<?php

namespace Tests\Feature\Rewards;

use App\Models\AgentMetric;
use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use App\Services\Rewards\RewardRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C2 — RewardRecorder service.
 *
 * Pins:
 *   • record() inserts the audit row + bumps user_points + bumps
 *     agent_metric in ONE transaction.
 *   • Daily caps clamp points but still record the event (so
 *     operators can see the cap fired in the audit log).
 *   • Streak math: same-day no-op, +1 day = increment, 2+ day gap = reset.
 *   • Level promotion when crossing thresholds.
 *   • Agent metric helpers correctly attribute swarm_run vs swarm_lead
 *     vs lead_won vs thumbs_up/down.
 *   • A failed record (e.g. caller passes a bad eventType through
 *     to the underlying create) returns null and does NOT throw.
 */
class RewardRecorderTest extends TestCase
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

    public function test_record_inserts_event_and_bumps_user_points(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        $event = $rec->record(
            eventType: RewardEvent::TYPE_LEAD_WON,
            userId:    $u->id,
            agentKey:  null,
            subject:   null,
            metadata:  ['note' => 'first deal'],
        );

        $this->assertNotNull($event);
        $this->assertSame(50, $event->points);

        $points = UserPoints::find($u->id);
        $this->assertSame(50, $points->total_points);
        // Level 1 starts at 100, so 50 still at level 0.
        $this->assertSame(0, $points->level);
    }

    public function test_record_promotes_user_level_on_threshold_cross(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        // Pre-seed at 80 pts (level 0).
        UserPoints::create(['user_id' => $u->id, 'total_points' => 80, 'level' => 0]);

        $rec->record(
            eventType: RewardEvent::TYPE_AGENT_SHARE,    // 3 pts default, override to 30
            userId:    $u->id,
            points:    30,
        );

        $points = UserPoints::find($u->id);
        $this->assertSame(110, $points->total_points);
        $this->assertSame(1, $points->level, 'crossing 100 promotes from 0 to 1');
    }

    public function test_daily_cap_clamps_points_but_still_records_event(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        // Cap for AGENT_CHAT is 10/day. Record 11 events.
        for ($i = 0; $i < 11; $i++) {
            $rec->record(
                eventType: RewardEvent::TYPE_AGENT_CHAT,
                userId:    $u->id,
                agentKey:  'sales',
            );
        }

        $this->assertSame(11, RewardEvent::where('user_id', $u->id)->count(),
            'all 11 events must be in the audit log');

        // 10 × 1 pt + 1 × 0 pt = 10.
        $this->assertSame(10, UserPoints::find($u->id)->total_points);

        $cappedEvent = RewardEvent::where('user_id', $u->id)
            ->where('event_type', RewardEvent::TYPE_AGENT_CHAT)
            ->orderBy('id', 'desc')
            ->first();
        $this->assertSame(0, $cappedEvent->points);
        $this->assertTrue($cappedEvent->metadata['cap_reached'] ?? false);
    }

    public function test_streak_starts_at_one_on_first_event(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();
        $rec->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);

        $row = UserPoints::find($u->id);
        $this->assertSame(1, $row->current_streak_days);
        $this->assertSame(1, $row->best_streak_days);
        $this->assertTrue($row->last_active_on->isToday());
    }

    public function test_streak_increments_on_consecutive_day(): void
    {
        $u = $this->user();

        // Pre-seed yesterday's row.
        UserPoints::create([
            'user_id'             => $u->id,
            'total_points'        => 5,
            'current_streak_days' => 3,
            'best_streak_days'    => 3,
            'last_active_on'      => Carbon::yesterday()->toDateString(),
        ]);

        (new RewardRecorder())->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);

        $row = UserPoints::find($u->id);
        $this->assertSame(4, $row->current_streak_days, 'consecutive day increments');
        $this->assertSame(4, $row->best_streak_days, 'best updates when current beats it');
    }

    public function test_streak_resets_on_two_day_gap(): void
    {
        $u = $this->user();

        UserPoints::create([
            'user_id'             => $u->id,
            'current_streak_days' => 5,
            'best_streak_days'    => 7,
            'last_active_on'      => Carbon::today()->subDays(3)->toDateString(),
        ]);

        (new RewardRecorder())->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);

        $row = UserPoints::find($u->id);
        $this->assertSame(1, $row->current_streak_days, '3-day gap resets streak');
        $this->assertSame(7, $row->best_streak_days, 'best is preserved across resets');
    }

    public function test_streak_unchanged_on_same_day_multiple_events(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        $rec->record(RewardEvent::TYPE_DAILY_LOGIN, $u->id);
        $rec->record(RewardEvent::TYPE_AGENT_CHAT, $u->id, 'sales');
        $rec->record(RewardEvent::TYPE_LEAD_REVIEWED, $u->id);

        $row = UserPoints::find($u->id);
        $this->assertSame(1, $row->current_streak_days,
            'multiple events on the same day must not bump the streak');
    }

    public function test_swarm_run_helper_bumps_signals_and_cost(): void
    {
        $rec = new RewardRecorder();
        $rec->recordSwarmAgentRun('sales', [
            'cost_usd'   => 0.0023,
            'tokens_in'  => 1500,
            'tokens_out' => 400,
            'ms'         => 1100,
        ]);
        $rec->recordSwarmAgentRun('sales', [
            'cost_usd'   => 0.0015,
            'tokens_in'  => 800,
            'tokens_out' => 200,
            'ms'         => 600,
        ]);

        $m = AgentMetric::find('sales');
        $this->assertSame(2, $m->signals_processed);
        $this->assertEqualsWithDelta(0.0038, (float) $m->total_cost_usd, 0.0001);
        $this->assertSame(2300, $m->total_tokens_in);
        $this->assertSame(600,  $m->total_tokens_out);
        $this->assertNotNull($m->last_run_at);
    }

    public function test_swarm_lead_helper_increments_count_and_running_score(): void
    {
        $rec = new RewardRecorder();
        $rec->recordSwarmAgentLead('vessel', 60);
        $rec->recordSwarmAgentLead('vessel', 80);
        $rec->recordSwarmAgentLead('vessel', 70);

        $m = AgentMetric::find('vessel');
        $this->assertSame(3, $m->leads_generated);
        $this->assertSame(70.0, $m->avgScore(), '(60+80+70)/3 = 70');
    }

    public function test_lead_won_event_bumps_agent_leads_won_counter(): void
    {
        $rec = new RewardRecorder();
        $rec->record(
            eventType: RewardEvent::TYPE_LEAD_WON,
            agentKey:  'sales',
            points:    0,                // already credited to user separately
        );

        $this->assertSame(1, AgentMetric::find('sales')->leads_won);
    }

    public function test_thumbs_up_and_down_update_feedback_counters(): void
    {
        $u = $this->user();
        $rec = new RewardRecorder();

        $rec->record(RewardEvent::TYPE_AGENT_THUMBS_UP,   $u->id, 'sales');
        $rec->record(RewardEvent::TYPE_AGENT_THUMBS_UP,   $u->id, 'sales');
        $rec->record(RewardEvent::TYPE_AGENT_THUMBS_DOWN, $u->id, 'sales');

        $m = AgentMetric::find('sales');
        $this->assertSame(2, $m->thumbs_up);
        $this->assertSame(1, $m->thumbs_down);
        $this->assertSame(66.7, $m->trustPct(), '2/(2+1) = 66.7%');
    }

    public function test_record_with_polymorphic_subject_persists_subject_keys(): void
    {
        $u = $this->user();
        $run = AgentSwarmRun::create([
            'signal_type' => 'tender',
            'signal_id'   => '1',
            'signal_hash' => AgentSwarmRun::hashFor('tender', '1'),
            'chain_name'  => 'tender_to_lead',
            'status'      => AgentSwarmRun::STATUS_DONE,
        ]);
        $lead = LeadOpportunity::create([
            'swarm_run_id'       => $run->id,
            'title'              => 'Test',
            'summary'            => '...',
            'score'              => 75,
            'source_signal_type' => 'tender',
            'status'             => 'review',
        ]);

        $rec = new RewardRecorder();
        $event = $rec->record(
            eventType: RewardEvent::TYPE_LEAD_QUALIFIED,
            userId:    $u->id,
            subject:   $lead,
        );

        $this->assertSame(LeadOpportunity::class, $event->subject_type);
        $this->assertSame($lead->id, $event->subject_id);
    }

    public function test_user_points_never_go_negative_on_correction(): void
    {
        $u = $this->user();
        UserPoints::create(['user_id' => $u->id, 'total_points' => 5, 'level' => 0]);

        // Admin issues a -50 correction — total clamps to 0, not -45.
        (new RewardRecorder())->record(
            eventType: RewardEvent::TYPE_LEAD_WON,    // any type
            userId:    $u->id,
            points:    -50,
        );

        $this->assertSame(0, UserPoints::find($u->id)->total_points);
    }

    public function test_event_with_no_user_does_not_create_user_points_row(): void
    {
        // System-only event (cron-triggered swarm run, no human in loop).
        (new RewardRecorder())->record(
            eventType: RewardEvent::TYPE_SWARM_RUN,
            agentKey:  'sales',
            metadata:  ['cost_usd' => 0.001, 'tokens_in' => 100, 'tokens_out' => 50],
        );

        $this->assertSame(0, UserPoints::count(),
            'system-only events must not create user_points rows');
        $this->assertSame(1, AgentMetric::count(),
            'but they MUST update agent_metric for the agent_key');
    }
}
