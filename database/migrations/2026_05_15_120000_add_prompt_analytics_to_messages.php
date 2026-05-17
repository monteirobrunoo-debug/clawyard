<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona colunas de telemetria por-mensagem para a feature
 * "Análise de Prompts" (2026-05-15).
 *
 * Antes: latency / tokens / custo só existiam ao nível de AGENT_METRIC
 * (denormalizado, sem granularidade). Os swarm_runs gravavam isto mas
 * o chat normal descartava. Resultado: o dashboard não conseguia
 * mostrar "qual prompt foi mais lento" ou "qual agente custa mais".
 *
 * Agora: cada assistant message guarda
 *   • model          — identificador do modelo que respondeu (claude-sonnet-4-5, …)
 *   • tokens_in      — tokens enviados (prompt + history + system)
 *   • tokens_out     — tokens recebidos (completion)
 *   • latency_ms     — ms entre primeira HTTP request e DONE
 *   • cost_usd       — custo estimado da chamada (input + output)
 *   • is_failed      — utilizador deu thumbs-down OU stream errou
 *
 * Todas as colunas são nullable para que mensagens legadas (sem
 * telemetria) não quebrem e que o backfill possa ser feito a
 * eventos antigos sem migration adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->string('model', 80)->nullable()->after('agent');
            $t->unsignedInteger('tokens_in')->nullable()->after('model');
            $t->unsignedInteger('tokens_out')->nullable()->after('tokens_in');
            $t->unsignedInteger('latency_ms')->nullable()->after('tokens_out');
            $t->decimal('cost_usd', 10, 6)->nullable()->after('latency_ms');
            $t->boolean('is_failed')->default(false)->after('cost_usd');

            // Index por (agent, created_at) para queries de saúde dos agentes
            $t->index(['agent', 'created_at'], 'messages_agent_created_at_idx');
            // Index por is_failed para detecção rápida de prompts maus
            $t->index(['is_failed', 'created_at'], 'messages_failed_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropIndex('messages_agent_created_at_idx');
            $t->dropIndex('messages_failed_created_at_idx');
            $t->dropColumn(['model', 'tokens_in', 'tokens_out', 'latency_ms', 'cost_usd', 'is_failed']);
        });
    }
};
