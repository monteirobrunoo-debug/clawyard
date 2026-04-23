<?php

namespace App\Services\TenderImport\Importers;

use App\Models\Tender;
use App\Services\TenderImport\Contracts\TenderImporterInterface;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Read filter that restricts loading to a column range and row cap.
 *
 * Critical because the NSPA Excel carries ~943,000 phantom trailing rows
 * (from workbook formatting/validation covering the full sheet). Loading
 * all of them into memory blows the 128MB default PHP memory limit.
 */
final class NspaReadFilter implements IReadFilter
{
    /** @param list<string> $allowedColumns */
    public function __construct(
        private readonly array $allowedColumns,
        private readonly int $maxRow,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row > $this->maxRow) return false;
        return in_array($columnAddress, $this->allowedColumns, true);
    }
}

/**
 * NSPA (NATO Support and Procurement Agency) importer.
 *
 * Expected sheet layout (18 columns, first row = header):
 *   RFP_ClosingDate | RFP_CollectiveNumber | RFP_LastModifiedDate |
 *   RFP_PurchasingOrganisation | RFP_Title | RFP_TypeDescription |
 *   Colaborador | Status | Oportunidade nº | Coluna1 | Nivel |
 *   Data de Distribuição | Valor da Offer Sub | Currency |
 *   tempo de hora | (blank) | (blank) | RESULTADO
 *
 * TIMEZONE: NSPA is headquartered in Luxembourg and the Excel dates are
 * naive (no TZ info). We interpret them as Europe/Luxembourg wall-clock
 * time and convert to UTC on write.
 *
 * TAIL HANDLING: this Excel has ~900k phantom trailing rows from Excel
 * formatting/data-validation. We short-circuit after N consecutive empty
 * `reference` cells so the importer finishes in seconds, not minutes.
 */
class NspaImporter implements TenderImporterInterface
{
    private const SOURCE_TZ                 = 'Europe/Luxembourg';
    private const SHEET_NAME                = 'NSPA';
    private const EMPTY_TAIL_STOP_THRESHOLD = 100;    // consecutive blank-ref rows → assume end-of-data
    private const MAX_ROWS                  = 50_000; // hard cap to avoid OOM on phantom-row files
    private const COLUMNS                   = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R'];

    public function source(): string
    {
        return 'nspa';
    }

    /**
     * @inheritDoc
     */
    public function parse(string $filePath): iterable
    {
        // Bump PHP memory for the load — the ReadFilter trims ~99% of the
        // phantom rows but PhpSpreadsheet's internal structures still need
        // ~200-300MB peak during load() for a 50k-row cap. 128MB default
        // causes a silent fatal (PHP Error, bypasses try/catch) where the
        // caller observes "0 rows parsed" with no hint of what broke.
        // Only raise — never shrink — so production overrides stay.
        $currentLimit = $this->memoryLimitBytes((string) ini_get('memory_limit'));
        if ($currentLimit >= 0 && $currentLimit < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        // Read-only + load just the NSPA sheet for memory efficiency. The
        // workbook also has a "Dados" sheet (dropdown master list) and
        // "Folha1" (empty) that we don't need.
        //
        // CRITICAL: also apply a ReadFilter to cap at MAX_ROWS and only
        // load columns A-R. Without this, the ~943k phantom trailing rows
        // in NSPA sheets OOM load().
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new NspaReadFilter(self::COLUMNS, self::MAX_ROWS));
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([self::SHEET_NAME]);
        }
        $spreadsheet = $reader->load($filePath);
        $sheet       = $spreadsheet->getSheetByName(self::SHEET_NAME)
            ?? $spreadsheet->getActiveSheet();

        $headers        = [];
        $emptyRunLength = 0;
        $rowIndex       = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowIndex++;
            $cells        = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // preserve column positions
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            if ($rowIndex === 1) {
                // Normalise header names: trim trailing spaces ("Nivel " → "Nivel")
                $headers = array_map(
                    fn($h) => is_string($h) ? trim($h) : $h,
                    $cells
                );
                continue;
            }

            // Map cell index → header name. Unnamed columns become "col_<n>".
            $rowData = [];
            foreach ($cells as $idx => $value) {
                $hdr           = $headers[$idx] ?? "col_{$idx}";
                $rowData[$hdr] = $this->normaliseCell($value);
            }

            $reference = $this->trim($rowData['RFP_CollectiveNumber'] ?? null);
            if (!$reference) {
                // Phantom trailing row. Bail early if we hit too many in a row.
                if (++$emptyRunLength >= self::EMPTY_TAIL_STOP_THRESHOLD) {
                    break;
                }
                continue;
            }
            $emptyRunLength = 0;

            yield [
                'source'                 => 'nspa',
                'reference'              => $reference,
                'title'                  => (string) ($this->trim($rowData['RFP_Title'] ?? '') ?? ''),
                'type'                   => $this->trim($rowData['RFP_TypeDescription'] ?? null),
                'purchasing_org'         => $this->trim($rowData['RFP_PurchasingOrganisation'] ?? null),
                'status'                 => Tender::normaliseStatus($rowData['Status'] ?? null),
                'priority'               => $this->trim($rowData['Nivel'] ?? null),
                'deadline_at'            => $this->toUtc($rowData['RFP_ClosingDate'] ?? null),
                'source_modified_at'     => $this->toUtc($rowData['RFP_LastModifiedDate'] ?? null),
                'assigned_at'            => $this->toUtc($rowData['Data de Distribuição'] ?? null),
                'sap_opportunity_number' => $this->trim($rowData['Oportunidade nº'] ?? null),
                'offer_value'            => $this->numeric($rowData['Valor da Offer Sub'] ?? null),
                'currency'               => $this->trim($rowData['Currency'] ?? null),
                'time_spent_hours'       => $this->numeric($rowData['tempo de hora'] ?? null),
                'notes'                  => $this->trim($rowData['Coluna1'] ?? null),
                'result'                 => $this->trim($rowData['RESULTADO'] ?? null),
                'collaborator_name'      => $this->trim($rowData['Colaborador'] ?? null),
                'raw_metadata'           => $this->serialisableRow($rowData),
            ];
        }

        // Free the spreadsheet — important when Laravel reuses the worker.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function trim(mixed $v): ?string
    {
        if ($v === null) return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function numeric(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float) $v;
        // Accept "1.234,56" (PT) and "1,234.56" (EN) styles
        $s = str_replace([' ', "\xC2\xA0"], '', (string) $v);
        if (preg_match('/^\-?\d{1,3}(\.\d{3})+,\d+$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\-?\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /**
     * Pass through PhpSpreadsheet cell values, unwrapping date serials to
     * PHP DateTime objects so the caller can treat them uniformly.
     */
    private function normaliseCell(mixed $v): mixed
    {
        // PhpSpreadsheet returns numeric values for dates because we set
        // ReadDataOnly. We can't cheaply detect "this numeric IS a date"
        // without format metadata, so we let the downstream `toUtc()`
        // attempt the conversion and fall through for non-dates.
        return $v;
    }

    /**
     * Interpret a cell value as a Europe/Luxembourg wall-clock timestamp
     * and convert to UTC. Returns null if the value can't be parsed.
     *
     * Handles three encodings:
     *   1. DateTime instance (some readers hydrate directly)
     *   2. Numeric Excel serial (days since 1900-01-01, fractional = time)
     *   3. String (ISO-ish or PT-style like "03/10/2025")
     */
    private function toUtc(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') return null;

        try {
            if ($v instanceof \DateTimeInterface) {
                return $this->wallClockLuxembourgToUtc(Carbon::instance($v));
            }

            if (is_numeric($v)) {
                // Excel date numbers only make sense when >= 1 (1 = 1900-01-01)
                // and < ~100000 (year ~2172). Anything else is a real number,
                // not a date (e.g. offer_value in a date column by mistake).
                $num = (float) $v;
                if ($num < 1 || $num > 100000) return null;
                $dt = ExcelDate::excelToDateTimeObject($num);
                return $this->wallClockLuxembourgToUtc(Carbon::instance($dt));
            }

            // String fallback — trust Luxembourg as source TZ.
            $s = trim((string) $v);
            if ($s === '') return null;
            return Carbon::parse($s, self::SOURCE_TZ)->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Take a naive Carbon whose wall-clock components represent Luxembourg
     * local time and return a UTC Carbon with the correct absolute instant.
     *
     * We cannot just `setTimezone('Europe/Luxembourg')` because that
     * *shifts* the numeric components to match; we need to *relabel*.
     */
    private function wallClockLuxembourgToUtc(Carbon $c): Carbon
    {
        return Carbon::create(
            $c->year, $c->month, $c->day,
            $c->hour, $c->minute, $c->second,
            self::SOURCE_TZ
        )->utc();
    }

    /**
     * Convert the raw row to a JSON-serialisable associative array for
     * `raw_metadata`. DateTime instances become ISO-8601 strings.
     */
    private function serialisableRow(array $row): array
    {
        return array_map(function ($v) {
            if ($v instanceof \DateTimeInterface) {
                return $v->format(\DateTime::ATOM);
            }
            return $v;
        }, $row);
    }

    /**
     * Parse a php.ini memory string ('128M', '1G', '-1') into bytes.
     * Returns -1 for unlimited.
     */
    private function memoryLimitBytes(string $s): int
    {
        $s = trim($s);
        if ($s === '' || $s === '-1') return -1;
        $unit = strtolower(substr($s, -1));
        $num  = (int) $s;
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
