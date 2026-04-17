<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add user_id scoping to the PSI Shared Context Bus.
 *
 * SECURITY: without this column the bus was global — User A's SAP data
 * (invoices, CardCodes, stock) was being injected into User B's agent
 * prompts. This migration enforces per-user isolation.
 *
 * Nullable because:
 *   - Existing rows predate the column and have no owner
 *   - System / orchestrator publishes may legitimately be userless
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shared_contexts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->index(['user_id', 'agent_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shared_contexts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'agent_key']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn('user_id');
        });
    }
};
