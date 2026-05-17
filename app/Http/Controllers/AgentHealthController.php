<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\RewardEvent;
use App\Services\AgentCatalog;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard "Saúde dos agentes" — agregações sobre messages.tokens_*,
 * latency_ms, cost_usd e RewardEvent (thumbs / chats).
 *
 * Manager+ apenas — expõe custos e desempenho operacional que os utilizadores
 * regulares não devem ver.
 *
 *   GET /agents/health  — view com tabela + gráfico semanal
 *   GET /agents/health/data?range=7d  — JSON para o gráfico (range: 7d, 30d, 90d)
 */
class AgentHealthController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth'];
    }

    public function index()
    {
        if (!Auth::user()?->isManager()) abort(403);

        $rows = $this->aggregateAgents(days: 7);
        $totals = [
            'chats'      => $rows->sum('chats'),
            'tokens_in'  => $rows->sum('tokens_in'),
            'tokens_out' => $rows->sum('tokens_out'),
            'cost_usd'   => round($rows->sum('cost_usd'), 4),
            'failed_pct' => $rows->sum('chats') > 0
                ? round(($rows->sum('failed') / max(1, $rows->sum('chats'))) * 100, 1)
                : 0,
        ];

        $failedRecent = Message::query()
            ->where('is_failed', true)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id', 'agent', 'model', 'created_at', 'latency_ms']);

        return view('agents.health', [
            'rows'         => $rows,
            'totals'       => $totals,
            'failedRecent' => $failedRecent,
            'agentMeta'    => \App\Models\AgentShare::agentMeta(),
        ]);
    }

    /**
     * JSON p/ o gráfico. Devolve séries diárias por agente.
     */
    public function data(\Illuminate\Http\Request $req)
    {
        if (!Auth::user()?->isManager()) abort(403);
        $range = $req->query('range', '7d');
        $days  = match ($range) { '30d' => 30, '90d' => 90, default => 7 };

        $start = now()->subDays($days)->startOfDay();
        $raw = DB::table('messages')
            ->selectRaw('DATE(created_at) as d, agent, COUNT(*) as chats, SUM(latency_ms) as lat, SUM(tokens_in) as ti, SUM(tokens_out) as too, SUM(cost_usd) as cost')
            ->where('role', 'assistant')
            ->where('created_at', '>=', $start)
            ->whereNotNull('agent')
            ->groupByRaw('DATE(created_at), agent')
            ->get();

        // Pivot to { dates: [...], series: { agent: [count per day, ...] } }
        $dates = collect();
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates->push(now()->subDays($i)->toDateString());
        }
        $series = [];
        foreach ($raw as $row) {
            $series[$row->agent] ??= array_fill(0, $days, 0);
            $idx = $dates->search($row->d);
            if ($idx !== false) $series[$row->agent][$idx] = (int) $row->chats;
        }

        return response()->json([
            'dates'  => $dates->values(),
            'series' => $series,
        ]);
    }

    /**
     * Agregação principal: uma linha por agente com chats, tokens, custo,
     * latência p50/p95, thumbs e taxa de falha nos últimos $days dias.
     */
    private function aggregateAgents(int $days)
    {
        $start = now()->subDays($days);

        // Agregação base via SQL — uma só query rápida.
        $base = DB::table('messages')
            ->selectRaw('
                agent,
                COUNT(*) as chats,
                SUM(tokens_in) as tokens_in,
                SUM(tokens_out) as tokens_out,
                SUM(cost_usd) as cost_usd,
                AVG(latency_ms) as avg_latency,
                SUM(CASE WHEN is_failed THEN 1 ELSE 0 END) as failed
            ')
            ->where('role', 'assistant')
            ->where('created_at', '>=', $start)
            ->whereNotNull('agent')
            ->groupBy('agent')
            ->get()
            ->keyBy('agent');

        // p50 / p95 latência por agente — passe separado, pequeno N por agente.
        $latencies = DB::table('messages')
            ->select('agent', 'latency_ms')
            ->where('role', 'assistant')
            ->where('created_at', '>=', $start)
            ->whereNotNull('agent')
            ->whereNotNull('latency_ms')
            ->orderBy('agent')->orderBy('latency_ms')
            ->get()
            ->groupBy('agent');

        // Thumbs por agente desde RewardEvent.
        $thumbs = DB::table('reward_events')
            ->selectRaw("agent_key as agent,
                SUM(CASE WHEN event_type = 'agent_thumbs_up' THEN 1 ELSE 0 END) as ups,
                SUM(CASE WHEN event_type = 'agent_thumbs_down' THEN 1 ELSE 0 END) as downs")
            ->whereIn('event_type', ['agent_thumbs_up', 'agent_thumbs_down'])
            ->where('created_at', '>=', $start)
            ->whereNotNull('agent_key')
            ->groupBy('agent_key')
            ->get()
            ->keyBy('agent');

        $meta = AgentCatalog::all();

        return collect($base)->map(function ($row, $key) use ($latencies, $thumbs, $meta) {
            $lats = $latencies->get($key, collect())->pluck('latency_ms')->values();
            $p50 = $this->percentile($lats, 0.5);
            $p95 = $this->percentile($lats, 0.95);
            $thumbRow = $thumbs->get($key);
            $ups   = (int) ($thumbRow->ups ?? 0);
            $downs = (int) ($thumbRow->downs ?? 0);
            $trust = ($ups + $downs) > 0 ? round(($ups / ($ups + $downs)) * 100, 1) : null;

            return (object) [
                'agent'      => $key,
                'name'       => $meta[$key]['name'] ?? ucfirst($key),
                'emoji'      => $meta[$key]['emoji'] ?? '🤖',
                'chats'      => (int) $row->chats,
                'tokens_in'  => (int) $row->tokens_in,
                'tokens_out' => (int) $row->tokens_out,
                'cost_usd'   => round((float) $row->cost_usd, 4),
                'avg_latency'=> (int) round((float) $row->avg_latency),
                'p50_latency'=> $p50,
                'p95_latency'=> $p95,
                'failed'     => (int) $row->failed,
                'fail_pct'   => $row->chats > 0 ? round(($row->failed / $row->chats) * 100, 1) : 0,
                'thumbs_up'  => $ups,
                'thumbs_down'=> $downs,
                'trust_pct'  => $trust,
            ];
        })->sortByDesc('chats')->values();
    }

    private function percentile($values, float $p): int
    {
        $arr = $values->all();
        $n = count($arr);
        if ($n === 0) return 0;
        sort($arr);
        $idx = max(0, min($n - 1, (int) floor($p * ($n - 1))));
        return (int) $arr[$idx];
    }
}
