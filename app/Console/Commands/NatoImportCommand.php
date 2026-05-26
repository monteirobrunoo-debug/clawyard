<?php

namespace App\Console\Commands;

use App\Models\NatoNcage;
use App\Models\NatoNsn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa NCAGE / NSN do Excel para Postgres.
 *
 * Usage:
 *   php artisan nato:import /mnt/volume_ams3_0_ncrml_nato/ncage.xlsx --type=ncage
 *   php artisan nato:import /mnt/volume_ams3_0_ncrml_nato/nsn.xlsx --type=nsn
 *
 * Auto-detecta colunas pelos headers (case-insensitive).
 * Bulk insert 1000 rows por chunk para eficiência.
 * Idempotente: usa upsert por CAGE code (NCAGE) ou NSN (NSN).
 *
 * Headers reconhecidos (case-insensitive, qualquer ordem):
 *
 * NCAGE: cage_code | code | ncage | "cage"
 *        company_name | company | name | "manufacturer name"
 *        country_code | country | "country code"
 *        city, address, postcode, phone, email, website, status
 *
 * NSN:   nsn | "stock number" | "nato stock number"
 *        fsc | "federal supply class"
 *        description | "item description" | "name"
 *        manufacturer_cage | cage | "manufacturer"
 *        manufacturer_pn | "part number" | pn
 */
class NatoImportCommand extends Command
{
    protected $signature = 'nato:import
                            {file : Path para Excel (.xlsx)}
                            {--type= : ncage|nsn (auto-detect se omitido)}
                            {--chunk=1000 : Rows por chunk de insert}
                            {--truncate : Apaga tabela antes (cuidado)}';

    protected $description = 'Importa NATO codification (NCAGE/NSN) de Excel para Postgres';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("Ficheiro não existe: {$file}");
            return self::FAILURE;
        }

        $this->info("📂 Carregando {$file} ...");

        try {
            $reader = IOFactory::createReaderForFile($file);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file);
            $sheet = $spreadsheet->getActiveSheet();
        } catch (\Throwable $e) {
            $this->error("Erro ao abrir Excel: {$e->getMessage()}");
            return self::FAILURE;
        }

        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            $this->error('Ficheiro vazio (sem dados além de headers).');
            return self::FAILURE;
        }

        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows));
        $this->line('Headers detectados: ' . implode(' | ', array_filter($headers)));

        $type = (string) $this->option('type') ?: $this->detectType($headers);
        if (!in_array($type, ['ncage', 'nsn'], true)) {
            $this->error("Tipo não detectado. Use --type=ncage ou --type=nsn");
            return self::FAILURE;
        }

        $this->info("🎯 Tipo: {$type}");
        $this->info('📊 Total rows: ' . number_format(count($rows)));

        if ($this->option('truncate')) {
            $table = $type === 'ncage' ? 'nato_ncage' : 'nato_nsn';
            if ($this->confirm("Apagar TODOS os dados da tabela {$table}?", false)) {
                DB::table($table)->truncate();
                $this->warn("🗑  {$table} truncada");
            }
        }

        $chunkSize = max(100, min((int) $this->option('chunk'), 5000));
        $colMap = $this->buildColumnMap($headers, $type);

        $this->line('Mapeamento colunas:');
        foreach ($colMap as $field => $idx) {
            $this->line("  {$field} → coluna #{$idx} ({$headers[$idx]})");
        }

        $bar = $this->output->createProgressBar(count($rows));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% · %elapsed:6s% · %message%');
        $bar->setMessage('iniciando…');
        $bar->start();

        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $records = [];
            foreach ($chunk as $row) {
                try {
                    $rec = $this->mapRow($row, $colMap, $type);
                    if ($rec) {
                        $records[] = $rec;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                }
                $bar->advance();
            }

            if (!empty($records)) {
                try {
                    if ($type === 'ncage') {
                        NatoNcage::upsert($records, ['cage_code'], array_keys($records[0]));
                    } else {
                        NatoNsn::upsert($records, ['nsn'], array_keys($records[0]));
                    }
                    $inserted += count($records);
                    $bar->setMessage("inseridos {$inserted}");
                } catch (\Throwable $e) {
                    $errors += count($records);
                    $this->newLine();
                    $this->warn("Chunk erro: " . mb_substr($e->getMessage(), 0, 200));
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Importação completa");
        $this->line("  Inseridos/actualizados: " . number_format($inserted));
        $this->line("  Skipped (linhas vazias): " . number_format($skipped));
        $this->line("  Erros: " . number_format($errors));

        // Stats finais
        $total = $type === 'ncage' ? NatoNcage::count() : NatoNsn::count();
        $this->info("📊 Total em {$type}: " . number_format($total));

        return self::SUCCESS;
    }

    /** Detecta tipo (ncage|nsn) pelos headers. */
    private function detectType(array $headers): string
    {
        $h = implode(' ', $headers);
        if (str_contains($h, 'cage') || str_contains($h, 'ncage')) return 'ncage';
        if (str_contains($h, 'nsn') || str_contains($h, 'stock number')) return 'nsn';
        return '';
    }

    /** Mapeia header→coluna (case-insensitive, com sinónimos comuns). */
    private function buildColumnMap(array $headers, string $type): array
    {
        $synonyms = $type === 'ncage' ? [
            'cage_code'    => ['cage_code', 'cage', 'ncage', 'code', 'ncage_code'],
            'company_name' => ['company_name', 'company', 'name', 'manufacturer name', 'manufacturer_name', 'fabricante'],
            'country_code' => ['country_code', 'country code', 'country', 'iso', 'pais'],
            'country_name' => ['country_name', 'country name', 'pais_nome'],
            'city'         => ['city', 'cidade'],
            'address'      => ['address', 'morada'],
            'postcode'     => ['postcode', 'zip', 'cp', 'postal'],
            'phone'        => ['phone', 'telefone', 'tel'],
            'email'        => ['email', 'e-mail', 'contact'],
            'website'      => ['website', 'web', 'url', 'site'],
            'status'       => ['status', 'estado'],
            'replaced_by'  => ['replaced_by', 'replaced by', 'replacement'],
        ] : [
            'nsn'                => ['nsn', 'stock number', 'nato stock number', 'nsn_code'],
            'fsc'                => ['fsc', 'federal supply class', 'class'],
            'fsc_name'           => ['fsc_name', 'fsc name', 'class_name'],
            'ncb'                => ['ncb', 'nato country', 'country code'],
            'niin'               => ['niin', 'item id'],
            'description'        => ['description', 'item description', 'name', 'descrição', 'descricao'],
            'unit_of_issue'      => ['unit_of_issue', 'unit', 'uoi', 'ui'],
            'manufacturer_cage'  => ['manufacturer_cage', 'cage', 'manufacturer', 'fabricante_cage', 'ncage'],
            'manufacturer_pn'    => ['manufacturer_pn', 'part number', 'pn', 'p/n', 'part_number'],
            'hazardous_material_code' => ['hazardous_material_code', 'hmc', 'haz'],
        ];

        $map = [];
        foreach ($synonyms as $field => $names) {
            foreach ($names as $name) {
                $idx = array_search($name, $headers, true);
                if ($idx !== false) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        // CAGE/NSN são obrigatórios
        $primary = $type === 'ncage' ? 'cage_code' : 'nsn';
        if (!isset($map[$primary])) {
            throw new \RuntimeException("Coluna '{$primary}' não encontrada nos headers");
        }

        return $map;
    }

    /** Mapeia 1 row do Excel para array para insert. */
    private function mapRow(array $row, array $colMap, string $type): ?array
    {
        $rec = [];
        foreach ($colMap as $field => $idx) {
            $val = $row[$idx] ?? null;
            if (is_string($val)) $val = trim($val);
            $rec[$field] = $val === '' ? null : $val;
        }

        $primary = $type === 'ncage' ? 'cage_code' : 'nsn';
        if (empty($rec[$primary])) return null;

        // Limpeza específica
        if ($type === 'ncage') {
            $rec['cage_code'] = strtoupper((string) $rec['cage_code']);
            if (mb_strlen($rec['cage_code']) > 10) return null;
            $rec['company_name'] = $rec['company_name'] ?? '(sem nome)';
        } else {
            $rec['nsn'] = NatoNsn::normalizeNsn((string) $rec['nsn']) ?? null;
            if (!$rec['nsn']) return null;
            // Auto-extract FSC + NCB + NIIN do NSN canónico
            $parts = explode('-', $rec['nsn']);
            $rec['fsc']  = $rec['fsc']  ?? $parts[0];
            $rec['ncb']  = $rec['ncb']  ?? $parts[1];
            $rec['niin'] = $rec['niin'] ?? ($parts[2] . $parts[3]);
        }

        $rec['raw'] = json_encode(array_values(array_filter($row, fn ($v) => $v !== null)));
        $rec['created_at'] = now();
        $rec['updated_at'] = now();
        return $rec;
    }
}
