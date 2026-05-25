<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenders.sap_customer_card_code — associação directa do tender a um
 * Customer/Lead no SAP B1.
 *
 * Pedido directo 2026-05-25 (Marine Department Fase 1):
 * "Tem que se associar diretamente o Cliente como está em SAP se
 *  não o agente não cria OP."
 *
 * Contexto: SAP B1 Sales Opportunities só aceitam BPs do tipo
 * Customer (C) ou Lead (L). Suppliers (F) são rejeitados. Quando
 * o tender Marine é criado e a `purchasing_org` (texto livre)
 * corresponde a um Supplier no SAP, Marta falha a criar OP com:
 *   "Não encontrei nenhum Customer ou Lead no SAP B1"
 *
 * Esta coluna permite ao operador linkar manualmente o CardCode
 * correcto (ex: C000263 NSPA/CIMO). Marta usa este valor directamente
 * se presente, em vez de fazer lookup por nome (que pode dar match
 * com Supplier errado).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            $t->string('sap_customer_card_code', 30)
                ->nullable()
                ->after('purchasing_org')
                ->comment('SAP B1 CardCode (Customer ou Lead) para Sales Opportunity');
            $t->index('sap_customer_card_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $t) {
            $t->dropIndex(['sap_customer_card_code']);
            $t->dropColumn('sap_customer_card_code');
        });
    }
};
