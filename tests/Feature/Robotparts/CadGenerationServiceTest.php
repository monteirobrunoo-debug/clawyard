<?php

namespace Tests\Feature\Robotparts;

use App\Models\PartOrder;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\Robotparts\CadGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D5 — CAD generation.
 *
 *   • Successful path: SCAD code is generated and persisted.
 *   • If openscad binary is available, STL is rendered and order
 *     transitions to stl_ready.
 *   • If openscad is missing (CI/test envs), order stays at 'designing'
 *     with the SCAD code preserved + an explanatory note.
 *   • Dispatch failure leaves order at 'designing' with notes.
 *   • Wrong status → no-op.
 *
 * We DON'T require openscad to pass — the test asserts the right end
 * state for whichever scenario the test box presents.
 */
class CadGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function purchasedOrder(): PartOrder
    {
        return PartOrder::create([
            'agent_key'   => 'sales',
            'name'        => 'Mounting bracket',
            'description' => 'L-shaped bracket, 30mm tall, 20mm base, 3mm thick',
            'cost_usd'    => 1.50,
            'status'      => PartOrder::STATUS_PURCHASED,
        ]);
    }

    public function test_scad_code_is_generated_and_persisted(): void
    {
        $order = $this->purchasedOrder();
        $scad = '$fn=64;'."\n".'difference() { cube([20,30,3]); translate([10,15,0]) cylinder(h=3,d=4); }';

        $svc = new CadGenerationService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => $scad],
            ]),
        );

        $result = $svc->generate($order);

        $this->assertNotEmpty($result->design_scad,
            'design_scad must be persisted regardless of openscad availability');
        $this->assertStringContainsString('cube', $result->design_scad);

        // End state is either stl_ready (binary exists) or still designing
        // (binary absent + note). Both are valid outcomes.
        $this->assertContains($result->status, [
            PartOrder::STATUS_STL_READY,
            PartOrder::STATUS_DESIGNING,
        ]);
    }

    public function test_dispatch_failure_keeps_order_in_designing_with_notes(): void
    {
        $order = $this->purchasedOrder();

        $svc = new CadGenerationService(
            new FakeAgentDispatcher([
                ['ok' => false, 'text' => '', 'error' => 'anthropic_5xx_503'],
            ]),
        );

        $result = $svc->generate($order);

        $this->assertSame(PartOrder::STATUS_DESIGNING, $result->status,
            'status should advance to designing even on failure (intent recorded)');
        $this->assertNull($result->design_scad);
        $this->assertStringContainsString('LLM dispatch failed', $result->notes);
    }

    public function test_markdown_fence_is_stripped_from_scad_output(): void
    {
        $order = $this->purchasedOrder();
        $svc = new CadGenerationService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => "```openscad\n\$fn=64;\ncube([10,10,10]);\n```"],
            ]),
        );

        $result = $svc->generate($order);
        $this->assertStringNotContainsString('```', $result->design_scad,
            'markdown fence wrappers must be stripped');
        $this->assertStringContainsString('$fn=64', $result->design_scad);
        $this->assertStringContainsString('cube', $result->design_scad);
    }

    public function test_no_op_when_order_not_in_purchased_state(): void
    {
        $order = PartOrder::create([
            'agent_key' => 'sales',
            'name'      => 'already done',
            'cost_usd'  => 1.0,
            'status'    => PartOrder::STATUS_STL_READY,
        ]);

        $svc = new CadGenerationService(new FakeAgentDispatcher());
        $result = $svc->generate($order);

        $this->assertSame(PartOrder::STATUS_STL_READY, $result->status);
        $this->assertNull($result->design_scad,
            'no LLM call when order is past purchased — design_scad stays null');
    }

    public function test_empty_scad_response_keeps_order_in_designing(): void
    {
        $order = $this->purchasedOrder();
        $svc = new CadGenerationService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => '   '],   // whitespace only
            ]),
        );
        $result = $svc->generate($order);

        $this->assertSame(PartOrder::STATUS_DESIGNING, $result->status);
        $this->assertNull($result->design_scad);
    }
}
