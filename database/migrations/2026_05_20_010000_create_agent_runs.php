<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * agent_runs — auditoria de cada execução autónoma de um agent
 * (tool-use loop + extended thinking).
 *
 * Pedido directo 2026-05-20: "agentes com mega capacidade de análise e
 * autónomos". Cada vez que TenderServiceAnalysisService chama um agent,
 * uma row aqui regista: que tools foram usadas, quantas iterações,
 * custo USD, output final, e o thinking-block se aplicável.
 *
 * Permite:
 *   - dashboard /agents/runs para o operador ver custo + comportamento
 *   - cost guard (corta quando estimated_cost ultrapassa cap)
 *   - debug em produção quando uma análise sai estranha
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('agent_key', 40)->index();              // ex.: mildef, sales
            $t->string('purpose', 60)->default('analysis');    // analysis|chat|crm_opp|…
            $t->unsignedSmallInteger('iterations')->default(0);
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('thinking_tokens')->default(0);
            $t->decimal('cost_usd', 8, 4)->default(0);
            $t->json('tool_trace')->nullable();    // [{name, input, output, ms, ok}]
            $t->text('final_text')->nullable();    // resposta agregada
            $t->text('thinking_text')->nullable(); // extended thinking (folded UI)
            $t->string('status', 20)->default('running'); // running|done|failed|cost_capped
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamps();
            $t->index(['tender_id', 'agent_key']);
            $t->index(['user_id', 'created_at']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
