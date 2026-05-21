<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21: Anthropic Batch API tracking.
 *
 * Anthropic Message Batches: até 100k requests por batch, processadas
 * em ≤24h, **50% off** no input/output token pricing. Perfeito para
 * tarefas não-interactivas (multi-agent nocturno, web-intel resync).
 *
 * Esta tabela guarda o estado de cada batch submetido:
 *   • batch_id          — UUID devolvido pela Anthropic
 *   • model             — modelo usado (sonnet-4.6 / haiku-4.5)
 *   • kind              — categoria (tender-analysis | supplier-intel | etc)
 *   • request_count     — quantos requests no batch
 *   • status            — created → in_progress → ended | cancelled | failed
 *   • submitted_at      — timestamp da submissão
 *   • ended_at          — quando Anthropic marcou ended
 *   • polled_at         — última vez que polámos status
 *   • results_collected — true quando já transferimos os results e
 *                          os aplicámos às rows originais
 *   • metadata          — JSON {anthropic_response{}, mapping{}, etc}
 *   • cost_usd_estimated — estimativa antes (vs custo real após end)
 *
 * Sem foreign keys — o link aos tenders/suppliers vai no metadata
 * via custom_id que cada request leva.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('anthropic_batches', function (Blueprint $t) {
            $t->id();

            // Identidade Anthropic
            $t->string('batch_id', 100)->unique()->nullable();   // null até criar
            $t->string('model', 64);
            $t->string('kind', 32)->index();   // tender-analysis|supplier-intel|other

            // Volumetria + estado
            $t->integer('request_count')->default(0);
            $t->string('status', 32)->default('pending')->index();   // pending|created|in_progress|ended|cancelled|failed

            // Timestamps
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('polled_at')->nullable();

            // Results collection
            $t->boolean('results_collected')->default(false)->index();
            $t->integer('results_succeeded')->nullable();
            $t->integer('results_errored')->nullable();
            $t->integer('results_canceled')->nullable();
            $t->integer('results_expired')->nullable();

            // Cost tracking
            $t->decimal('cost_usd_estimated', 8, 4)->nullable();
            $t->decimal('cost_usd_actual', 8, 4)->nullable();

            // Mapping de custom_id → row (tender_id, supplier_id, etc)
            // + raw response da Anthropic + erros se houver
            $t->jsonb('metadata')->nullable();

            // Audit
            $t->bigInteger('created_by_user_id')->unsigned()->nullable();

            $t->timestamps();

            $t->index(['kind', 'status']);
            $t->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anthropic_batches');
    }
};
