<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the Catarina/Monica leak (reported 2026-04-24):
 * a freshly-onboarded user (catarina.sequeira) was opening /tenders and
 * seeing another user's (monica.pereira) dashboard.
 *
 * Root cause was in Tender::scopeForUser, which previously matched
 * collaborator rows with `WHERE user_id = ? OR email = ?`. If ANY
 * collaborator row owned by user X happened to carry user Y's email
 * (data-entry typo, Outlook distribution list, manual edit), user Y
 * inherited X's tender list on top of their own — silently.
 *
 * The fix isolates the two paths:
 *
 *   1. Strict — every row with `user_id = $userId`.
 *   2. Fallback (only when 1 returns nothing) — rows with
 *      `user_id IS NULL AND LOWER(email) = LOWER($userEmail)`.
 *
 * These tests lock that contract so the OR-form can't sneak back in.
 */
class TenderScopeForUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build the exact bug shape:
     *   - Monica: user with linked collaborator + 1 tender.
     *   - Catarina: user with NO collaborator row of her own, AND a
     *     phantom Monica-owned collaborator row carrying Catarina's
     *     email (the data corruption that caused the leak).
     */
    public function test_user_does_not_inherit_tenders_via_phantom_email_match_on_other_users_collaborator(): void
    {
        $monica   = User::factory()->create(['role' => 'user', 'is_active' => true, 'email' => 'monica.pereira@hp-group.org']);
        $catarina = User::factory()->create(['role' => 'user', 'is_active' => true, 'email' => 'catarina.sequeira@hp-group.org']);

        // Monica's legitimate collaborator row + 1 tender.
        $monicaCollab = new TenderCollaborator();
        $monicaCollab->name            = $monica->name;
        $monicaCollab->normalized_name = TenderCollaborator::normalize($monica->name);
        $monicaCollab->email           = $monica->email;   // saving hook → user_id = monica.id
        $monicaCollab->is_active       = true;
        $monicaCollab->save();
        $this->assertSame($monica->id, $monicaCollab->fresh()->user_id);

        Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'MONICA-001',
            'title'                    => 'Concurso da Monica',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $monicaCollab->id,
        ]);

        // Phantom corruption: a SECOND collaborator row, still owned by
        // Monica (user_id = monica.id), but somehow carrying Catarina's
        // email — exactly what we observed in production.
        // We bypass the saving hook by raw-inserting because the hook
        // would auto-relink to Catarina; the bug is precisely that a
        // legacy row exists where the email is wrong but user_id wasn't
        // re-checked.
        TenderCollaborator::query()->insert([
            'name'            => 'Mónica Pereira (alias)',
            'normalized_name' => 'monica pereira (alias)',
            'user_id'         => $monica->id,
            'email'           => $catarina->email,    // <-- the corruption
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Catarina opens /tenders → must see ZERO. The OR-form would have
        // matched the phantom row by email and shown her Monica's tender.
        $forCatarina = Tender::query()->forUser($catarina->id)->get();
        $this->assertCount(0, $forCatarina, 'Catarina should not inherit tenders via a phantom email on someone else\'s collaborator row');

        // Sanity: Monica still sees her own tender (the fix isolates Catarina's
        // path without affecting Monica's strict user_id link).
        $forMonica = Tender::query()->forUser($monica->id)->get();
        $this->assertCount(1, $forMonica);
        $this->assertSame('MONICA-001', $forMonica->first()->reference);
    }

    public function test_email_fallback_only_fires_when_strict_match_is_empty(): void
    {
        $user = User::factory()->create(['role' => 'user', 'is_active' => true, 'email' => 'mark@example.com']);

        // Strict link: a collaborator with user_id = $user->id and a single tender.
        $strict = new TenderCollaborator();
        $strict->name            = $user->name;
        $strict->normalized_name = TenderCollaborator::normalize($user->name);
        $strict->email           = $user->email;
        $strict->is_active       = true;
        $strict->save();
        $this->assertSame($user->id, $strict->fresh()->user_id);

        Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'STRICT-001',
            'title'                    => 'Strict-linked tender',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $strict->id,
        ]);

        // Orphan-by-email row that ALSO matches the email and has its own tender.
        // Because the strict path returns rows for this user, the fallback path
        // must not fire — this row's tender stays out.
        TenderCollaborator::query()->insert([
            'name'            => 'Mark (orphan import)',
            'normalized_name' => 'mark (orphan import)',
            'user_id'         => null,
            'email'           => $user->email,
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        $orphan = TenderCollaborator::where('name', 'Mark (orphan import)')->first();

        Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'ORPHAN-001',
            'title'                    => 'Tender on orphan-by-email row',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $orphan->id,
        ]);

        $refs = Tender::query()->forUser($user->id)->pluck('reference')->all();

        // Only the strict-linked tender should appear; the orphan-by-email row
        // is invisible while a strict link exists.
        $this->assertSame(['STRICT-001'], $refs);
    }

    public function test_email_fallback_runs_when_no_strict_link_exists(): void
    {
        // Legacy case: user account exists, collaborator row exists with the
        // same email but user_id was never backfilled. The fallback path
        // must still pick it up so we don't break onboarding.
        $user = User::factory()->create(['role' => 'user', 'is_active' => true, 'email' => 'newhire@example.com']);

        TenderCollaborator::query()->insert([
            'name'            => 'New Hire',
            'normalized_name' => 'new hire',
            'user_id'         => null,
            'email'           => 'NEWHIRE@example.com',   // mixed case on purpose
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        $orphan = TenderCollaborator::where('normalized_name', 'new hire')->first();

        Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'FALLBACK-001',
            'title'                    => 'Tender via email fallback',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $orphan->id,
        ]);

        $refs = Tender::query()->forUser($user->id)->pluck('reference')->all();
        $this->assertSame(['FALLBACK-001'], $refs);
    }

    public function test_email_fallback_is_case_and_whitespace_insensitive(): void
    {
        // The user's email has trailing whitespace and lowercase; the
        // collaborator row has uppercase + leading whitespace. They must
        // still match — pre-fix the bare `=` comparison missed this.
        $user = User::factory()->create(['role' => 'user', 'is_active' => true, 'email' => 'casey@example.com']);

        TenderCollaborator::query()->insert([
            'name'            => 'Casey',
            'normalized_name' => 'casey',
            'user_id'         => null,
            'email'           => '  CASEY@Example.COM  ',
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        $orphan = TenderCollaborator::where('normalized_name', 'casey')->first();

        Tender::create([
            'source'                   => 'nspa',
            'reference'                => 'CASEY-001',
            'title'                    => 'Casey tender',
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $orphan->id,
        ]);

        $this->assertSame(['CASEY-001'], Tender::query()->forUser($user->id)->pluck('reference')->all());
    }
}
