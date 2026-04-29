<?php

namespace Tests\Feature\Robotparts;

use App\Models\PartOrder;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\Robotparts\PartValidationService;
use App\Services\Robotparts\RobotBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A — peer-review validation for purchases.
 *
 *   • 2 reviewer agents emit verdicts on each order
 *   • Excludes the buyer + committee helpers from being reviewers
 *     (no self-review, no double-vote)
 *   • Stores {agent_key, role, verdict, note, at} in validations json
 *   • Idempotent: re-running on already-reviewed order = no-op
 *   • Failed dispatch is silently dropped (review just doesn't happen)
 *   • Bad verdict from LLM (something other than approve/concern) is rejected
 */
class PartValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function order(): PartOrder
    {
        return PartOrder::create([
            'agent_key' => 'sales',
            'slot'      => RobotBlueprint::SLOT_VOICE,
            'name'      => 'PAM8403 Speaker Amp',
            'cost_usd'  => 2.50,
            'status'    => PartOrder::STATUS_PURCHASED,
            'committee_log' => [
                ['agent_key' => 'engineer', 'role' => 'helper', 'text' => 'a speaker fits'],
                ['agent_key' => 'crm',      'role' => 'helper', 'text' => 'good for voice'],
            ],
        ]);
    }

    public function test_two_approvals_persist(): void
    {
        $order = $this->order();
        $svc = new PartValidationService(new FakeAgentDispatcher([
            ['ok' => true, 'text' => '{"verdict":"approve","note":"Preço razoável e fit para voice."}'],
            ['ok' => true, 'text' => '{"verdict":"approve","note":"Combina com o slot."}'],
        ]));

        $result = $svc->review($order);
        $vs = $result->validationSummary();

        $this->assertSame(2, $vs['count']);
        $this->assertSame(2, $vs['approves']);
        $this->assertSame(0, $vs['concerns']);
        $this->assertSame('✅', $vs['badge']);
    }

    public function test_one_approval_one_concern(): void
    {
        $order = $this->order();
        $svc = new PartValidationService(new FakeAgentDispatcher([
            ['ok' => true, 'text' => '{"verdict":"approve","note":"Boa escolha."}'],
            ['ok' => true, 'text' => '{"verdict":"concern","note":"Existe versão a metade do preço no AliExpress."}'],
        ]));

        $result = $svc->review($order);
        $vs = $result->validationSummary();

        $this->assertSame(2, $vs['count']);
        $this->assertSame(1, $vs['approves']);
        $this->assertSame(1, $vs['concerns']);
        $this->assertSame('⚠️', $vs['badge']);
    }

    public function test_idempotent_already_reviewed(): void
    {
        $order = $this->order();
        $order->validations = [
            ['agent_key' => 'patent', 'role' => 'reviewer', 'verdict' => 'approve', 'note' => 'OK'],
            ['agent_key' => 'finance', 'role' => 'reviewer', 'verdict' => 'approve', 'note' => 'OK'],
        ];
        $order->save();

        // Dispatcher is empty — a call would fail. Service must NOT call.
        $svc = new PartValidationService(new FakeAgentDispatcher([]));
        $result = $svc->review($order);

        $this->assertCount(2, $result->validations,
            'already-reviewed order must not get extra reviews');
    }

    public function test_buyer_never_reviewer(): void
    {
        // Run review 10× on a fresh order, ensure buyer 'sales' never
        // appears as reviewer.
        for ($i = 0; $i < 10; $i++) {
            $order = $this->order();
            $svc = new PartValidationService(new FakeAgentDispatcher([
                ['ok' => true, 'text' => '{"verdict":"approve","note":"OK"}'],
                ['ok' => true, 'text' => '{"verdict":"approve","note":"OK"}'],
            ]));
            $result = $svc->review($order);

            $reviewerKeys = collect($result->validations ?? [])->pluck('agent_key');
            $this->assertNotContains('sales', $reviewerKeys, 'iteration ' . $i);
            // Helpers from committee_log also excluded.
            $this->assertNotContains('engineer', $reviewerKeys, 'iteration ' . $i);
            $this->assertNotContains('crm', $reviewerKeys, 'iteration ' . $i);
        }
    }

    public function test_dispatch_failure_skips_that_review(): void
    {
        $order = $this->order();
        $svc = new PartValidationService(new FakeAgentDispatcher([
            ['ok' => false, 'text' => '', 'error' => 'anthropic_5xx'],
            ['ok' => true,  'text' => '{"verdict":"approve","note":"OK"}'],
        ]));

        $result = $svc->review($order);
        $this->assertCount(1, $result->validations,
            'failed review is just dropped; second review still persists');
    }

    public function test_bad_verdict_value_rejected(): void
    {
        // LLM returns garbage verdict — review skipped.
        $order = $this->order();
        $svc = new PartValidationService(new FakeAgentDispatcher([
            ['ok' => true, 'text' => '{"verdict":"maybe","note":"Sei lá"}'],
            ['ok' => true, 'text' => '{"verdict":"approve","note":"OK"}'],
        ]));

        $result = $svc->review($order);
        $this->assertCount(1, $result->validations);
    }

    public function test_unparseable_json_rejected(): void
    {
        $order = $this->order();
        $svc = new PartValidationService(new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'I think this is fine, but...'],   // no JSON
            ['ok' => true, 'text' => '{"verdict":"approve","note":"Fit OK"}'],
        ]));

        $result = $svc->review($order);
        $this->assertCount(1, $result->validations);
    }

    public function test_zero_validations_shows_pending_badge(): void
    {
        $order = $this->order();
        $vs = $order->validationSummary();
        $this->assertSame('⏳', $vs['badge'],
            'zero reviews → pending hourglass');
    }
}
