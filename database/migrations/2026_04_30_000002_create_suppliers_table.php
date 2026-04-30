<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent supplier directory.
 *
 * Three population paths, all converging on this table:
 *
 *   1. Excel seed — `php artisan suppliers:import` reads the
 *      "Fornecedores Aprovados 2026" workbook (812 rows). source =
 *      'excel_2026'.
 *
 *   2. Auto-extraction — when an agent message contains an email
 *      mention (Marco lists suppliers for a tender, MilDef extracts
 *      naval contractors from a NATO RFQ, etc.), the backend extracts
 *      the address + name and upserts here. source = 'agent_extraction'.
 *
 *   3. Manual — operator hits POST /suppliers and types name/email/
 *      categories. source = 'manual'.
 *
 * The slug is the dedup key. We compute it from the name by lowercasing,
 * stripping accents/punctuation/legal suffixes ("Lda", "S.A.", "Inc"),
 * collapsing whitespace. Two paths writing "Wartsila" / "WÄRTSILÄ Iberia
 * SA" must converge on the same row — hence the slug is on a unique
 * index. Updates union the categories/brands arrays so each path
 * enriches the row instead of overwriting.
 *
 * Outreach analytics columns (total_outreach, total_replies,
 * avg_reply_hours, last_contacted_at) are bumped from the lead-outreach
 * pipeline elsewhere — see LeadOutreachService::recordSent() (TODO when
 * suggestion #2 lands). For now they're left at 0 — Excel-only data
 * doesn't have these stats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();

            // Identity ────────────────────────────────────────────────
            $t->string('name', 255);
            $t->string('slug', 255)->unique();      // dedup key, see comment above
            $t->string('legal_name', 255)->nullable();
            $t->string('country_code', 8)->nullable();   // ISO 3166-1 alpha-2 when known
            $t->string('website', 255)->nullable();

            // Contact ─────────────────────────────────────────────────
            // primary_email = the address we'd send a quote request to.
            // additional_emails: jsonb array of secondary contacts (sales,
            // technical, regional reps). The auto-extractor appends here.
            $t->string('primary_email', 255)->nullable();
            $t->jsonb('additional_emails')->nullable();   // array<string>
            $t->jsonb('phones')->nullable();              // array<string>

            // Approval / quality ──────────────────────────────────────
            // iqf_score: H&P internal "Indice Qualidade Fornecedor"
            //   3   = approved (the bulk of the Excel)
            //   2.5 = approved-with-conditions
            //   <2.5 = trouble
            //   null = unscored (auto-extracted suppliers start here)
            $t->decimal('iqf_score', 4, 2)->nullable();

            // status drives whether the dashboard surfaces them as
            // "ok to contact" (approved/pending) or hides them
            // (blacklisted) from the tender-side suggester.
            $t->string('status', 16)->default('approved')->index();

            // Classification ──────────────────────────────────────────
            // categories  = top-level Excel buckets, ex: ["13","14","16"]
            //               (13 = Military, 14 = PartYard Systems, 16 = Reps Brands)
            // subcategories = fine-grained codes from the Matriz sheet,
            //                 ex: ["13.15","16.34"]
            // brands      = represented brands when applicable, ex: ["Cummins","MTU"]
            $t->jsonb('categories')->nullable();
            $t->jsonb('subcategories')->nullable();
            $t->jsonb('brands')->nullable();

            // Provenance ──────────────────────────────────────────────
            // source identifies which pipeline created the row first.
            // Subsequent updates from other pipelines DON'T overwrite —
            // they merge into the existing row (slug match).
            $t->string('source', 32)->default('manual');
            $t->jsonb('source_meta')->nullable();   // raw row dump for audit

            // Outreach stats (bumped when leads:send-outreach fires) ──
            $t->unsignedInteger('total_outreach')->default(0);
            $t->unsignedInteger('total_replies')->default(0);
            $t->unsignedSmallInteger('avg_reply_hours')->nullable();
            $t->timestamp('last_contacted_at')->nullable();
            $t->timestamp('last_replied_at')->nullable();

            $t->text('notes')->nullable();

            $t->timestamps();

            // Indexes for the search/filter UI.
            $t->index('country_code');
            $t->index('iqf_score');
            $t->index(['status', 'iqf_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
