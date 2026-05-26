<?php

namespace App\Console\Commands;

use App\Models\NatoNcage;
use App\Services\NcageEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill proactivo de nomes de fabricantes para CAGE codes referenciados
 * em nato_nsn. Ordena por frequência (CAGEs mais usados primeiro) para
 * maximizar valor de cada $0.013 gasto.
 *
 * Uso:
 *   php artisan nato:enrich-cages --limit=100
 *   php artisan nato:enrich-cages --limit=1000 --dry-run
 *
 * Sem --limit, processa TODOS os CAGEs únicos. Para milhões podem ser
 * milhares de calls Tavily — usa --limit para controlar custo.
 *
 * Estimativa: cada CAGE = ~$0.013. 1,000 CAGEs = $13. 10,000 = $130.
 */
class NatoEnrichCagesCommand extends Command
{
    protected $signature = 'nato:enrich-cages
                            {--limit=100 : Máx CAGEs a processar (default 100)}
                            {--min-refs=1 : Só processa CAGEs referenciados N+ vezes em nato_nsn}
                            {--yes : Skipa prompt de confirmação (útil para nohup)}
                            {--dry-run : Lista CAGEs sem chamar Tavily}';

    protected $description = 'Backfill nato_ncage com nomes via Tavily+Haiku, ordenado por frequência';

    public function handle(NcageEnrichmentService $enricher): int
    {
        $limit   = max(1, (int) $this->option('limit'));
        $minRefs = max(1, (int) $this->option('min-refs'));
        $dry     = (bool) $this->option('dry-run');

        $this->info('NATO Enrichment — CAGEs sem nome canónico');
        $this->line('');

        // CAGEs em nato_nsn que NÃO têm row enriched em nato_ncage,
        // ordenados por número de NSNs que apontam para eles.
        $sql = <<<SQL
SELECT n.manufacturer_cage AS cage, COUNT(*) AS refs
FROM nato_nsn n
LEFT JOIN nato_ncage c ON c.cage_code = UPPER(n.manufacturer_cage)
WHERE n.manufacturer_cage IS NOT NULL
  AND n.manufacturer_cage != ''
  AND (
    c.id IS NULL
    OR c.company_name IS NULL
    OR c.company_name = '(sem nome)'
  )
GROUP BY n.manufacturer_cage
HAVING COUNT(*) >= ?
ORDER BY refs DESC
LIMIT ?
SQL;

        try {
            $rows = DB::select($sql, [$minRefs, $limit]);
        } catch (\Throwable $e) {
            $this->error('Query falhou: ' . $e->getMessage());
            return self::FAILURE;
        }

        $total = count($rows);
        if ($total === 0) {
            $this->info('✓ Nada a fazer — todos os CAGEs referenciados já estão enriquecidos.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$total} CAGEs a enriquecer (top " . number_format($limit) . ")");
        $this->line('');

        // Top 10 para ver
        $this->line('Top 10 candidatos:');
        foreach (array_slice($rows, 0, 10) as $r) {
            $this->line(sprintf('  %s — %s NSNs', $r->cage, number_format($r->refs)));
        }
        $this->line('');

        if ($dry) {
            $this->warn('🧪 DRY RUN — nenhuma call Tavily feita.');
            $estCost = $total * 0.013;
            $this->line(sprintf('Custo estimado se correres real: $%.2f (%d × \$0.013)', $estCost, $total));
            return self::SUCCESS;
        }

        if (!$this->option('yes')) {
            if (!$this->confirm("Processar {$total} CAGEs? Custo estimado ~$" . number_format($total * 0.013, 2), false)) {
                $this->warn('Cancelado.');
                return self::SUCCESS;
            }
        } else {
            $this->info('--yes activo, a processar sem confirmação...');
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% · %elapsed:6s% · %message%');
        $bar->setMessage('iniciando…');
        $bar->start();

        $enriched = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($rows as $r) {
            $cage = (string) $r->cage;
            try {
                $result = $enricher->enrich($cage);
                if ($result && !empty($result->company_name)
                    && $result->company_name !== '(sem nome)') {
                    $enriched++;
                    $bar->setMessage('último: ' . $cage . ' → ' . mb_substr($result->company_name, 0, 40));
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $bar->setMessage('falha: ' . $cage);
            }
            $bar->advance();

            // Rate-limit defensivo: 200ms entre calls para evitar 429 Tavily
            usleep(200_000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✓ Enrichment completo');
        $this->line('  Enriquecidos: ' . number_format($enriched));
        $this->line('  Skipped (sem evidência): ' . number_format($skipped));
        $this->line('  Falhados: ' . number_format($failed));
        $totalCost = number_format(($enriched + $skipped) * 0.013, 2);
        $this->line("  Custo estimado: ~\${$totalCost}");

        $totalNcage = NatoNcage::count();
        $this->info("📊 Total em nato_ncage agora: " . number_format($totalNcage));

        return self::SUCCESS;
    }
}
