<?php

namespace Tests\Feature\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\Robotparts\ShopCommitteeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D3 — multi-agent shop committee.
 *
 *   • 2 helpers contribute, then the buyer decides.
 *   • Committee log captures all 3 contributions.
 *   • Buyer's JSON populates name + description + search_query + cost.
 *   • Dispatcher failure (helper or buyer) → graceful cancel.
 *   • Buyer returning empty/zero-cost decision → cancel, no debit.
 */
class ShopCommitteeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_committee_produces_searching_order(): void
    {
        AgentWallet::forAgent('sales')->adjust(5.00);

        $dispatcher = new FakeAgentDispatcher([
            // helper 1
            ['ok' => true, 'text' => 'I think a servo motor would fit Marco well.'],
            // helper 2
            ['ok' => true, 'text' => 'Maybe an OLED display for status updates.'],
            // buyer's decision (JSON)
            ['ok' => true, 'text' => '{"name":"MG90S Servo","description":"9g micro servo for body articulation","search_query":"mg90s 9g servo","justification":"Servos beat displays for movement","est_cost_usd":2.50}'],
        ]);

        $svc = new ShopCommitteeService($dispatcher);
        $order = $svc->deliberate('sales', budget: 5.00);

        $this->assertSame(PartOrder::STATUS_SEARCHING, $order->status);
        $this->assertSame('MG90S Servo', $order->name);
        $this->assertSame('mg90s 9g servo', $order->search_query);
        $this->assertEqualsWithDelta(2.50, (float) $order->cost_usd, 0.0001);

        // 3 committee entries: 2 helpers + 1 buyer.
        $log = $order->committee_log;
        $this->assertCount(3, $log);
        $this->assertSame('helper', $log[0]['role']);
        $this->assertSame('helper', $log[1]['role']);
        $this->assertSame('buyer',  $log[2]['role']);
        $this->assertSame('sales',  $log[2]['agent_key']);
    }

    public function test_buyer_zero_cost_decision_cancels_order(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'a servo'],
            ['ok' => true, 'text' => 'a sensor'],
            ['ok' => true, 'text' => '{"name":"nothing","description":"too expensive","search_query":"","est_cost_usd":0}'],
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 1.00);

        $this->assertSame(PartOrder::STATUS_CANCELLED, $order->status);
        $this->assertStringContainsString('search_query', $order->notes);
    }

    public function test_buyer_dispatch_failure_cancels_with_notes(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'a sensor'],
            ['ok' => true, 'text' => 'a led'],
            ['ok' => false, 'text' => '', 'error' => 'anthropic_5xx_503'],
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 5.00);

        $this->assertSame(PartOrder::STATUS_CANCELLED, $order->status);
        $this->assertStringContainsString('buyer dispatch failed', $order->notes);
    }

    public function test_helper_dispatch_failure_proceeds_without_that_helper(): void
    {
        // First helper fails, second succeeds, buyer decides → order
        // still progresses to searching with only 1 helper in log.
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => false, 'text' => '', 'error' => 'anthropic_5xx_503'],
            ['ok' => true, 'text' => 'try a button'],
            ['ok' => true, 'text' => '{"name":"Button","description":"tactile","search_query":"6mm tactile button","est_cost_usd":0.50}'],
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 2.00);

        $this->assertSame(PartOrder::STATUS_SEARCHING, $order->status);
        $log = $order->committee_log;
        $this->assertCount(2, $log, 'failed helper produces no log entry; succeeded helper + buyer = 2 entries');
        $this->assertSame('helper', $log[0]['role']);
        $this->assertSame('buyer',  $log[1]['role']);
    }

    public function test_unparseable_json_from_buyer_cancels(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'a servo'],
            ['ok' => true, 'text' => 'a sensor'],
            ['ok' => true, 'text' => 'I would like to buy a servo. It is great.'],   // no JSON
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 3.00);

        $this->assertSame(PartOrder::STATUS_CANCELLED, $order->status);
    }

    public function test_buyer_json_with_markdown_fence_still_parses(): void
    {
        $dispatcher = new FakeAgentDispatcher([
            ['ok' => true, 'text' => 'h1'],
            ['ok' => true, 'text' => 'h2'],
            ['ok' => true, 'text' => "```json\n{\"name\":\"Wheel\",\"description\":\"60mm rubber wheel\",\"search_query\":\"60mm rubber robot wheel\",\"est_cost_usd\":1.20}\n```"],
        ]);

        $order = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 5.00);
        $this->assertSame(PartOrder::STATUS_SEARCHING, $order->status);
        $this->assertSame('Wheel', $order->name);
    }

    public function test_second_helper_sees_first_helpers_reply(): void
    {
        // Phase C — multi-round cooperation: helper 2's prompt must
        // include helper 1's text so they can react. We capture what
        // the dispatcher receives and assert helper 1's line shows up
        // in helper 2's system prompt.
        $captured = [];
        $dispatcher = new class($captured) extends \App\Services\AgentSwarm\AgentDispatcher {
            public array $captured;
            public function __construct(&$captured) {
                $this->captured = &$captured;
            }
            public function dispatch(
                string $systemPrompt,
                string $userMessage,
                int $maxTokens = 1500,
                ?string $model = null,
            ): array {
                $this->captured[] = $systemPrompt;
                // Hand-crafted response stream: helper1, helper2, buyer.
                static $count = 0;
                $count++;
                $replies = [
                    1 => 'I think a 5mm RED LED works great here, dirt cheap (~\$0.30).',
                    2 => 'Actually a WS2812B addressable RGB LED is much better for branding.',
                    3 => '{"name":"WS2812B Strip","description":"5050 RGB","search_query":"ws2812b 5050","est_cost_usd":1.50}',
                ];
                return [
                    'ok' => true,
                    'text' => $replies[$count] ?? '{}',
                    'model' => 'fake', 'tokens_in' => 100, 'tokens_out' => 50,
                    'cost_usd' => 0.001, 'ms' => 50, 'error' => null,
                ];
            }
        };

        (new \App\Services\Robotparts\ShopCommitteeService($dispatcher))
            ->deliberate('briefing', budget: 5.00);

        // 3 dispatches happened (helper1, helper2, buyer).
        $this->assertCount(3, $dispatcher->captured);
        // Helper 2's system prompt MUST contain helper 1's text.
        $this->assertStringContainsString(
            'I think a 5mm RED LED',
            $dispatcher->captured[1],
            'helper 2 must see helper 1 reply for multi-round cooperation'
        );
        // Helper 1 sees no previous (debateContext empty).
        $this->assertStringNotContainsString('PREVIOUS HELPERS HAVE ALREADY', $dispatcher->captured[0]);
        // Helper 2 has the debate block.
        $this->assertStringContainsString('PREVIOUS HELPERS HAVE ALREADY', $dispatcher->captured[1]);
    }

    public function test_helpers_picked_exclude_the_buyer(): void
    {
        // Run multiple committees, ensure buyer never appears as helper
        // in the committee_log.
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $dispatcher = new FakeAgentDispatcher([
                ['ok' => true, 'text' => 'h1'],
                ['ok' => true, 'text' => 'h2'],
                ['ok' => true, 'text' => '{"name":"X","description":"y","search_query":"q","est_cost_usd":1}'],
            ]);
            $orders[] = (new ShopCommitteeService($dispatcher))->deliberate('sales', budget: 5.00);
        }

        foreach ($orders as $order) {
            $helpers = collect($order->committee_log)->where('role', 'helper')->pluck('agent_key');
            $this->assertNotContains('sales', $helpers,
                'buyer must never be picked as one of its own helpers');
        }
    }
}

/**
 * Test double for AgentDispatcher. Returns canned responses in
 * arrival order. Each entry is merged with sensible defaults so
 * tests can pass just `['ok' => true, 'text' => 'foo']`.
 */
class FakeAgentDispatcher extends AgentDispatcher
{
    public function __construct(private array $queue = [])
    {
        // Skip parent — no HTTP needed.
    }

    public function dispatch(
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 1500,
        ?string $model = null,
    ): array {
        $next = array_shift($this->queue) ?? ['ok' => true, 'text' => '{}'];
        return array_merge([
            'ok'         => true,
            'text'       => '',
            'model'      => 'fake',
            'tokens_in'  => 100,
            'tokens_out' => 50,
            'cost_usd'   => 0.001,
            'ms'         => 50,
            'error'      => null,
        ], $next);
    }
}
