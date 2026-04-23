<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tender Collaborators — people who appear in the `Colaborador` column of the
 * imported Excel sheets. They may or may not have a linked User account.
 *
 * Matching strategy on import:
 *   1. Normalise the name (lowercase, strip accents, collapse spaces)
 *   2. Lookup by `normalized_name`; create if missing
 *   3. If a User with matching email exists, link via `user_id`
 *
 * Why a separate table (vs. reusing users)?
 *   • Excel names don't always map to registered users ("Sala Procurement")
 *   • Names can be renamed/merged without blowing up historical tender rows
 *   • Future-proofs cross-source collaborator identity (NSPA uses names,
 *     SAM.gov uses emails, NATO uses codes…)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_collaborators', function (Blueprint $t) {
            $t->id();
            $t->string('name');                       // as it appears in the source file
            $t->string('normalized_name')->unique();  // lowercase+ascii for idempotent upsert
            $t->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $t->string('email')->nullable();          // fallback when no user linked
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_collaborators');
    }
};
