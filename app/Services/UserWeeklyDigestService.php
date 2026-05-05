<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tender;
use App\Models\TenderAttachment;
use App\Models\TenderServiceAnalysis;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates per-user activity for a 7-day window (Mon-Sun) into a
 * structured array consumable by the WeeklyDigestMail template.
 *
 * Sections:
 *   • core      — concursos trabalhados / submetidos / agentes
 *   • stats     — PDFs / outreach / SAP / análises / custo IA / Δ semana
 *   • todos     — deadlines próximas / sem nº SAP / fornecedores PENDING
 *   • rewards   — pontos / nível / streak
 *   • manager   — agregados de equipa (apenas se isManager)
 */
class UserWeeklyDigestService
{
    /**
     * @param Carbon|null $weekStart  Monday of the digest week. Defaults
     *   to last Monday (so a Friday cron picks up Mon..Fri inclusive).
     */
    public function buildFor(User $user, ?Carbon $weekStart = null): array
    {
        $weekStart = ($weekStart ?? now()->startOfWeek())->copy()->startOfDay();
        $weekEnd   = $weekStart->copy()->addDays(7);

        $prevWeekStart = $weekStart->copy()->subDays(7);
        $prevWeekEnd   = $weekStart->copy();

        return [
            'user'        => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
            'week' => [
                'start' => $weekStart->format('d/m'),
                'end'   => $weekEnd->copy()->subDay()->format('d/m'),
                'iso'   => $weekStart->format('Y-W'),
            ],
            'core'         => $this->coreStats($user, $weekStart, $weekEnd),
            'agents'       => $this->agentUsage($user, $weekStart, $weekEnd),
            'stats'        => $this->extraStats($user, $weekStart, $weekEnd, $prevWeekStart, $prevWeekEnd),
            'top_categories' => $this->topCategories($user, $weekStart, $weekEnd),
            'top_suppliers'  => $this->topSuppliersContacted($user, $weekStart, $weekEnd),
            'cost'           => $this->aiCost($user, $weekStart, $weekEnd, $prevWeekStart, $prevWeekEnd),
            'todos'        => $this->todoLists($user),
            'intel'        => $this->intelligenceBlock($user),
            'team_compare' => $this->teamCompareAnonymized($weekStart, $weekEnd),
            'rewards'      => $this->rewardsBlock($user, $weekStart, $weekEnd),
            'manager' => method_exists($user, 'isManager') && $user->isManager()
                ? $this->managerOverview($weekStart, $weekEnd)
                : null,
        ];
    }

    // ── Sections ──────────────────────────────────────────────────────────

    private function coreStats(User $user, Carbon $start, Carbon $end): array
    {
        $userTenderIds = Tender::query()
            ->forUser($user->id)
            ->pluck('id');

        // Tenders with any change (status / collaboration / sap_opp) in window
        $touched = Tender::whereIn('id', $userTenderIds)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('updated_at', [$start, $end])
                  ->orWhereBetween('assigned_at', [$start, $end])
                  ->orWhereBetween('last_sap_sync_at', [$start, $end]);
            })
            ->get();

        $submitted = $touched->where('status', Tender::STATUS_SUBMETIDO);
        $won       = $touched->where('status', Tender::STATUS_GANHO);
        $lost      = $touched->where('status', Tender::STATUS_PERDIDO);

        return [
            'tenders_touched'   => $touched->count(),
            'tenders_submitted' => $submitted->count(),
            'tenders_won'       => $won->count(),
            'tenders_lost'      => $lost->count(),
            'submitted_list'    => $submitted->take(5)->map(fn($t) => [
                'id'        => $t->id,
                'reference' => $t->reference,
                'title'     => mb_substr((string) $t->title, 0, 80),
                'sap'       => $t->sap_opportunity_number,
            ])->values()->all(),
        ];
    }

    private function agentUsage(User $user, Carbon $start, Carbon $end): array
    {
        $sessionPattern = 'u' . $user->id . '_%';

        // Distinct conversations + total messages this week
        $convCount = Conversation::query()
            ->where('session_id', 'like', $sessionPattern)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Aggregate por agente — agent vive em conversations.agent
        // (não há agent_key em messages na schema actual). Quando uma
        // conversa "muda de agente" mid-stream, contamos para o agente
        // primário daquela sessão — aceitável para um digest semanal.
        $rows = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.session_id', 'like', $sessionPattern)
            ->whereBetween('messages.created_at', [$start, $end])
            ->where('messages.role', 'assistant')
            ->select(
                DB::raw('conversations.agent as agent'),
                DB::raw('COUNT(*) as msgs')
            )
            ->groupBy('conversations.agent')
            ->orderByDesc('msgs')
            ->limit(8)
            ->get();

        $totalMsgs = (int) $rows->sum('msgs');
        $top       = $rows->map(fn($r) => [
            'agent' => $r->agent ?: 'unknown',
            'msgs'  => (int) $r->msgs,
            'pct'   => $totalMsgs > 0 ? round(($r->msgs / $totalMsgs) * 100) : 0,
        ])->values()->all();

        return [
            'conversations' => $convCount,
            'total_messages'=> $totalMsgs,
            'top'           => $top,            // ordered by msgs DESC
            'top_agent'     => $top[0]['agent'] ?? null,
        ];
    }

    private function extraStats(User $user, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $userTenderIds = Tender::forUser($user->id)->pluck('id');

        // PDFs uploaded this week (across user's tenders)
        $pdfsThis = TenderAttachment::whereIn('tender_id', $userTenderIds)
            ->whereBetween('created_at', [$start, $end])->count();

        // Multi-agent service analyses generated by this user
        $analysesThis = TenderServiceAnalysis::where('generated_by_user_id', $user->id)
            ->whereBetween('generated_at', [$start, $end])->count();

        // SAP opportunities linked this week (proxy: tenders that got
        // their sap_opportunity_number filled in)
        $sapOppsThis = Tender::whereIn('id', $userTenderIds)
            ->whereNotNull('sap_opportunity_number')
            ->where('sap_opportunity_number', '!=', '')
            ->whereBetween('last_sap_sync_at', [$start, $end])
            ->count();

        // Δ semana — comparar nº de conversas com a semana anterior
        $sessionPattern = 'u' . $user->id . '_%';
        $convThis = Conversation::where('session_id', 'like', $sessionPattern)
            ->whereBetween('created_at', [$start, $end])->count();
        $convPrev = Conversation::where('session_id', 'like', $sessionPattern)
            ->whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $delta = $convPrev > 0
            ? (int) round((($convThis - $convPrev) / $convPrev) * 100)
            : ($convThis > 0 ? 100 : 0);

        // Streak — número de dias distintos com pelo menos 1 conversa
        $activeDays = Conversation::where('session_id', 'like', $sessionPattern)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as d')
            ->value('d') ?? 0;

        return [
            'pdfs_processed'    => $pdfsThis,
            'service_analyses'  => $analysesThis,
            'sap_opps_created'  => $sapOppsThis,
            'delta_pct'         => $delta,
            'active_days'       => (int) $activeDays,
        ];
    }

    private function todoLists(User $user): array
    {
        $userTenderIds = Tender::forUser($user->id)->pluck('id');

        // 15. Concursos com deadline ≤ 7 dias
        $upcoming = Tender::whereIn('id', $userTenderIds)
            ->active()
            ->whereNotIn('status', Tender::DONE_FROM_USER_POV)
            ->whereNotNull('deadline_at')
            ->whereBetween('deadline_at', [now(), now()->copy()->addDays(7)])
            ->orderBy('deadline_at')
            ->limit(5)
            ->get(['id', 'reference', 'title', 'deadline_at'])
            ->map(fn($t) => [
                'id'        => $t->id,
                'reference' => $t->reference,
                'title'     => mb_substr((string) $t->title, 0, 80),
                'deadline'  => $t->deadline_at?->format('d/m H:i'),
                'days'      => $t->deadline_at?->diffInDays(now()),
            ])->values()->all();

        // 16. Concursos sem nº oportunidade SAP
        $missingSap = Tender::whereIn('id', $userTenderIds)
            ->needingSapOpportunity()
            ->limit(5)
            ->pluck('reference', 'id')
            ->toArray();

        // 17. Fornecedores PENDING para validar (count global; aprovar
        // alarga o pool nas sugestões dos concursos)
        $pendingSuppliers = 0;
        try {
            $pendingSuppliers = DB::table('suppliers')
                ->where('status', 'pending')
                ->whereNotNull('primary_email')
                ->where('primary_email', '!=', '')
                ->count();
        } catch (\Throwable $e) { /* tabela ausente em testes */ }

        // 18. Concursos atrasados ainda recuperáveis
        $overdue = Tender::whereIn('id', $userTenderIds)
            ->overdue()
            ->orderBy('deadline_at')
            ->limit(5)
            ->get(['id', 'reference', 'title', 'deadline_at'])
            ->map(fn($t) => [
                'id'        => $t->id,
                'reference' => $t->reference,
                'title'     => mb_substr((string) $t->title, 0, 80),
                'deadline'  => $t->deadline_at?->format('d/m H:i'),
                'days_late' => $t->deadline_at?->diffInDays(now()),
            ])->values()->all();

        return [
            'upcoming_deadlines'  => $upcoming,
            'missing_sap_count'   => count($missingSap),
            'missing_sap_sample'  => array_slice(array_values($missingSap), 0, 3),
            'pending_suppliers'   => $pendingSuppliers,
            'overdue_recoverable' => $overdue,
        ];
    }

    /**
     * 8. Top 3 categorias H&P trabalhadas — agregadas dos
     * prelim_analysis.categories nos concursos touched esta semana.
     */
    private function topCategories(User $user, Carbon $start, Carbon $end): array
    {
        $userTenderIds = Tender::forUser($user->id)
            ->whereBetween('updated_at', [$start, $end])
            ->pluck('id');

        $rows = Tender::whereIn('id', $userTenderIds)
            ->whereNotNull('prelim_analysis')
            ->get(['prelim_analysis']);

        $tally = [];
        foreach ($rows as $r) {
            $cats = (array) ($r->prelim_analysis['categories'] ?? []);
            foreach ($cats as $c) {
                $tally[$c] = ($tally[$c] ?? 0) + 1;
            }
        }
        arsort($tally);

        $catLabels = [
            '1'  => 'Ships', '2' => 'Shipyard', '3' => 'Ship fittings',
            '4'  => 'Prime movers', '5' => 'Auxiliary', '6' => 'Propulsion',
            '7'  => 'Ship operation', '8' => 'Cargo handling', '9' => 'Electrical',
            '10' => 'Marine tech', '11' => 'Ports', '12' => 'Maritime services',
            '13' => 'Militar/Defesa', '14' => 'PartYard Systems',
            '15' => 'Industrial', '16' => 'Brand reps', '17' => 'Communications',
            '18' => 'Medical', '19' => 'Logistics', '20' => 'Storage',
        ];

        $top = [];
        foreach (array_slice($tally, 0, 3, true) as $code => $count) {
            $top[] = [
                'code'  => $code,
                'label' => $catLabels[$code] ?? "Cat {$code}",
                'count' => $count,
            ];
        }
        return $top;
    }

    /**
     * 9. Top 3 fornecedores contactados — supplier_outreach se
     * existir, senão devolve [] (degradação suave).
     */
    private function topSuppliersContacted(User $user, Carbon $start, Carbon $end): array
    {
        try {
            return DB::table('supplier_outreach')
                ->join('suppliers', 'suppliers.id', '=', 'supplier_outreach.supplier_id')
                ->where('supplier_outreach.created_by_user_id', $user->id)
                ->whereBetween('supplier_outreach.created_at', [$start, $end])
                ->select(
                    'suppliers.id',
                    'suppliers.name',
                    DB::raw('COUNT(*) as touches')
                )
                ->groupBy('suppliers.id', 'suppliers.name')
                ->orderByDesc('touches')
                ->limit(3)
                ->get()
                ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'touches' => (int) $r->touches])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 14. Custo IA — semana, semana anterior, mês corrente.
     * Fonte: tender_service_analyses.total_cost_usd (proxy razoável
     * porque é onde o user gasta mais; chat normal não tracked por user).
     */
    private function aiCost(User $user, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        try {
            $weekCost  = (float) DB::table('tender_service_analyses')
                ->where('generated_by_user_id', $user->id)
                ->whereBetween('generated_at', [$start, $end])
                ->sum('total_cost_usd');
            $prevCost  = (float) DB::table('tender_service_analyses')
                ->where('generated_by_user_id', $user->id)
                ->whereBetween('generated_at', [$prevStart, $prevEnd])
                ->sum('total_cost_usd');
            $monthCost = (float) DB::table('tender_service_analyses')
                ->where('generated_by_user_id', $user->id)
                ->whereBetween('generated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('total_cost_usd');
            return [
                'week_usd'  => round($weekCost, 4),
                'prev_usd'  => round($prevCost, 4),
                'month_usd' => round($monthCost, 4),
            ];
        } catch (\Throwable $e) {
            return ['week_usd' => 0, 'prev_usd' => 0, 'month_usd' => 0];
        }
    }

    /**
     * 19+20. Discoveries arXiv/patents (act_now/monitor, últimos 14d)
     * + concursos órfãos sem owner que matcham categorias do user.
     */
    private function intelligenceBlock(User $user): array
    {
        $discoveries = [];
        try {
            $discoveries = DB::table('discoveries')
                ->whereIn('priority', ['act_now', 'monitor'])
                ->where('created_at', '>=', now()->copy()->subDays(14))
                ->orderByDesc('relevance_score')
                ->orderByDesc('created_at')
                ->limit(3)
                ->get(['id', 'title', 'source', 'category', 'relevance_score', 'url'])
                ->map(fn($d) => [
                    'title'    => mb_substr((string) $d->title, 0, 90),
                    'source'   => $d->source,
                    'category' => $d->category,
                    'score'    => (int) $d->relevance_score,
                    'url'      => $d->url,
                ])->values()->all();
        } catch (\Throwable $e) { /* discoveries opcional */ }

        $orphanMatches = [];
        try {
            $myCategories = Tender::forUser($user->id)
                ->where('updated_at', '>=', now()->copy()->subDays(90))
                ->whereNotNull('prelim_analysis')
                ->get(['prelim_analysis'])
                ->flatMap(fn($t) => (array) ($t->prelim_analysis['categories'] ?? []))
                ->unique()
                ->take(5)
                ->all();

            if (!empty($myCategories)) {
                $orphans = Tender::query()
                    ->active()
                    ->whereNotIn('status', Tender::DONE_FROM_USER_POV)
                    ->whereNull('assigned_collaborator_id')
                    ->where('created_at', '>=', now()->copy()->subDays(7))
                    ->whereNotNull('prelim_analysis')
                    ->limit(20)
                    ->get(['id', 'reference', 'title', 'prelim_analysis']);

                foreach ($orphans as $o) {
                    $oCats = (array) ($o->prelim_analysis['categories'] ?? []);
                    if (array_intersect($oCats, $myCategories)) {
                        $orphanMatches[] = [
                            'id'        => $o->id,
                            'reference' => $o->reference,
                            'title'     => mb_substr((string) $o->title, 0, 80),
                        ];
                        if (count($orphanMatches) >= 3) break;
                    }
                }
            }
        } catch (\Throwable $e) { /* prelim_analysis pode estar ausente */ }

        return [
            'discoveries'    => $discoveries,
            'orphan_matches' => $orphanMatches,
        ];
    }

    /**
     * 21. Comparação anonimizada — só posições + counts, sem nomes,
     * para criar contexto sem expor colegas individualmente.
     */
    private function teamCompareAnonymized(Carbon $start, Carbon $end): array
    {
        try {
            $driver = DB::connection()->getDriverName();
            // Postgres regexp_replace; SQLite/MySQL não têm — fallback p/ LIKE prefix
            if ($driver === 'pgsql') {
                $rows = DB::table('messages')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->whereBetween('messages.created_at', [$start, $end])
                    ->where('messages.role', 'user')
                    ->select(
                        DB::raw("regexp_replace(conversations.session_id, '^u(\\d+)_.*', '\\1') as uid"),
                        DB::raw('COUNT(*) as msgs')
                    )
                    ->groupBy('uid')
                    ->orderByDesc('msgs')
                    ->limit(3)
                    ->get();
                return $rows->map(fn($r, $i) => [
                    'rank' => $i + 1,
                    'msgs' => (int) $r->msgs,
                ])->values()->all();
            }
            return [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function rewardsBlock(User $user, Carbon $start, Carbon $end): array
    {
        try {
            $earned = DB::table('reward_events')
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->sum('points');

            $points = $user->pointsRow();
            return [
                'points_this_week' => (int) $earned,
                'total_points'     => (int) ($points->total_points ?? 0),
                'level'            => (int) ($points->level ?? 1),
            ];
        } catch (\Throwable $e) {
            return ['points_this_week' => 0, 'total_points' => 0, 'level' => 0];
        }
    }

    /**
     * 22-25. Manager block — agregados de equipa + custo LLM total
     * + integrações em down (health snapshot).
     */
    private function managerOverview(Carbon $start, Carbon $end): array
    {
        try {
            $teamSubmitted = Tender::where('status', Tender::STATUS_SUBMETIDO)
                ->whereBetween('updated_at', [$start, $end])->count();
            $teamWon       = Tender::where('status', Tender::STATUS_GANHO)
                ->whereBetween('updated_at', [$start, $end])->count();
            $orphans       = Tender::active()
                ->whereNull('assigned_collaborator_id')
                ->where('created_at', '<=', now()->copy()->subDays(14))
                ->count();

            // 23. Custo LLM total — semana corrente + mês corrente
            // Soma de TODAS as análises (não só as deste user)
            $teamWeekCost  = (float) DB::table('tender_service_analyses')
                ->whereBetween('generated_at', [$start, $end])
                ->sum('total_cost_usd');
            $teamMonthCost = (float) DB::table('tender_service_analyses')
                ->whereBetween('generated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('total_cost_usd');

            // 25. Integrações em down — best-effort, lê o cache do
            // IntegrationHealthChecker se ainda fresco
            $downIntegrations = [];
            try {
                $health = app(\App\Services\IntegrationHealthChecker::class)->report();
                foreach ($health as $key => $check) {
                    if (($check['state'] ?? null) === 'down') {
                        $downIntegrations[] = $key;
                    }
                }
            } catch (\Throwable $e) { /* health probe falha — não bloquear digest */ }

            return [
                'team_submitted'  => $teamSubmitted,
                'team_won'        => $teamWon,
                'orphan_tenders'  => $orphans,
                'team_week_cost'  => round($teamWeekCost, 4),
                'team_month_cost' => round($teamMonthCost, 4),
                'down_integrations' => $downIntegrations,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
