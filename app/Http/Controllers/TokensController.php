<?php

namespace App\Http\Controllers;

use App\Models\TokenBudget;
use App\Services\TokenBudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /admin/tokens — dashboard de gestão do pool de tokens Anthropic.
 *
 * Pedido directo 2026-05-22: "faz dashboard para todos verem no ecra
 * incial ao lado dos ranks". O widget no dashboard inicial mostra
 * resumo a TODOS. Este controller serve um painel COMPLETO restrito a
 * admins: timeline 7 dias, set-pool inline, reset notifications.
 */
class TokensController extends Controller
{
    public function __construct(private TokenBudgetService $svc) {}

    /** GET /admin/tokens — dashboard completo. */
    public function index(Request $request)
    {
        $this->ensureAdmin();

        $summary = $this->svc->summary();
        $ranking = $this->svc->rankingThisMonth(50);
        $budget  = $this->svc->currentBudget();

        // Timeline 7 dias — daily spend agregado.
        $timeline = $this->buildTimelineLast7Days();

        // Histórico de períodos anteriores (3 últimos meses).
        $history = TokenBudget::orderByDesc('period_yyyy_mm')
            ->limit(6)
            ->get(['period_yyyy_mm', 'pool_eur', 'notified_at_80', 'notified_at_100']);

        return view('admin.tokens', [
            'summary'  => $summary,
            'ranking'  => $ranking,
            'budget'   => $budget,
            'timeline' => $timeline,
            'history'  => $history,
        ]);
    }

    /** POST /admin/tokens — update pool / thresholds / reset notifications. */
    public function update(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'pool_eur'             => ['required', 'numeric', 'min:0', 'max:100000'],
            'alert_at_percent'     => ['required', 'integer', 'min:1', 'max:100'],
            'hard_gate_at_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $budget = $this->svc->currentBudget();
        $oldPool = (float) $budget->pool_eur;
        $budget->update($data);

        // Se pool mudou, reset flags de notificação (vai re-alertar quando novos thresholds atingidos).
        if ((float) $data['pool_eur'] !== $oldPool) {
            $budget->update(['notified_at_80' => null, 'notified_at_100' => null]);
        }

        return redirect()->route('admin.tokens')->with('status', '✓ Pool actualizado.');
    }

    /** POST /admin/tokens/reset-notifications — força re-envio do próximo alerta. */
    public function resetNotifications(Request $request)
    {
        $this->ensureAdmin();
        $this->svc->currentBudget()->update([
            'notified_at_80'  => null,
            'notified_at_100' => null,
        ]);
        return back()->with('status', '✓ Notificações resetadas — próximo alerta vai ser enviado.');
    }

    /**
     * POST /admin/tokens/top-up — adiciona EUR ao pool do mês actual.
     * Default amount vem do config (services.tokens.topup_amount, €50).
     * O user pode passar amount=X no form para override.
     *
     * Pedido directo 2026-05-22: "ativo tudo clicando num botao mais
     * tokens". Botão no email + botão no /admin/tokens. Admin-only.
     */
    public function topUp(Request $request)
    {
        $this->ensureAdmin();

        $amount = (float) $request->input('amount', config('services.tokens.topup_amount', 50.00));
        $amount = max(0.0, min(10000.0, $amount));

        $newPool = $this->svc->topUp($amount);

        return back()->with('status',
            '✓ Adicionados €' . number_format($amount, 2)
            . ' ao pool. Novo pool: €' . number_format($newPool, 2)
            . '. Notificações resetadas.'
        );
    }

    /** Constrói timeline 7d via aggregation das 3 tabelas de cost. */
    private function buildTimelineLast7Days(): array
    {
        $rate = $this->svc->usdToEurRate();
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $next = (clone $date)->addDay();

            $msg = (float) DB::table('messages')
                ->whereBetween('created_at', [$date, $next])->sum('cost_usd');
            $run = (float) DB::table('agent_runs')
                ->whereBetween('created_at', [$date, $next])->sum('cost_usd');
            $tsa = (float) DB::table('tender_service_analyses')
                ->whereBetween('created_at', [$date, $next])->sum('total_cost_usd');

            $days[] = [
                'date'      => $date->format('d/m'),
                'iso_date'  => $date->format('Y-m-d'),
                'eur_spent' => round(($msg + $run + $tsa) * $rate, 4),
            ];
        }
        return $days;
    }

    private function ensureAdmin(): void
    {
        if (auth()->user()?->role !== 'admin') {
            abort(403, 'Apenas admins.');
        }
    }
}
