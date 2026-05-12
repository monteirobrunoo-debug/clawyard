<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * #7 — Admin health dashboard.
 *
 * GET /admin/health
 * Replaces the need to ssh+grep+ps for routine status checks. Surfaces:
 *   • DB row counts (conversations, messages, tenders, leads, audit, books)
 *   • Library embedding coverage (chunks total vs embedded)
 *   • Stream errors in last 24h (from log file)
 *   • Active agents in last hour (from messages.agent)
 *   • Disk + Postgres connection
 *
 * Cached 30s so refreshing doesn't hammer DB or read big log files.
 */
class HealthDashboardController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = Cache::remember('admin_health_dashboard:v1', 30, function () {
            return [
                'db'         => $this->dbStats(),
                'books'      => $this->bookStats(),
                'agents'     => $this->agentActivity(),
                'errors'     => $this->recentStreamErrors(),
                'disk'       => $this->diskUsage(),
                'pg_version' => $this->postgresVersion(),
                'fetched_at' => now()->toIso8601String(),
            ];
        });

        return view('admin.health', compact('data'));
    }

    private function dbStats(): array
    {
        return [
            'users'            => DB::table('users')->count(),
            'conversations'    => DB::table('conversations')->count(),
            'messages'         => DB::table('messages')->count(),
            'tenders'          => DB::table('tenders')->count(),
            'leads'            => DB::table('lead_opportunities')->count(),
            'audit_logs'       => $this->countSafe('audit_logs'),
            'reports'          => DB::table('reports')->count(),
            'msgs_last_24h'    => DB::table('messages')->where('created_at', '>=', now()->subDay())->count(),
            'convos_last_24h'  => DB::table('conversations')->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    private function countSafe(string $table): int
    {
        try { return DB::table($table)->count(); } catch (\Throwable $e) { return 0; }
    }

    private function bookStats(): array
    {
        $total    = DB::table('technical_book_chunks')->count();
        $embedded = DB::table('technical_book_chunks')->whereNotNull('embedding')->count();
        return [
            'total_books'    => DB::table('technical_book_chunks')->distinct('book_key')->count('book_key'),
            'total_chunks'   => $total,
            'embedded'       => $embedded,
            'missing'        => $total - $embedded,
            'coverage_pct'   => $total > 0 ? round(($embedded / $total) * 100, 1) : 0,
            'by_domain'      => DB::table('technical_book_chunks')
                ->selectRaw('domain, count(distinct book_key) as books, count(*) as chunks')
                ->groupBy('domain')->orderBy('domain')->get()->toArray(),
        ];
    }

    private function agentActivity(): array
    {
        return DB::table('messages')
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subHour())
            ->whereNotNull('agent')
            ->selectRaw('agent, count(*) as c')
            ->groupBy('agent')
            ->orderByDesc('c')
            ->limit(20)
            ->get()->toArray();
    }

    private function recentStreamErrors(): array
    {
        $log = storage_path('logs/laravel-' . now()->toDateString() . '.log');
        if (!is_file($log)) {
            $log = storage_path('logs/laravel.log');
            if (!is_file($log)) return [];
        }
        // Tail 2 MB only — avoids loading huge log files into memory.
        $fp = fopen($log, 'r');
        if (!$fp) return [];
        $size = filesize($log);
        fseek($fp, max(0, $size - 2 * 1024 * 1024));
        $tail = fread($fp, 2 * 1024 * 1024);
        fclose($fp);

        $lines = preg_split('/^\[/m', $tail) ?: [];
        $errs  = [];
        foreach (array_reverse($lines) as $entry) {
            if (str_contains($entry, 'ClawYard stream error') || str_contains($entry, '.ERROR')) {
                $when    = mb_substr($entry, 0, 19);
                $oneLine = mb_substr(preg_replace('/\s+/', ' ', $entry) ?: '', 0, 180);
                $errs[]  = ['at' => $when, 'line' => $oneLine];
                if (count($errs) >= 10) break;
            }
        }
        return $errs;
    }

    private function diskUsage(): array
    {
        $total = @disk_total_space('/');
        $free  = @disk_free_space('/');
        if (!$total) return [];
        return [
            'total_gb' => round($total / 1024 / 1024 / 1024, 1),
            'free_gb'  => round($free  / 1024 / 1024 / 1024, 1),
            'used_pct' => round(100 * ($total - $free) / $total, 1),
        ];
    }

    private function postgresVersion(): string
    {
        try {
            $v = DB::selectOne('SELECT version()');
            return $v ? (string) ($v->version ?? '?') : '?';
        } catch (\Throwable $e) {
            return 'err: ' . $e->getMessage();
        }
    }
}
