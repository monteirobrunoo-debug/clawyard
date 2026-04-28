<?php

namespace Tests\Feature;

use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Services\AgentSwarm\AgentSwarmRunner;
use App\Services\AgentSwarm\ChainSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 — orchestration shape lock-in. Pins the contract that B2 (real
 * agent calls) will replace the stub callAgent() against.
 *
 * Specifically:
 *   • Runner is idempotent on signal_hash — no duplicate runs.
 *   • Default chain matches signal_type.
 *   • Each agent in the chain spec produces exactly one chain_log
 *     step + a cost increment.
 *   • Per-run budget cap aborts the chain mid-flight without
 *     persisting a half-baked lead.
 *   • Per-day budget cap aborts new runs at start.
 *   • Synthesised leads land in lead_opportunities tied to the run.
 *   • Score determines the entry-point status (statusForScore).
 */
class AgentSwarmRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotent_re_running_for_same_signal_returns_existing_run(): void
    {
        $svc = new AgentSwarmRunner(maxCostPerRun: 1.0, maxCostPerDay: 1.0);

        $a = $svc->run(signalType: 'tender', signalId: '42', signalPayload: ['title' => 'X']);
        $b = $svc->run(signalType: 'tender', signalId: '42', signalPayload: ['title' => 'X']);

        $this->assertSame($a->id, $b->id, 'Re-running for the same signal must reuse the row');
        $this->assertSame(1, AgentSwarmRun::count());
    }

    public function test_signal_hash_is_stable_for_same_type_and_id(): void
    {
        $h1 = AgentSwarmRun::hashFor('tender', '42');
        $h2 = AgentSwarmRun::hashFor('tender', '42');
        $h3 = AgentSwarmRun::hashFor('tender', '43');

        $this->assertSame($h1, $h2);
        $this->assertNotSame($h1, $h3);
    }

    public function test_default_chain_matches_signal_type(): void
    {
        $svc = new AgentSwarmRunner(maxCostPerRun: 1.0, maxCostPerDay: 1.0);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);
        $this->assertSame(ChainSpec::TENDER_TO_LEAD, $run->chain_name);

        $run2 = $svc->run(signalType: 'email', signalId: '1', signalPayload: ['title' => 'E']);
        $this->assertSame(ChainSpec::EMAIL_TO_LEAD, $run2->chain_name);
    }

    public function test_chain_log_records_one_step_per_agent_call(): void
    {
        $svc = new AgentSwarmRunner(maxCostPerRun: 1.0, maxCostPerDay: 1.0);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        // tender_to_lead chain (B1 stub):
        //   phase 0: parallel(research, vessel, crm)  → 3 'agent_call'
        //   phase 1: history (hp disabled)            → 1 'history_skipped'
        //   phase 2: synthesize(sales)                → 1 'agent_call'
        //   total: 4 'agent_call' events.
        $agentCalls = collect($run->chain_log)->where('event', 'agent_call')->count();
        $this->assertSame(4, $agentCalls,
            'Stub chain must emit one agent_call per agent (3 parallel + 1 synth)');

        $skipped = collect($run->chain_log)->where('event', 'history_skipped')->count();
        $this->assertSame(1, $skipped, 'History phase must record skipped event when client disabled');
    }

    public function test_cost_accumulates_across_steps(): void
    {
        $svc = new AgentSwarmRunner(maxCostPerRun: 1.0, maxCostPerDay: 1.0);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        // 4 stub calls × 0.005 = 0.020 minimum.
        $this->assertGreaterThanOrEqual(0.015, (float) $run->cost_usd);
    }

    public function test_per_run_budget_aborts_the_chain(): void
    {
        // Tiny per-run budget — first call already exceeds it on the
        // PRE-CHECK at top of next phase. We expect status=aborted +
        // a reason recorded.
        $svc = new AgentSwarmRunner(maxCostPerRun: 0.001, maxCostPerDay: 1.0);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        $this->assertSame(AgentSwarmRun::STATUS_ABORTED, $run->status);
        $this->assertTrue(
            collect($run->chain_log)->contains(fn($s) => ($s['reason'] ?? '') === 'per_run_budget_exceeded'),
            'chain_log must record per_run_budget_exceeded reason'
        );
    }

    public function test_per_day_budget_aborts_new_runs_at_start(): void
    {
        // Already-spent is computed via SUM cost_usd over today's runs.
        // Pre-seed an existing run with cost_usd = 5 to push us past
        // the daily cap.
        AgentSwarmRun::create([
            'signal_type' => 'tender',
            'signal_id'   => '99',
            'signal_hash' => AgentSwarmRun::hashFor('tender', '99'),
            'chain_name'  => ChainSpec::TENDER_TO_LEAD,
            'status'      => AgentSwarmRun::STATUS_DONE,
            'cost_usd'    => 5.0,
        ]);

        $svc = new AgentSwarmRunner(maxCostPerRun: 1.0, maxCostPerDay: 1.0);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        $this->assertSame(AgentSwarmRun::STATUS_ABORTED, $run->status);
        $this->assertTrue(
            collect($run->chain_log)->contains(fn($s) => ($s['reason'] ?? '') === 'daily_budget_exceeded'),
            'chain_log must record daily_budget_exceeded'
        );
    }

    public function test_successful_run_persists_lead_with_score_band_status(): void
    {
        $svc = new AgentSwarmRunner(maxCostPerRun: 1.0, maxCostPerDay: 1.0);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'NSPA RFQ MTU 4000']);

        $this->assertSame(AgentSwarmRun::STATUS_DONE, $run->status);
        $this->assertSame(1, $run->leads()->count());
        $lead = $run->leads()->first();

        $this->assertSame(LeadOpportunity::statusForScore($lead->score), $lead->status);
        $this->assertStringContainsString('NSPA RFQ MTU 4000', $lead->title);
    }

    public function test_status_for_score_buckets(): void
    {
        $this->assertSame(LeadOpportunity::STATUS_DRAFT,     LeadOpportunity::statusForScore(0));
        $this->assertSame(LeadOpportunity::STATUS_DRAFT,     LeadOpportunity::statusForScore(29));
        $this->assertSame(LeadOpportunity::STATUS_REVIEW,    LeadOpportunity::statusForScore(30));
        $this->assertSame(LeadOpportunity::STATUS_REVIEW,    LeadOpportunity::statusForScore(70));
        $this->assertSame(LeadOpportunity::STATUS_CONFIDENT, LeadOpportunity::statusForScore(71));
        $this->assertSame(LeadOpportunity::STATUS_CONFIDENT, LeadOpportunity::statusForScore(100));
    }
}
