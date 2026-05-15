<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Cache SAP opportunity stage on tender row.
     *
     * RAZÃO:
     *   O dashboard de concursos mostrava `tender.status` (campo local
     *   manual editado num <select>). O utilizador pediu: "use sempre
     *   a info do estado SAP — essa é a válida para sabermos sempre
     *   via SAP o estado sem necessidade de clicar".
     *
     *   Fetch SAP por tender no render do dashboard = N+1 calls, 30+
     *   tenders × 2s/call = ≥1 min de load. Inviável.
     *
     *   Solução: cache na linha tender. Update opportunístico quando
     *   `sapPreview()` é chamado (utilizador entra no concurso → JSON
     *   fetch grava SAP stage_no e timestamp). O dashboard mostra valor
     *   cached + indicador "última sincronização há X".
     *
     * COLUNAS:
     *   sap_stage_no          → 1=Prospecção, 5=Cotação Compra, 6=Cotação
     *                           Venda, 7=Follow Up, 8=Possível Venda,
     *                           9=Ordem Compra, 10=Ordem Venda
     *   sap_opportunity_status → 'sos_Open' | 'sos_Won' | 'sos_Lost'
     *   sap_stage_updated_at   → timestamp do último fetch
     */
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->unsignedTinyInteger('sap_stage_no')->nullable()->after('sap_opportunity_number');
            $table->string('sap_opportunity_status', 32)->nullable()->after('sap_stage_no');
            $table->timestamp('sap_stage_updated_at')->nullable()->after('sap_opportunity_status');
            $table->index('sap_stage_no');
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->dropIndex(['sap_stage_no']);
            $table->dropColumn(['sap_stage_no', 'sap_opportunity_status', 'sap_stage_updated_at']);
        });
    }
};
