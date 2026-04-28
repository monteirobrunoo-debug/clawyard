<?php

namespace App\Http\Controllers;

use App\Models\AgentMetric;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;

/**
 * /rewards/* — read paths for the gamification subsystem.
 *
 *   GET /rewards/me          — personal dashboard (current user)
 *   GET /rewards/leaderboard — H&P-wide ranking
 *
 * Write paths live in App\Services\Rewards\RewardRecorder; this
 * controller is read-only.
 *
 * Visibility:
 *   /rewards/me          → any authenticated user (it's their own data)
 *   /rewards/leaderboard → manager+ only — the leaderboard is a team
 *                          performance signal, not gen-pop content,
 *                          and we don't want regulars to feel ranked.
 */
class RewardsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    /**
     * Personal dashboard — the current user's points/level/streak,
     * recent earning events, and earned badges.
     */
    public function me(Request $request)
    {
        $user = Auth::user();

        // Lazy-create the points row so brand-new users see zeros
        // instead of a NULL relation crashing the view.
        $points = $user->pointsRow();

        // Last 30 days of earning events. Include zero-point ones
        // (cap-reached, etc.) — operators want to see EVERYTHING they
        // did, even what didn't earn.
        $recentEvents = RewardEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Day-by-day points totals for the spark chart (last 14 days).
        // Returned as { 'YYYY-MM-DD' => points } with zero-fill so the
        // view doesn't have to handle missing days.
        $dailyTotals = $this->dailySparklineData($user->id, days: 14);

        return view('rewards.me', [
            'user'         => $user,
            'points'       => $points,
            'recentEvents' => $recentEvents,
            'dailyTotals'  => $dailyTotals,
            'levelNames'   => UserPoints::LEVEL_NAMES,
            'thresholds'   => UserPoints::LEVEL_THRESHOLDS,
        ]);
    }

    /**
     * H&P-wide leaderboard. Ranks all active users by total_points
     * descending. Caps at top 50 — beyond that the page is noise.
     *
     * Manager+ only — see middleware above.
     */
    public function leaderboard(Request $request)
    {
        if (!Auth::user()?->isManager()) abort(403);

        // Join user_points + users so the view has both the score AND
        // the display name in one pass (no N+1).
        $rows = UserPoints::query()
            ->join('users', 'users.id', '=', 'user_points.user_id')
            ->where('users.is_active', true)
            ->orderByDesc('user_points.total_points')
            ->orderByDesc('user_points.current_streak_days')
            ->limit(50)
            ->get([
                'user_points.user_id',
                'user_points.total_points',
                'user_points.level',
                'user_points.current_streak_days',
                'user_points.best_streak_days',
                'user_points.badges',
                'users.name',
                'users.email',
                'users.role',
            ]);

        // Top agents bar at the top — surfaces the most active agents
        // by leads_generated. Reuses the same page so managers don't
        // have to navigate twice.
        $topAgents = AgentMetric::query()
            ->where('signals_processed', '>', 0)
            ->orderByDesc('leads_generated')
            ->orderByDesc('signals_processed')
            ->limit(10)
            ->get();

        return view('rewards.leaderboard', [
            'rows'      => $rows,
            'topAgents' => $topAgents,
        ]);
    }

    /**
     * Build a { date => points } map for the last N days, zero-
     * filling missing days so the spark chart in the view is a flat
     * 14-element array.
     *
     * @return array<string, int>  YYYY-MM-DD => points earned that day
     */
    private function dailySparklineData(int $userId, int $days): array
    {
        $bucket = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $bucket[now()->subDays($i)->toDateString()] = 0;
        }

        $rows = RewardEvent::query()
            ->selectRaw('DATE(created_at) as d, SUM(points) as p')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->groupByRaw('DATE(created_at)')
            ->get();

        foreach ($rows as $row) {
            $bucket[$row->d] = (int) $row->p;
        }

        return $bucket;
    }
}
