<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * php artisan suppliers:import {path} [--sheet=Classificação Fornecedores] [--dry-run]
 *
 * Reads the H&P "Fornecedores Aprovados" workbook and upserts each row
 * into the suppliers table. Idempotent — re-running with the same file
 * (or a refreshed annual version) merges rather than duplicates,
 * because the slug is the dedup key (see Supplier::makeSlug).
 *
 * What gets imported per row:
 *   • name                ← column "Empresa"
 *   • iqf_score           ← column "Indice Qualidade Fornecedor"
 *   • categories          ← column index of any non-empty cat cell
 *                            ("4. prime movers..." → "4")
 *   • subcategories       ← cell content split on /,or whitespace
 *                            ("13.7 / 13.45" → ["13.7", "13.45"])
 *   • status              ← "approved" when iqf >= 2.5; "pending" otherwise
 *   • source              ← "excel_2026"
 *   • source_meta         ← raw row as jsonb for audit
 *
 * Re-runs DON'T overwrite enriched fields (primary_email, additional_emails,
 * phones, brands, notes) because those usually come from agent extraction
 * after the first Excel import — clobbering would lose data.
 */
class ImportApprovedSuppliersCommand extends Command
{
    protected $signature = 'suppliers:import
        {path : Absolute path to the .xlsx workbook}
        {--sheet=Classificação Fornecedores : Sheet name containing the supplier list}
        {--dry-run : Parse and report without writing to the DB}';

    protected $description = 'Import the H&P "Fornecedores Aprovados" workbook into the suppliers table.';

    public function handle(): int
    {
        $path  = (string) $this->argument('path');
        $sheet = (string) $this->option('sheet');
        $dry   = (bool)   $this->option('dry-run');

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->info("Reading {$path} (sheet: {$sheet})…");
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);
        $ws = $ss->getSheetByName($sheet);
        if (!$ws) {
            $this->error("Sheet '{$sheet}' not found. Available: " . implode(', ', array_map(
                fn($s) => "'" . $s->getTitle() . "'",
                $ss->getAllSheets()
            )));
            return self::FAILURE;
        }

        $rows = $ws->toArray(null, true, true, false);
        if (count($rows) < 2) {
            $this->warn('Sheet appears empty.');
            return self::SUCCESS;
        }

        // Build a column-index → top-level-category-code map from the
        // header row. Column "1. ships..." → "1", "13.Military..." → "13".
        $header = array_shift($rows);
        $catColMap = [];   // [col_idx => "13"]
        foreach ($header as $idx => $label) {
            if ($idx <= 1) continue;   // skip Empresa + IQF
            if (!is_string($label)) continue;
            if (preg_match('/^\s*(\d{1,2})\s*\./', $label, $m)) {
                $catColMap[$idx] = $m[1];
            }
        }
        $this->line('  → Detected ' . count($catColMap) . ' category columns.');

        $stats = ['parsed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $name = trim((string) ($row[0] ?? ''));
            if ($name === '') continue;

            $stats['parsed']++;

            $slug = Supplier::makeSlug($name);
            if ($slug === '') {
                $stats['skipped']++;
                $this->line("  ⚠ Skipping (empty slug): \"{$name}\"");
                continue;
            }

            // Parse IQF — accepts "3", "2.5", "2,5", "2.75". Comma → dot.
            $iqfRaw = $row[1] ?? null;
            $iqf = is_numeric($iqfRaw) ? (float) $iqfRaw
                 : (is_string($iqfRaw) ? (float) str_replace(',', '.', trim($iqfRaw)) : null);
            if ($iqf === 0.0 && (string) $iqfRaw !== '0') $iqf = null;

            // Extract category codes — top level (from header) +
            // sub-codes (from cell content like "13.7 / 13.45").
            $categories = [];
            $subcategories = [];
            foreach ($catColMap as $idx => $topCode) {
                $cell = trim((string) ($row[$idx] ?? ''));
                if ($cell === '') continue;
                $categories[] = $topCode;
                // Split on /, comma, "or", or whitespace; keep tokens
                // that look like a numeric subcode (X, X.Y, X.Y.Z).
                $tokens = preg_split('/[\/,]+|\s+or\s+|\s+/i', $cell) ?: [];
                foreach ($tokens as $tok) {
                    $tok = trim($tok, " \t\n\r\0\x0B.");
                    if ($tok === '') continue;
                    if (preg_match('/^\d{1,2}(?:\.\d{1,3}){0,2}$/', $tok)) {
                        $subcategories[] = $tok;
                    }
                }
            }
            $categories = array_values(array_unique($categories));
            $subcategories = array_values(array_unique($subcategories));
            sort($categories);
            sort($subcategories);

            $status = ($iqf !== null && $iqf < 2.5)
                ? Supplier::STATUS_PENDING
                : Supplier::STATUS_APPROVED;

            $payload = [
                'name'           => $name,
                'iqf_score'      => $iqf,
                'categories'     => $categories ?: null,
                'subcategories'  => $subcategories ?: null,
                'status'         => $status,
                'source'         => Supplier::SOURCE_EXCEL_2026,
                'source_meta'    => [
                    'sheet'      => $sheet,
                    'imported_at' => now()->toIso8601String(),
                    'iqf_raw'    => $iqfRaw,
                ],
            ];

            if ($dry) {
                $this->line(sprintf('  · would upsert %s (slug=%s, iqf=%s, cats=%s)',
                    mb_substr($name, 0, 40),
                    $slug,
                    $iqf ?? 'n/a',
                    implode(',', $categories) ?: 'none',
                ));
                continue;
            }

            $sup = Supplier::firstOrNew(['slug' => $slug]);
            $isNew = !$sup->exists;

            // Always-overwrite fields (the Excel is the source of
            // truth for these): name (canonical casing), iqf_score,
            // status, source_meta.
            $sup->name = $name;
            $sup->iqf_score = $iqf;
            $sup->status = $status;
            $sup->source_meta = $payload['source_meta'];

            // Source — only set on creation. Don't downgrade an
            // 'agent_extraction' row to 'excel_2026' on re-import.
            if ($isNew) $sup->source = Supplier::SOURCE_EXCEL_2026;

            // Categories / subcategories: UNION (don't drop categories
            // that another path added). The Excel is authoritative for
            // its own catalogue, agents can extend.
            $sup->mergeCategories($categories);
            $sup->mergeCategories($subcategories, sub: true);

            $sup->save();

            $isNew ? $stats['created']++ : $stats['updated']++;
        }

        $this->newLine();
        $this->info(sprintf(
            $dry ? 'DRY-RUN complete — would have processed %d (created %d, updated %d, skipped %d).'
                 : '✓ Done — parsed %d, created %d, updated %d, skipped %d.',
            $stats['parsed'], $stats['created'], $stats['updated'], $stats['skipped'],
        ));

        Log::info('suppliers:import done', $stats + ['path' => $path, 'sheet' => $sheet, 'dry_run' => $dry]);
        return self::SUCCESS;
    }
}
