<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * MetricsController — dashboards admin para custo Anthropic e saúde dos agentes.
 *
 * Pedido Bruno 2026-05-28 (Fase A2 + A3):
 *   - /admin/anthropic-cost: total dia, top users, top agents, gráfico 30d
 *   - /admin/agent-health: feedback ratio, latency p50/p95, fail rate por agente
 *
 * Dados já existem em agent_runs (per-execution audit) + agent_metrics
 * (denormalised KPIs) — apenas consulta read-only com cache 60s.
 */
class MetricsController extends Controller
{
    /**
     * GET /admin/anthropic-cost
     */
    public function anthropicCost(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        // ── KPIs ──────────────────────────────────────────────────────────
        $today      = $this->sumCost(now()->startOfDay());
        $yesterday  = $this->sumCostBetween(now()->subDay()->startOfDay(), now()->startOfDay());
        $last7d     = $this->sumCost(now()->subDays(7));
        $last30d    = $this->sumCost(now()->subDays(30));

        // Anomaly flag — hoje > 2× média dos últimos 7 dias?
        $avg7d = $last7d / 7;
        $anomalyToday = $avg7d > 0 && $today > ($avg7d * 2);

        // ── Top 10 users (30d) ────────────────────────────────────────────
        $topUsers = DB::table('agent_runs')
            ->join('users', 'users.id', '=', 'agent_runs.user_id')
            ->where('agent_runs.created_at', '>=', now()->subDays(30))
            ->groupBy('users.id', 'users.name')
            ->select('users.name', DB::raw('SUM(cost_usd) as total_cost'), DB::raw('COUNT(*) as runs'))
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        // ── Top agents (30d) ──────────────────────────────────────────────
        $topAgents = DB::table('agent_runs')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('agent_key')
            ->select('agent_key', DB::raw('SUM(cost_usd) as total_cost'), DB::raw('COUNT(*) as runs'), DB::raw('AVG(duration_ms) as avg_ms'))
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        // ── Daily cost (30d) — para sparkline ─────────────────────────────
        $dailyCost = DB::table('agent_runs')
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->select(DB::raw("DATE(created_at) as day"), DB::raw('SUM(cost_usd) as cost'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('admin.metrics.anthropic-cost', compact(
            'today', 'yesterday', 'last7d', 'last30d',
            'anomalyToday', 'avg7d',
            'topUsers', 'topAgents', 'dailyCost',
        ));
    }

    /**
     * GET /admin/agent-health
     */
    public function agentHealth(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        // ── Por agente: counts, feedback, latency, fail rate (30d) ────────
        $agents = DB::table('agent_runs')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('agent_key')
            ->select(
                'agent_key',
                DB::raw('COUNT(*) as runs'),
                DB::raw("SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as fails"),
                DB::raw("SUM(CASE WHEN status='cost_capped' THEN 1 ELSE 0 END) as capped"),
                DB::raw('AVG(duration_ms) as avg_ms'),
                DB::raw('SUM(cost_usd) as total_cost'),
                DB::raw('SUM(input_tokens + output_tokens) as total_tokens'),
            )
            ->orderByDesc('runs')
            ->get()
            ->map(function ($a) {
                $a->fail_rate_pct = $a->runs > 0 ? round(($a->fails / $a->runs) * 100, 1) : 0;
                $a->avg_seconds   = $a->avg_ms ? round($a->avg_ms / 1000, 1) : null;
                $a->cost_per_run  = $a->runs > 0 ? round($a->total_cost / $a->runs, 4) : 0;
                return $a;
            });

        // ── Latency p50/p95 por agente (subquery) ─────────────────────────
        // Postgres: percentile_cont funciona; ordenamos por agent_key para join no Blade.
        $latency = DB::table('agent_runs')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('duration_ms')
            ->groupBy('agent_key')
            ->select(
                'agent_key',
                DB::raw('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY duration_ms) as p50_ms'),
                DB::raw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) as p95_ms'),
            )
            ->get()
            ->keyBy('agent_key');

        // ── Feedback do agent_metrics (denormalised) ──────────────────────
        $metrics = DB::table('agent_metrics')
            ->select('agent_key', 'thumbs_up', 'thumbs_down', 'leads_generated', 'leads_won', 'last_run_at')
            ->get()
            ->keyBy('agent_key');

        // ── Últimas 10 falhas (debug) ─────────────────────────────────────
        $recentFails = DB::table('agent_runs')
            ->leftJoin('users', 'users.id', '=', 'agent_runs.user_id')
            ->where('agent_runs.status', 'failed')
            ->orderByDesc('agent_runs.created_at')
            ->limit(10)
            ->select(
                'agent_runs.agent_key',
                'agent_runs.created_at',
                'agent_runs.error',
                'agent_runs.tender_id',
                'users.name as user_name',
            )
            ->get();

        return view('admin.metrics.agent-health', compact(
            'agents', 'latency', 'metrics', 'recentFails',
        ));
    }

    // ── helpers ───────────────────────────────────────────────────────────
    private function sumCost(\Carbon\Carbon $since): float
    {
        return (float) DB::table('agent_runs')
            ->where('created_at', '>=', $since)
            ->sum('cost_usd');
    }

    private function sumCostBetween(\Carbon\Carbon $from, \Carbon\Carbon $to): float
    {
        return (float) DB::table('agent_runs')
            ->whereBetween('created_at', [$from, $to])
            ->sum('cost_usd');
    }
}
