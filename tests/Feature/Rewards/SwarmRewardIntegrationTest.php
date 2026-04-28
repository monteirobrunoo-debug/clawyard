<?php

namespace Tests\Feature\Rewards;

use App\Models\AgentMetric;
use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\AgentSwarm\AgentSwarmRunner;
use App\Services\AgentSwarm\PromptBuilder;
use App\Services\Rewards\RewardRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C2 — end-to-end smoke that the swarm wire-up + lead controller
 * wire-up bump the metric/points tables as designed.
 *
 * The point is NOT to retest the recorder semantics (covered in
 * RewardRecorderTest) — only to prove the HOOKS exist and trigger.
 */
class SwarmRewardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDispatcher(): AgentDispatcher
    {
        return new class extends AgentDispatcher {
            public function __construct() {}
            private array $queue = [
                ['ok' => true, 'text' => 'research finds market hot',
                 'model' => 'fake', 'tokens_in' => 100, 'tokens_out' => 50,
                 'cost_usd' => 0.001, 'ms' => 50, 'error' => null],
                ['ok' => true, 'text' => 'vessel finds drydock match',
                 'model' => 'fake', 'tokens_in' => 100, 'tokens_out' => 50,
                 'cost_usd' => 0.001, 'ms' => 50, 'error' => null],
                ['ok' => true, 'text' => 'crm finds existing customer',
                 'model' => 'fake', 'tokens_in' => 100, 'tokens_out' => 50,
                 'cost_usd' => 0.001, 'ms' => 50, 'error' => null],
                ['ok' => true,
                 'text' => '{"leads":[{"title":"NSPA RFQ","summary":"Strong","score":78}]}',
                 'model' => 'fake', 'tokens_in' => 200, 'tokens_out' => 100,
                 'cost_usd' => 0.003, 'ms' => 80, 'error' => null],
            ];
            public function dispatch(string $sys, string $usr, int $max = 1500, ?string $model = null): array
            {
                return array_shift($this->queue) ?: [
                    'ok' => true, 'text' => '{"leads":[]}', 'model' => 'fake',
                    'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0,
                    'ms' => 1, 'error' => null,
                ];
            }
        };
    }

    public function test_swarm_run_bumps_agent_metrics_for_each_participating_agent(): void
    {
        $svc = new AgentSwarmRunner(
            maxCostPerRun: 1.0,
            maxCostPerDay: 1.0,
            dispatcher:    $this->fakeDispatcher(),
            prompts:       new PromptBuilder(),
            rewards:       new RewardRecorder(),
        );

        $svc->run('tender', '1', ['title' => 'NSPA RFQ MTU']);

        // tender_to_lead chain runs research/vessel/crm in parallel +
        // sales as synth → 4 unique agents touched.
        $expected = ['research', 'vessel', 'crm', 'sales'];
        foreach ($expected as $key) {
            $m = AgentMetric::find($key);
            $this->assertNotNull($m, "agent_metric must exist for {$key}");
            $this->assertSame(1, $m->signals_processed,
                "{$key} must have exactly 1 swarm_run recorded");
            $this->assertSame(1, $m->leads_generated,
                "{$key} must be credited for the 1 lead synthesised");
            $this->assertGreaterThan(0.0, (float) $m->total_cost_usd);
        }
    }

    public function test_swarm_run_does_not_create_user_points_rows(): void
    {
        $svc = new AgentSwarmRunner(
            maxCostPerRun: 1.0,
            maxCostPerDay: 1.0,
            dispatcher:    $this->fakeDispatcher(),
            prompts:       new PromptBuilder(),
            rewards:       new RewardRecorder(),
        );
        $svc->run('tender', '1', ['title' => 'X']);

        $this->assertSame(0, UserPoints::count(),
            'cron-triggered swarm runs must not create user_points rows '
          . '(no human earned anything)');
    }

    public function test_lead_won_event_credits_user_and_all_chain_agents(): void
    {
        // Pre-stage: a successful swarm run with a lead at status=review.
        $run = AgentSwarmRun::create([
            'signal_type' => 'tender',
            'signal_id'   => '1',
            'signal_hash' => AgentSwarmRun::hashFor('tender', '1'),
            'chain_name'  => 'tender_to_lead',
            'status'      => AgentSwarmRun::STATUS_DONE,
            'chain_log'   => [
                ['event' => 'agent_call', 'ok' => true, 'agent' => 'research'],
                ['event' => 'agent_call', 'ok' => true, 'agent' => 'vessel'],
                ['event' => 'agent_call', 'ok' => false, 'agent' => 'crm'],   // failed — must be excluded
                ['event' => 'agent_call', 'ok' => true, 'agent' => 'sales'],
            ],
        ]);
        $lead = LeadOpportunity::create([
            'swarm_run_id'       => $run->id,
            'title'              => 'Test',
            'summary'            => '...',
            'score'              => 80,
            'source_signal_type' => 'tender',
            'status'             => LeadOpportunity::STATUS_REVIEW,
        ]);

        $u = User::create(['name' => 'M', 'email' => 'm+'.uniqid().'@p.eu', 'password' => 'x', 'role' => 'admin']);
        $this->actingAs($u);

        // Hit PATCH /leads/{lead} with status=won via the controller,
        // through the registered route.
        $resp = $this->patch(route('leads.update', $lead), [
            'status' => LeadOpportunity::STATUS_WON,
        ]);
        $resp->assertRedirect();

        // User awarded 50 pts for lead_won.
        $this->assertSame(50, UserPoints::find($u->id)->total_points);

        // Each successful chain agent (research, vessel, sales — NOT crm
        // which had ok=false) must have leads_won = 1.
        $this->assertSame(1, AgentMetric::find('research')->leads_won);
        $this->assertSame(1, AgentMetric::find('vessel')->leads_won);
        $this->assertSame(1, AgentMetric::find('sales')->leads_won);
        $this->assertNull(AgentMetric::find('crm'),
            'failed agents must not get leads_won credit');
    }

    public function test_no_status_change_does_not_record_reward_event(): void
    {
        // Lead update that touches notes but not status — no reward event.
        $run = AgentSwarmRun::create([
            'signal_type' => 'tender',
            'signal_id'   => '1',
            'signal_hash' => AgentSwarmRun::hashFor('tender', '1'),
            'chain_name'  => 'tender_to_lead',
            'status'      => AgentSwarmRun::STATUS_DONE,
        ]);
        $lead = LeadOpportunity::create([
            'swarm_run_id'       => $run->id,
            'title'              => 'T',
            'summary'            => '...',
            'score'              => 50,
            'source_signal_type' => 'tender',
            'status'             => LeadOpportunity::STATUS_REVIEW,
        ]);

        $u = User::create(['name' => 'M', 'email' => 'mm+'.uniqid().'@p.eu', 'password' => 'x', 'role' => 'admin']);
        $this->actingAs($u);

        $this->patch(route('leads.update', $lead), [
            'notes' => 'just a note',
        ])->assertRedirect();

        $this->assertSame(0, RewardEvent::count(),
            'updates that don\'t change status must not fire reward events');
    }
}
