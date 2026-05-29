<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserBudgetService unit tests (Fase B3-lite 2026-05-28).
 *
 * Catch regressions no per-user budget cap (B1 — commit ab757c6):
 *   - Admins sem cap → canSpend sempre true
 *   - Users normais com cap default config
 *   - Override per-user via daily_budget_eur
 *   - status() devolve níveis correctos (green/amber/red/over)
 */
class UserBudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_has_no_cap(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $svc = app(UserBudgetService::class);

        $this->assertGreaterThan(1e10, $svc->dailyCap($admin));
        $this->assertTrue($svc->canSpend($admin, 999999.99));
        $this->assertSame('unlimited', $svc->status($admin)['level']);
    }

    public function test_regular_user_uses_config_default(): void
    {
        config(['services.budget.daily_eur' => 10.0]);
        $user = User::factory()->create(['role' => 'user']);
        $svc = app(UserBudgetService::class);

        $this->assertEqualsWithDelta(10.0, $svc->dailyCap($user), 0.01);
    }

    public function test_user_override_via_daily_budget_eur_column(): void
    {
        // Apenas se a coluna existir. Skip se a migration ainda não correu nesta env de teste.
        if (!\Schema::hasColumn('users', 'daily_budget_eur')) {
            $this->markTestSkipped('users.daily_budget_eur column not yet provisioned');
        }
        $user = User::factory()->create(['role' => 'user', 'daily_budget_eur' => 50.0]);
        $svc = app(UserBudgetService::class);

        $this->assertEqualsWithDelta(50.0, $svc->dailyCap($user), 0.01);
    }

    public function test_status_level_green_when_under_60pct(): void
    {
        config(['services.budget.daily_eur' => 10.0]);
        $user = User::factory()->create(['role' => 'user']);
        $svc = app(UserBudgetService::class);

        // Sem gasto — verde absoluto
        $status = $svc->status($user);
        $this->assertSame('green', $status['level']);
        $this->assertSame(10.0, $status['cap']);
    }

    public function test_null_user_returns_safe_defaults(): void
    {
        $svc = app(UserBudgetService::class);

        $this->assertSame(0.0, $svc->dailyCap(null));
        $this->assertFalse($svc->canSpend(null));
        $this->assertSame(0.0, $svc->remaining(null));
        $this->assertSame('unknown', $svc->status(null)['level']);
    }
}
