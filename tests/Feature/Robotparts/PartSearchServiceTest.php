<?php

namespace Tests\Feature\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\Robotparts\PartSearchService;
use App\Services\WebSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D4 — web search + buyer's pick.
 *
 *   • Successful pick → order moves to 'purchased', wallet debited
 *   • Tavily unavailable → cancel cleanly
 *   • Buyer picks "nothing" → cancel
 *   • Buyer picks something but balance dropped → cancel, no debit
 *   • Wrong status (not 'searching') → no-op
 */
class PartSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $agentKey, float $cost = 2.50): PartOrder
    {
        return PartOrder::create([
            'agent_key'    => $agentKey,
            'name'         => 'Servo (proposed)',
            'cost_usd'     => $cost,
            'status'       => PartOrder::STATUS_SEARCHING,
            'search_query' => 'mg90s mini servo',
        ]);
    }

    public function test_successful_pick_purchases_and_debits(): void
    {
        AgentWallet::forAgent('sales')->adjust(5.00);
        $order = $this->order('sales', cost: 2.50);

        $svc = new PartSearchService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => '{"name":"MG90S Servo","description":"9g micro servo","source_url":"https://robotshop.com/mg90s","source_image_url":"https://robotshop.com/mg90s.jpg","cost_usd":2.30}'],
            ]),
            new FakeWebSearchService('1. **MG90S** 95%\n   URL: https://robotshop.com/mg90s\n   Content: 9g micro servo, $2.30...'),
        );

        $result = $svc->findAndPick($order);

        $this->assertSame(PartOrder::STATUS_PURCHASED, $result->status);
        $this->assertSame('MG90S Servo', $result->name);
        $this->assertSame('https://robotshop.com/mg90s', $result->source_url);
        $this->assertEqualsWithDelta(2.30, (float) $result->cost_usd, 0.0001);

        $w = AgentWallet::find('sales');
        $this->assertEqualsWithDelta(2.70, (float) $w->balance_usd, 0.0001,
            '5.00 - 2.30 = 2.70');
        $this->assertEqualsWithDelta(2.30, (float) $w->lifetime_spent_usd, 0.0001);
    }

    public function test_tavily_unavailable_cancels_gracefully(): void
    {
        AgentWallet::forAgent('vessel')->adjust(5.00);
        $order = $this->order('vessel');

        $svc = new PartSearchService(
            new FakeAgentDispatcher(),
            new FakeWebSearchService('(Web search not available — TAVILY_API_KEY not configured)'),
        );

        $result = $svc->findAndPick($order);

        $this->assertSame(PartOrder::STATUS_CANCELLED, $result->status);
        $this->assertStringContainsString('Tavily not configured', $result->notes);

        // Wallet untouched.
        $this->assertEqualsWithDelta(5.00, (float) AgentWallet::find('vessel')->balance_usd, 0.0001);
    }

    public function test_buyer_picks_nothing_cancels(): void
    {
        AgentWallet::forAgent('sales')->adjust(2.00);
        $order = $this->order('sales');

        // Buyer JSON with cost_usd=0 → "no fit"
        $svc = new PartSearchService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => '{"name":"none","description":"all too expensive","source_url":"","cost_usd":0}'],
            ]),
            new FakeWebSearchService('1. **expensive** 95%\n   URL: https://x.com/p'),
        );

        $result = $svc->findAndPick($order);
        $this->assertSame(PartOrder::STATUS_CANCELLED, $result->status);

        // Wallet untouched.
        $this->assertEqualsWithDelta(2.00, (float) AgentWallet::find('sales')->balance_usd, 0.0001);
    }

    public function test_insufficient_balance_at_debit_time_cancels(): void
    {
        // Wallet has $1, but buyer picks something costing $5.
        AgentWallet::forAgent('crm')->adjust(1.00);
        $order = $this->order('crm', cost: 5.00);

        $svc = new PartSearchService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => '{"name":"Big servo","description":"high torque","source_url":"https://x.com/p","cost_usd":5.00}'],
            ]),
            new FakeWebSearchService('1. result'),
        );

        $result = $svc->findAndPick($order);

        $this->assertSame(PartOrder::STATUS_CANCELLED, $result->status);
        $this->assertStringContainsString('insufficient balance', $result->notes);
        $this->assertEqualsWithDelta(1.00, (float) AgentWallet::find('crm')->balance_usd, 0.0001);
    }

    public function test_dispatch_failure_cancels(): void
    {
        AgentWallet::forAgent('sales')->adjust(5.00);
        $order = $this->order('sales');

        $svc = new PartSearchService(
            new FakeAgentDispatcher([
                ['ok' => false, 'text' => '', 'error' => 'anthropic_5xx_503'],
            ]),
            new FakeWebSearchService('1. result'),
        );

        $result = $svc->findAndPick($order);
        $this->assertSame(PartOrder::STATUS_CANCELLED, $result->status);
    }

    public function test_no_op_when_order_not_in_searching_state(): void
    {
        $order = PartOrder::create([
            'agent_key' => 'sales',
            'name'      => 'already purchased',
            'cost_usd'  => 2.0,
            'status'    => PartOrder::STATUS_PURCHASED,
        ]);

        $svc = new PartSearchService(
            new FakeAgentDispatcher(),
            new FakeWebSearchService(''),
        );

        $result = $svc->findAndPick($order);
        $this->assertSame(PartOrder::STATUS_PURCHASED, $result->status,
            'service must be idempotent: order already past searching → no-op');
    }

    public function test_search_candidates_text_is_persisted_for_audit(): void
    {
        AgentWallet::forAgent('sales')->adjust(5.00);
        $order = $this->order('sales');

        $svc = new PartSearchService(
            new FakeAgentDispatcher([
                ['ok' => true, 'text' => '{"name":"X","description":"y","source_url":"https://x.com","cost_usd":1.0}'],
            ]),
            new FakeWebSearchService('PROOF_OF_SEARCH_TEXT — this should land in audit'),
        );

        $result = $svc->findAndPick($order);
        $this->assertSame(PartOrder::STATUS_PURCHASED, $result->status);
        $this->assertStringContainsString(
            'PROOF_OF_SEARCH_TEXT',
            $result->search_candidates['raw_text'] ?? ''
        );
    }
}

class FakeWebSearchService extends WebSearchService
{
    public function __construct(private string $cannedResponse = '') {
        // Skip parent — no HTTP needed.
    }
    public function isAvailable(): bool { return true; }
    public function search(string $query, int $maxResults = 5, string $searchDepth = 'basic', ?int $days = null): string
    {
        return $this->cannedResponse;
    }
}
