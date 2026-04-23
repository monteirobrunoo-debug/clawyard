<?php

namespace App\Services\TenderImport\Contracts;

/**
 * Contract for per-source tender importers.
 *
 * Each source (NSPA, NATO, SAM.gov, NCIA, …) ships its own radically
 * different file format. Implementations translate their native shape
 * into a common normalised row that TenderImportService can upsert.
 *
 * NORMALISED ROW SHAPE
 * --------------------
 * Required keys:
 *   - source:    string   source key matching TenderImport.source
 *   - reference: string   unique-within-source identifier
 *   - title:     string
 *   - raw_metadata: array original row data verbatim, for audit/re-derivation
 *
 * Optional keys (set when the source carries them):
 *   - type, purchasing_org, status, priority, notes, result
 *   - deadline_at            Carbon UTC
 *   - source_modified_at     Carbon UTC
 *   - assigned_at            Carbon UTC
 *   - sap_opportunity_number string
 *   - offer_value            float
 *   - currency               string(3)
 *   - time_spent_hours       float
 *   - collaborator_name      string  (raw — service resolves to Collaborator id)
 *
 * Rows with a blank `reference` MUST be skipped inside the generator.
 * Timezone conversion (source-local → UTC) is the importer's
 * responsibility — the service trusts the values as-is.
 */
interface TenderImporterInterface
{
    /** Source key (e.g. 'nspa'). Must match TenderImport.source. */
    public function source(): string;

    /**
     * Parse the file and yield normalised rows.
     *
     * @return iterable<array<string, mixed>>
     */
    public function parse(string $filePath): iterable;
}
