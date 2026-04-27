<?php

namespace Tests\Feature;

use App\Models\PartnerWorkshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * marco:import-partners — pins the import contract against the
 * real backed-up xlsx so a future PhpSpreadsheet bump or a re-export
 * with shifted columns blows up the test rather than silently
 * skewing the agent's data.
 *
 * The fixture lives in storage/app/marco/sources/ committed alongside
 * the migration. Tests use the same file.
 */
class MarcoImportPartnersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'database/seed-data/marco/2026-04-25_port-workshop-mapping_v1.xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(base_path(self::FIXTURE))) {
            $this->markTestSkipped('Fixture xlsx not present — run import on a checkout that includes it.');
        }
    }

    public function test_dry_run_reports_count_without_writing(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE), '--dry-run' => true])
            ->expectsOutputToContain('Parsed 49 partner rows from xlsx.')
            ->assertExitCode(0);

        $this->assertSame(0, PartnerWorkshop::count(), 'Dry-run must not write rows');
    }

    public function test_real_import_creates_49_rows(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])
            ->expectsOutputToContain('Done. Created 49 · updated 0 · deactivated 0.')
            ->assertExitCode(0);

        $this->assertSame(49, PartnerWorkshop::count());
    }

    public function test_idempotent_rerun_updates_zero_creates_zero_or_existing(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])->assertExitCode(0);
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])
            ->expectsOutputToContain('Done. Created 0 · updated 49 · deactivated 0.')
            ->assertExitCode(0);

        $this->assertSame(49, PartnerWorkshop::count());
    }

    public function test_high_priority_rows_match_workshop_curation(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])->assertExitCode(0);

        // From the workshop spreadsheet (sheet 1, status column): 9 high
        // priority partners. If this drops or grows in a re-export, the
        // sheet was edited — make the test fail noisily so we don't miss it.
        $highPriority = PartnerWorkshop::where('priority', PartnerWorkshop::PRIORITY_HIGH)
            ->pluck('company_name')
            ->sort()
            ->values()
            ->all();

        $this->assertCount(9, $highPriority);
        $this->assertContains('Navantia Valencia (Unidad de Reparaciones)', $highPriority);
        $this->assertContains('Fincantieri Genova (Bacino di Carenaggio)', $highPriority);
        $this->assertContains('Sembcorp Marine', $highPriority);
        $this->assertContains('Dormac Marine & Engineering', $highPriority);
    }

    public function test_domain_classification_marks_engine_oem_as_spares(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])->assertExitCode(0);

        $wartsila = PartnerWorkshop::where('company_name', 'like', 'Wartsila Greece%')->firstOrFail();
        $this->assertContains(PartnerWorkshop::DOMAIN_SPARES, $wartsila->domains);

        $bergen = PartnerWorkshop::where('company_name', 'like', 'Rolls-Royce Marine Spain%')->firstOrFail();
        $this->assertContains(PartnerWorkshop::DOMAIN_SPARES, $bergen->domains);
    }

    public function test_domain_classification_marks_shipyards_as_repair(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])->assertExitCode(0);

        $damen = PartnerWorkshop::where('company_name', 'Damen Shiprepair Rotterdam')->firstOrFail();
        $this->assertContains(PartnerWorkshop::DOMAIN_REPAIR, $damen->domains);

        $fincantieri = PartnerWorkshop::where('company_name', 'like', 'Fincantieri Genova%')->firstOrFail();
        $this->assertContains(PartnerWorkshop::DOMAIN_REPAIR, $fincantieri->domains);
    }

    public function test_coverage_chips_are_populated(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])->assertExitCode(0);

        // From sheet 3 (Services Coverage Matrix): Damen Shiprepair Rotterdam
        // has ✓ on repairs_refits, prime_movers, propulsors, electrical, marine_tech.
        $damen = PartnerWorkshop::where('company_name', 'Damen Shiprepair Rotterdam')->firstOrFail();
        $this->assertTrue($damen->coverage_chips['repairs_refits']);
        $this->assertTrue($damen->coverage_chips['prime_movers']);
        $this->assertTrue($damen->coverage_chips['propulsors']);
        $this->assertFalse($damen->coverage_chips['naval_weapons']);
    }

    public function test_prune_deactivates_rows_missing_from_xlsx(): void
    {
        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE)])->assertExitCode(0);

        // Inject a phantom row that the next import won't see.
        PartnerWorkshop::create([
            'port'         => 'Phantom',
            'company_name' => 'Ghost Ltd',
            'priority'     => PartnerWorkshop::PRIORITY_PROSPECT,
            'is_active'    => true,
        ]);

        $this->artisan('marco:import-partners', ['path' => base_path(self::FIXTURE), '--prune' => true])
            ->assertExitCode(0);

        $ghost = PartnerWorkshop::where('company_name', 'Ghost Ltd')->firstOrFail();
        $this->assertFalse($ghost->is_active, 'Pruned phantom row must be deactivated, not deleted');
    }
}
