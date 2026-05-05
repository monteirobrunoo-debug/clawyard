<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-agent "service execution analysis" cache for tenders.
 *
 * One row per tender (unique). When the operator clicks "Análise do
 * serviço" on a tender, TenderServiceAnalysisService consults 4-6
 * specialist agents (Cor. Rodrigues, Marco Sales, Captain Porto,
 * Eng. Victor, etc.), aggregates their output, and stores it here.
 *
 * Re-running the analysis overwrites the row. The 24h cache is
 * enforced by the service (compares generated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_service_analyses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('status', 16)->default('pending');     // pending | running | done | failed
            $t->json('agents_consulted')->nullable();         // ["mildef","sales","capitao","engineer"]
            $t->json('sections')->nullable();                  // {agent_key: {summary, key_points, risks, recommendations}, ...}
            $t->text('executive_summary')->nullable();         // synthesized top-level paragraph
            $t->decimal('total_cost_usd', 8, 4)->default(0);
            $t->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_service_analyses');
    }
};
