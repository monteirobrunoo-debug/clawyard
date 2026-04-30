<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confidential-tender flag.
 *
 * When TRUE on a tender, the system blocks:
 *   • LLM agent calls that would carry the tender's title/description
 *     (Claude, NVIDIA NIM) — no content leaves Laravel
 *   • Tavily web search augmentation
 *   • Supplier auto-extraction from agent responses about this tender
 *   • Any "Sugerir fornecedores e drafts" button (the panel is hidden)
 *
 * Use case: NATO / classified RFQs where the title alone could be
 * sensitive ("torpedo fire control system for X frigate"). The
 * sales-loop dashboard, SAP sync and tender notes still work, but
 * AI augmentation is OFF.
 *
 * Default false — mark concursos as confidencial manually on the
 * detail page (manager+ via TenderUpdateRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            if (!Schema::hasColumn('tenders', 'is_confidential')) {
                $t->boolean('is_confidential')->default(false)->index()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            if (Schema::hasColumn('tenders', 'is_confidential')) {
                $t->dropColumn('is_confidential');
            }
        });
    }
};
