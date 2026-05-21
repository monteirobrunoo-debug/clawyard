<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21: Web intel sync para fornecedores aprovados.
 *
 * Pedido directo do Bruno: "Os agentes tem de verificar na web o que
 * faz os fornecedores e confrontar se os que temos aprovado tem o
 * mesmo material também".
 *
 * Hoje o matching local depende de categories/subcategories estáticos
 * preenchidos manualmente — incompleto. Estes campos guardam o que
 * Tavily + Claude extraem do site real do fornecedor (catálogo,
 * produtos, capacidades) para o suggester cruzar com itens do tender.
 *
 * Restricted categories (13 militar, 14 PartYard Systems): NÃO são
 * sincronizadas (security audit 2026-05-02 — nomes não saem para a
 * Tavily). status='skipped_restricted' nestes casos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            // Resumo executivo em prosa (≤500 chars) do que o supplier
            // faz, gerado por Claude a partir das páginas extraídas.
            $t->text('web_intel_summary')->nullable();

            // Lista estruturada de produtos/categorias detectadas no
            // site. Format: ["distribution boxes", "circuit breakers",
            // "surge protectors", ...]. Usado pelo suggester para
            // fazer match contra itens do tender (extraídos pela Marta).
            $t->jsonb('web_intel_products')->nullable();

            // URLs das páginas usadas como evidence. Format:
            // [{"url": "...", "title": "..."}, ...]. Operador clica
            // para ver a fonte ao validar o match.
            $t->jsonb('web_intel_urls')->nullable();

            // Quando foi sincronizado pela última vez. Re-sync após
            // 30 dias por defeito (cron) — websites mudam, lineup
            // de produtos também.
            $t->timestamp('web_intel_synced_at')->nullable();

            // Estado da última sincronização para investigação
            // (UI mostra "✓ verificado" vs "✗ falha" vs "🔒 restrito").
            //   pending             — nunca corrido
            //   ok                  — extraído com sucesso
            //   failed              — Tavily/Claude rebentou
            //   no_data             — Tavily devolveu zero hits
            //   skipped_restricted  — cat 13/14, nunca tentar
            $t->string('web_intel_status', 20)->nullable();

            $t->text('web_intel_error')->nullable();

            $t->index('web_intel_synced_at');
            $t->index('web_intel_status');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropIndex(['web_intel_synced_at']);
            $t->dropIndex(['web_intel_status']);
            $t->dropColumn([
                'web_intel_summary',
                'web_intel_products',
                'web_intel_urls',
                'web_intel_synced_at',
                'web_intel_status',
                'web_intel_error',
            ]);
        });
    }
};
