<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase B do dashboard de tender — cotações recebidas dos fornecedores
 * convidados na Fase 1. Cada row é 1 resposta de 1 fornecedor para 1
 * tender; Excel comparativo gera-se a partir desta tabela.
 *
 * Suporta:
 *   - supplier_id formal (vindo de /suppliers)
 *   - supplier_name_freetext quando é ad-hoc (fornecedor que não está
 *     ainda registado na BD)
 *   - PDF original anexado via pdf_attachment_id (FK a tender_attachments)
 *   - parsed_by_marta_at timestamp para sinalizar extracção LLM
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tender_supplier_quotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->string('supplier_name_freetext', 200)->nullable();

            $t->decimal('unit_price', 14, 2)->nullable();
            $t->string('currency', 3)->default('EUR');
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('total_price', 14, 2)->nullable();

            $t->unsignedSmallInteger('delivery_days')->nullable();
            $t->unsignedSmallInteger('validity_days')->nullable();
            $t->string('incoterm', 10)->nullable();   // CIF, FCA, DAP, EXW, etc.
            $t->text('notes')->nullable();

            // PDF original (se houver) — FK a tender_attachments. Quando
            // o user carrega a cotação em PDF, salvamos como attachment
            // e linkamos aqui para audit.
            $t->foreignId('pdf_attachment_id')->nullable()
                ->constrained('tender_attachments')->nullOnDelete();

            $t->timestamp('parsed_by_marta_at')->nullable();
            $t->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tender_id', 'supplier_id']);
            $t->index('tender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_supplier_quotations');
    }
};
