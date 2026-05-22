<?php

namespace App\Services;

use App\Models\TokenBudget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TokenBudgetService — agregador do gasto Anthropic vs pool mensal.
 *
 * Pool partilhado (default €150/mês). Sem hard caps individuais —
 * o que um user não usa fica disponível para outro. Tracking real:
 * agrega cost_usd das 3 tabelas que registam custo:
 *
 *   1. messages.cost_usd            — chat directo com agentes
 *      (JOIN conversations para chegar a user_id)
 *   2. agent_runs.cost_usd          — tool-use loops autónomos
 *   3. tender_service_analyses.total_cost_usd — análises multi-agente
 *
 * USD → EUR via config services.tokens.usd_eur_rate (default 0.92).
 *
 * Notificações:
 *   • alertIfNeeded() — chamado por cron diário; envia email ao admin
 *     a primeira vez que pool atinge 80% e 100% no período. Flags
 *     notified_at_* tornam isto idempotente.
 *   • Hard gate (futuro): quando hard_gate_at_percent > 0 e ultrapassado,
 *     novos chat calls devolvem erro amigável em vez de chamar Anthropic.
 */
class TokenBudgetService
{
    public function __construct() {}

    /** Pool budget do período actual (cria default se não existir). */
    public function currentBudget(): TokenBudget
    {
        return TokenBudget::firstOrCreate(
            ['period_yyyy_mm' => TokenBudget::currentPeriod()],
            ['pool_eur' => (float) config('services.tokens.pool_eur', 150.00)],
        );
    }

    /** USD→EUR rate (configurável via env, default 0.92 para 2026). */
    public function usdToEurRate(): float
    {
        return (float) config('services.tokens.usd_eur_rate', 0.92);
    }

    /** Janela [start, end] do período actual (inclusive). */
    public function periodWindow(): array
    {
        $start = Carbon::createFromFormat('Y-m', TokenBudget::currentPeriod())->startOfMonth();
        $end   = (clone $start)->endOfMonth();
        return [$start, $end];
    }

    /**
     * Gasto total deste mês em EUR (todos os users + jobs anónimos).
     * Soma cost_usd das 3 tabelas, converte para EUR.
     */
    public function spentThisMonth(): float
    {
        [$start, $end] = $this->periodWindow();
        $rate = $this->usdToEurRate();

        $msg   = (float) DB::table('messages')
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost_usd');

        $runs  = (float) DB::table('agent_runs')
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost_usd');

        $tsa   = (float) DB::table('tender_service_analyses')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_cost_usd');

        return round(($msg + $runs + $tsa) * $rate, 4);
    }

    /**
     * Gasto por user este mês em EUR.
     * messages: JOIN conversations.user_id
     * agent_runs: tem user_id direto
     * tender_service_analyses: tem generated_by_user_id
     *
     * @return array<int,float> user_id => euros gastos
     */
    public function spentByUserThisMonth(): array
    {
        [$start, $end] = $this->periodWindow();
        $rate = $this->usdToEurRate();

        // messages → conversations → user_id
        $msgRows = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereBetween('messages.created_at', [$start, $end])
            ->whereNotNull('conversations.user_id')
            ->groupBy('conversations.user_id')
            ->selectRaw('conversations.user_id, COALESCE(SUM(messages.cost_usd), 0) as total')
            ->pluck('total', 'conversations.user_id')->toArray();

        // agent_runs.user_id
        $runRows = DB::table('agent_runs')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, COALESCE(SUM(cost_usd), 0) as total')
            ->pluck('total', 'user_id')->toArray();

        // tender_service_analyses.generated_by_user_id
        $tsaRows = DB::table('tender_service_analyses')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('generated_by_user_id')
            ->groupBy('generated_by_user_id')
            ->selectRaw('generated_by_user_id, COALESCE(SUM(total_cost_usd), 0) as total')
            ->pluck('total', 'generated_by_user_id')->toArray();

        // Merge — sum por user_id
        $byUser = [];
        foreach ([$msgRows, $runRows, $tsaRows] as $rows) {
            foreach ($rows as $uid => $usd) {
                $byUser[(int) $uid] = ($byUser[(int) $uid] ?? 0.0) + ((float) $usd * $rate);
            }
        }

        // Sort descending by EUR
        arsort($byUser);
        foreach ($byUser as &$v) $v = round($v, 4);
        return $byUser;
    }

    /**
     * Ranking estruturado (top N) com nome do user + fair share.
     *
     * @return array<int,array{rank:int,user_id:int,name:string,email:string,eur_spent:float,pct_of_pool:float,vs_fair_share:float}>
     */
    public function rankingThisMonth(int $limit = 20): array
    {
        $budget = $this->currentBudget();
        $pool   = (float) $budget->pool_eur;
        $byUser = $this->spentByUserThisMonth();
        $activeCount = max(1, count($byUser));
        $fairShare = $pool / $activeCount;

        $users = User::whereIn('id', array_keys($byUser))->get(['id', 'name', 'email'])->keyBy('id');

        $out = [];
        $rank = 1;
        foreach (array_slice($byUser, 0, $limit, true) as $uid => $eur) {
            $u = $users[$uid] ?? null;
            $out[] = [
                'rank'          => $rank++,
                'user_id'       => $uid,
                'name'          => $u?->name ?? "user#{$uid}",
                'email'         => $u?->email ?? '?',
                'eur_spent'     => round($eur, 2),
                'pct_of_pool'   => $pool > 0 ? round(($eur / $pool) * 100, 1) : 0,
                'vs_fair_share' => $fairShare > 0 ? round($eur / $fairShare, 2) : 0,
            ];
        }
        return $out;
    }

    /** Resumo do período actual — usado pelo command + UI. */
    public function summary(): array
    {
        $budget    = $this->currentBudget();
        $pool      = (float) $budget->pool_eur;
        $spent     = $this->spentThisMonth();
        $remaining = max(0.0, $pool - $spent);
        $pct       = $pool > 0 ? round(($spent / $pool) * 100, 1) : 0;

        return [
            'period'         => $budget->period_yyyy_mm,
            'pool_eur'       => $pool,
            'spent_eur'      => $spent,
            'remaining_eur'  => round($remaining, 2),
            'percent_used'   => $pct,
            'alert_at'       => (int) $budget->alert_at_percent,
            'is_alert'       => $pct >= (int) $budget->alert_at_percent,
            'is_exhausted'   => $pct >= 100,
            'usd_eur_rate'   => $this->usdToEurRate(),
        ];
    }

    /**
     * Hard gate — devolve true quando novos chat calls devem ser BLOQUEADOS.
     *
     * Activo apenas quando hard_gate_at_percent > 0 E o pool ultrapassou
     * esse threshold no período actual. Default desactivado (gate = 0).
     *
     * Para activar:
     *   php artisan tokens:set-pool 150 --gate=95
     *
     * Chamado por AnthropicKeyTrait::headersForMessage() antes de cada
     * call Anthropic. Quando true, throws TokenPoolExhaustedException
     * que é capturada pelo handler e devolve 503 ao user.
     */
    public function isHardGated(): bool
    {
        // Cache 60s — chamado em headersForMessage() por CADA chat call.
        // Sem cache, faz currentBudget() + summary() (3 SUM queries) por
        // cada turn de cada agente. 2026-05-22: corrige NetworkError em
        // chat causado por latência acumulada.
        return \Illuminate\Support\Facades\Cache::remember(
            'token_hard_gated:v1',
            60,
            function () {
                try {
                    $budget = $this->currentBudget();
                    $gate   = (int) $budget->hard_gate_at_percent;
                    if ($gate <= 0) return false;
                    $summary = $this->summary();
                    return $summary['percent_used'] >= $gate;
                } catch (\Throwable) {
                    return false;  // fail open
                }
            }
        );
    }

    /**
     * Verifica thresholds e dispara notificações (idempotente).
     * Chamado por cron diário. Devolve true se enviou alguma notificação.
     */
    public function alertIfNeeded(): bool
    {
        $summary = $this->summary();
        $budget  = $this->currentBudget();
        $sent    = false;

        if ($summary['percent_used'] >= 100 && !$budget->notified_at_100) {
            $this->dispatchAlert('exhausted', $summary);
            $budget->update(['notified_at_100' => now()]);
            $sent = true;
        } elseif ($summary['is_alert'] && !$budget->notified_at_80) {
            $this->dispatchAlert('warning', $summary);
            $budget->update(['notified_at_80' => now()]);
            $sent = true;
        }

        return $sent;
    }

    /**
     * Envia alerta a TODOS os admins configurados (multi-recipient).
     * Usa Mailable formatado com botão "Mais Tokens" — clique leva
     * ao /admin/tokens para top-up.
     *
     * 2026-05-22: pedido directo — destinatários default são Bruno +
     * Catarina + Mónica (services.tokens.admin_emails).
     */
    private function dispatchAlert(string $kind, array $summary): void
    {
        $msg = $kind === 'exhausted'
            ? "🚨 Pool de tokens ESGOTADO ({$summary['percent_used']}%) — €{$summary['spent_eur']}/€{$summary['pool_eur']} no período {$summary['period']}."
            : "⚠️ Pool de tokens em {$summary['percent_used']}% — €{$summary['spent_eur']}/€{$summary['pool_eur']} no período {$summary['period']}. Threshold: {$summary['alert_at']}%.";

        Log::warning("TokenBudget alert: {$msg}");

        try {
            $emails = (array) config('services.tokens.admin_emails', []);
            $emails = array_values(array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
            if (empty($emails)) {
                Log::info('TokenBudget: nenhum admin_emails configurado — alerta só logado');
                return;
            }

            $dashboardUrl = rtrim((string) config('app.url'), '/') . '/admin/tokens';
            $mailable = new \App\Mail\TokenPoolAlertMail($kind, $summary, $dashboardUrl);

            foreach ($emails as $email) {
                try {
                    \Illuminate\Support\Facades\Mail::to($email)->send($mailable);
                    Log::info("TokenBudget: alert email enviado a {$email}");
                } catch (\Throwable $e) {
                    Log::warning("TokenBudget: falha a enviar a {$email} — " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TokenBudget: dispatchAlert falhou — ' . $e->getMessage());
        }
    }

    /**
     * Top-up — adiciona valor ao pool do período actual.
     * Reset das flags de notificação para que novos alertas disparem
     * quando os thresholds forem atingidos no pool aumentado.
     *
     * @return float novo pool_eur
     */
    public function topUp(?float $amount = null): float
    {
        $amount ??= (float) config('services.tokens.topup_amount', 50.00);
        $amount = max(0.0, min(10000.0, $amount));

        $budget = $this->currentBudget();
        $newPool = (float) $budget->pool_eur + $amount;
        $budget->update([
            'pool_eur'        => $newPool,
            'notified_at_80'  => null,
            'notified_at_100' => null,
        ]);

        Log::info('TokenBudget: top-up', [
            'period'  => $budget->period_yyyy_mm,
            'amount'  => $amount,
            'new_pool'=> $newPool,
            'by_user' => auth()->id(),
        ]);

        return $newPool;
    }
}
