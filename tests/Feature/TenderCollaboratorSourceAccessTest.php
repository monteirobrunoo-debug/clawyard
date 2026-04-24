<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the per-collaborator source whitelist feature:
 *
 *   PATCH /tenders/collaborators/{id}/toggle-source/{source}
 *   Tender::scopeForUser(userId)   respects allowed_sources
 *
 * User request (2026-04-24): "para cada utilizador no dashboard dos
 * concursos tem de ter autorização para ver concursos NSPA, Acingov, SAM".
 * Admins click chips on /tenders/collaborators to block sources per user.
 *
 * Semantics we lock down here:
 *   - NULL         → no filter (see every source, legacy default)
 *   - []           → blocked from every source
 *   - [... keys …] → whitelist
 *
 *   First toggle on NULL materialises the whitelist as
 *   `Tender::SOURCES - [clicked]` (click NSPA once → block NSPA, not
 *   everything else). When the whitelist grows back to include every
 *   source, we collapse it to NULL so the UI has a clean "no filter"
 *   state to return to.
 */
class TenderCollaboratorSourceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    private function regularUser(): User
    {
        return User::factory()->create(['role' => 'user', 'is_active' => true]);
    }

    /**
     * Collaborator linked to a User (so scopeForUser can find it) with
     * a configurable allowed_sources value. Email matches the user's so
     * the scope's email-fallback path also resolves it.
     */
    private function collaboratorFor(User $user, ?array $allowedSources = null): TenderCollaborator
    {
        $c = new TenderCollaborator();
        $c->name            = $user->name;
        $c->normalized_name = TenderCollaborator::normalize($user->name);
        $c->email           = $user->email;  // saving hook auto-links to user_id
        $c->is_active       = true;
        $c->allowed_sources = $allowedSources;
        $c->save();
        return $c;
    }

    private function tender(string $source, int $collabId, ?string $ref = null): Tender
    {
        return Tender::create([
            'source'                   => $source,
            'reference'                => $ref ?? 'REF-'.$source.'-'.$collabId.'-'.uniqid(),
            'title'                    => "Concurso {$source}",
            'status'                   => Tender::STATUS_PENDING,
            'assigned_collaborator_id' => $collabId,
        ]);
    }

    // ── toggleSource endpoint ─────────────────────────────────────────────

    public function test_first_toggle_on_null_materialises_as_full_set_minus_clicked(): void
    {
        $mgr = $this->manager();
        $c   = $this->collaboratorFor($this->regularUser(), allowedSources: null);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, 'nspa']));

        $r->assertOk()->assertJson(['ok' => true, 'has_source' => false, 'mode' => 'whitelist']);

        // Result should be every source EXCEPT nspa. Array content compared
        // as a set — the controller uses array_values, order is an
        // implementation detail we don't want to pin.
        $expected = array_values(array_diff(Tender::SOURCES, ['nspa']));
        $this->assertEqualsCanonicalizing($expected, $c->fresh()->allowed_sources);
    }

    public function test_toggle_removes_source_already_in_whitelist(): void
    {
        $mgr = $this->manager();
        $c   = $this->collaboratorFor($this->regularUser(), allowedSources: ['nspa', 'acingov', 'sam_gov']);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, 'acingov']));

        $r->assertOk()->assertJson(['ok' => true, 'has_source' => false, 'mode' => 'whitelist']);
        $this->assertEqualsCanonicalizing(['nspa', 'sam_gov'], $c->fresh()->allowed_sources);
    }

    public function test_toggle_adds_source_not_yet_in_whitelist(): void
    {
        $mgr = $this->manager();
        $c   = $this->collaboratorFor($this->regularUser(), allowedSources: ['nspa']);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, 'acingov']));

        $r->assertOk()->assertJson(['ok' => true, 'has_source' => true, 'mode' => 'whitelist']);
        $this->assertEqualsCanonicalizing(['nspa', 'acingov'], $c->fresh()->allowed_sources);
    }

    public function test_toggle_that_restores_every_source_collapses_to_null(): void
    {
        // Start one source short of the full set — toggling the missing
        // one back in should collapse the whole array to NULL (the
        // "no restriction" sentinel) instead of carrying a 9-element
        // whitelist that means the same thing.
        $mgr     = $this->manager();
        $missing = 'other';
        $full    = array_values(array_diff(Tender::SOURCES, [$missing]));
        $c       = $this->collaboratorFor($this->regularUser(), allowedSources: $full);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, $missing]));

        $r->assertOk()->assertJson([
            'ok'              => true,
            'allowed_sources' => null,
            'has_source'      => true,
            'mode'            => 'unrestricted',
        ]);
        $this->assertNull($c->fresh()->allowed_sources);
    }

    public function test_toggle_that_removes_last_source_leaves_blocked_all(): void
    {
        // Whitelist with a single entry → toggling that entry off should
        // leave [] (explicit "blocked from every source"), NOT collapse
        // back to NULL (which would mean the opposite).
        $mgr = $this->manager();
        $c   = $this->collaboratorFor($this->regularUser(), allowedSources: ['nspa']);

        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, 'nspa']));

        $r->assertOk()->assertJson(['ok' => true, 'has_source' => false, 'mode' => 'blocked_all']);
        $this->assertSame([], $c->fresh()->allowed_sources);
    }

    public function test_invalid_source_returns_422(): void
    {
        $mgr = $this->manager();
        $c   = $this->collaboratorFor($this->regularUser());

        // `bogus` isn't in Tender::SOURCES. The route regex `[a-z_]+`
        // accepts it, so the controller is the layer that rejects.
        $r = $this->actingAs($mgr)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, 'bogus']));

        $r->assertStatus(422)->assertJson(['ok' => false]);
        // Nothing persisted.
        $this->assertNull($c->fresh()->allowed_sources);
    }

    public function test_regular_user_cannot_toggle_source(): void
    {
        $user = $this->regularUser();
        $c    = $this->collaboratorFor($user);

        $r = $this->actingAs($user)
            ->patchJson(route('tenders.collaborators.toggle_source', [$c, 'nspa']));

        $r->assertForbidden();
        $this->assertNull($c->fresh()->allowed_sources);
    }

    // ── Tender::scopeForUser respects allowed_sources ────────────────────

    public function test_scope_for_user_with_null_sees_every_source(): void
    {
        $user = $this->regularUser();
        $c    = $this->collaboratorFor($user, allowedSources: null);

        $this->tender('nspa',    $c->id);
        $this->tender('acingov', $c->id);
        $this->tender('sam_gov', $c->id);

        $sources = Tender::query()->forUser($user->id)->pluck('source')->sort()->values()->all();
        $this->assertSame(['acingov', 'nspa', 'sam_gov'], $sources);
    }

    public function test_scope_for_user_with_whitelist_filters_by_source(): void
    {
        $user = $this->regularUser();
        $c    = $this->collaboratorFor($user, allowedSources: ['nspa']);

        $this->tender('nspa',    $c->id);
        $this->tender('acingov', $c->id);
        $this->tender('sam_gov', $c->id);

        $sources = Tender::query()->forUser($user->id)->pluck('source')->all();
        $this->assertSame(['nspa'], $sources);
    }

    public function test_scope_for_user_with_empty_array_sees_nothing(): void
    {
        $user = $this->regularUser();
        $c    = $this->collaboratorFor($user, allowedSources: []);

        $this->tender('nspa',    $c->id);
        $this->tender('acingov', $c->id);

        $this->assertSame(0, Tender::query()->forUser($user->id)->count());
    }

    public function test_scope_for_user_with_no_collaborator_row_sees_nothing(): void
    {
        // Control: a user with no collaborator row at all → scope returns
        // empty (the 1=0 branch), proving the filtering doesn't accidentally
        // match by something else.
        $user = $this->regularUser();

        // Create tenders tied to a DIFFERENT collaborator so they exist in
        // the table but aren't theirs.
        $other = new TenderCollaborator();
        $other->name            = 'Outro';
        $other->normalized_name = 'outro';
        $other->is_active       = true;
        $other->save();
        $this->tender('nspa', $other->id);

        $this->assertSame(0, Tender::query()->forUser($user->id)->count());
    }

    public function test_scope_union_across_two_collaborator_rows_with_different_whitelists(): void
    {
        // Same user, two collaborator rows (rare but allowed by the schema —
        // e.g. "José Silva" and "Jose Silva" both pointing to the same
        // email). Their whitelists should UNION so the user sees tenders
        // from either row's allowed sources.
        $user = $this->regularUser();

        $a = $this->collaboratorFor($user, allowedSources: ['nspa']);
        $b = new TenderCollaborator();
        $b->name            = $user->name.' (alt)';
        $b->normalized_name = TenderCollaborator::normalize($b->name);
        $b->email           = $user->email;
        $b->user_id         = $user->id;
        $b->is_active       = true;
        $b->allowed_sources = ['acingov'];
        $b->save();

        $this->tender('nspa',    $a->id);
        $this->tender('acingov', $b->id);
        $this->tender('sam_gov', $a->id);   // neither whitelist includes this

        $sources = Tender::query()->forUser($user->id)->pluck('source')->sort()->values()->all();
        $this->assertSame(['acingov', 'nspa'], $sources);
    }

    public function test_scope_permissive_when_one_row_has_null_allowed_sources(): void
    {
        // If ANY of the user's collaborator rows has NULL (no restriction),
        // the whole union goes permissive — a single unrestricted link
        // overrides the other row's whitelist. This matches the expectation
        // that NULL means "see everything".
        $user = $this->regularUser();

        $restricted = $this->collaboratorFor($user, allowedSources: ['nspa']);
        $open = new TenderCollaborator();
        $open->name            = $user->name.' (open)';
        $open->normalized_name = TenderCollaborator::normalize($open->name);
        $open->email           = $user->email;
        $open->user_id         = $user->id;
        $open->is_active       = true;
        $open->allowed_sources = null;
        $open->save();

        $this->tender('nspa',    $restricted->id);
        $this->tender('acingov', $restricted->id);
        $this->tender('sam_gov', $open->id);

        $sources = Tender::query()->forUser($user->id)->pluck('source')->sort()->values()->all();
        $this->assertSame(['acingov', 'nspa', 'sam_gov'], $sources);
    }

    // ── Model helper ──────────────────────────────────────────────────────

    public function test_can_see_source_helper(): void
    {
        $c = new TenderCollaborator();

        $c->allowed_sources = null;
        $this->assertTrue($c->canSeeSource('nspa'));
        $this->assertTrue($c->canSeeSource('acingov'));

        $c->allowed_sources = [];
        $this->assertFalse($c->canSeeSource('nspa'));

        $c->allowed_sources = ['nspa', 'sam_gov'];
        $this->assertTrue($c->canSeeSource('nspa'));
        $this->assertTrue($c->canSeeSource('sam_gov'));
        $this->assertFalse($c->canSeeSource('acingov'));
    }
}
