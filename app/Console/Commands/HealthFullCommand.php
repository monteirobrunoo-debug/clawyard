<?php

namespace App\Console\Commands;

use App\Models\AgentMemory;
use App\Models\Tender;
use App\Models\TenderServiceAnalysis;
use App\Models\TokenBudget;
use App\Models\User;
use App\Services\TokenBudgetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * health:full — diagnóstico abrangente do sistema + per-user.
 *
 * Pedido directo 2026-05-22: "testa o clawyard e verifica se está
 * tudo a funcionar bem em cada user, para nao termos erros".
 *
 * Output:
 *   • System health (DB, Redis, recent errors)
 *   • Token budget status
 *   • Cada user activo: tenders, memórias, último login, gasto tokens
 *   • Recent 500/exceptions no log
 *
 * Uso:
 *   php artisan health:full
 *   php artisan health:full --user=jose.inacio@hp-group.org
 */
class HealthFullCommand extends Command
{
    protected $signature = 'health:full
                            {--user= : Filtra por email parcial (substring)}';

    protected $description = 'Health check completo do ClawYard — system + per-user';

    public function handle(): int
    {
        $issues = [];

        // ── 1. SYSTEM ────────────────────────────────────────────────────────
        $this->info('═══ 1. System Health ═══');

        // DB
        try {
            $dbVersion = DB::selectOne('SELECT version() as v')->v ?? '?';
            $userCount = User::count();
            $this->line('  ✓ Postgres: ' . mb_substr($dbVersion, 0, 50));
            $this->line("  ✓ Users: {$userCount}");
        } catch (\Throwable $e) {
            $issues[] = 'DB: ' . $e->getMessage();
            $this->error('  ✗ DB falhou: ' . $e->getMessage());
        }

        // Redis
        try {
            Redis::ping();
            $this->line('  ✓ Redis ping OK');
        } catch (\Throwable $e) {
            $issues[] = 'Redis: ' . $e->getMessage();
            $this->error('  ✗ Redis falhou: ' . $e->getMessage());
        }

        // Cache
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'ok', 5);
            $val = Cache::get($key);
            $this->line('  ✓ Cache: ' . ($val === 'ok' ? 'OK' : 'WEIRD'));
            Cache::forget($key);
        } catch (\Throwable $e) {
            $issues[] = 'Cache: ' . $e->getMessage();
        }

        $this->line('');

        // ── 2. TOKEN BUDGET ──────────────────────────────────────────────────
        $this->info('═══ 2. Token Budget ═══');
        try {
            $svc = app(TokenBudgetService::class);
            $s = $svc->summary();
            $emoji = $s['percent_used'] >= 100 ? '🚨' : ($s['percent_used'] >= 80 ? '⚠️' : '✓');
            $this->line(sprintf(
                '  %s Pool %s: €%.2f / €%.2f (%.1f%%) · restam €%.2f',
                $emoji,
                $s['period'],
                $s['spent_eur'],
                $s['pool_eur'],
                $s['percent_used'],
                $s['remaining_eur']
            ));
        } catch (\Throwable $e) {
            $issues[] = 'TokenBudget: ' . $e->getMessage();
            $this->error('  ✗ TokenBudget falhou: ' . $e->getMessage());
        }
        $this->line('');

        // ── 3. PER-USER CHECK ────────────────────────────────────────────────
        $this->info('═══ 3. Per-User Status ═══');

        $query = User::query()
            ->where('email', 'not like', '%.merged-into-%')
            ->where('is_active', true)
            ->orderBy('email');

        if ($emailFilter = $this->option('user')) {
            $query->where('email', 'like', "%{$emailFilter}%");
        }

        $users = $query->get();
        if ($users->isEmpty()) {
            $this->warn('  Nenhum user encontrado.');
        }

        $byUserEur = [];
        try {
            $byUserEur = app(TokenBudgetService::class)->spentByUserThisMonth();
        } catch (\Throwable) {}

        $rows = [];
        foreach ($users as $u) {
            $row = [
                'email' => mb_substr($u->email, 0, 35),
                'role'  => $u->role ?? '?',
            ];

            // Last login
            try {
                $row['last_login'] = $u->last_login_at?->diffForHumans() ?? 'never';
            } catch (\Throwable) {
                $row['last_login'] = '?';
            }

            // Memories
            try {
                $row['memories'] = (int) AgentMemory::where('user_id', $u->id)->count();
            } catch (\Throwable) {
                $row['memories'] = '?';
            }

            // Tenders assigned
            try {
                $row['tenders'] = (int) DB::table('tender_user')->where('user_id', $u->id)->count();
            } catch (\Throwable) {
                try {
                    $row['tenders'] = (int) Tender::where('assigned_to_user_id', $u->id)->count();
                } catch (\Throwable) {
                    $row['tenders'] = '?';
                }
            }

            // Token spend this month
            $row['tokens_eur'] = sprintf('%.2f', $byUserEur[$u->id] ?? 0);

            // Recent activity check — has any chat/job in last 7 days?
            try {
                $hasRecent = DB::table('agent_runs')
                    ->where('user_id', $u->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                $row['active_7d'] = $hasRecent ? '✓' : '·';
            } catch (\Throwable) {
                $row['active_7d'] = '?';
            }

            $rows[] = $row;
        }

        $this->table(
            ['Email', 'Role', 'Last login', 'Memories', 'Tenders', '€ mês', '7d?'],
            array_map(fn ($r) => array_values($r), $rows),
        );

        // ── 4. ERROS RECENTES NO LOG ──────────────────────────────────────────
        $this->line('');
        $this->info('═══ 4. Erros recentes (últimos 50 linhas do log) ═══');
        $log = storage_path('logs/laravel.log');
        if (!file_exists($log)) {
            $this->line('  (log não existe — sem erros gravados ainda)');
        } else {
            $cmd = sprintf(
                'grep -iE "error|exception|fatal" %s | tail -10',
                escapeshellarg($log)
            );
            $out = trim((string) shell_exec($cmd));
            if ($out === '') {
                $this->line('  ✓ Nenhum erro recente.');
            } else {
                foreach (explode("\n", $out) as $line) {
                    $line = mb_substr($line, 0, 200);
                    $this->warn('  · ' . $line);
                }
                $issues[] = 'Erros no log — ver detalhe acima.';
            }
        }

        // ── 5. SUMÁRIO ───────────────────────────────────────────────────────
        $this->line('');
        if (empty($issues)) {
            $this->info('✅ Tudo OK. Sistema saudável para todos os users.');
            return self::SUCCESS;
        }

        $this->error('⚠ ' . count($issues) . ' issue(s) detectado(s):');
        foreach ($issues as $i) $this->error('  · ' . $i);
        return self::FAILURE;
    }
}
