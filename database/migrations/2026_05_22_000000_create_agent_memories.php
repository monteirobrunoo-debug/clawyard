<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * agent_memories — Long-Term Memory (LTM) para os agentes.
 *
 * Base teórica: "Agentic Artificial Intelligence" (Bornet et al., 2025),
 * Cap 7 — Memory: Building AI That Learns. Os LLMs em produção (level 3)
 * têm "memória de peixe-dourado" — começam de zero em cada conversa.
 * Estudos citados pelo livro mostram +70% velocidade + +45% satisfação
 * quando agentes têm LTM persistente.
 *
 * Arquitectura: key/value scopado por (user_id, agent_key). Cada
 * agente tem o SEU caderno de notas POR utilizador. Bruno e Pedro
 * podem ter preferências diferentes para o mesmo agente.
 *
 * importance ∈ [0, 1] é usado pelo recall — quando o agente recupera
 * top-N memórias, ordena por importance × recency. last_recalled_at
 * dá decay implícito (memórias velhas que nunca são tocadas saem
 * primeiro do top-N).
 *
 * Casos de uso esperados:
 *   • Cor. Rodrigues: "Bruno prefere fornecedores alemães para MTU 396"
 *   • Marco Sales: "PartYard usa SAP B1 sales person SlpCode=12 para mil-def"
 *   • Ana RH: "headcount total ~22, departamento de testes médicos é novo"
 *   • Eng. Victor: "patente SmartShield UXS é fundadora — sempre referir"
 *
 * Privacidade: ON DELETE CASCADE quando o user é eliminado.
 * PII: nunca guardar passwords/tokens/credit cards aqui — fillable scrub.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_memories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('agent_key', 32)->index();
            $t->string('memory_key', 100);
            $t->text('memory_value');
            $t->decimal('importance', 3, 2)->default(0.50);
            $t->unsignedInteger('recall_count')->default(0);
            $t->timestamp('last_recalled_at')->nullable();
            $t->string('source', 32)->default('explicit');
            // source values: 'explicit' (user told agent to remember),
            // 'inferred' (agent saved from conversation), 'system' (preset).
            $t->timestamps();

            // Recall query: user X agent → ordered by importance × recency.
            $t->index(['user_id', 'agent_key', 'importance'], 'idx_mem_recall');
            // Unique: 1 valor por chave dentro do scope.
            $t->unique(['user_id', 'agent_key', 'memory_key'], 'uniq_mem_kv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memories');
    }
};
