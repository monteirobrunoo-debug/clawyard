<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-collaborator STATUS whitelist — mirror of allowed_sources but
 * applied to Tender::status (pending, em_tratamento, …).
 *
 * Use case: some users only deal with tenders that are already
 * "em_tratamento" (they take over after triage). Others handle only
 * "pending" (the triage-and-route role). Restricting their visibility
 * keeps the dashboard and digest focused on what they actually act on.
 *
 * Same NULL/[]/array semantics as allowed_sources:
 *   NULL  → no restriction (default)
 *   []    → blocked from every status
 *   [...] → only these statuses
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_collaborators', function (Blueprint $t) {
            $t->json('allowed_statuses')->nullable()->after('allowed_sources');
        });
    }

    public function down(): void
    {
        Schema::table('tender_collaborators', function (Blueprint $t) {
            $t->dropColumn('allowed_statuses');
        });
    }
};
