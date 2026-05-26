<?php

namespace App\Console\Commands;

use App\Services\NatoCodificationService;
use Illuminate\Console\Command;

/**
 * Estado actual do dataset NATO local. Util para verificar pós-import
 * e diagnosticar quando Cor. Rodrigues volta a cair em Tavily.
 *
 * Uso: php artisan nato:stats
 */
class NatoStatsCommand extends Command
{
    protected $signature = 'nato:stats';

    protected $description = 'Mostra contagem actual de NCAGE/NSN/países e última actualização';

    public function handle(NatoCodificationService $nato): int
    {
        $this->info('NATO Codification — dataset local');
        $this->line('');

        $available = $nato->isAvailable();
        $this->line('Disponível: ' . ($available ? '<info>SIM</info>' : '<comment>NÃO (sem dados)</comment>'));

        $stats = $nato->stats();
        if (isset($stats['error'])) {
            $this->error('Erro: ' . $stats['error']);
            return self::FAILURE;
        }

        $this->line('');
        $this->table(
            ['Tabela', 'Rows', 'Última actualização'],
            [
                ['nato_ncage',         number_format($stats['ncage_count']     ?? 0), $stats['last_ncage_at'] ?? '—'],
                ['nato_nsn',           number_format($stats['nsn_count']       ?? 0), $stats['last_nsn_at']   ?? '—'],
                ['nato_country_codes', number_format($stats['countries_count'] ?? 0), '(seed)'],
            ]
        );

        if (!$available) {
            $this->line('');
            $this->warn('Sem dados ainda — agentes vão usar Tavily.');
            $this->line('Importar com:');
            $this->line('  php artisan nato:import /mnt/volume_ams3_0_ncrml_nato/ncage.xlsx --type=ncage');
            $this->line('  php artisan nato:import /mnt/volume_ams3_0_ncrml_nato/nsn.xlsx --type=nsn');
        }

        return self::SUCCESS;
    }
}
