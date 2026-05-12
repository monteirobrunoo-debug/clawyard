<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * #8 — Audit log of sensitive actions.
     *
     * Captures who-did-what for actions that compliance/HP-Group might
     * need to reconstruct: SAP opportunity create, tender assign, user
     * role change, agent share, message delete, conversation export.
     *
     * Designed append-only — `destroy()` is not exposed in the UI.
     * Rotation handled out-of-band (90 day retention via scheduled job).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->index();
            $table->string('action', 60)->index();          // "tender.assign", "user.role_change", etc.
            $table->string('resource_type', 60)->nullable(); // "Tender", "User", "LeadOpportunity"
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('payload')->nullable();             // before/after diff for updates
            $table->timestamp('created_at')->useCurrent();
            $table->index(['resource_type', 'resource_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
