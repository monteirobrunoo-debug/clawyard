<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aliases — alternative normalised names that should also resolve to
 * a given collaborator row. Closes the duplicate-row class of bugs:
 *
 *   • Excel A has "Mónica Pereira" → row created with normalized_name='monica pereira'
 *   • Excel B has just "Mónica"    → no exact match → NEW row 'monica' created
 *   • All Excel B's tenders silently bind to the new orphan row.
 *
 * With aliases, the admin (or the merge tool) writes 'monica' into the
 * existing row's aliases array. Future imports that read just 'Mónica'
 * find the row via the alias rather than creating a duplicate.
 *
 * Schema:
 *   • NULL  = no aliases
 *   • []    = no aliases (same as NULL — both mean "exact match only")
 *   • [...] = list of normalised name variants
 *
 * The merge endpoint added in this commit auto-populates aliases when
 * fusing two rows, so the manual maintenance is minimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_collaborators', function (Blueprint $t) {
            $t->json('aliases')->nullable()->after('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::table('tender_collaborators', function (Blueprint $t) {
            $t->dropColumn('aliases');
        });
    }
};
