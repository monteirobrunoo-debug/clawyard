<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /tenders/collaborators/{from}/merge/{into}
 *
 * Pinned behaviour:
 *   • Tenders move from `from` → `into` in a single transaction.
 *   • `into.aliases` gets `from.normalized_name` appended (idempotent).
 *   • `from` is deactivated (history preserved, hidden from UI).
 *   • Manager-only via the existing HasMiddleware gate.
 *   • Self-merge refused with a flash error.
 *   • Pre-existing aliases on either side are merged into `into`.
 */
class TenderCollaboratorMergeTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    private function collab(string $name, ?array $aliases = null, bool $active = true): TenderCollaborator
    {
        return TenderCollaborator::create([
            'name'            => $name,
            'normalized_name' => TenderCollaborator::normalize($name),
            'aliases'         => $aliases,
            'is_active'       => $active,
        ]);
    }

    private function tenderOn(int $collabId): Tender
    {
        return Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'REF-'.uniqid(),
            'title'                    => 'T',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $collabId,
        ]);
    }

    public function test_merge_moves_tenders_writes_alias_and_deactivates_source(): void
    {
        $mgr  = $this->manager();
        $into = $this->collab('Monica Pereira');
        $from = $this->collab('Monica');

        // Two tenders on the orphan row.
        $t1 = $this->tenderOn($from->id);
        $t2 = $this->tenderOn($from->id);

        $r = $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        $r->assertRedirect(route('tenders.collaborators.index'));

        // Tenders moved.
        $this->assertSame($into->id, $t1->fresh()->assigned_collaborator_id);
        $this->assertSame($into->id, $t2->fresh()->assigned_collaborator_id);

        // Alias added.
        $this->assertContains('monica', $into->fresh()->aliases ?? []);

        // Source deactivated, history kept.
        $this->assertFalse($from->fresh()->is_active);
        $this->assertNotNull($from->fresh()->id);
    }

    public function test_merge_carries_existing_aliases_from_source_to_target(): void
    {
        $mgr  = $this->manager();
        $into = $this->collab('Monica Pereira', aliases: ['mp']);
        // The absorbed row already accumulated its own aliases via a
        // previous merge — those should also flow to the survivor.
        $from = $this->collab('Mónica P.', aliases: ['monica p.', 'mónica p.']);

        $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        $aliases = $into->fresh()->aliases ?? [];
        // All four should be present, no duplicates.
        $this->assertContains('mp', $aliases);
        $this->assertContains('monica p.', $aliases);
        $this->assertContains('mónica p.', $aliases);
        $this->assertCount(count(array_unique($aliases)), $aliases);
    }

    public function test_merge_is_idempotent_re_running_does_not_duplicate_alias(): void
    {
        $mgr  = $this->manager();
        $into = $this->collab('Monica Pereira');
        $from = $this->collab('Monica');

        // First merge.
        $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        // Reactivate `from` and merge again — alias must not duplicate.
        $from->is_active = true;
        $from->save();

        $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        $aliases = $into->fresh()->aliases ?? [];
        $this->assertSame(['monica'], $aliases,
            'Re-merging the same pair must not duplicate the alias entry');
    }

    public function test_self_merge_is_refused(): void
    {
        $mgr  = $this->manager();
        $row  = $this->collab('Monica');

        $r = $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $row, 'into' => $row]));

        $r->assertSessionHasErrors('merge');
        $this->assertTrue($row->fresh()->is_active, 'Row must not be deactivated by a refused self-merge');
    }

    public function test_merging_two_inactive_rows_is_refused(): void
    {
        $mgr  = $this->manager();
        $into = $this->collab('Monica Pereira', active: false);
        $from = $this->collab('Monica',         active: false);

        $r = $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        $r->assertSessionHasErrors('merge');
    }

    public function test_regular_user_cannot_merge(): void
    {
        $u    = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $into = $this->collab('Monica Pereira');
        $from = $this->collab('Monica');

        $r = $this->actingAs($u)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        $r->assertForbidden();
        $this->assertTrue($from->fresh()->is_active);
    }

    public function test_post_merge_findOrCreateByName_resolves_to_survivor_via_alias(): void
    {
        // The whole point: after merging, a fresh import that brings
        // in just "Monica" must land on the survivor row, not create
        // another duplicate.
        $mgr  = $this->manager();
        $into = $this->collab('Monica Pereira');
        $from = $this->collab('Monica');

        $this->actingAs($mgr)
            ->post(route('tenders.collaborators.merge', ['from' => $from, 'into' => $into]));

        $resolved = TenderCollaborator::findOrCreateByName('Monica');
        $this->assertSame($into->id, $resolved->id);
        // No new row created.
        $this->assertSame(2, TenderCollaborator::count());
    }
}
