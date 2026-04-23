<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tender Imports — one row per Excel upload. Audit trail for idempotency
 * (file_hash lets us detect "already imported this exact file") plus
 * post-hoc debugging ("why did row X disappear after the Thursday import?").
 *
 * `errors` JSON stores parse/validation failures per row so the super-user
 * can review what was skipped without having to re-upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_imports', function (Blueprint $t) {
            $t->id();
            $t->string('source', 32);                 // nspa | nato | sam_gov | ncia | acingov | vortal | ungm | unido | other
            $t->string('file_name');
            $t->string('file_hash', 64);              // sha256 — detect duplicate uploads
            $t->string('sheet_name')->nullable();
            $t->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete(); // who uploaded
            $t->unsignedInteger('rows_parsed')->default(0);
            $t->unsignedInteger('rows_created')->default(0);
            $t->unsignedInteger('rows_updated')->default(0);
            $t->unsignedInteger('rows_skipped')->default(0);
            $t->json('errors')->nullable();           // [{row: 42, reason: "missing reference"}]
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamps();

            $t->index(['source', 'created_at']);
            $t->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_imports');
    }
};
