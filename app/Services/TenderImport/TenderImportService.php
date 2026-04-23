<?php

namespace App\Services\TenderImport;

use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\TenderImport;
use App\Services\TenderImport\Contracts\TenderImporterInterface;
use App\Services\TenderImport\Importers\NspaImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates tender imports:
 *   1. Dispatches to the right per-source Importer
 *   2. Resolves Colaborador name → TenderCollaborator (idempotent)
 *   3. Upserts Tender rows by (source, reference)
 *   4. Writes a TenderImport audit row with parse/create/update/skip counts
 *
 * Re-import semantics: the SAME file can be uploaded twice without
 * corrupting local edits. Specifically:
 *   • If a user has manually filled `sap_opportunity_number` locally and
 *     the new Excel is blank for that field, we KEEP the local value.
 *     Rationale: the Excel is the source of truth for the initial set
 *     of RFPs, but SAP opp numbers are created and tracked internally.
 *   • Re-imports NEVER clear `assigned_collaborator_id` — only overwrite
 *     it when the Excel has a non-blank Colaborador.
 */
class TenderImportService
{
    /** @var array<string, class-string<TenderImporterInterface>> */
    private const IMPORTERS = [
        'nspa' => NspaImporter::class,
        // future: 'nato' => NatoImporter::class,
        // future: 'sam_gov' => SamGovImporter::class,
    ];

    public function supports(string $source): bool
    {
        return isset(self::IMPORTERS[$source]);
    }

    /** @return list<string> */
    public function availableSources(): array
    {
        return array_keys(self::IMPORTERS);
    }

    /**
     * Run a full import. Returns the TenderImport audit row.
     *
     * @throws \InvalidArgumentException if source has no registered importer.
     */
    public function import(
        string $source,
        string $filePath,
        ?string $originalName = null,
        ?int $userId = null
    ): TenderImport {
        if (!$this->supports($source)) {
            throw new \InvalidArgumentException("No importer registered for source: {$source}");
        }

        $t0       = microtime(true);
        $fileName = $originalName ?? basename($filePath);
        $fileHash = hash_file('sha256', $filePath);

        /** @var TenderImporterInterface $importer */
        $importer = app(self::IMPORTERS[$source]);

        $audit = TenderImport::create([
            'source'     => $source,
            'file_name'  => $fileName,
            'file_hash'  => $fileHash,
            'user_id'    => $userId,
            'sheet_name' => null,
        ]);

        $errors  = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $parsed  = 0;

        // Wrap in a transaction so a row-level explosion rolls back partial
        // writes and we can just re-run. The audit row itself is INSERTed
        // OUTSIDE the transaction (above) so it survives for forensics if
        // the rollback fires.
        DB::transaction(function () use (
            $importer, $filePath, $audit,
            &$parsed, &$created, &$updated, &$skipped, &$errors
        ) {
            foreach ($importer->parse($filePath) as $row) {
                $parsed++;

                $collabId = null;
                if (!empty($row['collaborator_name'])) {
                    $collabId = TenderCollaborator::findOrCreateByName($row['collaborator_name'])?->id;
                }

                try {
                    $this->upsertRow($row, $collabId, $audit->id, $created, $updated, $skipped);
                } catch (\Throwable $e) {
                    $errors[] = [
                        'reference' => $row['reference'] ?? '?',
                        'error'     => $e->getMessage(),
                    ];
                    $skipped++;
                    Log::warning('TenderImport row failed', [
                        'import_id' => $audit->id,
                        'reference' => $row['reference'] ?? '?',
                        'message'   => $e->getMessage(),
                    ]);
                }
            }
        });

        $audit->update([
            'rows_parsed'  => $parsed,
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_skipped' => $skipped,
            'errors'       => $errors ?: null,
            'duration_ms'  => (int) ((microtime(true) - $t0) * 1000),
        ]);

        Log::info('TenderImport finished', [
            'id'      => $audit->id,
            'summary' => $audit->summary,
        ]);

        return $audit->fresh();
    }

    /**
     * Upsert a single parsed row. Mutates the counter refs.
     */
    private function upsertRow(
        array $row,
        ?int $collabId,
        int $importId,
        int &$created,
        int &$updated,
        int &$skipped
    ): void {
        $existing = Tender::where('source', $row['source'])
            ->where('reference', $row['reference'])
            ->first();

        $attrs = [
            'title'                  => $row['title'] ?? '',
            'type'                   => $row['type'] ?? null,
            'purchasing_org'         => $row['purchasing_org'] ?? null,
            'status'                 => $row['status'] ?? Tender::STATUS_PENDING,
            'priority'               => $row['priority'] ?? null,
            'deadline_at'            => $row['deadline_at'] ?? null,
            'source_modified_at'     => $row['source_modified_at'] ?? null,
            'assigned_at'            => $row['assigned_at'] ?? null,
            'sap_opportunity_number' => $row['sap_opportunity_number'] ?? null,
            'offer_value'            => $row['offer_value'] ?? null,
            'currency'               => $row['currency'] ?? null,
            'time_spent_hours'       => $row['time_spent_hours'] ?? null,
            'notes'                  => $row['notes'] ?? null,
            'result'                 => $row['result'] ?? null,
            'raw_metadata'           => $row['raw_metadata'] ?? null,
            'last_import_id'         => $importId,
        ];

        // Only set assignee when Excel has a non-blank Colaborador; preserve
        // in-app reassignments otherwise.
        if ($collabId !== null) {
            $attrs['assigned_collaborator_id'] = $collabId;
        }

        if ($existing) {
            // Preserve local SAP opp when Excel is blank (see class docblock)
            if (empty($attrs['sap_opportunity_number']) && !empty($existing->sap_opportunity_number)) {
                $attrs['sap_opportunity_number'] = $existing->sap_opportunity_number;
            }
            $existing->fill($attrs);
            if ($existing->isDirty()) {
                $existing->save();
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            $attrs['source']    = $row['source'];
            $attrs['reference'] = $row['reference'];
            Tender::create($attrs);
            $created++;
        }
    }
}
