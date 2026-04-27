<?php

namespace App\Console\Commands;

use App\Models\PartnerWorkshop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Idempotent importer for the Strategic Port Workshop Mapping xlsx.
 *
 *   php artisan marco:import-partners <path-to-xlsx>
 *   php artisan marco:import-partners                            # default → backed-up file
 *   php artisan marco:import-partners --dry-run                  # report only
 *   php artisan marco:import-partners --prune                    # deactivate rows missing
 *
 * Sheet expectations:
 *   1. "Port Workshop Database"     — main rows, 12 columns starting at row 2
 *   2. "Services Coverage Matrix"   — coverage chips for each row, joined by
 *                                      (port, company_name)
 *
 * Domain auto-classification (overridable post-import):
 *   • SPARES  if Category contains "Engine" / "Prime Movers" / "Drive Systems"
 *               / "Electrical" / "Spare Parts" / "OEM"
 *               OR coverage chip prime_movers / aux_systems / electrical
 *   • REPAIR  if Category contains "Shipyard" / "Repair" / "Drydock"
 *               / "Naval" / "Marine Engineering"
 *               OR coverage chip repairs_refits / shipyard_equip / propulsors
 *               / naval_weapons
 *
 * Most full-service shipyards qualify as BOTH — the function returns
 * an array, not a single tag.
 *
 * Idempotency: upsert by (port, company_name). Re-running the same
 * file just updates fields. With --prune, rows that exist in DB but
 * are NOT in the current xlsx have is_active flipped to false (kept
 * for history; can be reactivated by hand on /admin if the row
 * reappears in the next sheet).
 */
class MarcoImportPartnersCommand extends Command
{
    protected $signature = 'marco:import-partners
        {path? : Path to xlsx (defaults to storage/app/marco/sources/latest)}
        {--dry-run : Parse and report but do not write to DB}
        {--prune   : Deactivate DB rows that are not in this xlsx}';

    protected $description = 'Import the Strategic Port Workshop Mapping xlsx into partner_workshops';

    /** Region rollup matching the Dashboard sheet. */
    private const REGION_BY_COUNTRY = [
        'Netherlands'   => 'Northern Europe',
        'Belgium'       => 'Northern Europe',
        'Germany'       => 'Northern Europe',
        'Poland'        => 'Northern Europe',
        'Spain'         => 'Mediterranean',
        'France'        => 'Mediterranean',
        'Italy'         => 'Mediterranean',
        'Greece'        => 'Mediterranean',
        'UAE'           => 'Middle East',
        'Egypt'         => 'Middle East',
        'USA'           => 'Americas',
        'Brazil'        => 'Americas',
        'Singapore'     => 'Asia Pacific',
        'Morocco'       => 'Africa',
        'South Africa'  => 'Africa',
        'Nigeria'       => 'Africa',
        'Kenya'         => 'Africa',
    ];

    /**
     * Coverage matrix column index → JSON key.
     *
     * Sheet 3 layout (1-indexed): A=Port, B=Partner, then 16 chip
     * columns C..R. Off-by-one is the easy mistake here — PhpSpreadsheet
     * uses 1-indexed columns, so the first chip column is index 3.
     */
    private const CHIP_COLUMNS = [
        3  => 'repairs_refits',
        4  => 'shipyard_equip',
        5  => 'ship_fittings',
        6  => 'prime_movers',
        7  => 'aux_systems',
        8  => 'propulsors',
        9  => 'ship_ops',
        10 => 'cargo_special',
        11 => 'electrical',
        12 => 'marine_tech',
        13 => 'port_tech',
        14 => 'maritime_svc',
        15 => 'naval_weapons',
        16 => 'shipbrokers',
        17 => 'shipowners',
        18 => 'media',
    ];

    /** Workshop-status string → our priority enum. */
    private const PRIORITY_MAP = [
        'high priority'     => PartnerWorkshop::PRIORITY_HIGH,
        'active prospect'   => PartnerWorkshop::PRIORITY_ACTIVE,
        'partner candidate' => PartnerWorkshop::PRIORITY_CANDIDATE,
        'prospect'          => PartnerWorkshop::PRIORITY_PROSPECT,
        'info only'         => PartnerWorkshop::PRIORITY_INFO_ONLY,
    ];

    public function handle(): int
    {
        $path = $this->resolvePath();
        if (!$path || !is_file($path)) {
            $this->error("xlsx not found: " . ($path ?? '(none)'));
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prune  = (bool) $this->option('prune');

        $this->info("Reading: {$path}" . ($dryRun ? ' [DRY RUN]' : ''));

        $rows = $this->parseRows($path);
        $this->info(sprintf('Parsed %d partner rows from xlsx.', count($rows)));

        if ($dryRun) {
            foreach ($rows as $r) {
                $this->line(sprintf('  · %-28s %-30s [%s] domains=%s',
                    $r['port'], Str::limit($r['company_name'], 30), $r['priority'],
                    json_encode($r['domains'])
                ));
            }
            return self::SUCCESS;
        }

        $seenKeys = [];
        $created  = 0;
        $updated  = 0;
        DB::transaction(function () use ($rows, &$seenKeys, &$created, &$updated) {
            foreach ($rows as $payload) {
                $existing = PartnerWorkshop::where('port', $payload['port'])
                    ->where('company_name', $payload['company_name'])
                    ->first();
                if ($existing) {
                    $existing->fill($payload)->save();
                    $updated++;
                } else {
                    PartnerWorkshop::create($payload);
                    $created++;
                }
                $seenKeys[] = $payload['port'] . '|' . $payload['company_name'];
            }
        });

        $deactivated = 0;
        if ($prune) {
            // Mark rows that exist in DB but were NOT in this xlsx as
            // inactive — preserve history, hide from agent queries.
            $candidates = PartnerWorkshop::query()
                ->where('is_active', true)
                ->get(['id', 'port', 'company_name']);
            foreach ($candidates as $row) {
                $key = $row->port . '|' . $row->company_name;
                if (!in_array($key, $seenKeys, true)) {
                    $row->is_active = false;
                    $row->save();
                    $deactivated++;
                }
            }
        }

        $this->info(sprintf(
            'Done. Created %d · updated %d · deactivated %d.',
            $created, $updated, $deactivated
        ));

        Log::info('marco:import-partners completed', [
            'file'        => basename($path),
            'created'     => $created,
            'updated'     => $updated,
            'deactivated' => $deactivated,
        ]);

        return self::SUCCESS;
    }

    private function resolvePath(): ?string
    {
        $arg = $this->argument('path');
        if ($arg) return $arg;

        // Default — pick the newest file in storage/app/marco/sources/.
        $dir = storage_path('app/marco/sources');
        if (!is_dir($dir)) return null;
        $files = glob($dir . '/*.xlsx') ?: [];
        if (empty($files)) return null;
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }

    /**
     * Parse the xlsx into payloads ready for upsert.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(string $path): array
    {
        $book = IOFactory::load($path);

        $db   = $book->getSheetByName('Port Workshop Database');
        $cov  = $book->getSheetByName('Services Coverage Matrix');
        if (!$db) {
            throw new \RuntimeException("Sheet 'Port Workshop Database' missing.");
        }

        // Build coverage lookup keyed by (port|company) lowercased so
        // small typo differences across sheets don't break the join.
        $coverageByKey = [];
        if ($cov) {
            $highest = $cov->getHighestRow();
            for ($r = 3; $r <= $highest; $r++) {
                $port = trim((string) $cov->getCell([1, $r])->getValue());
                $name = trim((string) $cov->getCell([2, $r])->getValue());
                if ($port === '' || $name === '') continue;
                $chips = [];
                foreach (self::CHIP_COLUMNS as $col => $key) {
                    $cell = trim((string) $cov->getCell([$col, $r])->getValue());
                    $chips[$key] = ($cell !== '' && $cell !== null);
                }
                $coverageByKey[$this->joinKey($port, $name)] = $chips;
            }
        }

        $rows = [];
        $highestDb = $db->getHighestRow();
        for ($r = 2; $r <= $highestDb; $r++) {
            $port = trim((string) $db->getCell([1, $r])->getValue());
            $name = trim((string) $db->getCell([3, $r])->getValue());
            if ($port === '' || $name === '') continue;

            $country     = trim((string) $db->getCell([2, $r])->getValue());
            $category    = trim((string) $db->getCell([4, $r])->getValue());
            $services    = trim((string) $db->getCell([5, $r])->getValue());
            $address     = trim((string) $db->getCell([6, $r])->getValue());
            $phone       = trim((string) $db->getCell([7, $r])->getValue());
            $email       = trim((string) $db->getCell([8, $r])->getValue());
            $website     = trim((string) $db->getCell([9, $r])->getValue());
            $relevance   = trim((string) $db->getCell([10, $r])->getValue());
            $statusRaw   = trim((string) $db->getCell([11, $r])->getValue());
            $notes       = trim((string) $db->getCell([12, $r])->getValue());

            $chips = $coverageByKey[$this->joinKey($port, $name)] ?? [];

            $rows[] = [
                'port'           => $port,
                'country'        => $country ?: null,
                'region'         => self::REGION_BY_COUNTRY[$country] ?? null,
                'company_name'   => $name,
                'category'       => $category ?: null,
                'services_text'  => $services ?: null,
                'service_tokens' => $this->tokeniseServices($services),
                'coverage_chips' => $chips,
                'domains'        => $this->classifyDomains($category, $chips),
                'address'        => $address ?: null,
                'phone'          => $phone ?: null,
                'email'          => $email ?: null,
                'website'        => $website ?: null,
                'relevance'      => $relevance ?: null,
                'priority'       => self::PRIORITY_MAP[strtolower($statusRaw)] ?? PartnerWorkshop::PRIORITY_PROSPECT,
                'notes'          => $notes ?: null,
                'source_file'    => basename($path),
                'source_row'     => $r,
                'is_active'      => true,
            ];
        }

        return $rows;
    }

    private function joinKey(string $port, string $name): string
    {
        return strtolower(trim($port)) . '|' . strtolower(trim($name));
    }

    /**
     * Turn the free-text "Services Covered" into a normalised array.
     * Splits on `;` then trims and lowercases each token. Keeps original
     * order so the most-prominent service stays first in the JSON.
     *
     * @return array<int, string>
     */
    private function tokeniseServices(?string $raw): array
    {
        if (!$raw) return [];
        $parts = array_map('trim', explode(';', $raw));
        $parts = array_filter($parts, fn($p) => $p !== '');
        return array_values(array_unique(array_map('strtolower', $parts)));
    }

    /**
     * Auto-classify which domain(s) a row belongs to. See class docblock
     * for the rule. Returns an array of {spares, repair} (1 or 2 items).
     */
    private function classifyDomains(?string $category, array $chips): array
    {
        $cat = strtolower((string) $category);

        $sparesByCategory = (bool) preg_match(
            '/(prime\s*movers|engine|drive\s*systems|electrical|electronics|hydraulics|propulsion|spares?|oem)/i',
            $cat
        );
        $sparesByChip = !empty($chips['prime_movers'])
                     || !empty($chips['aux_systems'])
                     || !empty($chips['electrical']);

        $repairByCategory = (bool) preg_match(
            '/(shipyard|repair|drydock|naval|marine\s*engineering|workshop|coatings)/i',
            $cat
        );
        $repairByChip = !empty($chips['repairs_refits'])
                     || !empty($chips['shipyard_equip'])
                     || !empty($chips['propulsors'])
                     || !empty($chips['naval_weapons']);

        $domains = [];
        if ($sparesByCategory || $sparesByChip)  $domains[] = PartnerWorkshop::DOMAIN_SPARES;
        if ($repairByCategory || $repairByChip)  $domains[] = PartnerWorkshop::DOMAIN_REPAIR;

        // If we couldn't classify (rare — e.g. "Maritime Services / Bunkering"),
        // tag both so the row is at least visible to one agent rather than
        // orphaned. The admin can correct the row later.
        if (empty($domains)) {
            $domains = [PartnerWorkshop::DOMAIN_SPARES, PartnerWorkshop::DOMAIN_REPAIR];
        }

        return array_values(array_unique($domains));
    }
}
