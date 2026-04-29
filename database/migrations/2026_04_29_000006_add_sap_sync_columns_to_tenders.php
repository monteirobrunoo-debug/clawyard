<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic SAP-to-clawyard sync needs to know:
 *   • when the local row was last reconciled against SAP
 *     (last_sap_sync_at) so the next run can do delta syncs and
 *     report freshness to the dashboard.
 *   • a snapshot of the last seen SAP fields (last_sap_status,
 *     last_sap_remarks_hash) so the sync command can detect changes
 *     without comparing huge text columns every time.
 *
 * All nullable so existing rows continue to work without backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            if (!Schema::hasColumn('tenders', 'last_sap_sync_at')) {
                $t->timestamp('last_sap_sync_at')->nullable()->index();
            }
            if (!Schema::hasColumn('tenders', 'last_sap_status')) {
                $t->string('last_sap_status', 32)->nullable();
            }
            if (!Schema::hasColumn('tenders', 'last_sap_remarks_hash')) {
                $t->string('last_sap_remarks_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            $t->dropColumn(['last_sap_sync_at', 'last_sap_status', 'last_sap_remarks_hash']);
        });
    }
};
