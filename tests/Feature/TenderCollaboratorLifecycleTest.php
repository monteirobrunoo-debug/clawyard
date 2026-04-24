<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the collaborator-roster lifecycle endpoints that the
 * /tenders/collaborators dashboard uses to exclude / re-include users:
 *
 *   DELETE  /tenders/collaborators/{id}          → soft deactivate
 *   POST    /tenders/collaborators/{id}/reactivate
 *   DELETE  /tenders/collaborators/{id}/force    → hard delete (guarded)
 *
 * User request (2026-04-24): "Tenho de ter a capacidade de excluir users
 * ou não do dashboard". Until this set existed, once a collaborator was
 * desactivated the manager had no way to bring them back and no way to
 * purge a genuine mistake (typo / duplicate). These tests lock the
 * contract so the next import refactor can't silently regress it.
 *
 * SQLite in-memory DB (see phpunit.xml) — offline, no production impact.
 */
class TenderCollaboratorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        // The tenders.collaborators gate requires isManager() (admin|manager).
        return User::factory()->create(['role' => 'manager']);
    }

    private function regularUser(): User
    {
        return User::factory()->create(['role' => 'user']);
    }

    private function collaborator(bool $active = true): TenderCollaborator
    {
        $c = new TenderCollaborator();
        $c->name            = 'Teste Colaborador';
        $c->normalized_name = TenderCollaborator::normalize('Teste Colaborador');
        $c->email           = null;   // skip auto-link to a User
        $c->is_active       = $active;
        $c->save();
        return $c;
    }

    private function assignTender(TenderCollaborator $c): Tender
    {
        // Minimum viable row that satisfies the tenders schema (source,
        // reference, title) and ties it to this collaborator so the hard-
        // delete guard trips.
        return Tender::create([
            'source'                   => 'other',
            'reference'                => 'REF-TEST-'.$c->id,
            'title'                    => 'Concurso para teste',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $c->id,
        ]);
    }

    // ── Reactivate ────────────────────────────────────────────────────────

    public function test_manager_can_reactivate_an_inactive_collaborator(): void
    {
        $mgr  = $this->manager();
        $c    = $this->collaborator(active: false);

        $r = $this->actingAs($mgr)
            ->post(route('tenders.collaborators.reactivate', $c));

        $r->assertRedirect(route('tenders.collaborators.index'));
        $this->assertTrue($c->fresh()->is_active);
    }

    public function test_reactivate_is_idempotent_on_already_active(): void
    {
        $mgr = $this->manager();
        $c   = $this->collaborator(active: true);

        $r = $this->actingAs($mgr)
            ->post(route('tenders.collaborators.reactivate', $c));

        // No error — just a notice. Row stays active.
        $r->assertRedirect();
        $this->assertTrue($c->fresh()->is_active);
    }

    public function test_regular_user_cannot_reactivate(): void
    {
        $user = $this->regularUser();
        $c    = $this->collaborator(active: false);

        $r = $this->actingAs($user)
            ->post(route('tenders.collaborators.reactivate', $c));

        $r->assertForbidden();
        $this->assertFalse($c->fresh()->is_active);
    }

    // ── Hard delete ───────────────────────────────────────────────────────

    public function test_manager_can_hard_delete_collaborator_without_history(): void
    {
        $mgr = $this->manager();
        $c   = $this->collaborator(active: false);
        $id  = $c->id;

        $r = $this->actingAs($mgr)
            ->delete(route('tenders.collaborators.force_destroy', $c));

        $r->assertRedirect(route('tenders.collaborators.index'));
        $this->assertDatabaseMissing('tender_collaborators', ['id' => $id]);
    }

    public function test_hard_delete_is_blocked_when_tender_history_exists(): void
    {
        $mgr    = $this->manager();
        $c      = $this->collaborator(active: false);
        $tender = $this->assignTender($c);
        $id     = $c->id;

        $r = $this->actingAs($mgr)
            ->from(route('tenders.collaborators.index'))
            ->delete(route('tenders.collaborators.force_destroy', $c));

        // Guard trips — row must still exist AND the tender must still
        // point at it (the FK's nullOnDelete would silently erase the link
        // if the delete had gone through).
        $r->assertRedirect(route('tenders.collaborators.index'));
        $r->assertSessionHasErrors('tenders');
        $this->assertDatabaseHas('tender_collaborators', ['id' => $id]);
        $this->assertSame($id, $tender->fresh()->assigned_collaborator_id);
    }

    public function test_regular_user_cannot_hard_delete(): void
    {
        $user = $this->regularUser();
        $c    = $this->collaborator(active: false);
        $id   = $c->id;

        $r = $this->actingAs($user)
            ->delete(route('tenders.collaborators.force_destroy', $c));

        $r->assertForbidden();
        $this->assertDatabaseHas('tender_collaborators', ['id' => $id]);
    }

    // ── Route names wired for the view ───────────────────────────────────

    public function test_routes_resolve_to_expected_urls(): void
    {
        $c = $this->collaborator();

        $this->assertStringContainsString(
            "/tenders/collaborators/{$c->id}/reactivate",
            route('tenders.collaborators.reactivate', $c)
        );
        $this->assertStringContainsString(
            "/tenders/collaborators/{$c->id}/force",
            route('tenders.collaborators.force_destroy', $c)
        );
    }
}
