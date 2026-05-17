<?php

namespace App\Console\Commands;

use App\Mail\WeeklyActivityReport;
use App\Models\AgentShare;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Envia o relatório semanal de actividade a cada utilizador activo.
 *
 *   php artisan clawyard:weekly-activity            # produção — todos os utilizadores
 *   php artisan clawyard:weekly-activity --dry      # só conta + mostra, não envia
 *   php artisan clawyard:weekly-activity --user=X   # debug — só um utilizador
 *
 * Agendado em routes/console.php para correr à segunda-feira às 08:00
 * (Europe/Lisbon).
 */
class SendWeeklyActivityCommand extends Command
{
    protected $signature = 'clawyard:weekly-activity
                            {--dry : Não envia, só mostra contagens}
                            {--user= : ID do utilizador (debug, manda só para um)}';

    protected $description = 'Envia o relatório semanal de actividade ClawYard';

    public function handle(): int
    {
        $weekAgo  = now()->subDays(7);
        $userIds  = $this->resolveUserIds();
        $sent     = 0;
        $skipped  = 0;
        $agentMeta = AgentShare::agentMeta();

        foreach ($userIds as $uid) {
            $user = User::find($uid);
            if (!$user || !$user->email || !$user->is_active) {
                $skipped++;
                continue;
            }

            // Pontos ganhos na semana
            $weekEvents = RewardEvent::query()
                ->where('user_id', $uid)
                ->where('created_at', '>=', $weekAgo)
                ->get();
            $weekPoints = (int) $weekEvents->sum('points');

            // Skip silencioso — sem actividade na semana não recebe email
            // (evita spam para quem está de férias). dry mostra à mesma.
            if ($weekPoints === 0 && !$this->option('dry')) {
                $skipped++;
                continue;
            }

            $points = UserPoints::find($uid);
            $level  = $points?->level ?? 0;
            $total  = $points?->total_points ?? 0;
            $next   = $level + 1;

            // Top 3 agentes da semana
            $top = $weekEvents
                ->whereNotNull('agent_key')
                ->groupBy('agent_key')
                ->map(fn ($g) => $g->count())
                ->sortDesc()
                ->take(3);
            $topAgents = $top->map(function ($chats, $key) use ($agentMeta) {
                $m = $agentMeta[$key] ?? [];
                return [
                    'agent' => $key,
                    'name'  => $m['name']  ?? ucfirst($key),
                    'emoji' => $m['emoji'] ?? '🤖',
                    'chats' => $chats,
                ];
            })->values()->all();

            // KPIs específicos
            $leadsQ   = $weekEvents->where('event_type', RewardEvent::TYPE_LEAD_QUALIFIED)->count();
            $tendersI = $weekEvents->where('event_type', RewardEvent::TYPE_TENDER_IMPORTED)->count();
            $chats    = $weekEvents->where('event_type', RewardEvent::TYPE_AGENT_CHAT)->count();

            // Ranking actual entre activos
            $rank = 1 + (int) UserPoints::query()
                ->join('users', 'users.id', '=', 'user_points.user_id')
                ->where('users.is_active', true)
                ->where('user_points.total_points', '>', $total)
                ->count();

            $stats = [
                'points_earned'   => $weekPoints,
                'total_points'    => (int) $total,
                'level'           => $level,
                'level_name'      => UserPoints::LEVEL_NAMES[$level] ?? 'Recruta',
                'next_level_name' => UserPoints::LEVEL_NAMES[$next]  ?? null,
                'points_to_next'  => $points?->pointsToNextLevel() ?? 0,
                'streak'          => (int) ($points?->current_streak_days ?? 0),
                'best_streak'     => (int) ($points?->best_streak_days ?? 0),
                'chats'           => $chats,
                'top_agents'      => $topAgents,
                'leads_qualified' => $leadsQ,
                'tenders_imported'=> $tendersI,
                'badges_earned'   => [], // futuros — pode comparar with previous_badges snapshot
                'rank'            => $rank,
            ];

            if ($this->option('dry')) {
                $this->info(sprintf(
                    '[DRY] %-30s %5d pts · L%d · #%d · %d chats · %d leads',
                    $user->email, $weekPoints, $level, $rank, $chats, $leadsQ
                ));
                $sent++;
                continue;
            }

            try {
                Mail::to($user->email)->send(new WeeklyActivityReport($user, $stats));
                $sent++;
                $this->line("  ✓ {$user->email} ({$weekPoints} pts)");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$user->email}: " . $e->getMessage());
                $skipped++;
            }
        }

        $this->info("\nResumo: {$sent} enviados · {$skipped} ignorados");
        return self::SUCCESS;
    }

    private function resolveUserIds(): array
    {
        if ($only = $this->option('user')) {
            return [(int) $only];
        }
        return User::where('is_active', true)->pluck('id')->all();
    }
}
