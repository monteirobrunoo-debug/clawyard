<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track web-enrichment runs on the suppliers table.
 *
 *   enriched_at      — last time we ran the Tavily+Claude enrichment
 *                      pipeline for this row. NULL = never tried.
 *   enrich_attempts  — total runs (success or fail). Used to back off on
 *                      suppliers that consistently return nothing —
 *                      after 3 attempts with no useful data we stop
 *                      hammering Tavily for them.
 *
 * Why timestamps + counter on the row instead of an audit table:
 *   • Re-enrichment is idempotent and cheap — we don't need a full
 *     history (source_meta carries the last raw result for debug).
 *   • The dashboard query "find suppliers that need enrichment" needs
 *     a single index on (enriched_at, enrich_attempts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            if (!Schema::hasColumn('suppliers', 'enriched_at')) {
                $t->timestamp('enriched_at')->nullable()->after('last_replied_at');
            }
            if (!Schema::hasColumn('suppliers', 'enrich_attempts')) {
                $t->unsignedSmallInteger('enrich_attempts')->default(0)->after('enriched_at');
            }
        });

        // Composite index for the cron query "next 50 to enrich".
        // Done as a raw statement because hasIndex isn't always reliable
        // across drivers; CONCURRENTLY is unavailable inside transaction.
        try {
            Schema::table('suppliers', function (Blueprint $t) {
                $t->index(['enriched_at', 'enrich_attempts'], 'suppliers_enrich_queue_idx');
            });
        } catch (\Throwable) {
            // Index already exists — re-runs are fine.
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            try { $t->dropIndex('suppliers_enrich_queue_idx'); } catch (\Throwable) {}
            foreach (['enriched_at', 'enrich_attempts'] as $c) {
                if (Schema::hasColumn('suppliers', $c)) $t->dropColumn($c);
            }
        });
    }
};
