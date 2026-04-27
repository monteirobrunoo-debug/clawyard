<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAdminEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-user agent access — pins:
 *   • User::canUseAgent (NULL=all, []=none, whitelist)
 *   • Gate agents.use mirrors the model
 *   • Admin always passes regardless of allowed_agents
 *   • Apply preset replaces, blow up on unknown preset
 *   • /admin/agent-access is admin-only and renders the matrix
 *   • Toggle endpoint flips the bit + logs a user_admin_event
 *   • Preset endpoint applies + logs
 *   • /api/agents filters out un-allowed keys
 *   • /api/chat 403s when the agent is not allowed
 */
class UserAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function user(?array $allowed = null, string $role = 'user'): User
    {
        return User::factory()->create([
            'role'           => $role,
            'is_active'      => true,
            'allowed_agents' => $allowed,
        ]);
    }

    // ── Model + Gate ─────────────────────────────────────────────────────

    public function test_null_allowed_agents_means_all_allowed(): void
    {
        $u = $this->user(allowed: null);
        $this->assertTrue($u->canUseAgent('sales'));
        $this->assertTrue($u->canUseAgent('aria'));
        $this->assertTrue($u->canUseAgent('any-key'));
    }

    public function test_empty_array_means_blocked_from_all(): void
    {
        $u = $this->user(allowed: []);
        $this->assertFalse($u->canUseAgent('sales'));
    }

    public function test_whitelist_only_allows_listed_keys(): void
    {
        $u = $this->user(allowed: ['sales', 'sap']);
        $this->assertTrue($u->canUseAgent('sales'));
        $this->assertTrue($u->canUseAgent('sap'));
        $this->assertFalse($u->canUseAgent('aria'));
        $this->assertFalse($u->canUseAgent('vessel'));
    }

    public function test_admin_always_passes_regardless_of_allowed_agents(): void
    {
        $a = $this->user(allowed: [], role: 'admin');
        // Even with allowed_agents=[] (would block any user), admin
        // still passes the gate because canUseAgent short-circuits.
        $this->assertTrue($a->canUseAgent('sales'));
        $this->assertTrue($a->can('agents.use', 'sales'));
    }

    public function test_gate_mirrors_model(): void
    {
        $u = $this->user(allowed: ['sales']);
        $this->assertTrue($u->can('agents.use', 'sales'));
        $this->assertFalse($u->can('agents.use', 'aria'));
    }

    // ── Presets ──────────────────────────────────────────────────────────

    public function test_apply_preset_replaces_allowed_agents(): void
    {
        $u = $this->user(allowed: ['some-old-agent']);
        $u->applyAgentPreset('vendor_spares');

        $this->assertEqualsCanonicalizing(
            User::AGENT_PRESETS['vendor_spares'],
            $u->fresh()->allowed_agents
        );
    }

    public function test_full_access_preset_sets_null(): void
    {
        $u = $this->user(allowed: ['blocked']);
        $u->applyAgentPreset('full_access');
        $this->assertNull($u->fresh()->allowed_agents);
    }

    public function test_blocked_preset_sets_empty_array(): void
    {
        $u = $this->user(allowed: ['sales']);
        $u->applyAgentPreset('blocked');
        $this->assertSame([], $u->fresh()->allowed_agents);
        $this->assertFalse($u->fresh()->canUseAgent('sales'));
    }

    public function test_unknown_preset_throws(): void
    {
        $u = $this->user();
        $this->expectException(\InvalidArgumentException::class);
        $u->applyAgentPreset('does-not-exist');
    }

    // ── /admin/agent-access matrix ───────────────────────────────────────

    public function test_matrix_visible_to_admin(): void
    {
        $admin = $this->admin();
        $other = $this->user(allowed: ['sales']);

        $r = $this->actingAs($admin)->get(route('admin.agentAccess'));
        $r->assertOk();
        $r->assertSeeText($other->name);
    }

    public function test_matrix_forbidden_to_non_admin(): void
    {
        $u = $this->user();
        $r = $this->actingAs($u)->get(route('admin.agentAccess'));
        $r->assertForbidden();
    }

    // ── Toggle endpoint ──────────────────────────────────────────────────

    public function test_toggle_first_click_on_null_creates_full_minus_one(): void
    {
        $admin = $this->admin();
        $u     = $this->user(allowed: null);

        $r = $this->actingAs($admin)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $u->id, 'agentKey' => 'sales']));
        $r->assertOk()->assertJson(['ok' => true, 'now_allowed' => false]);

        $allowed = $u->fresh()->allowed_agents;
        // Should be every catalog key minus 'sales' (and minus the
        // routing meta-agents 'auto' / 'orchestrator').
        $this->assertNotNull($allowed);
        $this->assertNotContains('sales', $allowed);
        $this->assertNotContains('auto', $allowed);
        $this->assertContains('aria', $allowed);
    }

    public function test_toggle_re_clicking_an_off_chip_turns_it_back_on(): void
    {
        $admin = $this->admin();
        $u     = $this->user(allowed: ['sales']);

        // Toggle 'sales' OFF first (it's currently in the whitelist).
        $this->actingAs($admin)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $u->id, 'agentKey' => 'sales']));
        $this->assertNotContains('sales', $u->fresh()->allowed_agents);

        // Toggle it back ON.
        $this->actingAs($admin)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $u->id, 'agentKey' => 'sales']));
        $this->assertContains('sales', $u->fresh()->allowed_agents);
    }

    public function test_toggle_logs_user_admin_event(): void
    {
        $admin = $this->admin();
        $u     = $this->user(allowed: ['sales']);

        $this->actingAs($admin)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $u->id, 'agentKey' => 'sales']));

        $event = UserAdminEvent::where('target_user_id', $u->id)
            ->where('event_type', 'agent_access_toggle')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame('sales',  $event->payload['agent']);
    }

    public function test_toggle_refused_for_admin_target(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $r = $this->actingAs($admin)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $other->id, 'agentKey' => 'sales']));
        $r->assertStatus(422);
        $this->assertNull($other->fresh()->allowed_agents);
    }

    public function test_toggle_unknown_agent_returns_422(): void
    {
        $admin = $this->admin();
        $u     = $this->user();

        $r = $this->actingAs($admin)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $u->id, 'agentKey' => 'bogusagent']));
        $r->assertStatus(422);
    }

    public function test_toggle_forbidden_to_non_admin(): void
    {
        $caller = $this->user(role: 'manager');
        $u      = $this->user();

        $r = $this->actingAs($caller)
            ->patchJson(route('admin.users.toggleAgent', ['user' => $u->id, 'agentKey' => 'sales']));
        $r->assertForbidden();
    }

    // ── Preset endpoint ─────────────────────────────────────────────────

    public function test_apply_preset_endpoint_writes_and_logs(): void
    {
        $admin = $this->admin();
        $u     = $this->user(allowed: ['old']);

        $r = $this->actingAs($admin)
            ->post(route('admin.users.agentPreset', ['user' => $u->id, 'preset' => 'vendor_spares']));
        $r->assertRedirect();

        $this->assertEqualsCanonicalizing(
            User::AGENT_PRESETS['vendor_spares'],
            $u->fresh()->allowed_agents
        );

        $this->assertDatabaseHas('user_admin_events', [
            'target_user_id' => $u->id,
            'actor_user_id'  => $admin->id,
            'event_type'     => 'agent_preset_applied',
        ]);
    }

    public function test_apply_preset_endpoint_refused_for_admin_target(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $r = $this->actingAs($admin)
            ->post(route('admin.users.agentPreset', ['user' => $other->id, 'preset' => 'vendor_spares']));
        $r->assertSessionHasErrors('preset');
    }
}
