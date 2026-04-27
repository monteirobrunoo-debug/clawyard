<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated database of port workshops / OEM service centres / shipyards
 * that PartYard does (or wants to do) business with.
 *
 * Fed by `php artisan marco:import-partners <xlsx>`. Two agents query it:
 *
 *   • Marco (sales)  → SPARES domain — Wärtsilä, Bergen, MTU service centres,
 *                      drive systems, electrical workshops, parts logistics
 *   • Vasco (vessel) → REPAIR domain — full-service shipyards, drydocks,
 *                      naval weapon systems, classification societies
 *
 * Why a `domains` JSON column instead of a single enum: most full-service
 * shipyards qualify as BOTH (Damen, Fincantieri, Drydocks World do
 * repairs AND stock spares for the engines they overhaul). Keeping it
 * as an array of tags lets the same row surface to either agent
 * naturally — no duplicate insert.
 *
 * Status is the manual-curation tier from the workshop spreadsheet:
 * `high_priority` / `active_prospect` / `partner_candidate` / `prospect`
 * / `info_only` — admins re-rank without touching the import.
 *
 * Source-trace: `source_file` keeps the path of the xlsx the row was
 * imported from; `source_row` keeps the original row number. Re-importing
 * the same file is idempotent via the unique (port, company_name) index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_workshops', function (Blueprint $t) {
            $t->id();

            $t->string('port', 96)->index();
            $t->string('country', 64)->nullable()->index();
            $t->string('region', 64)->nullable();   // Northern Europe / Mediterranean / …
            $t->string('company_name', 192);
            $t->string('category', 96)->nullable(); // free-text from sheet, e.g. "Prime Movers / Engine OEM"

            // Free-text "Services Covered" → semicolon-separated list
            // captured verbatim from the sheet AND a normalised array of
            // tokens for SQL filtering (Postgres JSON contains operator).
            $t->text('services_text')->nullable();
            $t->json('service_tokens')->nullable();

            // Coverage matrix from sheet 3: chips for the 17 PartYard
            // service columns. Stored as JSON object so the agent layer
            // can do `?->where('coverage_chips->prime_movers', true)`.
            $t->json('coverage_chips')->nullable();

            // SPARES vs REPAIR routing. Auto-classified at import time
            // (see PartnerDomainClassifier) and editable by hand.
            $t->json('domains')->nullable();        // ["spares"], ["repair"], or both

            $t->string('address', 255)->nullable();
            $t->string('phone', 64)->nullable();
            $t->string('email', 128)->nullable();
            $t->string('website', 191)->nullable();

            $t->text('relevance')->nullable();      // "PartYard Relevance" column
            $t->string('priority', 32)->default('prospect')->index();
            $t->text('notes')->nullable();

            // Provenance — important so future imports can compare against
            // the same row even after a sheet reshuffle.
            $t->string('source_file', 255)->nullable();
            $t->unsignedInteger('source_row')->nullable();

            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();

            // Same partner can appear twice (e.g. Wärtsilä in Greece AND
            // in Dubai). Uniqueness must be (port, company_name).
            $t->unique(['port', 'company_name']);
            $t->index(['priority', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_workshops');
    }
};
