<?php

namespace Tests\Feature;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /tenders dashboard exposes a `restriction` payload to the view
 * so a discreet banner can tell the user "you only see source X" or
 * "you are blocked from every source". Without this, a restricted
 * user sees a partial list and assumes the system is broken when an
 * expected tender doesn't appear.
 *
 * Locked behaviour:
 *   • Manager+ (canViewAll) never gets a restriction payload — they
 *     supervise everyone, the filter doesn't apply to their view.
 *   • Regular user with allowed_sources=NULL → no payload (no banner).
 *   • Regular user with allowed_sources=[whitelist] → mode=whitelist
 *     + the source list.
 *   • Regular user with allowed_sources=[] → mode=blocked_all.
 */
class TenderRestrictionBannerTest extends TestCase
{
    use RefreshDatabase;

    private function regularUser(?array $allowed = null): User
    {
        $u = User::factory()->create([
            'role'      => 'user',
            'is_active' => true,
        ]);

        $c = new TenderCollaborator();
        $c->name            = $u->name;
        $c->normalized_name = TenderCollaborator::normalize($u->name);
        $c->email           = $u->email;
        $c->is_active       = true;
        $c->allowed_sources = $allowed;
        $c->save();

        return $u;
    }

    public function test_manager_never_gets_restriction_payload(): void
    {
        $mgr = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        // Even if a collaborator row for the manager has an explicit
        // whitelist, the dashboard renders the unrestricted view.
        $c = new TenderCollaborator();
        $c->name            = $mgr->name;
        $c->normalized_name = TenderCollaborator::normalize($mgr->name);
        $c->email           = $mgr->email;
        $c->is_active       = true;
        $c->allowed_sources = ['nspa'];
        $c->save();

        $r = $this->actingAs($mgr)->get(route('tenders.index'));
        $r->assertOk();
        $r->assertViewHas('restriction', null);
    }

    public function test_unrestricted_user_gets_no_payload(): void
    {
        $u = $this->regularUser(allowed: null);

        $r = $this->actingAs($u)->get(route('tenders.index'));
        $r->assertOk();
        $r->assertViewHas('restriction', null);
    }

    public function test_user_with_whitelist_gets_payload_listing_sources(): void
    {
        $u = $this->regularUser(allowed: ['nspa', 'acingov']);

        $r = $this->actingAs($u)->get(route('tenders.index'));
        $r->assertOk();
        $r->assertViewHas('restriction', function ($payload) {
            return is_array($payload)
                && $payload['mode'] === 'whitelist'
                && [] === array_diff(['nspa', 'acingov'], $payload['sources'])
                && [] === array_diff($payload['sources'], ['nspa', 'acingov']);
        });
    }

    public function test_user_blocked_from_all_gets_blocked_all_payload(): void
    {
        $u = $this->regularUser(allowed: []);

        $r = $this->actingAs($u)->get(route('tenders.index'));
        $r->assertOk();
        $r->assertViewHas('restriction', function ($payload) {
            return is_array($payload)
                && $payload['mode'] === 'blocked_all'
                && $payload['sources'] === [];
        });
    }

    public function test_banner_renders_in_html_for_whitelist(): void
    {
        $u = $this->regularUser(allowed: ['nspa']);

        $r = $this->actingAs($u)->get(route('tenders.index'));
        $r->assertOk();
        $r->assertSee('Vês apenas concursos das fontes', false);
        $r->assertSee('NSPA', false);
        // The blocked-all variant must NOT render here.
        $r->assertDontSee('Estás bloqueado de todas as fontes', false);
    }

    public function test_banner_renders_in_html_for_blocked_all(): void
    {
        $u = $this->regularUser(allowed: []);

        $r = $this->actingAs($u)->get(route('tenders.index'));
        $r->assertOk();
        $r->assertSee('Estás bloqueado de todas as fontes', false);
        // The whitelist variant must NOT render here.
        $r->assertDontSee('Vês apenas concursos das fontes', false);
    }
}
