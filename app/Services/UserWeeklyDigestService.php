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
            'core'    => $this->coreStats($user, $weekStart, $weekEnd),
            'agents'  => $this->agentUsage($user, $weekStart, $weekEnd),
            'stats'   => $this->extraStats($user, $weekStart, $weekEnd, $prevWeekStart, $prevWeekEnd),
            'todos'   => $this->todoLists($user),
            'rewards' => $this->rewardsBlock($user, $weekStart, $weekEnd),
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

        // Concursos com deadline ≤ 7 dias
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

        // Concursos sem nº oportunidade SAP
        $missingSap = Tender::whereIn('id', $userTenderIds)
            ->needingSapOpportunity()
            ->limit(5)
            ->pluck('reference', 'id')
            ->toArray();

        return [
            'upcoming_deadlines' => $upcoming,
            'missing_sap_count'  => count($missingSap),
            'missing_sap_sample' => array_slice(array_values($missingSap), 0, 3),
        ];
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
            return [
                'team_submitted' => $teamSubmitted,
                'team_won'       => $teamWon,
                'orphan_tenders' => $orphans,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
