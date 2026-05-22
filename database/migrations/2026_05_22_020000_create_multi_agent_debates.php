<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * multi_agent_debates — registo de sessões de debate multi-agente
 * para tenders críticos.
 *
 * Base: Bornet 2025 Cap 6 (Cognitive Diversity, Montreal research):
 * agentes diversos a debater geram +13% accuracy vs single agent.
 * MIT/Google Brain: debate reduz error rate em 22%.
 *
 * Triggers típicos:
 *   • Tenders > €100k (manual via botão)
 *   • Tenders mil-def de alto risco
 *   • Decisões reversíveis caras (e.g. recomendação de OEM exclusivo)
 *
 * 3 rounds:
 *   round=1 — independent: cada agente propõe sem ver os outros
 *   round=2 — critique: cada agente lê outros + identifica disagreements
 *   round=3 — synthesis: Haiku consolida com confidence weighting
 *
 * Estado armazenado por round → permite resumir + tracear o processo.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('multi_agent_debates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('topic', 500);  // pergunta ou intent que originou o debate
            $t->json('agent_keys');     // ['mildef', 'sales', 'engineer']
            $t->string('status', 20)->default('pending');
            // status: pending | running | done | failed
            $t->json('rounds')->nullable();  // [{round:1, opinions:{agent:text}}, ...]
            $t->text('synthesis')->nullable();
            $t->json('disagreements')->nullable();
            $t->unsignedSmallInteger('confidence_pct')->nullable();  // 0-100 do synthesizer
            $t->decimal('cost_usd', 8, 4)->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['tender_id', 'created_at']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_agent_debates');
    }
};
