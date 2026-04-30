<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\SupplierEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan suppliers:enrich [--limit=50] [--id=<n>]
 *                              [--missing-email-only] [--force] [--dry-run]
 *
 * Walks suppliers needing web enrichment (default scope), runs Tavily
 * search → Claude extraction → merges results back into the row.
 *
 * Default scope (`needsEnrichment` model scope):
 *   • Never enriched (enriched_at IS NULL) AND no email yet
 *   • OR never enriched AND no website
 *   • OR enriched > 30 days ago AND still no email
 * Excludes blacklisted + rows that already failed 3+ times.
 *
 * --missing-email-only       restrict to suppliers without primary_email
 * --id=<n>                   enrich a specific supplier id (overrides scope)
 * --force                    re-enrich even rows out of the default queue
 * --dry-run                  log what would be done; no DB writes
 *
 * Cost per row: ~$0.001 Tavily + ~$0.005 Claude = ~$0.006.
 * The whole 805-row directory enriches in ~70 minutes with --limit=50
 * spread across ~16 cron runs (default cron is daily, so the seed
 * library is fully enriched within ~16 days without spiking spend).
 */
class EnrichSuppliersCommand extends Command
{
    protected $signature = 'suppliers:enrich
        {--limit=50 : Max suppliers to process per run}
        {--id= : Enrich only the given supplier id}
        {--missing-email-only : Restrict to suppliers without primary_email}
        {--force : Re-enrich even rows already enriched recently}
        {--dry-run : Skip DB writes; log what would happen}';

    protected $description = 'Auto-fill the supplier directory by searching the web (Tavily + Claude).';

    public function handle(SupplierEnrichmentService $svc): int
    {
        $dry          = (bool) $this->option('dry-run');
        $force        = (bool) $this->option('force');
        $missingOnly  = (bool) $this->option('missing-email-only');
        $limit        = max(1, (int) $this->option('limit'));

        if (!config('services.tavily.api_key')) {
            $this->warn('TAVILY_API_KEY not configured — cannot enrich.');
            return self::SUCCESS;
        }

        $query = Supplier::query();

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } elseif ($force) {
            // --force without --id: still only contactable rows; just
            // ignore enriched_at recency.
            $query->contactable()->where('enrich_attempts', '<', 5);
        } else {
            $query->needsEnrichment();
            if ($missingOnly) $query->whereNull('primary_email');
        }

        $suppliers = $query
            ->orderByRaw('enriched_at IS NULL DESC')
            ->orderBy('enrich_attempts')
            ->orderByDesc('iqf_score')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($suppliers->isEmpty()) {
            $this->info('Nothing to enrich.');
            return self::SUCCESS;
        }

        $this->info("Enriching {$suppliers->count()} supplier(s)…");

        $stats = [
            'processed'      => 0,
            'updated'        => 0,
            'no_change'      => 0,
            'failed'         => 0,
            'fields_updated' => [],
        ];

        foreach ($suppliers as $supplier) {
            $stats['processed']++;
            $label = mb_substr($supplier->name, 0, 50);

            if ($dry) {
                $this->line("  · would enrich #{$supplier->id} {$label}");
                continue;
            }

            $res = $svc->enrichOne($supplier);
            if (!$res['ok']) {
                $stats['failed']++;
                $this->line("  ✗ #{$supplier->id} {$label} — " . ($res['reason'] ?? 'unknown'));
                continue;
            }

            $changed = $res['updated'];
            if (empty($changed)) {
                $stats['no_change']++;
                $this->line("  · #{$supplier->id} {$label} — no new fields");
            } else {
                $stats['updated']++;
                foreach ($changed as $f) {
                    $stats['fields_updated'][$f] = ($stats['fields_updated'][$f] ?? 0) + 1;
                }
                $this->line("  ✓ #{$supplier->id} {$label} — " . implode(', ', $changed));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            $dry ? 'DRY-RUN — would have processed %d.'
                 : '✓ Done — processed %d, updated %d, no-change %d, failed %d.',
            $stats['processed'], $stats['updated'], $stats['no_change'], $stats['failed'],
        ));
        if (!$dry && !empty($stats['fields_updated'])) {
            $this->line('  Fields updated: ' . json_encode($stats['fields_updated'], JSON_UNESCAPED_UNICODE));
        }

        Log::info('suppliers:enrich done', $stats);
        return self::SUCCESS;
    }
}
