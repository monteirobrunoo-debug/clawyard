<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the `super-user` chip on the shares dashboard —
 * `PATCH /admin/users/{user}/toggle-promote`.
 *
 * User request (2026-04-24): "quero nomear na partilha de user no
 * dashboard quem pode partilhar os processos ou ser super-user". The
 * chip flips the target user's role between `user` ↔ `manager`, which
 * is exactly the role that unlocks the manager-only gates (tender
 * overview, collaborator roster, sharing agents with clients).
 *
 * These tests guarantee:
 *   1. Only admin can hit the endpoint.
 *   2. user ↔ manager flip goes both ways.
 *   3. admin can't self-demote (would orphan the last-admin seat).
 *   4. admins are left untouched (they're above manager — use /admin/users).
 *   5. guests are left untouched (deliberate read-only class).
 */
class AdminTogglePromoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_promotes_user_to_manager_then_demotes_back(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $u     = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $r1 = $this->actingAs($admin)
            ->patchJson("/admin/users/{$u->id}/toggle-promote");
        $r1->assertOk()->assertJson(['ok' => true, 'role' => 'manager', 'is_manager' => true]);
        $this->assertSame('manager', $u->fresh()->role);

        $r2 = $this->actingAs($admin)
            ->patchJson("/admin/users/{$u->id}/toggle-promote");
        $r2->assertOk()->assertJson(['ok' => true, 'role' => 'user', 'is_manager' => false]);
        $this->assertSame('user', $u->fresh()->role);
    }

    public function test_non_admin_manager_cannot_promote(): void
    {
        $mgr = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $u   = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $r = $this->actingAs($mgr)
            ->patchJson("/admin/users/{$u->id}/toggle-promote");

        // admin-only middleware returns 403 JSON for XHR/JSON requests.
        $r->assertStatus(403);
        $this->assertSame('user', $u->fresh()->role);
    }

    public function test_regular_user_cannot_promote_anyone(): void
    {
        $actor = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $u     = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $r = $this->actingAs($actor)
            ->patchJson("/admin/users/{$u->id}/toggle-promote");

        $r->assertStatus(403);
        $this->assertSame('user', $u->fresh()->role);
    }

    public function test_admin_cannot_self_demote(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $r = $this->actingAs($admin)
            ->patchJson("/admin/users/{$admin->id}/toggle-promote");

        $r->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_admin_role_is_not_toggled_by_this_endpoint(): void
    {
        $admin       = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $otherAdmin  = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $r = $this->actingAs($admin)
            ->patchJson("/admin/users/{$otherAdmin->id}/toggle-promote");

        // Endpoint rejects admin targets — the caller must use /admin/users
        // to downgrade an admin, to avoid accidental privilege loss.
        $r->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame('admin', $otherAdmin->fresh()->role);
    }

    public function test_guest_role_is_not_toggled_by_this_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $guest = User::factory()->create(['role' => 'guest', 'is_active' => true]);

        $r = $this->actingAs($admin)
            ->patchJson("/admin/users/{$guest->id}/toggle-promote");

        $r->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame('guest', $guest->fresh()->role);
    }
}
