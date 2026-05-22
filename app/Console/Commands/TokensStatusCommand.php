<?php

namespace App\Console\Commands;

use App\Services\TokenBudgetService;
use Illuminate\Console\Command;

/**
 * tokens:status — pool, gasto, restante + ranking dos top consumers.
 *
 * Uso:
 *   php artisan tokens:status                # tabela completa
 *   php artisan tokens:status --limit=5     # só top 5
 *   php artisan tokens:status --notify      # corre alertIfNeeded (cron-friendly)
 */
class TokensStatusCommand extends Command
{
    protected $signature = 'tokens:status
                            {--limit=20 : Top N users no ranking}
                            {--notify : Disparar email de alerta se threshold atingido}';

    protected $description = 'Mostra pool de tokens Anthropic, gasto deste mês e ranking dos top consumers';

    public function handle(TokenBudgetService $svc): int
    {
        $summary = $svc->summary();

        $this->line('');
        $this->info(sprintf('═══ TOKEN BUDGET %s ═══', $summary['period']));
        $this->line('');

        $bar = $this->renderBar($summary['percent_used']);
        $this->line(sprintf('  Pool:       €%.2f', $summary['pool_eur']));
        $this->line(sprintf('  Gasto:      €%.2f  (%.1f%%)', $summary['spent_eur'], $summary['percent_used']));
        $this->line(sprintf('  Restante:   €%.2f', $summary['remaining_eur']));
        $this->line('  ' . $bar);
        $this->line(sprintf('  USD→EUR rate: %.3f', $summary['usd_eur_rate']));

        if ($summary['is_exhausted']) {
            $this->error('  🚨 POOL ESGOTADO — considera aumentar pool_eur ou pausar AI calls.');
        } elseif ($summary['is_alert']) {
            $this->warn(sprintf('  ⚠️  Alert threshold atingido (%d%%) — restante baixo.', $summary['alert_at']));
        }

        $this->line('');
        $ranking = $svc->rankingThisMonth((int) $this->option('limit'));
        if (empty($ranking)) {
            $this->line('  Sem actividade neste período ainda.');
        } else {
            $this->info('RANKING — top consumers (' . count($ranking) . '):');
            $this->table(
                ['#', 'User', 'Email', 'Gasto €', '% Pool', '× Fair Share'],
                array_map(fn ($r) => [
                    $r['rank'],
                    $r['name'],
                    $r['email'],
                    sprintf('%.2f', $r['eur_spent']),
                    sprintf('%.1f%%', $r['pct_of_pool']),
                    sprintf('%.2fx', $r['vs_fair_share']),
                ], $ranking),
            );
        }

        if ($this->option('notify')) {
            $sent = $svc->alertIfNeeded();
            $this->line('');
            $this->line($sent ? '  ✉️  Alerta enviado.' : '  (sem novos alertas a enviar)');
        }

        return self::SUCCESS;
    }

    private function renderBar(float $pct): string
    {
        $width = 30;
        $filled = (int) round(($pct / 100) * $width);
        $filled = min($width, max(0, $filled));
        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);
        $color = $pct >= 100 ? '31' : ($pct >= 80 ? '33' : '32');  // red / yellow / green
        return "\033[{$color}m[{$bar}]\033[0m " . number_format($pct, 1) . '%';
    }
}
