<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates organizational_knowledge com jsonb + sem transaction poison.
 *
 * Bug detectado em produção 2026-05-25: a migration anterior
 * (2026_05_22_120000) declarava `$t->json('tags')` que cria coluna
 * tipo `json` em Postgres. Depois o `CREATE INDEX ... USING GIN (tags
 * jsonb_path_ops)` falhava com "operator class does not accept data
 * type json". Como Laravel envolve up() em transaction por defeito,
 * a falha do GIN poisonava TODA a transaction → tabela nunca era
 * commitada → `knowledge add` rebentava com "relation does not exist".
 *
 * Fix:
 *   1. $withinTransaction = false → cada DDL é auto-commit
 *   2. jsonb() em vez de json() → GIN aceita
 *   3. dropIfExists primeiro → idempotente
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::dropIfExists('organizational_knowledge');

        Schema::create('organizational_knowledge', function (Blueprint $t) {
            $t->id();
            $t->string('knowledge_key', 150)->unique();
            $t->text('knowledge_value');
            $t->string('category', 30)->default('general');
            $t->decimal('importance', 3, 2)->default(0.50);
            $t->string('source', 30)->default('manual');
            $t->foreignId('extracted_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('extracted_from_context', 100)->nullable();
            $t->jsonb('tags')->nullable();
            $t->unsignedInteger('recall_count')->default(0);
            $t->timestamp('last_recalled_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index('category');
            $t->index(['category', 'importance']);
            $t->index('expires_at');
        });

        // GIN index para tags JSONB — Postgres only, fail-silent.
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_org_knowledge_tags ON organizational_knowledge USING GIN (tags jsonb_path_ops)');
            }
        } catch (\Throwable $e) {
            \Log::info('organizational_knowledge: GIN skipped — ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_knowledge');
    }
};
