<?php

namespace Tests\Feature;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-user health-check command. Detects (without fixing) the soft-
 * failure modes that quietly degrade dashboard / digest experience:
 *
 *   • NO_COLLAB_ROW         — user has no collaborator at all
 *   • MULTIPLE_LINKED_ROWS  — user has 2+ rows pointing at them
 *   • LOOSE_EMAIL_MATCH     — collaborator carries the user's email
 *                              but user_id is NULL (legacy state,
 *                              auto-link never fired)
 *   • BLOCKED_FROM_ALL_SOURCES — allowed_sources=[]
 *
 * Restrictions (whitelisted sources) are NOT anomalies; they're
 * deliberate policy. The command only fails the exit code when at
 * least one user has an anomaly.
 */
class TenderAuditAllUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $email = null, string $role = 'user'): User
    {
        return User::factory()->create([
            'email'     => $email ?? fake()->unique()->safeEmail(),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    public function test_clean_state_passes_with_zero_anomalies_and_exit_0(): void
    {
        $u = $this->user('alice@example.com');

        $c = new TenderCollaborator();
        $c->name = 'Alice';
        $c->normalized_name = 'alice';
        $c->email = 'alice@example.com';   // saving hook auto-links
        $c->is_active = true;
        $c->save();

        $this->artisan('tenders:audit-all-users')
            ->expectsOutputToContain('OK — 1 active user(s) audited, no anomalies.')
            ->assertExitCode(0);
    }

    public function test_user_with_no_collaborator_row_is_flagged(): void
    {
        $this->user('orphan@example.com');

        $this->artisan('tenders:audit-all-users')
            ->expectsOutputToContain('NO_COLLAB_ROW')
            ->assertExitCode(1);
    }

    public function test_user_with_multiple_linked_rows_is_flagged(): void
    {
        $u = $this->user('dup@example.com');

        // Two rows both linked to the same user (the saving hook would
        // normally only set one — we go through a second SAVE that
        // changes the email and forces another link).
        $a = new TenderCollaborator();
        $a->name = 'Dup A'; $a->normalized_name = 'dup a';
        $a->email = 'dup@example.com'; $a->is_active = true; $a->save();

        $b = new TenderCollaborator();
        $b->name = 'Dup B'; $b->normalized_name = 'dup b';
        $b->email = 'dup@example.com'; $b->is_active = true; $b->save();

        $this->artisan('tenders:audit-all-users')
            ->expectsOutputToContain('MULTIPLE_LINKED_ROWS')
            ->assertExitCode(1);
    }

    public function test_loose_email_match_is_flagged(): void
    {
        $u = $this->user('legacy@example.com');

        $c = new TenderCollaborator();
        $c->name = 'Legacy'; $c->normalized_name = 'legacy';
        $c->email = 'legacy@example.com'; $c->is_active = true; $c->save();
        // Simulate the legacy state: user_id was never backfilled.
        \DB::table('tender_collaborators')->where('id', $c->id)->update(['user_id' => null]);

        $this->artisan('tenders:audit-all-users')
            ->expectsOutputToContain('LOOSE_EMAIL_MATCH')
            ->assertExitCode(1);
    }

    public function test_blocked_from_all_sources_is_flagged(): void
    {
        $u = $this->user('blocked@example.com');

        $c = new TenderCollaborator();
        $c->name = 'Blocked'; $c->normalized_name = 'blocked';
        $c->email = $u->email; $c->is_active = true;
        $c->allowed_sources = [];
        $c->save();

        $this->artisan('tenders:audit-all-users')
            ->expectsOutputToContain('BLOCKED_FROM_ALL_SOURCES')
            ->assertExitCode(1);
    }

    public function test_whitelist_restriction_is_not_an_anomaly(): void
    {
        // Restriction is policy, not a problem. Should NOT fail the exit
        // code or appear under "anomalies".
        $u = $this->user('restricted@example.com');

        $c = new TenderCollaborator();
        $c->name = 'Restricted'; $c->normalized_name = 'restricted';
        $c->email = $u->email; $c->is_active = true;
        $c->allowed_sources = ['nspa'];
        $c->save();

        $this->artisan('tenders:audit-all-users')
            ->expectsOutputToContain('OK — 1 active user(s) audited, no anomalies.')
            ->assertExitCode(0);
    }

    public function test_json_mode_outputs_machine_readable(): void
    {
        $u = $this->user('json@example.com');

        $c = new TenderCollaborator();
        $c->name = 'Json'; $c->normalized_name = 'json';
        $c->email = $u->email; $c->is_active = true; $c->save();

        // Capture stdout and assert it parses to JSON. Avoids brittle
        // string-matching on user names (which faker may generate with
        // apostrophes / accents that escape unpredictably).
        \Artisan::call('tenders:audit-all-users', ['--json' => true]);
        $payload = \Artisan::output();

        $this->assertJson($payload);
        $rows = json_decode($payload, true);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame($u->email, $rows[0]['email']);
        $this->assertSame([], $rows[0]['anomalies']);
    }
}
