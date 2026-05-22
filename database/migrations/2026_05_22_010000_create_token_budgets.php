<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * token_budgets — pool mensal partilhado de tokens Anthropic.
 *
 * Pedido directo 2026-05-22: "patilhar tokens no valor de 150 euros
 * por mes para os utilziaradores, quando experiar quero saber, o que
 * nao usa consome o outro que necesssita, mas cria ranking de tokens
 * com preços reais".
 *
 * Modelo: 1 row por período (YYYY-MM). Pool partilhado, não há
 * allocations per-user. O ranking mostra quem gastou quanto vs fair
 * share (€150 ÷ users activos), mas não impõe hard cap individual.
 *
 * O custo real vem agregado das 3 tabelas existentes que já têm
 * cost_usd: messages, agent_runs, tender_service_analyses.
 *
 * Notificações:
 *   • Quando pool atinge 80% → admin recebe email
 *   • Quando atinge 100% → admin + flag para hard-gate (futuro)
 *   • flags notified_at_80/100 são timestamp idempotente — emails
 *     não são re-enviados se já saiu uma vez no período.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('token_budgets', function (Blueprint $t) {
            $t->id();
            $t->char('period_yyyy_mm', 7)->unique();   // '2026-05'
            $t->decimal('pool_eur', 10, 2)->default(150.00);
            $t->unsignedTinyInteger('alert_at_percent')->default(80);
            $t->unsignedTinyInteger('hard_gate_at_percent')->default(0);
            $t->timestamp('notified_at_80')->nullable();
            $t->timestamp('notified_at_100')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_budgets');
    }
};
