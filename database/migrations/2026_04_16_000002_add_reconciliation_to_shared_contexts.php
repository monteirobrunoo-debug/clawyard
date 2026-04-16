<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds context reconciliation columns to shared_contexts.
 *
 * change_type:       'new' | 'confirmed' | 'updated' | 'contradicted'
 * similarity_score:  0.0–1.0 (how similar to previous entry from same agent)
 * change_note:       short auto-generated description of what changed
 * previous_summary:  snapshot of the previous summary for audit trail
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_contexts', function (Blueprint $table) {
            $table->string('change_type', 20)->default('new')->after('tags');
            $table->float('similarity_score')->nullable()->after('change_type');
            $table->string('change_note', 200)->nullable()->after('similarity_score');
            $table->text('previous_summary')->nullable()->after('change_note');
        });
    }

    public function down(): void
    {
        Schema::table('shared_contexts', function (Blueprint $table) {
            $table->dropColumn(['change_type', 'similarity_score', 'change_note', 'previous_summary']);
        });
    }
};
