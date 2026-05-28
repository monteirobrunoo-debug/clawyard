<?php

namespace App\Http\Middleware;

use App\Services\UserBudgetService;
use Closure;
use Illuminate\Http\Request;

/**
 * CheckUserBudget — middleware que bloqueia chat/análises quando o user
 * passou o cap diário Anthropic (Fase B1 2026-05-28).
 *
 * Aplicado a: /api/chat (POST), /tenders/{id}/service-analysis (POST).
 * Não aplicado a: GETs (history, agent list, dashboards) — sem custo LLM.
 *
 * Resposta quando bloqueado: HTTP 429 com JSON
 *   { error: "budget_exceeded", spent, cap, message }
 * O frontend (welcome.blade.php) tem handler que mostra toast vermelho.
 *
 * Admins skip (cap = PHP_FLOAT_MAX). Configurável por user via
 * users.daily_budget_eur column.
 */
class CheckUserBudget
{
    public function __construct(private UserBudgetService $budget) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);  // auth middleware trata

        if ($this->budget->canSpend($user)) {
            return $next($request);
        }

        $status = $this->budget->status($user);
        $message = sprintf(
            "Atingiste o limite diário Anthropic (€%.2f gasto de €%.2f). " .
            "Renova à meia-noite UTC. Admins podem aumentar o cap em /admin/users.",
            $status['spent'],
            $status['cap'],
        );

        \Log::info('CheckUserBudget: blocked', [
            'user_id'    => $user->id,
            'spent_eur'  => $status['spent'],
            'cap_eur'    => $status['cap'],
            'route'      => $request->path(),
        ]);

        return response()->json([
            'error'   => 'budget_exceeded',
            'spent'   => $status['spent'],
            'cap'     => $status['cap'],
            'percentage' => $status['percentage'],
            'message' => $message,
        ], 429);
    }
}
