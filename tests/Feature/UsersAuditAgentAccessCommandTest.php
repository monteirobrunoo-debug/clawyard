<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * users:audit-agent-access — pins:
 *   • Clean state (everyone NULL or full whitelist) → exit 0,
 *     "no anomalies" message.
 *   • allowed_agents=[] → BLOCKED_FROM_ALL flag, exit 1.
 *   • Whitelist with ≤2 entries → THIN_WHITELIST flag, exit 1.
 *   • Whitelist with 3+ entries → no flag.
 *   • Orphan agents listed when no non-admin user can use them.
 *   • Universal agents listed when EVERY non-admin user can use them.
 *   • Admins are excluded from the user-side audit (gate always
 *     passes for them, so they don't restrict anything).
 *   • --json mode emits a parseable payload.
 */
class UsersAuditAgentAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(?array $allowed, string $role = 'user'): User
    {
        return User::factory()->create([
            'role'           => $role,
            'is_active'      => true,
            'allowed_agents' => $allowed,
        ]);
    }

    public function test_clean_state_no_anomalies(): void
    {
        // Two users, both unrestricted (NULL = sees all).
        $this->user(null);
        $this->user(null);

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('OK — 2 active user(s) audited, no anomalies.')
            ->assertExitCode(0);
    }

    public function test_blocked_from_all_is_flagged(): void
    {
        $this->user(allowed: []);

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('BLOCKED_FROM_ALL')
            ->assertExitCode(1);
    }

    public function test_thin_whitelist_is_flagged(): void
    {
        $this->user(allowed: ['sales']);
        $this->user(allowed: ['sales', 'sap']);

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('THIN_WHITELIST')
            ->assertExitCode(1);
    }

    public function test_three_or_more_agents_is_not_thin(): void
    {
        $this->user(allowed: ['sales', 'sap', 'document']);

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('OK — 1 active user(s) audited, no anomalies.')
            ->assertExitCode(0);
    }

    public function test_admin_users_are_excluded_from_audit_rows(): void
    {
        // Admin with allowed_agents=[] — wouldn't be flagged because
        // gate always passes for admins. The audit only looks at
        // user/manager rows.
        $this->user(allowed: [], role: 'admin');
        $this->user(allowed: null, role: 'user');     // clean

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('OK — 1 active user(s) audited, no anomalies.')
            ->assertExitCode(0);
    }

    public function test_orphan_agent_surfaces_when_no_user_has_access(): void
    {
        // Restrict everyone to just 'sales' — every other catalog
        // agent becomes an orphan.
        $this->user(allowed: ['sales']);

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('Orphan agents')
            ->assertExitCode(1);   // also has THIN_WHITELIST anomaly
    }

    public function test_universal_agent_listed_when_everyone_has_access(): void
    {
        // Two users with NULL — both have access to every catalog
        // agent → every agent is universal.
        $this->user(null);
        $this->user(null);

        $this->artisan('users:audit-agent-access')
            ->expectsOutputToContain('Universal agents')
            ->assertExitCode(0);
    }

    public function test_json_mode_emits_parseable_payload(): void
    {
        $this->user(allowed: ['sales', 'sap', 'document']);

        \Artisan::call('users:audit-agent-access', ['--json' => true]);
        $out = \Artisan::output();

        $this->assertJson($out);
        $payload = json_decode($out, true);
        $this->assertSame(1, $payload['total_users']);
        $this->assertGreaterThan(0, $payload['total_agents']);
        $this->assertIsArray($payload['rows']);
        $this->assertIsArray($payload['orphan_agents']);
        $this->assertIsArray($payload['universal_agents']);
    }
}
