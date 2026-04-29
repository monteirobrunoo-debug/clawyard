<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Services\SapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan tenders:sync-from-sap [--limit=100] [--id=<tender_id>]
 *
 * Reverse direction of the existing notes→SAP sync. Walks every tender
 * with sap_opportunity_number and pulls the latest SAP opp data:
 *   • Status   (sos_Open / sos_Won / sos_Lost) → maps to local status
 *   • Remarks  → tender.notes (only when local notes were not edited
 *                 since last_sap_sync_at, to avoid stomping user edits)
 *
 * The dashboard 'feels live' because tender rows now reflect SAP-side
 * changes within the cron interval (15 min by default).
 *
 * Cron: every 15 minutes via routes/console.php — short interval is OK
 * because:
 *   • SAP B1 OData is idempotent and has its own caching
 *   • Each call is one $select request, ~50ms each
 *   • Per run = 1 SAP login + N opp fetches; 50 tenders ≈ 5s total
 *
 * Idempotent: tenders without sap_opp are skipped silently. Re-running
 * within seconds is fine — last_sap_sync_at is just bumped.
 */
class SyncTendersFromSapCommand extends Command
{
    protected $signature = 'tenders:sync-from-sap
        {--limit=100 : Max tenders to sync per run}
        {--id= : Sync only the given tender id}
        {--force-remarks : Overwrite local notes even if user edited them after last sync}';

    protected $description = 'Pull latest SAP B1 opportunity data into linked tenders (status + Remarks).';

    /** Map SAP B1 opportunity Status → local Tender status. */
    private const STATUS_MAP = [
        'sos_Open' => null,    // ambiguous — don't auto-change local status from open
        'sos_Won'  => Tender::STATUS_GANHO,
        'sos_Lost' => Tender::STATUS_PERDIDO,
    ];

    public function handle(SapService $sap): int
    {
        if (!config('services.sap.username') || !config('services.sap.password')) {
            $this->warn('SAP not configured — skipping sync.');
            return self::SUCCESS;
        }

        $query = Tender::query()
            ->whereNotNull('sap_opportunity_number');

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        }

        $tenders = $query
            ->orderBy('last_sap_sync_at', 'asc')   // oldest sync first
            ->limit((int) $this->option('limit'))
            ->get();

        if ($tenders->isEmpty()) {
            $this->info('Nothing to sync.');
            return self::SUCCESS;
        }

        $this->info("Syncing {$tenders->count()} tender(s) from SAP…");

        $stats = ['fetched' => 0, 'updated' => 0, 'no_change' => 0, 'errors' => 0, 'skipped_remarks' => 0];

        foreach ($tenders as $tender) {
            $seqNo = $tender->getSapSequentialNo();
            if (!$seqNo) {
                $stats['errors']++;
                continue;
            }

            try {
                $opp = $sap->getOpportunityWithStages($seqNo);
            } catch (\Throwable $e) {
                Log::warning('tenders:sync-from-sap fetch failed', [
                    'tender_id' => $tender->id,
                    'seq_no'    => $seqNo,
                    'error'     => $e->getMessage(),
                ]);
                $stats['errors']++;
                continue;
            }

            if ($opp === null) {
                Log::warning('tenders:sync-from-sap opportunity not found', [
                    'tender_id' => $tender->id,
                    'seq_no'    => $seqNo,
                ]);
                $stats['errors']++;
                continue;
            }

            $stats['fetched']++;
            $changed = $this->applyDelta($tender, $opp);
            $tender->last_sap_sync_at = now();
            $tender->save();

            if ($changed['any']) {
                $stats['updated']++;
                if ($changed['remarks_skipped']) $stats['skipped_remarks']++;
                // Drop the internal 'any' marker from the user-facing
                // output — it's just a flag for the caller, not a field.
                $details = collect($changed)
                    ->forget('any')
                    ->filter()
                    ->keys()
                    ->implode(', ');
                $this->line(sprintf('  ✓ #%d %s — %s', $tender->id, $tender->reference, $details));
            } else {
                $stats['no_change']++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ Done — fetched %d, updated %d, no change %d, errors %d, remarks skipped %d',
            $stats['fetched'], $stats['updated'], $stats['no_change'], $stats['errors'], $stats['skipped_remarks'],
        ));

        Log::info('tenders:sync-from-sap done', $stats);
        return self::SUCCESS;
    }

    /**
     * Apply changes from a SAP opportunity to a local tender.
     * Returns an array of which fields changed:
     *   ['status' => bool, 'notes' => bool, 'remarks_skipped' => bool, 'any' => bool]
     */
    private function applyDelta(Tender $tender, array $opp): array
    {
        $changed = ['status' => false, 'notes' => false, 'remarks_skipped' => false, 'any' => false];

        // Status mapping — only transition LOCAL status to ganho/perdido
        // when SAP says won/lost. We don't reverse because local status
        // has more nuance (em_tratamento, submetido, avaliacao, …).
        $sapStatus = (string) ($opp['Status'] ?? '');
        if ($sapStatus !== '' && $tender->last_sap_status !== $sapStatus) {
            $tender->last_sap_status = $sapStatus;
            $newLocal = self::STATUS_MAP[$sapStatus] ?? null;
            if ($newLocal && $tender->status !== $newLocal) {
                $tender->status = $newLocal;
                $changed['status'] = true;
                $changed['any'] = true;
            }
        }

        // Remarks → notes. Skip if local notes were edited AFTER last
        // SAP sync (user has unsynced changes — overwriting would lose
        // their work). Use --force-remarks to override.
        $sapRemarks = (string) ($opp['Remarks'] ?? '');
        $sapHash = $sapRemarks !== '' ? hash('sha256', $sapRemarks) : null;
        if ($sapHash !== null && $sapHash !== (string) $tender->last_sap_remarks_hash) {
            $userEditedSinceSync = $tender->last_sap_sync_at
                && $tender->updated_at->gt($tender->last_sap_sync_at);

            if (!$userEditedSinceSync || $this->option('force-remarks')) {
                $tender->notes = $sapRemarks;
                $tender->last_sap_remarks_hash = $sapHash;
                $changed['notes'] = true;
                $changed['any'] = true;
            } else {
                // Track the new hash anyway so next sync sees the
                // current SAP state, but DON'T overwrite local notes.
                $tender->last_sap_remarks_hash = $sapHash;
                $changed['remarks_skipped'] = true;
                $changed['any'] = true;
            }
        }

        return $changed;
    }
}
