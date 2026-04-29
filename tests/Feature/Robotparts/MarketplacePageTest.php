<?php

namespace Tests\Feature\Robotparts;

use App\Models\AgentWallet;
use App\Models\PartOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /marketplace consolidated feed.
 *
 *   • Auth-gated
 *   • Renders header stats + top wallets + filters + orders feed
 *   • Status filter narrows the list
 *   • Agent filter narrows the list
 *   • Committee log shows up in the rendered HTML so user sees the
 *     deliberation thread
 */
class MarketplacePageTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'      => 'M-' . uniqid(),
            'email'     => 'm+' . uniqid() . '@partyard.eu',
            'password'  => 'x',
            'role'      => 'user',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get(route('marketplace.index'))->assertRedirect(route('login'));
    }

    public function test_renders_empty_state_when_no_orders(): void
    {
        $this->actingAs($this->user())
            ->get(route('marketplace.index'))
            ->assertOk()
            ->assertSee('Marketplace dos agentes')
            ->assertSee('Nenhuma peça comprada ainda');
    }

    public function test_renders_orders_with_committee_log(): void
    {
        AgentWallet::forAgent('sales')->adjust(10);
        $order = PartOrder::create([
            'agent_key'   => 'sales',
            'name'        => 'Tower Pro MG90S Servo',
            'description' => '9g micro servo',
            'cost_usd'    => 2.50,
            'status'      => PartOrder::STATUS_STL_READY,
            'source_url'  => 'https://robotshop.com/mg90s',
        ]);
        $order->appendCommittee('research', 'helper', 'I think a servo would be great for movement.');
        $order->appendCommittee('vessel',   'helper', 'A waterproof one would last longer.');
        $order->appendCommittee('sales',    'buyer',  '{"name":"MG90S","cost":2.50}');

        $resp = $this->actingAs($this->user())
            ->get(route('marketplace.index'))
            ->assertOk()
            ->assertSee('Tower Pro MG90S Servo')
            ->assertSee('Marina Research')           // helper's display name from AgentCatalog
            ->assertSee('Capitão Vasco', false)       // accent-tolerance
            ->assertSee('Marco Sales')
            ->assertSee('I think a servo would be great');

        // Stats header reflects the order.
        $resp->assertSee('1', false)   // total orders
             ->assertSee('STL prontos');
    }

    public function test_status_filter_narrows_results(): void
    {
        AgentWallet::forAgent('sales')->adjust(10);
        PartOrder::create([
            'agent_key' => 'sales', 'name' => 'KEEP_VISIBLE',
            'cost_usd' => 1, 'status' => PartOrder::STATUS_STL_READY,
        ]);
        PartOrder::create([
            'agent_key' => 'sales', 'name' => 'HIDE_BY_FILTER',
            'cost_usd' => 1, 'status' => PartOrder::STATUS_CANCELLED,
        ]);

        $this->actingAs($this->user())
            ->get(route('marketplace.index', ['status' => 'stl_ready']))
            ->assertOk()
            ->assertSee('KEEP_VISIBLE')
            ->assertDontSee('HIDE_BY_FILTER');
    }

    public function test_agent_filter_narrows_results(): void
    {
        AgentWallet::forAgent('sales')->adjust(10);
        AgentWallet::forAgent('vessel')->adjust(10);
        PartOrder::create([
            'agent_key' => 'sales',  'name' => 'SALES_PART',
            'cost_usd' => 1, 'status' => PartOrder::STATUS_STL_READY,
        ]);
        PartOrder::create([
            'agent_key' => 'vessel', 'name' => 'VESSEL_PART',
            'cost_usd' => 1, 'status' => PartOrder::STATUS_STL_READY,
        ]);

        $this->actingAs($this->user())
            ->get(route('marketplace.index', ['agent_key' => 'sales']))
            ->assertOk()
            ->assertSee('SALES_PART')
            ->assertDontSee('VESSEL_PART');
    }

    public function test_top_wallets_panel_shows_richest_agents(): void
    {
        AgentWallet::forAgent('sales')->adjust(15.00);
        AgentWallet::forAgent('vessel')->adjust(5.00);

        $resp = $this->actingAs($this->user())
            ->get(route('marketplace.index'))
            ->assertOk()
            ->assertSee('Top 10 wallets')
            ->assertSee('$15.00')
            ->assertSee('$5.00');

        // Top wallet (sales/$15) appears BEFORE vessel/$5 in the HTML.
        $body = $resp->getContent();
        $this->assertLessThan(
            strpos($body, '$5.00'),
            strpos($body, '$15.00'),
            'wallets must be ordered by balance desc'
        );
    }
}
