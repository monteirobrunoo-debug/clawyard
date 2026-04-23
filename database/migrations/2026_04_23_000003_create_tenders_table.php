<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenders — unified table for procurement opportunities from all sources.
 *
 * MULTI-SOURCE STRATEGY
 * ---------------------
 * Different sources ship radically different schemas (NSPA has 18 cols,
 * SAM.gov uses JSON API, Acingov is RSS, etc.). To stay flexible:
 *   • Common queryable fields are promoted to columns (reference, title,
 *     deadline_at, status, assignee) — fast filters + indexed dashboard.
 *   • Everything else lives in `raw_metadata` JSON — preserves the source
 *     of truth so we can re-derive columns later without re-importing.
 *   • `(source, reference)` is UNIQUE — same reference can exist in
 *     different sources without collision; re-imports upsert cleanly.
 *
 * TIMEZONE
 * --------
 * All *_at columns are stored in UTC (Laravel default). The source timezone
 * varies per feed — NSPA is Luxembourg (UTC+1/+2), NATO is Brussels, SAM.gov
 * is America/New_York. Each importer converts to UTC on write. Display
 * layer shows both Lisbon and Luxembourg per user request — see the
 * `deadline_lisbon` / `deadline_luxembourg` accessors on the Tender model.
 *
 * STATUS ENUM
 * -----------
 * Status values are normalised on import from the Excel free-text values
 * listed in the `Dados` sheet: EM TRATAMENTO, CANCELADO, NÃO TRATAR,
 * SUBMETIDO, AVALIAÇÃO — plus extras we'll need downstream (GANHO,
 * PERDIDO, PENDING when blank). Stored as snake_case lowercase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $t) {
            $t->id();

            // ── Identity ────────────────────────────────────────────────
            $t->string('source', 32);                        // nspa|nato|sam_gov|ncia|acingov|vortal|ungm|unido|other
            $t->string('reference');                         // e.g. JLA25005, CSP25050
            $t->string('title', 500);
            $t->string('type', 64)->nullable();              // Service / Supply / Works
            $t->string('purchasing_org', 255)->nullable();

            // ── Workflow ────────────────────────────────────────────────
            $t->string('status', 32)->default('pending');    // em_tratamento|cancelado|nao_tratar|submetido|avaliacao|ganho|perdido|pending
            $t->string('priority', 16)->nullable();          // baixo|medio|alto — from "Nivel"
            $t->foreignId('assigned_collaborator_id')->nullable()
                ->constrained('tender_collaborators')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->foreignId('assigned_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // ── Dates (all UTC storage) ─────────────────────────────────
            $t->timestamp('deadline_at')->nullable();        // RFP_ClosingDate (source TZ converted to UTC)
            $t->timestamp('source_modified_at')->nullable(); // RFP_LastModifiedDate

            // ── SAP integration ─────────────────────────────────────────
            $t->string('sap_opportunity_number', 64)->nullable(); // e.g. "15567/2025"

            // ── Commercial ──────────────────────────────────────────────
            $t->decimal('offer_value', 15, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->decimal('time_spent_hours', 6, 2)->nullable();

            // ── Free-text & outcome ─────────────────────────────────────
            $t->text('notes')->nullable();                   // "Coluna1" — reasons, context
            $t->string('result', 64)->nullable();            // RESULTADO

            // ── Import lineage & digest tracking ────────────────────────
            $t->json('raw_metadata')->nullable();            // full original row, future-proofing
            $t->foreignId('last_import_id')->nullable()
                ->constrained('tender_imports')->nullOnDelete();
            $t->timestamp('last_digest_sent_at')->nullable(); // avoid hammering users

            $t->timestamps();
            $t->softDeletes();                               // never lose history

            // ── Indexes ─────────────────────────────────────────────────
            $t->unique(['source', 'reference']);             // idempotent upsert key
            $t->index('status');
            $t->index('deadline_at');
            $t->index(['assigned_collaborator_id', 'status']);
            $t->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
