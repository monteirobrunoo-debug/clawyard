<?php

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Hardening tests for the post-2026-04-25 invariants on
 * TenderCollaborator's saving() hook and on Tender::scopeForUser.
 *
 * Three behaviours are locked down:
 *
 *   A) Establish-only auto-link (no silent destruction of user_id).
 *      • Setting a NEW email that matches a User → user_id auto-set.
 *      • Setting an email that matches NO User → user_id LEFT alone
 *        (legacy behaviour reset it to NULL — that's the footgun).
 *      • Clearing email → user_id LEFT alone (legacy behaviour cleared
 *        it; that's why repairing phantom rows had to bypass the hook).
 *
 *   B) Anti-corruption invariant.
 *      • Saving a row where user_id = X.id but email belongs to Y
 *        (a registered, different User) throws DomainException.
 *      • Aliases (email matching no User) are still allowed —
 *        distribution lists, shared inboxes, etc.
 *      • Saving a row where the two fields agree is unaffected.
 *
 *   C) scopeForUser email-fallback telemetry.
 *      • When the strict (user_id) match is empty AND the email-only
 *        fallback finds rows, an info log is emitted so we can spot
 *        accumulating loose links that need backfilling.
 */
class TenderCollaboratorHookHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── A) Establish-only auto-link ──────────────────────────────────────

    public function test_setting_email_matching_a_user_auto_sets_user_id(): void
    {
        $u = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = TenderCollaborator::normalize('Alice');
        $c->email = 'alice@hp-group.org';
        $c->is_active = true;
        $c->save();

        $this->assertSame($u->id, $c->fresh()->user_id);
    }

    public function test_clearing_email_does_not_null_user_id(): void
    {
        $u = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = TenderCollaborator::normalize('Alice');
        $c->email = 'alice@hp-group.org';
        $c->is_active = true;
        $c->save();
        $this->assertSame($u->id, $c->fresh()->user_id);

        // Now clear the email. New rule: user_id is preserved.
        $c->email = null;
        $c->save();

        $row = $c->fresh();
        $this->assertNull($row->email);
        $this->assertSame($u->id, $row->user_id, 'user_id must survive an email clear');
    }

    public function test_setting_email_with_no_matching_user_does_not_null_user_id(): void
    {
        $u = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = TenderCollaborator::normalize('Alice');
        $c->email = 'alice@hp-group.org';
        $c->is_active = true;
        $c->save();
        $this->assertSame($u->id, $c->fresh()->user_id);

        // Switch the email to a distribution list / alias — no User has
        // that address. user_id must NOT be wiped.
        $c->email = 'team-procurement@hp-group.org';
        $c->save();

        $row = $c->fresh();
        $this->assertSame('team-procurement@hp-group.org', $row->email);
        $this->assertSame($u->id, $row->user_id, 'unmatched alias email must not destroy the link');
    }

    // ── B) Anti-corruption invariant ─────────────────────────────────────

    public function test_invariant_blocks_email_belonging_to_a_different_user(): void
    {
        $monica   = User::factory()->create(['email' => 'monica@hp-group.org',   'is_active' => true]);
        $catarina = User::factory()->create(['email' => 'catarina@hp-group.org', 'is_active' => true]);

        // Try to save the exact corruption shape that caused the leak:
        // user_id points at Monica, email belongs to Catarina.
        $c = new TenderCollaborator();
        $c->name = 'Mónica Pereira';
        $c->normalized_name = TenderCollaborator::normalize('Mónica Pereira');
        $c->user_id = $monica->id;
        $c->email   = $catarina->email;
        $c->is_active = true;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/refusing to save inconsistent link/i');
        $c->save();
    }

    public function test_invariant_allows_alias_email_with_no_matching_user(): void
    {
        $u = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        // Alias / distribution list — nobody else owns this address.
        // The invariant must NOT fire on this case.
        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = TenderCollaborator::normalize('Alice');
        $c->user_id = $u->id;
        $c->email   = 'team@hp-group.org';   // no User has this
        $c->is_active = true;
        $c->save();

        $row = $c->fresh();
        $this->assertSame($u->id, $row->user_id);
        $this->assertSame('team@hp-group.org', $row->email);
    }

    public function test_invariant_allows_consistent_link(): void
    {
        $u = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = TenderCollaborator::normalize('Alice');
        $c->user_id = $u->id;
        $c->email   = 'alice@hp-group.org';
        $c->is_active = true;
        $c->save();

        $this->assertSame($u->id, $c->fresh()->user_id);
    }

    // ── C) Email-fallback telemetry ──────────────────────────────────────

    public function test_email_fallback_emits_info_log(): void
    {
        $alice = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        // Create a collaborator with the matching email but NO user_id link
        // (the legacy state the fallback exists to bridge).
        $c = new TenderCollaborator();
        $c->name = 'Alice (legacy)';
        $c->normalized_name = TenderCollaborator::normalize('Alice (legacy)');
        $c->email = 'alice@hp-group.org';
        $c->is_active = true;
        // Save first (the hook will set user_id to alice). Then simulate the
        // legacy state by clearing user_id directly via the query builder
        // (bypassing the new establish-only hook).
        $c->save();
        \DB::table('tender_collaborators')->where('id', $c->id)->update(['user_id' => null]);
        $c = $c->fresh();
        $this->assertNull($c->user_id, 'sanity: legacy state set up correctly');

        // Spy on the log channel.
        Log::spy();

        // Trigger scopeForUser via a query.
        Tender::query()->forUser($alice->id)->get();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message, $context = null) use ($alice, $c) {
                return $message === 'Tender::scopeForUser fell back to email match'
                    && is_array($context)
                    && ($context['user_id'] ?? null) === $alice->id
                    && in_array($c->id, $context['matched_collabs'] ?? [], true);
            });
    }

    public function test_email_fallback_does_not_log_when_strict_match_succeeds(): void
    {
        // The happy path: user_id link exists. Fallback never fires, so
        // no info log emitted. Prevents the log channel filling with
        // noise on every dashboard load.
        $alice = User::factory()->create(['email' => 'alice@hp-group.org', 'is_active' => true]);

        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = TenderCollaborator::normalize('Alice');
        $c->email = 'alice@hp-group.org';
        $c->is_active = true;
        $c->save();   // hook sets user_id = alice.id

        Log::spy();

        Tender::query()->forUser($alice->id)->get();

        Log::shouldNotHaveReceived('info', [\Mockery::pattern('/fell back to email match/')]);
    }
}
