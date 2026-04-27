<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /chat banner — explains why the agent picker is smaller than usual.
 *
 * Locked behaviour:
 *   • Admin: never sees the banner (admin always passes the gate
 *     so the picker is full anyway).
 *   • User with NULL allowed_agents: no banner (no restriction).
 *   • User with whitelist: indigo info banner showing N/M count.
 *   • User with []: amber warning ("blocked from all").
 *
 * The view receives an `$agentRestriction` payload from the route
 * closure; the test asserts both the payload shape AND the rendered
 * HTML so a future refactor of the banner template can't silently
 * drop the warning.
 */
class ChatAgentRestrictionBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_never_sees_the_banner(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin', 'is_active' => true, 'allowed_agents' => [],
        ]);

        $r = $this->actingAs($admin)->get('/chat');
        $r->assertOk();
        $r->assertViewHas('agentRestriction', null);
    }

    public function test_unrestricted_user_does_not_see_banner(): void
    {
        $u = User::factory()->create([
            'role' => 'user', 'is_active' => true, 'allowed_agents' => null,
        ]);

        $r = $this->actingAs($u)->get('/chat');
        $r->assertOk();
        $r->assertViewHas('agentRestriction', null);
        $r->assertDontSee('id="agent-restriction-banner"', false);
    }

    public function test_user_with_whitelist_sees_count_banner(): void
    {
        $u = User::factory()->create([
            'role' => 'user', 'is_active' => true,
            'allowed_agents' => ['sales', 'sap', 'document'],
        ]);

        $r = $this->actingAs($u)->get('/chat');
        $r->assertOk();
        $r->assertViewHas('agentRestriction', function ($payload) {
            return is_array($payload)
                && $payload['mode']    === 'whitelist'
                && $payload['visible'] === 3
                && $payload['total']   > 3;
        });
        $r->assertSee('id="agent-restriction-banner"', false);
        $r->assertSee('Vês', false);
    }

    public function test_user_blocked_from_all_sees_warning_banner(): void
    {
        $u = User::factory()->create([
            'role' => 'user', 'is_active' => true, 'allowed_agents' => [],
        ]);

        $r = $this->actingAs($u)->get('/chat');
        $r->assertOk();
        $r->assertViewHas('agentRestriction', function ($payload) {
            return is_array($payload)
                && $payload['mode']    === 'blocked_all'
                && $payload['visible'] === 0;
        });
        $r->assertSee('Acesso a agentes bloqueado', false);
    }
}
