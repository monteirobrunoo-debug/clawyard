<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tender saved views — guarda combinações de filtros por user
 * para se aplicarem com 1 click.
 *
 * Pedido directo 2026-05-19 ("saved views / favoritos"):
 *   user define filtros (source=marine + status=em_tratamento + q="bomba"),
 *   carrega "💾 Guardar como…", dá-lhe nome ("As minhas marítimas urgentes"),
 *   passa a aparecer como chip clicável no topo do /tenders. Tap → filtros
 *   re-aplicados via GET.
 *
 * Scope: per-user (cada user vê apenas as suas). Sem partilha entre users.
 * sort_order permite drag-reorder no futuro.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tender_saved_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->json('filters');  // {source, status, urgency, collaborator_id, q, sort, dir}
            $t->unsignedTinyInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_saved_views');
    }
};
