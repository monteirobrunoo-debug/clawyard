<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /conversations — pinned behaviour around the search/filter feature
 * added 2026-04-27 ("users complain they can't find their history").
 *
 * Locked:
 *   • A user only sees conversations whose session_id starts with
 *     `u<their_id>_`. Cannot leak across users via search.
 *   • ?q=<text> matches conversations whose any message contains the
 *     text (case-insensitive LIKE).
 *   • ?agent=<key> filters to one agent.
 *   • Both filters compose.
 *   • Empty results render the "Sem resultados" empty-state.
 */
class ConversationSearchTest extends TestCase
{
    use RefreshDatabase;

    private function convFor(User $u, string $agent = 'sales'): Conversation
    {
        return Conversation::create([
            'session_id' => 'u'.$u->id.'_cyw_'.uniqid(),
            'agent'      => $agent,
            'channel'    => 'web',
        ]);
    }

    private function msg(Conversation $c, string $role, string $content): Message
    {
        return Message::create([
            'conversation_id' => $c->id,
            'role'            => $role,
            'content'         => $content,
        ]);
    }

    /**
     * Helper — pull the resolved conversations collection out of the
     * test response. Going through `assertViewHas` with a closure
     * pluck-by-id is brittle because some Laravel versions hide the
     * paginator items behind getCollection(). This direct read is
     * what the rendered view actually iterates.
     *
     * @return int[]   list of conversation ids in render order
     */
    private function visibleIds(\Illuminate\Testing\TestResponse $r): array
    {
        $data = $r->original->getData();
        $paginator = $data['conversations'] ?? null;
        if (!$paginator) return [];
        return collect($paginator->items())->pluck('id')->all();
    }

    public function test_user_only_sees_their_own_conversations(): void
    {
        $alice = User::factory()->create(['is_active' => true]);
        $bob   = User::factory()->create(['is_active' => true]);

        $aliceConv = $this->convFor($alice);
        $this->msg($aliceConv, 'user', 'Alice secret');

        $bobConv = $this->convFor($bob);
        $this->msg($bobConv, 'user', 'Bob secret');

        $r = $this->actingAs($alice)->get(route('conversations'));
        $r->assertOk();
        $ids = $this->visibleIds($r);
        $this->assertContains($aliceConv->id, $ids);
        $this->assertNotContains($bobConv->id, $ids);
    }

    public function test_q_filters_by_message_text(): void
    {
        $u = User::factory()->create(['is_active' => true]);
        $a = $this->convFor($u);
        $b = $this->convFor($u);

        $this->msg($a, 'user', 'preciso de filtro Wartsila urgente');
        $this->msg($b, 'user', 'overhaul MTU em Singapura');

        $r = $this->actingAs($u)->get(route('conversations', ['q' => 'wartsila']));
        $r->assertOk();
        $ids = $this->visibleIds($r);
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    public function test_agent_filter_restricts_to_one_agent(): void
    {
        $u = User::factory()->create(['is_active' => true]);

        $sales = $this->convFor($u, 'sales');
        $this->msg($sales, 'user', 'x');
        $sap   = $this->convFor($u, 'sap');
        $this->msg($sap, 'user', 'y');

        $r = $this->actingAs($u)->get(route('conversations', ['agent' => 'sap']));
        $r->assertOk();
        $ids = $this->visibleIds($r);
        $this->assertContains($sap->id, $ids);
        $this->assertNotContains($sales->id, $ids);
    }

    public function test_q_and_agent_compose(): void
    {
        $u = User::factory()->create(['is_active' => true]);

        $a = $this->convFor($u, 'sales');
        $this->msg($a, 'user', 'wartsila');
        $b = $this->convFor($u, 'sales');
        $this->msg($b, 'user', 'mtu');
        $c = $this->convFor($u, 'sap');
        $this->msg($c, 'user', 'wartsila');

        // sales × wartsila → only $a.
        $r = $this->actingAs($u)->get(route('conversations', ['q' => 'wartsila', 'agent' => 'sales']));
        $ids = $this->visibleIds($r);
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
        $this->assertNotContains($c->id, $ids);
    }

    public function test_empty_search_renders_no_results_state(): void
    {
        $u = User::factory()->create(['is_active' => true]);
        $c = $this->convFor($u);
        $this->msg($c, 'user', 'lorem ipsum');

        $r = $this->actingAs($u)->get(route('conversations', ['q' => 'unrelated_text_xyz']));
        $r->assertOk();
        $r->assertSee('Sem resultados', false);
    }

    public function test_user_agents_dropdown_is_populated_from_user_history(): void
    {
        $u = User::factory()->create(['is_active' => true]);
        $this->convFor($u, 'sales');
        $this->convFor($u, 'sap');
        $this->convFor($u, 'sales');   // duplicate — should still be listed once via DISTINCT

        $r = $this->actingAs($u)->get(route('conversations'));
        $r->assertViewHas('userAgents', function ($agents) {
            return in_array('sales', $agents, true) && in_array('sap', $agents, true) && count($agents) === 2;
        });
    }
}
