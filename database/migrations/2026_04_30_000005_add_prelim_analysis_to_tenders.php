<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-analysis stash on tenders.
 *
 * When a tender is imported, AnalyseTenderJob queues immediately and
 * pre-computes:
 *   • Inferred supplier-category codes
 *   • Top N matched local suppliers (by category)
 *   • Web query + Tavily result snapshot
 *   • Optional: key spec / equipment / deadline-urgency extracted by
 *     Claude from title+notes (later phase)
 *
 * The result lives here so when the operator opens the tender, the
 * suggester panel is already pre-warmed — zero-click intelligence.
 *
 * prelim_analysis structure:
 *   {
 *     "categories": ["13","14"],
 *     "top_supplier_ids": [123, 456, …],
 *     "web_query": "...",
 *     "web_results": [{title,url,snippet}, …],
 *     "version": 1
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            if (!Schema::hasColumn('tenders', 'prelim_analysis')) {
                $t->jsonb('prelim_analysis')->nullable()->after('raw_metadata');
            }
            if (!Schema::hasColumn('tenders', 'prelim_analysed_at')) {
                $t->timestamp('prelim_analysed_at')->nullable()->after('prelim_analysis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            foreach (['prelim_analysis', 'prelim_analysed_at'] as $c) {
                if (Schema::hasColumn('tenders', $c)) $t->dropColumn($c);
            }
        });
    }
};
