<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pgvector upgrade — adiciona coluna `embedding` (1024-dim) ao
 * technical_book_chunks para semantic search via cosine similarity.
 *
 * Modelo de embeddings: nvidia/nv-embedqa-e5-v5 (1024 dims, optimizado
 * para retrieval QA, multilíngue PT/EN/ES). Custo ~free no NIM tier.
 *
 * Pré-requisito: extensão `vector` no Postgres. Já existe no droplet
 * Forge porque hp-history a usa, mas o CREATE EXTENSION é idempotente.
 *
 * Após esta migration, correr:
 *   php artisan books:embed                 (gera embeddings para todos)
 *   php artisan books:embed --refresh       (regenera tudo)
 *   php artisan books:embed --batch=20      (envia 20 chunks por chamada)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Garantir extensão pgvector activa (idempotente)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            } catch (\Throwable $e) {
                // Algumas configs Postgres precisam superuser; se falhar,
                // o user terá de correr manualmente. Não bloquear deploy.
                \Log::warning('Could not create vector extension: ' . $e->getMessage());
            }
        }

        // 2. Adicionar coluna embedding — 1024 dims (nv-embedqa-e5-v5)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE technical_book_chunks ADD COLUMN IF NOT EXISTS embedding vector(1024)');
            DB::statement('ALTER TABLE technical_book_chunks ADD COLUMN IF NOT EXISTS embedding_model varchar(80)');

            // 3. Índice IVFFlat para cosine similarity rápida.
            // lists=100 é razoável para 1.7k rows (sqrt(N) heuristic).
            // Cresce para ~lists=200 quando passar 10k chunks.
            try {
                DB::statement("CREATE INDEX IF NOT EXISTS technical_book_chunks_embedding_idx
                              ON technical_book_chunks
                              USING ivfflat (embedding vector_cosine_ops)
                              WITH (lists = 100)");
            } catch (\Throwable $e) {
                \Log::warning('Could not create ivfflat index: ' . $e->getMessage());
            }
        } else {
            // SQLite/MySQL fallback — coluna json/text para preservar API
            Schema::table('technical_book_chunks', function (Blueprint $t) {
                if (!Schema::hasColumn('technical_book_chunks', 'embedding_model')) {
                    $t->string('embedding_model', 80)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS technical_book_chunks_embedding_idx');
            DB::statement('ALTER TABLE technical_book_chunks DROP COLUMN IF EXISTS embedding');
            DB::statement('ALTER TABLE technical_book_chunks DROP COLUMN IF EXISTS embedding_model');
        }
    }
};
