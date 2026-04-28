<?php

namespace App\Console\Commands;

use App\Services\TenderImport\TenderImportService;
use Illuminate\Console\Command;

/**
 * CLI entry point for tender imports — the primary test harness for
 * the importer pipeline before the web UI is built.
 *
 * Usage:
 *   php artisan tenders:import nspa /tmp/NSPA_22-04-2026.xlsx
 *   php artisan tenders:import nspa /tmp/file.xlsx --user=1
 *   php artisan tenders:import --list-sources
 */
class TenderImportCommand extends Command
{
    protected $signature = 'tenders:import
        {source? : Source key (nspa, nato, sam_gov, ...)}
        {file? : Path to the Excel file}
        {--user= : User id to attribute the import to}
        {--list-sources : Show available importers and exit}
        {--strict : Refuse to create new collaborator rows from name alone — leave unmatched tenders unassigned for triage}';

    protected $description = 'Import tenders from an Excel file for a given source';

    public function handle(TenderImportService $service): int
    {
        if ($this->option('list-sources')) {
            $this->info('Available sources:');
            foreach ($service->availableSources() as $s) {
                $this->line("  • {$s}");
            }
            return self::SUCCESS;
        }

        $source = $this->argument('source');
        $file   = $this->argument('file');

        if (!$source || !$file) {
            $this->error('Usage: tenders:import <source> <file>');
            $this->line('       tenders:import --list-sources');
            return self::FAILURE;
        }

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        if (!$service->supports($source)) {
            $this->error("Unknown source: {$source}");
            $this->line('Available: ' . implode(', ', $service->availableSources()));
            return self::FAILURE;
        }

        $this->info("Importing {$source} from " . basename($file) . '…');

        try {
            $audit = $service->import(
                source: $source,
                filePath: $file,
                originalName: basename($file),
                userId: $this->option('user') ? (int) $this->option('user') : null,
                strictCollaborators: (bool) $this->option('strict'),
            );
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✅ ' . $audit->summary);

        if ($audit->errors) {
            $this->newLine();
            $this->warn('Row errors (' . count($audit->errors) . '):');
            foreach (array_slice($audit->errors, 0, 10) as $err) {
                $this->line("  [{$err['reference']}] {$err['error']}");
            }
            if (count($audit->errors) > 10) {
                $this->line('  … and ' . (count($audit->errors) - 10) . ' more (see tender_imports.errors JSON)');
            }
        }

        return self::SUCCESS;
    }
}
