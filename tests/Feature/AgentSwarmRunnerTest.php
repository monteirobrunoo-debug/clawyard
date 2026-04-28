<?php

namespace Tests\Feature;

use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\AgentSwarm\AgentSwarmRunner;
use App\Services\AgentSwarm\ChainSpec;
use App\Services\AgentSwarm\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pin the orchestration shape AND the B2 real-dispatch wiring.
 *
 * B1 (skeleton) tests pinned:
 *   • Idempotent on signal_hash — no duplicate runs.
 *   • Default chain matches signal_type.
 *   • Each chain spec produces N agent_call steps + 1 history event.
 *   • Per-run + per-day budget caps abort gracefully.
 *   • Synthesised leads land in lead_opportunities.
 *   • Score → status mapping via statusForScore.
 *
 * B2 (this revision) adds:
 *   • Tests use a FakeAgentDispatcher so the suite never hits
 *     Anthropic. A canned response per agent call drives the chain.
 *   • Synthesis parses the FakeDispatcher's JSON reply and the lead
 *     persists with the model-emitted score.
 *   • Failed dispatch (ok=false) does NOT crash the chain — chain
 *     continues, error is recorded in chain_log, downstream synthesis
 *     uses fallback stub.
 *   • Failed JSON parse triggers stub fallback with capped score.
 */
class AgentSwarmRunnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a Runner wired to a fake dispatcher with sensible default
     * canned responses (one per agent_call). Override per-test by
     * pre-queueing specific responses.
     */
    private function makeRunner(
        ?FakeAgentDispatcher $dispatcher = null,
        float $maxCostPerRun = 1.0,
        float $maxCostPerDay = 1.0,
    ): AgentSwarmRunner {
        $dispatcher = $dispatcher ?? FakeAgentDispatcher::withDefaults();
        return new AgentSwarmRunner(
            maxCostPerRun: $maxCostPerRun,
            maxCostPerDay: $maxCostPerDay,
            dispatcher:    $dispatcher,
            prompts:       new PromptBuilder(),
        );
    }

    public function test_idempotent_re_running_for_same_signal_returns_existing_run(): void
    {
        $svc = $this->makeRunner();

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
        $svc = $this->makeRunner();
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);
        $this->assertSame(ChainSpec::TENDER_TO_LEAD, $run->chain_name);

        $run2 = $svc->run(signalType: 'email', signalId: '1', signalPayload: ['title' => 'E']);
        $this->assertSame(ChainSpec::EMAIL_TO_LEAD, $run2->chain_name);
    }

    public function test_chain_log_records_one_step_per_agent_call(): void
    {
        $svc = $this->makeRunner();
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        // tender_to_lead chain:
        //   phase 0: parallel(research, vessel, crm)  → 3 'agent_call'
        //   phase 1: history (hp disabled)            → 1 'history_skipped'
        //   phase 2: synthesize(sales)                → 1 'agent_call'
        //   total: 4 'agent_call' events.
        $agentCalls = collect($run->chain_log)->where('event', 'agent_call')->count();
        $this->assertSame(4, $agentCalls,
            'Chain must emit one agent_call per agent (3 parallel + 1 synth)');

        $skipped = collect($run->chain_log)->where('event', 'history_skipped')->count();
        $this->assertSame(1, $skipped, 'History phase records skipped event when client disabled');
    }

    public function test_cost_accumulates_from_dispatcher_responses(): void
    {
        // Each canned response carries cost_usd=0.002 — 4 calls = 0.008.
        $dispatcher = FakeAgentDispatcher::withDefaults(perCallCost: 0.002);
        $svc = $this->makeRunner($dispatcher);

        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        $this->assertEqualsWithDelta(0.008, (float) $run->cost_usd, 0.0001,
            'cost_usd must reflect the sum of dispatcher cost_usd, not synthetic');
    }

    public function test_per_run_budget_aborts_the_chain(): void
    {
        // Budget so tight even one call exceeds it on the next phase check.
        $svc = $this->makeRunner(
            FakeAgentDispatcher::withDefaults(perCallCost: 0.05),
            maxCostPerRun: 0.001,
            maxCostPerDay: 1.0,
        );
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        $this->assertSame(AgentSwarmRun::STATUS_ABORTED, $run->status);
        $this->assertTrue(
            collect($run->chain_log)->contains(fn($s) => ($s['reason'] ?? '') === 'per_run_budget_exceeded'),
            'chain_log must record per_run_budget_exceeded reason'
        );
    }

    public function test_per_day_budget_aborts_new_runs_at_start(): void
    {
        AgentSwarmRun::create([
            'signal_type' => 'tender',
            'signal_id'   => '99',
            'signal_hash' => AgentSwarmRun::hashFor('tender', '99'),
            'chain_name'  => ChainSpec::TENDER_TO_LEAD,
            'status'      => AgentSwarmRun::STATUS_DONE,
            'cost_usd'    => 5.0,
        ]);

        $svc = $this->makeRunner();
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'T']);

        $this->assertSame(AgentSwarmRun::STATUS_ABORTED, $run->status);
        $this->assertTrue(
            collect($run->chain_log)->contains(fn($s) => ($s['reason'] ?? '') === 'daily_budget_exceeded'),
            'chain_log must record daily_budget_exceeded'
        );
    }

    public function test_successful_synthesis_persists_lead_with_parsed_score(): void
    {
        // Three parallel agents return free text; the synth returns a
        // structured lead with score=82 — the lead must persist exactly
        // that score.
        $dispatcher = (new FakeAgentDispatcher())
            ->queueOk('research analysis: market is hot')
            ->queueOk('vessel analysis: hull match found')
            ->queueOk('crm analysis: customer in CRM since 2024')
            ->queueOk('{"leads":[{"title":"NSPA MTU spares lead","summary":"Strong fit","score":82,"customer_hint":"NSPA","equipment_hint":"MTU 4000"}]}');

        $svc = $this->makeRunner($dispatcher);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'NSPA RFQ MTU']);

        $this->assertSame(AgentSwarmRun::STATUS_DONE, $run->status);
        $this->assertSame(1, $run->leads()->count());

        $lead = $run->leads()->first();
        $this->assertSame(82, $lead->score);
        $this->assertSame('NSPA MTU spares lead', $lead->title);
        $this->assertSame(LeadOpportunity::statusForScore(82), $lead->status);
    }

    public function test_failed_dispatch_in_parallel_phase_does_not_crash_chain(): void
    {
        // Second parallel agent fails; chain continues to synth.
        $dispatcher = (new FakeAgentDispatcher())
            ->queueOk('research output')
            ->queueFail('anthropic_5xx_503')
            ->queueOk('crm output')
            ->queueOk('{"leads":[{"title":"Partial chain lead","summary":"Vessel agent failed","score":55}]}');

        $svc = $this->makeRunner($dispatcher);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'X']);

        $this->assertSame(AgentSwarmRun::STATUS_DONE, $run->status,
            'one failed agent must not abort the run');

        $errored = collect($run->chain_log)
            ->where('event', 'agent_call')
            ->where('ok', false)
            ->count();
        $this->assertSame(1, $errored, 'exactly one agent_call must be marked ok=false');

        $this->assertSame(1, $run->leads()->count(),
            'synthesis still emits a lead with whatever data IS available');
    }

    public function test_unparseable_synthesis_falls_back_to_stub(): void
    {
        // 3 parallels OK, synth returns gibberish — fallback stub
        // must produce ONE lead with score capped at 50.
        $dispatcher = (new FakeAgentDispatcher())
            ->queueOk('research')
            ->queueOk('vessel')
            ->queueOk('crm')
            ->queueOk('lol no json here');

        $svc = $this->makeRunner($dispatcher);
        $run = $svc->run(signalType: 'tender', signalId: '1', signalPayload: ['title' => 'X']);

        $this->assertSame(AgentSwarmRun::STATUS_DONE, $run->status);
        $this->assertSame(1, $run->leads()->count());

        $lead = $run->leads()->first();
        $this->assertLessThanOrEqual(50, $lead->score,
            'fallback synthesis must cap the score so it never looks high-conviction');

        // chain_log must record WHY synthesis fell back so operators
        // can audit later.
        $synthStep = collect($run->chain_log)
            ->where('event', 'agent_call')
            ->where('agent', 'sales')
            ->first();
        $this->assertSame('no_json_object_found', $synthStep['parse_error'] ?? null);
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

/**
 * Test double for AgentDispatcher. Returns canned responses in
 * arrival order — when the queue is empty, falls back to the
 * "default" response so longer chains don't blow up.
 *
 * Lives in this file because no other test needs it; promote to
 * a tests/Support/ helper if a second feature requires it.
 */
class FakeAgentDispatcher extends AgentDispatcher
{
    private array $queue = [];
    private float $defaultCost = 0.001;

    /** Skip parent __construct so we don't need an HTTP client. */
    public function __construct() {}

    public static function withDefaults(float $perCallCost = 0.001): self
    {
        $f = new self();
        $f->defaultCost = $perCallCost;
        return $f;
    }

    public function queueOk(string $text, float $cost = 0.001): self
    {
        $this->queue[] = [
            'ok'         => true,
            'text'       => $text,
            'model'      => 'fake-claude-sonnet-4-6',
            'tokens_in'  => 100,
            'tokens_out' => 50,
            'cost_usd'   => $cost,
            'ms'         => 50,
            'error'      => null,
        ];
        return $this;
    }

    public function queueFail(string $error): self
    {
        $this->queue[] = [
            'ok'         => false,
            'text'       => '',
            'model'      => 'fake-claude-sonnet-4-6',
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'cost_usd'   => 0.0,
            'ms'         => 5,
            'error'      => $error,
        ];
        return $this;
    }

    public function dispatch(
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 1500,
        ?string $model = null,
    ): array {
        if ($this->queue) return array_shift($this->queue);

        // Default canned response — used by tests that don't care
        // about per-step content (idempotency, budget caps).
        return [
            'ok'         => true,
            'text'       => '{"leads":[{"title":"Default lead","summary":"OK","score":50}]}',
            'model'      => 'fake-claude-sonnet-4-6',
            'tokens_in'  => 100,
            'tokens_out' => 50,
            'cost_usd'   => $this->defaultCost,
            'ms'         => 50,
            'error'      => null,
        ];
    }
}
