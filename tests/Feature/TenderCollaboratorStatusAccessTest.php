<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-collaborator STATUS whitelist — mirror of allowed_sources but
 * applied to Tender::status. Same NULL/[]/array semantics.
 *
 *   PATCH /tenders/collaborators/{id}/toggle-status/{status}
 *   Tender::scopeForUser(userId)   honours allowed_statuses
 *
 * Locked behaviour:
 *   • NULL → no filter (sees every status)
 *   • []   → blocked from every status
 *   • whitelist → only those statuses
 *   • First click on a NULL row materialises full-enum-minus-clicked
 *   • Whitelist that contains every status collapses back to NULL
 *   • Manager-only endpoint
 */
class TenderCollaboratorStatusAccessTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    private function collaboratorFor(User $u, ?array $statuses = null): TenderCollaborator
    {
        $c = new TenderCollaborator();
        $c->name = $u->name; $c->normalized_name = TenderCollaborator::normalize($u->name);
        $c->email = $u->email; $c->is_active = true;
        $c->allowed_statuses = $statuses;
        $c->save();
        return $c;
    }

    private function tender(string $status, int $collabId): Tender
    {
        return Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'REF-'.$status.'-'.$collabId.'-'.uniqid(),
            'title'                    => "Concurso {$status}",
            'status'                   => $status,
            'assigned_collaborator_id' => $collabId,
        ]);
    }

    public function test_first_toggle_on_null_materialises_as_full_set_minus_clicked(): void
    {
        $mgr = $this->manager();
        $u   = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c   = $this->collaboratorFor($u);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_status', [$c, Tender::STATUS_PENDING]));
        $r->assertOk()->assertJson(['ok' => true, 'has_status' => false, 'mode' => 'whitelist']);

        $allStatuses = array_values(array_unique(array_merge(Tender::ACTIVE_STATUSES, Tender::TERMINAL_STATUSES)));
        $expected = array_values(array_diff($allStatuses, [Tender::STATUS_PENDING]));
        $this->assertEqualsCanonicalizing($expected, $c->fresh()->allowed_statuses);
    }

    public function test_whitelist_with_every_status_collapses_to_null(): void
    {
        $mgr = $this->manager();
        $u   = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $allStatuses = array_values(array_unique(array_merge(Tender::ACTIVE_STATUSES, Tender::TERMINAL_STATUSES)));
        $missing = Tender::STATUS_GANHO;
        $c = $this->collaboratorFor($u, statuses: array_values(array_diff($allStatuses, [$missing])));

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_status', [$c, $missing]));
        $r->assertOk()->assertJson(['mode' => 'unrestricted']);
        $this->assertNull($c->fresh()->allowed_statuses);
    }

    public function test_invalid_status_returns_422(): void
    {
        $mgr = $this->manager();
        $u   = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c   = $this->collaboratorFor($u);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_status', [$c, 'bogus']));
        $r->assertStatus(422);
    }

    public function test_regular_user_cannot_toggle_status(): void
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = $this->collaboratorFor($u);

        $r = $this->actingAs($u)
            ->patchJson(route('tenders.collaborators.toggle_status', [$c, Tender::STATUS_PENDING]));
        $r->assertForbidden();
    }

    // ── Scope honours allowed_statuses ───────────────────────────────────

    public function test_scope_for_user_filters_by_allowed_statuses(): void
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = $this->collaboratorFor($u, statuses: [Tender::STATUS_EM_TRATAMENTO]);

        $this->tender(Tender::STATUS_PENDING, $c->id);
        $this->tender(Tender::STATUS_EM_TRATAMENTO, $c->id);
        $this->tender(Tender::STATUS_SUBMETIDO, $c->id);

        $statuses = Tender::query()->forUser($u->id)->pluck('status')->all();
        $this->assertSame([Tender::STATUS_EM_TRATAMENTO], $statuses);
    }

    public function test_scope_for_user_with_empty_allowed_statuses_sees_nothing(): void
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = $this->collaboratorFor($u, statuses: []);

        $this->tender(Tender::STATUS_PENDING, $c->id);
        $this->tender(Tender::STATUS_EM_TRATAMENTO, $c->id);

        $this->assertSame(0, Tender::query()->forUser($u->id)->count());
    }

    public function test_scope_for_user_with_null_allowed_statuses_sees_everything(): void
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $c = $this->collaboratorFor($u, statuses: null);

        $this->tender(Tender::STATUS_PENDING, $c->id);
        $this->tender(Tender::STATUS_GANHO, $c->id);
        $this->tender(Tender::STATUS_PERDIDO, $c->id);

        $this->assertSame(3, Tender::query()->forUser($u->id)->count());
    }
}
