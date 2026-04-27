<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user agent whitelist. Mirrors the allowed_sources / allowed_statuses
 * pattern on TenderCollaborator so the access-control mental model stays
 * consistent across the app.
 *
 * Semantics:
 *   • NULL  → no restriction. User can use every agent. Backwards-
 *             compatible default for every existing row.
 *   • []    → blocked from every agent. User can log in but the
 *             agent picker is empty.
 *   • [...] → whitelist of agent keys ('sales', 'sap', 'aria', …).
 *             Anything not in the list is hidden from the picker
 *             and rejected at the chat endpoint with 403.
 *
 * The admin matrix view (/admin/agent-access) flips bits in this column
 * and the chatStream + agents API enforce on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('allowed_agents')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('allowed_agents');
        });
    }
};
