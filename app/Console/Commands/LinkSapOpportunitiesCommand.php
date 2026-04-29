<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\User;
use App\Services\SapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan tenders:link-sap-opportunities [--user=email] [--dry-run]
 *
 * Walks every tender that lacks a sap_opportunity_number and tries to
 * link it to an existing SAP B1 opportunity by matching the tender's
 * reference against OpportunityName via OData contains() filter.
 *
 * Origin of the bug this fixes:
 *   Imports were sometimes done before the matching SAP opportunity
 *   existed (or vice-versa), leaving the FK NULL on the tender row.
 *   That blocks two things downstream:
 *     1. The note-sync to SAP doesn't fire (the controller's
 *        $seqNo guard returns null).
 *     2. The /tenders/{id} page shows the SAP opportunity card in
 *        'empty' state with a suggestion the user often misses.
 *
 *   Catarina raised this on 2026-04-29: 18 of her 58 tenders had
 *   no sap_opportunity_number, even though the SAP opps existed.
 *
 * Strategy:
 *   • For each candidate tender, search SAP for opportunities whose
 *     OpportunityName CONTAINS the tender's reference (verbatim, then
 *     a digit-only fallback for refs like 'EOT25050' → '25050').
 *   • If exactly 1 candidate matches, link it. If multiple → ambiguous,
 *     skipped with a log entry. If zero → unmatched, also skipped.
 *
 * Idempotent: re-running on already-linked tenders is a no-op (the
 * filter `whereNull('sap_opportunity_number')` excludes them).
 */
class LinkSapOpportunitiesCommand extends Command
{
    protected $signature = 'tenders:link-sap-opportunities
        {--user= : Restrict to tenders owned by collaborator linked to this user email}
        {--dry-run : Print matches without writing}
        {--limit=100 : Max tenders to process (rate-limit on SAP)}';

    protected $description = 'Auto-link tenders without sap_opportunity_number to existing SAP B1 opportunities by reference match.';

    public function handle(SapService $sap): int
    {
        if (!config('services.sap.username') || !config('services.sap.password')) {
            $this->error('SAP not configured (SAP_B1_USER / SAP_B1_PASSWORD missing in .env).');
            return self::FAILURE;
        }

        $userFilter = $this->option('user');
        $dryRun     = (bool) $this->option('dry-run');
        $limit      = (int) $this->option('limit');

        // Build the candidate query.
        $query = Tender::query()
            ->whereNull('sap_opportunity_number')
            ->whereNotNull('reference');

        if ($userFilter) {
            $user = User::where('email', 'like', '%' . $userFilter . '%')->first();
            if (!$user) {
                $this->error("User '{$userFilter}' not found.");
                return self::FAILURE;
            }
            $collabIds = TenderCollaborator::where('user_id', $user->id)->pluck('id');
            $query->whereIn('assigned_collaborator_id', $collabIds);
            $this->line("Filtering by user: {$user->name} ({$user->email})");
        }

        $tenders = $query->limit($limit)->get();
        $this->info("Inspecting {$tenders->count()} tender(s) without sap_opportunity_number…");
        if ($dryRun) $this->warn('--dry-run mode: no DB writes will happen.');

        $stats = ['linked' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'errors' => 0];

        foreach ($tenders as $tender) {
            $reference = trim((string) $tender->reference);
            if ($reference === '') continue;

            // Try the verbatim reference first.
            $candidates = $this->searchByName($sap, $reference);

            // Fallback: digits only (handles refs like 'EOT25050+' / 'AGR26002+').
            if (empty($candidates) && preg_match('/\d{4,}/', $reference, $m)) {
                $candidates = $this->searchByName($sap, $m[0]);
            }

            if (empty($candidates)) {
                $stats['unmatched']++;
                $this->line(sprintf('  ❓ %s — no SAP match', $reference));
                continue;
            }

            if (count($candidates) > 1) {
                $stats['ambiguous']++;
                $names = collect($candidates)->take(3)->pluck('OpportunityName')->implode(' | ');
                $this->warn(sprintf('  ⚠ %s — %d candidates: %s%s',
                    $reference,
                    count($candidates),
                    $names,
                    count($candidates) > 3 ? ' …' : '',
                ));
                continue;
            }

            $opp = $candidates[0];
            $seqNo = (int) ($opp['SequentialNo'] ?? 0);
            $oppName = (string) ($opp['OpportunityName'] ?? '');

            if ($seqNo <= 0) {
                $stats['errors']++;
                continue;
            }

            // Format that the rest of the codebase recognises:
            // 'SequentialNo/Year' (year extracted from PredictedClosingDate or
            // current). $tender->getSapSequentialNo() handles either format,
            // but the canonical column value is 'NNN/YYYY'.
            $year = (int) date('Y');
            if (!empty($opp['PredictedClosingDate'])) {
                $year = (int) substr((string) $opp['PredictedClosingDate'], 0, 4);
            }
            $formatted = "{$seqNo}/{$year}";

            $this->info(sprintf('  ✓ %s → SAP #%s (%s)',
                $reference, $formatted, mb_substr($oppName, 0, 60)));

            if (!$dryRun) {
                $tender->sap_opportunity_number = $formatted;
                $tender->save();
            }
            $stats['linked']++;
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ Done — linked: %d, ambiguous: %d, unmatched: %d, errors: %d',
            $stats['linked'], $stats['ambiguous'], $stats['unmatched'], $stats['errors'],
        ));

        Log::info('tenders:link-sap-opportunities done', $stats);
        return self::SUCCESS;
    }

    /** Wrapper that captures exceptions so a single bad query doesn't kill the loop. */
    private function searchByName(SapService $sap, string $needle): array
    {
        try {
            return $sap->searchOpportunitiesByName($needle, top: 5);
        } catch (\Throwable $e) {
            Log::warning('LinkSapOpportunitiesCommand: search failed', [
                'needle' => $needle,
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }
}
