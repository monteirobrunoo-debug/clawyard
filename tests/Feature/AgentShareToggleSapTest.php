<?php

namespace Tests\Feature;

use App\Models\AgentShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the per-share SAP access toggle added via
 * `PATCH /admin/shares/{share}/toggle-sap`.
 *
 * User request: "quero ter a possibilidade de pôr um pisco no user para
 * aceder ao SAP". Before this endpoint the field was write-once at
 * create time — these tests guarantee:
 *
 *   1. The owner can flip it both ways.
 *   2. Each call is persisted and returned as JSON for the front-end
 *      to update the chip colour/label.
 *   3. A different authenticated user cannot flip someone else's share.
 *   4. An anonymous request is bounced to login, not executed.
 *
 * All tests use an in-memory SQLite DB (see phpunit.xml), so the whole
 * suite runs offline with no side effects on production data.
 */
class AgentShareToggleSapTest extends TestCase
{
    use RefreshDatabase;

    private function makeShare(User $owner, bool $sap = false): AgentShare
    {
        return AgentShare::create([
            'token'            => AgentShare::generateToken(),
            'agent_key'        => 'sap',
            'client_name'      => 'Cliente Teste',
            'client_email'     => 'cliente@example.com',
            'allow_sap_access' => $sap,
            'is_active'        => true,
            'expires_at'       => now()->addDay(),
            'created_by'       => $owner->id,
        ]);
    }

    public function test_owner_can_flip_sap_access_on_and_off(): void
    {
        $owner = User::factory()->create();
        $share = $this->makeShare($owner, false);

        // OFF → ON
        $r1 = $this->actingAs($owner)
            ->patch("/admin/shares/{$share->id}/toggle-sap");
        $r1->assertOk()->assertJson(['ok' => true, 'allow_sap_access' => true]);
        $this->assertTrue($share->fresh()->allow_sap_access);

        // ON → OFF (idempotent from the caller's POV — same endpoint toggles)
        $r2 = $this->actingAs($owner)
            ->patch("/admin/shares/{$share->id}/toggle-sap");
        $r2->assertOk()->assertJson(['ok' => true, 'allow_sap_access' => false]);
        $this->assertFalse($share->fresh()->allow_sap_access);
    }

    public function test_non_owner_non_admin_gets_403(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $share    = $this->makeShare($owner, false);

        $r = $this->actingAs($stranger)
            ->patch("/admin/shares/{$share->id}/toggle-sap");
        $r->assertForbidden();

        // Value on DB must not have moved.
        $this->assertFalse($share->fresh()->allow_sap_access);
    }

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $owner = User::factory()->create();
        $share = $this->makeShare($owner, true);

        $r = $this->patch("/admin/shares/{$share->id}/toggle-sap");
        // Laravel auth middleware redirects (302) to /login by default.
        $r->assertStatus(302);

        // Still ON — no state change.
        $this->assertTrue($share->fresh()->allow_sap_access);
    }

    public function test_route_is_registered_under_expected_name(): void
    {
        // Smoke check: the view builds its onclick URL from this route
        // via route(). If the name changes, the button breaks silently.
        $owner = User::factory()->create();
        $share = $this->makeShare($owner, false);

        $url = route('shares.toggleSap', $share);
        $this->assertStringContainsString("/admin/shares/{$share->id}/toggle-sap", $url);
    }
}
