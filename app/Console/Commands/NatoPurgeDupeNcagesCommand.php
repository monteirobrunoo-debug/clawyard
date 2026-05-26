<?php

namespace App\Console\Commands;

use App\Models\NatoNcage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purga rows enriched onde a mesma empresa aparece em >N CAGEs diferentes.
 *
 * Razão: o Haiku gerou falsos positivos onde 11+ CAGEs ficaram atribuídos
 * a "Curtiss-Wright", 4 a "Schneider Electric", 3 a "Daken S.p.A.", etc.
 * São claramente alucinações — uma empresa real tem 1-2 CAGEs, não 11.
 *
 * Default: purga apenas onde >2 CAGEs apontam para a mesma empresa.
 * Idempotente. Não toca rows não-enriquecidas.
 *
 * Uso:
 *   php artisan nato:purge-dupe-ncages --dry-run
 *   php artisan nato:purge-dupe-ncages --threshold=3
 */
class NatoPurgeDupeNcagesCommand extends Command
{
    protected $signature = 'nato:purge-dupe-ncages
                            {--threshold=2 : Purga empresas com >N CAGEs (default 2 = manter ≤2)}
                            {--dry-run : Lista candidatos sem apagar}';

    protected $description = 'Purga rows enriched suspeitas (mesma empresa em demasiados CAGEs)';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $dry       = (bool) $this->option('dry-run');

        $this->info("NATO Purge — empresas com > {$threshold} CAGEs");
        $this->line('');

        // Lista empresas duplicadas
        $duplicates = DB::select(
            "SELECT company_name, COUNT(*) AS cnt
             FROM nato_ncage
             WHERE company_name IS NOT NULL
               AND company_name != '(sem nome)'
               AND status = 'enriched_via_tavily'
             GROUP BY company_name
             HAVING COUNT(*) > ?
             ORDER BY cnt DESC",
            [$threshold]
        );

        if (empty($duplicates)) {
            $this->info("✓ Nenhuma duplicação acima do threshold {$threshold}.");
            return self::SUCCESS;
        }

        $this->line('Empresas suspeitas (com mais de ' . $threshold . ' CAGEs):');
        $totalToDelete = 0;
        foreach ($duplicates as $d) {
            $this->line(sprintf('  %d× %s', $d->cnt, $d->company_name));
            $totalToDelete += $d->cnt;
        }
        $this->line('');
        $this->warn("Total rows a purgar: {$totalToDelete}");

        if ($dry) {
            $this->info('🧪 DRY RUN — nenhuma escrita.');
            return self::SUCCESS;
        }

        if (!$this->confirm("Purgar {$totalToDelete} rows de nato_ncage?", false)) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($duplicates as $d) {
            $n = NatoNcage::where('company_name', $d->company_name)
                ->where('status', 'enriched_via_tavily')
                ->delete();
            $deleted += $n;
            $this->line("  Apagado {$n}× {$d->company_name}");
        }

        $this->info("✓ Total apagado: {$deleted}");

        // Limpa cache Redis (negativa + positiva) para forçar re-enrich
        \Cache::flush();
        $this->warn('Cache Redis flushed (re-enrich vai correr fresh nas próximas perguntas).');

        return self::SUCCESS;
    }
}
