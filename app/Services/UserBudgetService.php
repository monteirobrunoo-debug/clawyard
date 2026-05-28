<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * UserBudgetService — limita gasto diário Anthropic por user.
 *
 * Pedido Bruno 2026-05-28 (Fase B1):
 *   "per-user budget cap (corta agente quando user gasta >€X/dia)"
 *
 * Funcionamento:
 *   - Cap default: config('services.budget.daily_eur') (default €10).
 *   - Admins não têm cap (User::isAdmin() === true).
 *   - Gasto = SUM(cost_usd) em agent_runs do dia (UTC), convertido a EUR.
 *   - Cache Redis 60s para evitar query a cada chunk de streaming.
 *
 * Integração:
 *   - Middleware CheckUserBudget aplicado a /api/chat.
 *   - UI: GET /api/user-budget devolve {spent, cap, percentage, status}.
 *
 * Storage: agent_runs já tem cost_usd + user_id + created_at — não há
 * migration necessária. O recorder dos agentes (TenderServiceAnalysisService
 * + agentes individuais) já popula esta tabela.
 */
class UserBudgetService
{
    /** Taxa de conversão USD→EUR aproximada. Anthropic factura em USD. */
    private const USD_TO_EUR = 0.92;

    /** TTL do cache de gasto diário (em segundos). 60s = balance entre
     *  precisão (não atrasar muito o block quando passa cap) e load DB. */
    private const CACHE_TTL = 60;

    public function dailyCap(?User $user): float
    {
        if (!$user) return 0.0;

        // Admins sem cap — devolve infinito (qualquer gasto cabe).
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return PHP_FLOAT_MAX;
        }

        // Override por user via $user->daily_budget_eur (column opcional).
        // Se não existir ou for null, usa config global.
        $override = $user->daily_budget_eur ?? null;
        if ($override !== null && $override > 0) {
            return (float) $override;
        }

        return (float) config('services.budget.daily_eur', 10.0);
    }

    public function dailySpend(int $userId): float
    {
        return Cache::remember(
            $this->cacheKey($userId),
            self::CACHE_TTL,
            function () use ($userId) {
                $usd = (float) DB::table('agent_runs')
                    ->where('user_id', $userId)
                    ->where('created_at', '>=', now()->startOfDay())
                    ->sum('cost_usd');
                return round($usd * self::USD_TO_EUR, 4);
            }
        );
    }

    public function canSpend(?User $user, float $estimatedEur = 0.0): bool
    {
        if (!$user) return false;
        $cap = $this->dailyCap($user);
        if ($cap >= PHP_FLOAT_MAX) return true; // admin sem cap
        $spent = $this->dailySpend($user->id);
        return ($spent + $estimatedEur) <= $cap;
    }

    public function remaining(?User $user): float
    {
        if (!$user) return 0.0;
        $cap = $this->dailyCap($user);
        if ($cap >= PHP_FLOAT_MAX) return PHP_FLOAT_MAX;
        return max(0.0, $cap - $this->dailySpend($user->id));
    }

    /**
     * Estado para UI: verde (<60%), amber (60-90%), red (>=90% ou over).
     * Devolve struct pronto para JSON.
     */
    public function status(?User $user): array
    {
        if (!$user) return ['cap' => 0, 'spent' => 0, 'percentage' => 0, 'level' => 'unknown'];

        $cap = $this->dailyCap($user);
        if ($cap >= PHP_FLOAT_MAX) {
            return [
                'cap'        => null,
                'spent'      => $this->dailySpend($user->id),
                'percentage' => null,
                'level'      => 'unlimited',
                'is_admin'   => true,
            ];
        }

        $spent = $this->dailySpend($user->id);
        $pct = $cap > 0 ? min(100, round(($spent / $cap) * 100, 1)) : 0;

        $level = match (true) {
            $spent >= $cap     => 'over',
            $pct  >= 90        => 'red',
            $pct  >= 60        => 'amber',
            default            => 'green',
        };

        return [
            'cap'        => round($cap, 2),
            'spent'      => round($spent, 4),
            'percentage' => $pct,
            'level'      => $level,
            'is_admin'   => false,
        ];
    }

    /** Limpa o cache (útil em testes ou após admin top-up manual). */
    public function flush(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    private function cacheKey(int $userId): string
    {
        return 'user_budget:' . $userId . ':' . now()->format('Y-m-d');
    }
}
