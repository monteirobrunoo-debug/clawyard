<?php

namespace Tests\Feature\Robotparts;

use App\Models\RobotResearchReport;
use App\Models\User;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\Robotparts\ResearchCouncilService;
use App\Services\WebSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B — robot research council.
 *
 *   • 1 lead + 3 participants, all from AgentCatalog (no meta-agents)
 *   • Each participant searches the web + writes findings
 *   • Lead synthesises into final_summary + proposals JSON
 *   • Persists ONE row in robot_research_reports
 *   • Failure isolation: any participant failure is dropped, lead failure
 *     marks the report as cancelled but keeps partial findings
 *   • /robot/research timeline page renders
 */
class ResearchCouncilServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_council_completes_with_findings_and_proposals(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            // 4 participants, each writes findings
            ['ok' => true, 'text' => '- finding A1\n- finding A2'],
            ['ok' => true, 'text' => '- finding B1\n- finding B2'],
            ['ok' => true, 'text' => '- finding C1\n- finding C2'],
            ['ok' => true, 'text' => '- finding D1\n- finding D2'],
            // Lead synthesises (5th call)
            ['ok' => true, 'text' => '{"summary":"Sumário final do conselho.","proposals":[{"kind":"swap","target":"muscles","suggestion":"Trocar MG90S por MG996R para mais torque."}]}'],
        ]);

        $svc = new ResearchCouncilService($dispatcher, new FakeWebSearchService('result text'));
        $report = $svc->run(topic: 'Test topic', leadingAgent: 'engineer');

        $this->assertSame(RobotResearchReport::STATUS_COMPLETE, $report->status);
        $this->assertSame('engineer', $report->leading_agent);
        $this->assertCount(4, $report->participants);
        $this->assertCount(4, $report->findings);
        $this->assertSame('Sumário final do conselho.', $report->final_summary);
        $this->assertCount(1, $report->proposals);
        $this->assertSame('swap', $report->proposals[0]['kind']);
        $this->assertNotNull($report->completed_at);
    }

    public function test_lead_synthesis_failure_cancels_keeping_findings(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => '- f1'],
            ['ok' => true, 'text' => '- f2'],
            ['ok' => true, 'text' => '- f3'],
            ['ok' => true, 'text' => '- f4'],
            ['ok' => false, 'text' => '', 'error' => 'anthropic_5xx'],
        ]);

        $svc = new ResearchCouncilService($dispatcher, new FakeWebSearchService('result'));
        $report = $svc->run(topic: 'X', leadingAgent: 'sales');

        $this->assertSame(RobotResearchReport::STATUS_CANCELLED, $report->status);
        $this->assertCount(4, $report->findings,
            'partial findings preserved even when lead fails');
        $this->assertNull($report->final_summary);
    }

    public function test_participant_failure_is_skipped(): void
    {
        // 4 participants — first 2 fail, last 2 succeed. Lead synthesises 2 findings.
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => false, 'text' => '', 'error' => 'fail1'],
            ['ok' => false, 'text' => '', 'error' => 'fail2'],
            ['ok' => true,  'text' => '- f3'],
            ['ok' => true,  'text' => '- f4'],
            ['ok' => true,  'text' => '{"summary":"OK","proposals":[]}'],
        ]);

        $svc = new ResearchCouncilService($dispatcher, new FakeWebSearchService('r'));
        $report = $svc->run(topic: 'Y', leadingAgent: 'crm');

        $this->assertSame(RobotResearchReport::STATUS_COMPLETE, $report->status);
        $this->assertCount(2, $report->findings,
            'only the 2 successful participants contributed');
    }

    public function test_total_cost_accumulates(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'f1', 'cost_usd' => 0.001],
            ['ok' => true, 'text' => 'f2', 'cost_usd' => 0.002],
            ['ok' => true, 'text' => 'f3', 'cost_usd' => 0.003],
            ['ok' => true, 'text' => 'f4', 'cost_usd' => 0.004],
            ['ok' => true, 'text' => '{"summary":"S","proposals":[]}', 'cost_usd' => 0.005],
        ]);

        $svc = new ResearchCouncilService($dispatcher, new FakeWebSearchService('r'));
        $report = $svc->run(topic: 'Z', leadingAgent: 'vessel');

        $this->assertEqualsWithDelta(0.015, (float) $report->total_cost_usd, 0.0001,
            '0.001 + 0.002 + 0.003 + 0.004 + 0.005 = 0.015 USD total');
    }

    public function test_research_page_renders(): void
    {
        $u = User::create([
            'name' => 'r', 'email' => 'r+'.uniqid().'@p.eu',
            'password' => 'x', 'role' => 'user', 'is_active' => true,
        ]);

        // Pre-seed a report so the page has content to render.
        RobotResearchReport::create([
            'topic'         => 'Test research session',
            'status'        => RobotResearchReport::STATUS_COMPLETE,
            'leading_agent' => 'engineer',
            'participants'  => ['engineer', 'sales', 'crm', 'vessel'],
            'findings'      => [
                ['agent_key' => 'engineer', 'persona_angle' => 'R&D', 'findings_md' => 'PROOF_FINDING_VISIBLE', 'at' => now()->toIso8601String()],
            ],
            'final_summary' => 'PROOF_SUMMARY_VISIBLE',
            'proposals'     => [['kind' => 'note', 'suggestion' => 'PROOF_PROPOSAL_VISIBLE']],
            'total_cost_usd' => 0.0123,
            'completed_at'   => now(),
        ]);

        $this->actingAs($u)->get(route('robot.research'))
            ->assertOk()
            ->assertSee('Conselho de pesquisa')
            ->assertSee('Test research session')
            ->assertSee('PROOF_SUMMARY_VISIBLE')
            ->assertSee('PROOF_PROPOSAL_VISIBLE');
    }
}
