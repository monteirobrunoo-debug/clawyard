<?php

namespace Tests\Feature\Robotparts;

use App\Models\PartOrder;
use App\Services\Robotparts\ShopCommitteeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: vessel order #15 picked a $8.99 part with $3.20 budget
 * — buyer LLM ignored the budget constraint in the prompt, then the
 * search phase died on 'insufficient balance at debit time'. Now the
 * service catches over-budget picks BEFORE leaving the committee
 * phase so we never reach the search/debit step with an unaffordable
 * order.
 */
class ShopCommitteeBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_over_budget_pick_cancels_in_committee(): void
    {
        // Buyer LLM picks a $8.99 part but budget is only $3.00.
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'try a sensor'],
            ['ok' => true, 'text' => 'or a switch'],
            ['ok' => true, 'text' => '{"name":"JSN-SR04T","description":"waterproof ultrasonic sensor","search_query":"jsn-sr04t","est_cost_usd":8.99}'],
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('vessel', budget: 3.00);

        $this->assertSame(PartOrder::STATUS_CANCELLED, $order->status,
            'over-budget pick must be caught in committee, not pushed downstream');
        $this->assertStringContainsString('over-budget', $order->notes);
        $this->assertStringContainsString('$8.99', $order->notes);
        $this->assertStringContainsString('$3.00', $order->notes);
    }

    public function test_within_budget_pick_proceeds_to_searching(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'try a led'],
            ['ok' => true, 'text' => 'or a button'],
            ['ok' => true, 'text' => '{"name":"5mm Red LED","description":"tiny LED","search_query":"5mm red led 100pcs","est_cost_usd":1.50}'],
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('crm', budget: 3.00);

        $this->assertSame(PartOrder::STATUS_SEARCHING, $order->status);
        $this->assertEqualsWithDelta(1.50, (float) $order->cost_usd, 0.0001);
    }

    public function test_at_budget_exact_pick_passes(): void
    {
        // Budget edge case: cost equals budget exactly — must pass.
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'h1'],
            ['ok' => true, 'text' => 'h2'],
            ['ok' => true, 'text' => '{"name":"X","description":"y","search_query":"z","est_cost_usd":3.00}'],
        ]);
        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 3.00);
        $this->assertSame(PartOrder::STATUS_SEARCHING, $order->status);
    }

    public function test_micro_overshoot_within_tolerance_passes(): void
    {
        // 5 millidollar tolerance for floating-point rounding — $3.001
        // with budget $3.00 should still pass.
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'h1'],
            ['ok' => true, 'text' => 'h2'],
            ['ok' => true, 'text' => '{"name":"X","description":"y","search_query":"z","est_cost_usd":3.001}'],
        ]);
        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 3.00);
        $this->assertSame(PartOrder::STATUS_SEARCHING, $order->status);
    }
}
