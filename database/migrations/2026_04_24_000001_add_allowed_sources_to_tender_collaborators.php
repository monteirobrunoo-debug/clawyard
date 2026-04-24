<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-collaborator source whitelist.
 *
 * User request (2026-04-24): "para cada utilizador no dashboard dos
 * concursos tem de ter autorização para ver concursos NSPA, Acingov, SAM".
 *
 * Design:
 *   - `allowed_sources` is a nullable JSON array of source keys
 *     (e.g. ["nspa","acingov"]).
 *   - NULL means "no restriction" — the collaborator sees every source.
 *     Keeping NULL as the default lets every existing row stay
 *     unaffected: current users don't suddenly lose visibility because
 *     we added a migration.
 *   - [] (empty array) means "blocked from every source". Explicit
 *     denial, different from NULL.
 *   - Any non-empty array is a whitelist — only the listed sources
 *     are visible to that collaborator.
 *
 * We chose JSON over a join table because:
 *   - The source keys are a small, fixed enum (Tender::SOURCES, 9 values).
 *   - Filtering is always "WHERE source IN (...)" driven from the
 *     collaborator row we already joined on the assign path.
 *   - A pivot table would add a migration + model + extra queries for
 *     no query-planner win.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_collaborators', function (Blueprint $t) {
            // Nullable — NULL == see everything (legacy behaviour).
            // Arrays with the Tender::SOURCES keys are the whitelist.
            $t->json('allowed_sources')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tender_collaborators', function (Blueprint $t) {
            $t->dropColumn('allowed_sources');
        });
    }
};
