<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * organizational_knowledge — memória PARTILHADA da PartYard / HP-Group.
 *
 * Pedido directo 2026-05-22 (após rollback do per-user LTM):
 * "os users vão se esquecer de pedir para memorizar, tem de ser criado
 *  memoria para os agentes da ClawYard que pertence à PartYard".
 *
 * Diferenças vs LTM per-user (que partimos antes):
 *   • Memórias são da EMPRESA, não de cada user
 *   • Auto-extracted em background (job pós-chat, não no hot path)
 *   • Acessível via TOOL (`knowledge_search`) — agente decide quando
 *   • ZERO toque em chat()/stream() ou enrichSystemPrompt()
 *
 * Categorias canónicas:
 *   • supplier   — fornecedores aprovados, contactos, preferências
 *   • customer   — clientes, NIFs, vendedor pairing
 *   • pricing    — preços históricos, ranges, ranges de margem
 *   • regulation — STANAG, ITAR, EU regs com data validade
 *   • process    — fluxos internos PartYard (Inquiry MOD_072, etc)
 *   • product    — produtos, NSNs, partnumbers conhecidos
 *   • preference — "Bruno prefere X" tipo decisões corporativas
 *   • general    — fallback
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizational_knowledge', function (Blueprint $t) {
            $t->id();
            $t->string('knowledge_key', 150)->unique();
            $t->text('knowledge_value');
            $t->string('category', 30)->default('general')->index();
            $t->decimal('importance', 3, 2)->default(0.50);
            $t->string('source', 30)->default('manual');
            // source: 'manual' | 'auto-extracted' | 'web-search' | 'doc-import'
            $t->foreignId('extracted_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('extracted_from_context', 100)->nullable();  // tender_id, conversation_id
            $t->json('tags')->nullable();
            $t->unsignedInteger('recall_count')->default(0);
            $t->timestamp('last_recalled_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['category', 'importance']);
            $t->index('expires_at');
        });

        // GIN index para tags JSONB (Postgres). Skip silenciosamente em SQLite.
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX idx_org_knowledge_tags ON organizational_knowledge USING GIN (tags jsonb_path_ops)');
            }
        } catch (\Throwable $e) {
            // index é optimização — falha não bloqueia migration
            \Log::info('organizational_knowledge: GIN index skipped — ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_knowledge');
    }
};
