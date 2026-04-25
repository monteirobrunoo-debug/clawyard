<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk source-restriction admin endpoint:
 *
 *   POST /tenders/collaborators/bulk-sources
 *     { action: 'set'|'add'|'remove', sources: [...] }
 *
 * Pinned semantics:
 *   • set    → replaces each row's allowed_sources (collapses to NULL
 *              when payload covers every source).
 *   • add    → unions payload into existing whitelist; rows with
 *              allowed_sources=NULL stay NULL (already see all).
 *   • remove → diffs payload out; NULL materialises to full-minus-
 *              payload (same first-click rule as toggleSource).
 *   • Inactive rows are NOT touched (active() scope).
 *   • Manager-only (gate enforced via HasMiddleware on the controller).
 */
class TenderCollaboratorBulkSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    private function collab(string $name, ?array $allowed = null, bool $active = true): TenderCollaborator
    {
        $c = new TenderCollaborator();
        $c->name = $name;
        $c->normalized_name = TenderCollaborator::normalize($name);
        $c->is_active = $active;
        $c->allowed_sources = $allowed;
        $c->save();
        return $c;
    }

    public function test_set_replaces_whitelist_on_every_active_row(): void
    {
        $mgr = $this->manager();
        $a = $this->collab('A', allowed: null);
        $b = $this->collab('B', allowed: ['nspa', 'acingov']);
        $c = $this->collab('C', allowed: []);
        $inactive = $this->collab('Inactive', allowed: null, active: false);

        $r = $this->actingAs($mgr)->postJson(route('tenders.collaborators.bulk_sources'), [
            'action'  => 'set',
            'sources' => ['nspa'],
        ]);
        $r->assertOk()->assertJson(['ok' => true, 'action' => 'set', 'touched' => 3]);

        $this->assertSame(['nspa'], $a->fresh()->allowed_sources);
        $this->assertSame(['nspa'], $b->fresh()->allowed_sources);
        $this->assertSame(['nspa'], $c->fresh()->allowed_sources);
        $this->assertNull($inactive->fresh()->allowed_sources, 'inactive must not be touched');
    }

    public function test_set_collapses_to_null_when_payload_covers_every_source(): void
    {
        $mgr = $this->manager();
        $row = $this->collab('Row', allowed: ['nspa']);

        $r = $this->actingAs($mgr)->postJson(route('tenders.collaborators.bulk_sources'), [
            'action'  => 'set',
            'sources' => Tender::SOURCES,
        ]);
        $r->assertOk();
        $this->assertNull($row->fresh()->allowed_sources, 'full set must collapse to NULL sentinel');
    }

    public function test_add_unions_payload_and_skips_rows_with_null(): void
    {
        $mgr = $this->manager();
        $unrestricted = $this->collab('Unrestricted', allowed: null);
        $partial      = $this->collab('Partial', allowed: ['nspa']);

        $r = $this->actingAs($mgr)->postJson(route('tenders.collaborators.bulk_sources'), [
            'action'  => 'add',
            'sources' => ['acingov'],
        ]);
        $r->assertOk();

        $this->assertNull($unrestricted->fresh()->allowed_sources, 'NULL row stays NULL on add');
        $this->assertEqualsCanonicalizing(['nspa', 'acingov'], $partial->fresh()->allowed_sources);
    }

    public function test_remove_materialises_null_rows_as_full_minus_payload(): void
    {
        $mgr = $this->manager();
        $unrestricted = $this->collab('Unrestricted', allowed: null);
        $whitelisted  = $this->collab('Whitelisted', allowed: ['nspa', 'acingov']);

        $r = $this->actingAs($mgr)->postJson(route('tenders.collaborators.bulk_sources'), [
            'action'  => 'remove',
            'sources' => ['acingov'],
        ]);
        $r->assertOk();

        // NULL row materialises as Tender::SOURCES minus acingov.
        $expectedAll = array_values(array_diff(Tender::SOURCES, ['acingov']));
        $this->assertEqualsCanonicalizing($expectedAll, $unrestricted->fresh()->allowed_sources);

        // Whitelisted row simply loses acingov.
        $this->assertEqualsCanonicalizing(['nspa'], $whitelisted->fresh()->allowed_sources);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $mgr = $this->manager();
        $this->collab('Row');

        $r = $this->actingAs($mgr)->postJson(route('tenders.collaborators.bulk_sources'), [
            'action'  => 'set',
            'sources' => ['bogus'],
        ]);
        $r->assertStatus(422);
    }

    public function test_regular_user_cannot_call_bulk_endpoint(): void
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->collab('Row', allowed: null);

        $r = $this->actingAs($u)->postJson(route('tenders.collaborators.bulk_sources'), [
            'action'  => 'set',
            'sources' => ['nspa'],
        ]);
        $r->assertForbidden();
    }
}
