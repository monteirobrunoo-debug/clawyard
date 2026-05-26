<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

/**
 * Inspecciona uma BD SQLite (ex: sega_segk.db) e devolve schema completo:
 * tabelas, colunas, tipos, row counts, e 3 sample rows por tabela.
 *
 * Usado para descobrir o layout do NSN_Catalog antes de importar para
 * Postgres (nato_ncage / nato_nsn). Sem mexer em dados — read-only.
 *
 * Uso:
 *   php artisan nato:db-inspect /var/www/NSN_Catalog/sega_segk.db
 *   php artisan nato:db-inspect /var/www/NSN_Catalog/sega_segk.db --table=ncage
 *   php artisan nato:db-inspect /path/file.db --samples=10
 */
class NatoDbInspectCommand extends Command
{
    protected $signature = 'nato:db-inspect
                            {path : Path para o .db SQLite}
                            {--table= : Filtra para apenas esta tabela}
                            {--samples=3 : Quantas linhas exemplo por tabela}';

    protected $description = 'Inspecciona schema + samples de BD SQLite (read-only)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path)) {
            $this->error("Ficheiro não existe: {$path}");
            return self::FAILURE;
        }

        $size = filesize($path);
        $this->info("📂 {$path}");
        $this->line('   Tamanho: ' . $this->humanBytes((int) $size));
        $this->line('');

        try {
            // SQLite read-only DSN (não cria journal/wal)
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_READONLY_STATEMENT => true,
            ]);
        } catch (\Throwable $e) {
            // Fallback: alguns drivers não suportam READONLY_STATEMENT
            try {
                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (\Throwable $e2) {
                $this->error('Não consegui abrir SQLite: ' . $e2->getMessage());
                $this->line('  → Verifica que é SQLite válido: `file ' . escapeshellarg($path) . '`');
                return self::FAILURE;
            }
        }

        // Lista tabelas (filtra sqlite_* internas)
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tables)) {
            $this->warn('Nenhuma tabela encontrada.');
            return self::FAILURE;
        }

        $filter = (string) $this->option('table');
        $samples = max(0, min((int) $this->option('samples'), 50));

        $this->info('Tabelas (' . count($tables) . '):');
        $this->line('');

        foreach ($tables as $table) {
            if ($filter !== '' && stripos($table, $filter) === false) continue;

            // Row count (rápido via sqlite_stat ou COUNT)
            $count = '?';
            try {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM " . $this->quoteIdent($table))->fetchColumn();
            } catch (\Throwable) {
                // ignora
            }

            $this->line("━━━ <fg=cyan>{$table}</> — " . (is_int($count) ? number_format($count) . ' rows' : '?'));

            // Colunas via PRAGMA
            try {
                $cols = $pdo->query("PRAGMA table_info(" . $this->quoteIdent($table) . ")")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $this->warn('  Erro PRAGMA: ' . $e->getMessage());
                continue;
            }

            if (empty($cols)) {
                $this->line('  (sem colunas — view?)');
                continue;
            }

            $rows = array_map(fn ($c) => [
                $c['cid'] ?? '',
                $c['name'] ?? '',
                $c['type'] ?? '',
                $c['notnull'] ? 'NOT NULL' : '',
                $c['pk'] ? 'PK' : '',
            ], $cols);

            $this->table(['#', 'Coluna', 'Tipo', 'Null?', 'PK'], $rows);

            // Samples
            if ($samples > 0 && is_int($count) && $count > 0) {
                try {
                    $sampleRows = $pdo->query(
                        "SELECT * FROM " . $this->quoteIdent($table) . " LIMIT " . $samples
                    )->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($sampleRows)) {
                        $this->line('  <fg=yellow>Sample rows:</>');
                        foreach ($sampleRows as $i => $r) {
                            $this->line('  [' . ($i + 1) . '] ' . $this->compactRow($r));
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn('  Sample erro: ' . $e->getMessage());
                }
            }

            $this->line('');
        }

        // Hints de mapeamento
        $this->info('💡 Sugestão de mapeamento para Postgres:');
        $this->line('   php artisan nato:db-import ' . escapeshellarg($path) . ' --type=ncage --table=<nome>');
        $this->line('   php artisan nato:db-import ' . escapeshellarg($path) . ' --type=nsn   --table=<nome>');
        $this->line('');
        $this->line('   Auto-detect colunas activo. Se headers forem ambíguos, usa:');
        $this->line('   --map cage_code=cage,company_name=mfr,country_code=ctry');

        return self::SUCCESS;
    }

    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function compactRow(array $row): string
    {
        $parts = [];
        foreach ($row as $k => $v) {
            $v = is_string($v) ? mb_substr($v, 0, 40) : (string) $v;
            $v = str_replace(["\n", "\r", "\t"], ' ', $v);
            $parts[] = "{$k}={$v}";
            if (count($parts) >= 8) {
                $parts[] = '…';
                break;
            }
        }
        return implode(' · ', $parts);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return number_format($b, 2) . ' ' . $units[$i];
    }
}
