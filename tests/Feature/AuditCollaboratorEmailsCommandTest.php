<?php

namespace Tests\Feature;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coverage for `tenders:audit-collaborator-emails`.
 *
 * The command's job is to find rows where `tender_collaborators.email`
 * belongs to a different User than `tender_collaborators.user_id`
 * (the bug shape that produced the Catarina-saw-Mónica leak), and
 * optionally repair them.
 *
 * Tests cover:
 *   - audit-only (no flags) reports mismatch and changes nothing
 *   - --fix clears the email and keeps user_id
 *   - --reattach re-derives user_id from the email
 *   - clean rows produce no false positives
 *   - mutually-exclusive options error out
 *   - command is idempotent (re-run after --fix → no rows to repair)
 */
class AuditCollaboratorEmailsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Insert a collaborator row directly via the query builder so we
     * bypass the saving hook that would normally auto-fix the
     * `email → user_id` linkage. The bug only exists when something
     * (manual edit / legacy import) put the row in an inconsistent
     * state, so the test must reproduce that state literally.
     */
    private function insertRaw(array $row): TenderCollaborator
    {
        $defaults = [
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload = $row + $defaults;
        $payload['normalized_name'] ??= TenderCollaborator::normalize($payload['name'] ?? '');
        DB::table('tender_collaborators')->insert($payload);
        return TenderCollaborator::query()->orderByDesc('id')->first();
    }

    public function test_audit_only_reports_mismatch_and_makes_no_changes(): void
    {
        $monica   = User::factory()->create(['email' => 'monica.pereira@hp-group.org', 'is_active' => true]);
        $catarina = User::factory()->create(['email' => 'catarina.sequeira@hp-group.org', 'is_active' => true]);

        $bad = $this->insertRaw([
            'name'    => 'Mónica Pereira',
            'user_id' => $monica->id,
            'email'   => $catarina->email,   // phantom: belongs to Catarina, row owned by Monica
        ]);

        $this->artisan('tenders:audit-collaborator-emails')
            ->expectsOutputToContain('Found 1 mismatched row')
            ->expectsOutputToContain('Read-only audit')
            ->assertExitCode(0);

        // Read-only — nothing changed.
        $row = $bad->fresh();
        $this->assertSame($monica->id, $row->user_id);
        $this->assertSame($catarina->email, $row->email);
    }

    public function test_fix_clears_the_email_and_keeps_user_id(): void
    {
        $monica   = User::factory()->create(['email' => 'monica.pereira@hp-group.org', 'is_active' => true]);
        $catarina = User::factory()->create(['email' => 'catarina.sequeira@hp-group.org', 'is_active' => true]);

        $bad = $this->insertRaw([
            'name'    => 'Mónica Pereira',
            'user_id' => $monica->id,
            'email'   => $catarina->email,
        ]);

        $this->artisan('tenders:audit-collaborator-emails', ['--fix' => true])
            ->expectsOutputToContain('Repaired 1 row(s) [mode=clear-email]')
            ->assertExitCode(0);

        $row = $bad->fresh();
        $this->assertSame($monica->id, $row->user_id, 'user_id must stay pointed at the original owner');
        $this->assertNull($row->email, 'phantom email must be cleared');
    }

    public function test_reattach_repoints_user_id_to_the_email_owner(): void
    {
        $monica   = User::factory()->create(['email' => 'monica.pereira@hp-group.org', 'is_active' => true]);
        $catarina = User::factory()->create(['email' => 'catarina.sequeira@hp-group.org', 'is_active' => true]);

        $bad = $this->insertRaw([
            'name'    => 'Mónica Pereira',
            'user_id' => $monica->id,
            'email'   => $catarina->email,
        ]);

        $this->artisan('tenders:audit-collaborator-emails', ['--reattach' => true])
            ->expectsOutputToContain('Repaired 1 row(s) [mode=reattach]')
            ->assertExitCode(0);

        $row = $bad->fresh();
        $this->assertSame($catarina->id, $row->user_id, 'user_id must move to the email owner');
        $this->assertSame($catarina->email, $row->email, 'email is preserved as the source of truth in this mode');
    }

    public function test_no_false_positives_on_clean_rows(): void
    {
        $u = User::factory()->create(['email' => 'mark@example.com', 'is_active' => true]);

        // Consistent: user_id and email both resolve to Mark.
        $this->insertRaw([
            'name'    => 'Mark',
            'user_id' => $u->id,
            'email'   => 'mark@example.com',
        ]);
        // Consistent with whitespace + case differences — must still pass.
        $this->insertRaw([
            'name'    => 'Mark (alias)',
            'user_id' => $u->id,
            'email'   => '  MARK@Example.COM  ',
        ]);
        // No email at all — not a phantom candidate.
        $this->insertRaw([
            'name'    => 'Mark (no email)',
            'user_id' => $u->id,
            'email'   => null,
        ]);
        // Email points to no User at all → not a mismatch (nothing to compare against).
        $this->insertRaw([
            'name'    => 'Mark (alias unknown)',
            'user_id' => $u->id,
            'email'   => 'unknown@external.com',
        ]);

        $this->artisan('tenders:audit-collaborator-emails')
            ->expectsOutputToContain('no mismatches found')
            ->assertExitCode(0);
    }

    public function test_fix_is_idempotent(): void
    {
        $monica   = User::factory()->create(['email' => 'monica.pereira@hp-group.org', 'is_active' => true]);
        $catarina = User::factory()->create(['email' => 'catarina.sequeira@hp-group.org', 'is_active' => true]);

        $bad = $this->insertRaw([
            'name'    => 'Mónica Pereira',
            'user_id' => $monica->id,
            'email'   => $catarina->email,
        ]);

        $this->artisan('tenders:audit-collaborator-emails', ['--fix' => true])->assertExitCode(0);

        // Capture state after first repair.
        $afterFirst = $bad->fresh();
        $this->assertSame($monica->id, $afterFirst->user_id);
        $this->assertNull($afterFirst->email);

        // Second run must NOT find a mismatch (the row no longer has an
        // email to be wrong about) AND must not change the row again.
        // Either of the two clean-state outputs is acceptable, since
        // after the fix this row drops out of the candidate set entirely.
        $this->artisan('tenders:audit-collaborator-emails', ['--fix' => true])
            ->assertExitCode(0);

        $afterSecond = $bad->fresh();
        $this->assertSame($afterFirst->user_id, $afterSecond->user_id);
        $this->assertSame($afterFirst->email,   $afterSecond->email);
        $this->assertEquals(
            $afterFirst->updated_at?->toIso8601String(),
            $afterSecond->updated_at?->toIso8601String(),
            'Idempotent run must not bump updated_at'
        );
    }

    public function test_fix_and_reattach_are_mutually_exclusive(): void
    {
        $this->artisan('tenders:audit-collaborator-emails', ['--fix' => true, '--reattach' => true])
            ->expectsOutputToContain('mutually exclusive')
            ->assertExitCode(1);
    }
}
