<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\SupplierEmailFinderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan suppliers:find-emails [--limit=30] [--id=X] [--force]
 *
 * Targeted email-finder that runs AFTER the general enrichment cron.
 * Only suppliers that already have a `website` and still NULL
 * primary_email get processed (the bulk enrichment cron fills the
 * websites; this fills the email gap).
 *
 * Why a separate command from the general enricher:
 *   • Email scraping is HTTP-bound — slower (5–15s per supplier with
 *     contact-page probing) and politer to space out.
 *   • Many suppliers will yield no email (corporate forms only) and
 *     re-running for them daily is wasted effort. The 30-day cooldown
 *     in source_meta.last_email_finder.attempted_at saves the cycles.
 *
 * Cost: zero per supplier (no LLM, no Tavily, just outbound HTTP +
 * SMTP probe). Tens of suppliers per minute, easily.
 */
class FindSupplierEmailsCommand extends Command
{
    protected $signature = 'suppliers:find-emails
        {--limit=30 : Max suppliers per run}
        {--id= : Find email for one specific supplier id}
        {--force : Skip the 30-day cooldown}';

    protected $description = 'Scrape supplier websites + verify candidates to fill missing primary_email.';

    public function handle(SupplierEmailFinderService $svc): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        $query = Supplier::query();

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } else {
            // Has a website, no primary email yet, not blacklisted.
            $query->contactable()
                  ->whereNotNull('website')
                  ->whereNull('primary_email');

            if (!$force) {
                // Skip suppliers attempted in the last 30 days.
                $query->where(function ($w) {
                    $w->whereRaw("source_meta::text NOT LIKE ?", ['%"last_email_finder"%'])
                      ->orWhereRaw(
                          "(source_meta->'last_email_finder'->>'attempted_at')::timestamp < ?",
                          [now()->subDays(30)->toIso8601String()]
                      );
                });
            }
        }

        $suppliers = $query
            ->orderByDesc('iqf_score')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($suppliers->isEmpty()) {
            $this->info('Nothing to find.');
            return self::SUCCESS;
        }

        $this->info("Searching emails for {$suppliers->count()} supplier(s)…");
        $stats = ['processed' => 0, 'found' => 0, 'no_emails' => 0];

        foreach ($suppliers as $supplier) {
            $stats['processed']++;
            $label = mb_substr($supplier->name, 0, 50);

            $res = $svc->findFor($supplier);

            if ($res['ok']) {
                $stats['found']++;
                $this->line("  ✓ #{$supplier->id} {$label} — " . implode(', ', $res['found'])
                    . ' [' . implode(',', array_unique($res['from'])) . ']');
            } else {
                $stats['no_emails']++;
                $reason = $res['reason'] ?? 'no_emails_found';
                $this->line("  · #{$supplier->id} {$label} — {$reason}");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ Done — processed %d, found emails for %d, none for %d.',
            $stats['processed'], $stats['found'], $stats['no_emails'],
        ));

        Log::info('suppliers:find-emails done', $stats);
        return self::SUCCESS;
    }
}
