<?php

namespace App\Console\Commands;

use App\Models\NatoNcage;
use App\Models\NatoNsn;
use Illuminate\Console\Command;
use PDO;

/**
 * Importa BD SQLite (ex: sega_segk.db, FLIS extract) para Postgres
 * nato_ncage / nato_nsn. Streaming via rowid cursor — memória constante
 * mesmo em ficheiros multi-GB.
 *
 * Sinónimos de colunas auto-detectados (case-insensitive). Override
 * manual via --map.
 *
 * Uso:
 *   php artisan nato:db-import /var/www/NSN_Catalog/sega_segk.db \
 *       --type=ncage --table=ncage --chunk=5000
 *
 *   php artisan nato:db-import /var/www/NSN_Catalog/sega_segk.db \
 *       --type=nsn --table=nsn_data --chunk=5000 \
 *       --map=nsn=stock_number,description=item_name
 */
class NatoDbImportCommand extends Command
{
    protected $signature = 'nato:db-import
                            {path : Path para o .db SQLite}
                            {--type= : ncage|nsn (obrigatório)}
                            {--table= : Nome da tabela SQLite (obrigatório)}
                            {--map= : col1=field1,col2=field2 overrides (opcional)}
                            {--chunk=10000 : Rows por batch upsert (default 10000)}
                            {--limit=0 : Limita import a N rows (debug, 0 = todos)}
                            {--start-rowid=0 : Resume — começar a partir deste rowid (default 0)}
                            {--dry-run : Não escreve — só conta + valida mapping}';

    protected $description = 'Importa SQLite NSN_Catalog para Postgres nato_* (streaming)';

    /**
     * Sinónimos comuns de colunas — case-insensitive.
     * Inclui dialectos turco/militar para suportar catálogos NATO de outros
     * países (SEGA SEGK Turquia, etc.):
     *   • Nsc  ↔ fsc  (NATO Stock Class)
     *   • Niin ↔ niin (mas: 9 chars = NCB(2) + NIIN(7))
     *   • Anin ↔ description (Approved Item Name)
     *   • Namc ↔ manufacturer_cage (Manufacturer's NCAGE)
     *   • Iign ↔ manufacturer_pn (Item Identification / OEM PN)
     *   • Tiic ↔ unit_of_issue (Type Item Identification Code)
     *   • NiinStatusCode ↔ replaced_by indicator
     */
    private const SYNONYMS = [
        'ncage' => [
            'cage_code'    => ['cage_code', 'cage', 'ncage', 'code', 'ncage_code', 'cagec', 'namc'],
            'company_name' => ['company_name', 'company', 'name', 'manufacturer', 'manufacturer_name', 'mfr', 'mfr_name', 'fabricante'],
            'country_code' => ['country_code', 'country', 'iso', 'ctry', 'cntry', 'pais', 'country_iso'],
            'country_name' => ['country_name', 'country_long', 'pais_nome'],
            'city'         => ['city', 'cidade', 'localidade'],
            'address'      => ['address', 'street', 'morada', 'address1', 'addr'],
            'postcode'     => ['postcode', 'zip', 'cp', 'postal', 'postal_code', 'zip_code'],
            'phone'        => ['phone', 'telefone', 'tel', 'telephone'],
            'email'        => ['email', 'e-mail', 'email_address', 'contact_email'],
            'website'      => ['website', 'web', 'url', 'site', 'web_url'],
            'status'       => ['status', 'estado', 'state', 'cage_status'],
            'replaced_by'  => ['replaced_by', 'replacement', 'replaced', 'successor'],
        ],
        'nsn' => [
            'nsn'                => ['nsn', 'stock_number', 'nato_stock_number', 'nsn_code', 'nsnnumber'],
            'fsc'                => ['fsc', 'federal_supply_class', 'class', 'fsc_code', 'nsc'],
            'fsc_name'           => ['fsc_name', 'class_name', 'fsc_title'],
            'ncb'                => ['ncb', 'nato_country', 'ncb_code'],
            'niin'               => ['niin', 'item_id', 'niinnumber'],
            'description'        => ['description', 'item_description', 'item_name', 'name', 'descricao', 'descrição', 'item', 'anin'],
            'unit_of_issue'      => ['unit_of_issue', 'unit', 'uoi', 'ui', 'unit_issue', 'tiic'],
            'manufacturer_cage'  => ['manufacturer_cage', 'cage', 'cage_code', 'mfr_cage', 'fabricante_cage', 'ncage', 'namc'],
            'manufacturer_pn'    => ['manufacturer_pn', 'part_number', 'pn', 'mfr_pn', 'p_n', 'partno', 'iign'],
            'hazardous_material_code' => ['hazardous_material_code', 'hmc', 'haz', 'hazcode'],
            'replaced_by'        => ['replaced_by', 'nsnreplacement1', 'replacement', 'successor'],
            'replaced_by_2'      => ['replaced_by_2', 'nsnreplacement2', 'successor2'],
            'niin_status_code'   => ['niin_status_code', 'niinstatuscode', 'status_code', 'status'],
        ],
    ];

    public function handle(): int
    {
        $path       = (string) $this->argument('path');
        $type       = (string) $this->option('type');
        $table      = (string) $this->option('table');
        $chunk      = max(100, min((int) $this->option('chunk'), 50000));
        $limit      = max(0, (int) $this->option('limit'));
        $startRowid = max(0, (int) $this->option('start-rowid'));
        $dry        = (bool) $this->option('dry-run');

        if (!is_file($path)) {
            $this->error("Ficheiro não existe: {$path}");
            return self::FAILURE;
        }
        if (!in_array($type, ['ncage', 'nsn'], true)) {
            $this->error('--type tem de ser ncage ou nsn');
            return self::FAILURE;
        }
        if ($table === '') {
            $this->error('--table é obrigatório. Corre `nato:db-inspect ' . $path . '` primeiro.');
            return self::FAILURE;
        }

        // Abre SQLite read-only
        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            $this->error('Não consegui abrir: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Verifica que a tabela existe
        $exists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = " . $pdo->quote($table)
        )->fetchColumn();
        if (!$exists) {
            $this->error("Tabela '{$table}' não existe no SQLite. Verifica com nato:db-inspect.");
            return self::FAILURE;
        }

        // Descobre colunas
        $colsInfo = $pdo->query('PRAGMA table_info(' . $this->quoteIdent($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $sqliteCols = array_map(fn ($c) => (string) ($c['name'] ?? ''), $colsInfo);
        $hasRowid = !in_array(0, array_column($colsInfo, 'pk'), true)
                 || count(array_filter(array_column($colsInfo, 'pk'), fn ($p) => (int) $p > 0)) === 0;

        // Constrói mapping
        $map = $this->buildMap($type, $sqliteCols, (string) $this->option('map'));

        $this->info("📂 {$path}");
        $this->info("📊 Tabela: {$table}  · Tipo: {$type}  · Chunk: " . number_format($chunk));
        $this->info('🔗 Mapping (campo Postgres ← coluna SQLite):');
        foreach ($map as $field => $sqliteCol) {
            $this->line("    {$field} ← {$sqliteCol}");
        }
        $this->line('');

        // Total rows
        $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . $this->quoteIdent($table))->fetchColumn();
        if ($limit > 0) $total = min($total, $limit);
        $this->info('Total rows a processar: ' . number_format($total));

        if ($dry) {
            $this->warn('🧪 DRY RUN — nenhuma escrita. Sample (primeiras 3):');
            $sample = $pdo->query('SELECT * FROM ' . $this->quoteIdent($table) . ' LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sample as $i => $r) {
                $rec = $this->mapRow($r, $map, $type);
                $this->line('  [' . ($i + 1) . '] ' . json_encode($rec, JSON_UNESCAPED_UNICODE));
            }
            return self::SUCCESS;
        }

        // Streaming import: rowid cursor (sem OFFSET, escala para milhões de rows)
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% · %elapsed:6s% · %message%');
        $bar->setMessage('iniciando…');
        $bar->start();

        $cols = array_values($map);
        $orderCol = $hasRowid ? 'rowid' : ($cols[0] ?? 'rowid');

        $selectCols = '"rowid" AS __rowid__, ' . implode(', ', array_map(fn ($c) => $this->quoteIdent($c), $cols));
        $sql = "SELECT {$selectCols} FROM " . $this->quoteIdent($table)
             . " WHERE \"{$orderCol}\" > :lastId ORDER BY \"{$orderCol}\" LIMIT :lim";

        $stmt = $pdo->prepare($sql);

        $lastId = $startRowid;
        if ($startRowid > 0) {
            $this->warn("⏵  Resume: começar a partir do rowid {$startRowid}");
        }
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;
        $remaining = $total;

        while ($remaining > 0) {
            $thisChunk = min($chunk, $remaining);
            $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
            $stmt->bindValue(':lim',    $thisChunk, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) break;

            $records = [];
            foreach ($rows as $r) {
                $rowid = (int) ($r['__rowid__'] ?? 0);
                if ($rowid > $lastId) $lastId = $rowid;

                try {
                    $rec = $this->mapRow($r, $map, $type);
                    if ($rec) {
                        $records[] = $rec;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            if (!empty($records)) {
                // Postgres tem limite de 65,535 bind parameters por query.
                // Cada record tem ~15-17 colunas → max ~3,800 records por upsert.
                // Sub-chunk para 2,500 (conservador, deixa margem para variação).
                $fieldsCount  = count(array_keys($records[0]));
                $maxPerBatch  = max(500, (int) floor(60000 / max(1, $fieldsCount)));
                $batches      = array_chunk($records, $maxPerBatch);

                foreach ($batches as $batch) {
                    try {
                        if ($type === 'ncage') {
                            NatoNcage::upsert($batch, ['cage_code'], array_keys($batch[0]));
                        } else {
                            NatoNsn::upsert($batch, ['nsn'], array_keys($batch[0]));
                        }
                        $inserted += count($batch);
                    } catch (\Throwable $e) {
                        $errors += count($batch);
                        $bar->setMessage('erro batch: ' . mb_substr($e->getMessage(), 0, 60));
                    }
                }
                $bar->setMessage('upserts ' . number_format($inserted));
            }

            $bar->advance(count($rows));
            $remaining -= count($rows);
            if (count($rows) < $thisChunk) break; // fim
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✓ Import completo');
        $this->line('  Inseridos/actualizados: ' . number_format($inserted));
        $this->line('  Skipped (linhas inválidas): ' . number_format($skipped));
        $this->line('  Erros: ' . number_format($errors));

        $totalDb = $type === 'ncage' ? NatoNcage::count() : NatoNsn::count();
        $this->info("📊 Total em {$type}: " . number_format($totalDb));

        return self::SUCCESS;
    }

    /** @return array<string,string> field → sqlite_col */
    private function buildMap(string $type, array $sqliteCols, string $override): array
    {
        $synonyms = self::SYNONYMS[$type];
        $colsLower = array_map(fn ($c) => mb_strtolower($c), $sqliteCols);

        $map = [];
        foreach ($synonyms as $field => $names) {
            foreach ($names as $name) {
                $idx = array_search(mb_strtolower($name), $colsLower, true);
                if ($idx !== false) {
                    $map[$field] = $sqliteCols[$idx];
                    break;
                }
            }
        }

        // Parse overrides
        if ($override !== '') {
            foreach (explode(',', $override) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2) {
                    $field = trim($parts[0]);
                    $col   = trim($parts[1]);
                    if (in_array($col, $sqliteCols, true)) {
                        $map[$field] = $col;
                    }
                }
            }
        }

        // Primary key obrigatório — com fallback inteligente para NSN catalogs
        // que não têm coluna `nsn` directa mas têm fsc + niin separados (típico
        // em SEGA SEGK Turquia, FLIS US, e outros catálogos NATO oficiais).
        $primary = $type === 'ncage' ? 'cage_code' : 'nsn';
        if (!isset($map[$primary])) {
            // Fallback NSN: aceitar se temos fsc + niin (vamos construir o NSN)
            if ($type === 'nsn' && isset($map['fsc']) && isset($map['niin'])) {
                // OK — mapRow vai construir nsn = fsc . niin
                // Não setamos map['nsn'] aqui — o mapRow detecta a ausência e constrói.
            } else {
                throw new \RuntimeException(
                    "Coluna primary '{$primary}' não detectada. Use --map {$primary}=<col_no_sqlite>. "
                    . 'Colunas SQLite: ' . implode(', ', $sqliteCols)
                    . ($type === 'nsn' ? '. Alternativa: assegurar que --map inclui fsc=<col> e niin=<col>.' : '')
                );
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $map, string $type): ?array
    {
        $rec = [];
        foreach ($map as $field => $sqliteCol) {
            $val = $row[$sqliteCol] ?? null;
            if (is_string($val)) $val = trim($val);
            $rec[$field] = ($val === '' || $val === null) ? null : $val;
        }

        if ($type === 'ncage') {
            if (empty($rec['cage_code'])) return null;
            $rec['cage_code'] = strtoupper((string) $rec['cage_code']);
            if (mb_strlen($rec['cage_code']) > 10) return null;
            $rec['company_name'] = $rec['company_name'] ?? '(sem nome)';
        } else {
            // NSN: aceita 3 formatos de input
            //   1. Coluna nsn directa (13 dígitos com ou sem hifens)
            //   2. fsc(4) + niin(9 = NCB(2)+NIIN(7)) — caso turco SEGA SEGK
            //   3. fsc(4) + niin(7) (NCB ausente → ignora, sem normalizar)
            if (empty($rec['nsn'])) {
                $fsc  = (string) ($rec['fsc']  ?? '');
                $niin = (string) ($rec['niin'] ?? '');
                if ($fsc !== '' && $niin !== '') {
                    // Limpa: só dígitos. Caso 2: fsc(4) + niin(9 = NCB+NIIN) = 13.
                    $fscDigits  = preg_replace('/\D/', '', $fsc) ?? '';
                    $niinDigits = preg_replace('/\D/', '', $niin) ?? '';
                    $rec['nsn'] = $fscDigits . $niinDigits;
                }
            }

            $rec['nsn'] = NatoNsn::normalizeNsn((string) ($rec['nsn'] ?? '')) ?? null;
            if (!$rec['nsn']) return null;

            $parts = explode('-', $rec['nsn']);
            $rec['fsc']  = $rec['fsc']  ?? $parts[0];
            $rec['ncb']  = $rec['ncb']  ?? $parts[1];
            $rec['niin'] = ($parts[2] . $parts[3]);  // Sempre 7 dígitos, override input
            // Status code (NiinStatusCode = 'C' canceled, 'X' replaced, etc.)
            // Não persistimos directamente — só usamos se replaced_by_xxx existir
        }

        // raw = sample do row original (debug / forensics)
        $rec['raw'] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $rec['created_at'] = now();
        $rec['updated_at'] = now();
        return $rec;
    }

    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
