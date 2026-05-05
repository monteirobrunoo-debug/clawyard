<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biblioteca técnica PartYard — soldadura naval, mecânica, reparações.
 * Indexa os 15 livros (Manuais/biblioteca-tecnica/) em chunks
 * pesquisáveis para o WorkReportAgent citar fontes ao responder.
 *
 * Chunking: 1 chunk por página de PDF (página é unidade natural
 * para citation: "Modenesi cap. 4 p.87"). Texto guardado tal qual
 * (até 8000 chars/página). Pesquisa: full-text PostgreSQL via
 * GIN tsvector OU fallback LIKE para SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_book_chunks', function (Blueprint $t) {
            $t->id();
            $t->string('book_key', 80);          // ex: "01-metalurgia-da-soldagem-modenesi"
            $t->string('book_title', 200);       // human-readable título
            $t->string('domain', 32);            // soldadura | naval | outros
            $t->unsignedSmallInteger('page_no'); // 1-based
            $t->text('content');                 // até 8000 chars
            $t->json('keywords')->nullable();    // ["mig","mag","fcaw","6013"]
            $t->timestamps();

            $t->index(['domain', 'book_key']);
            $t->index('page_no');
        });

        // Postgres: full-text search index (huge perf gain on LIKE %X%)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \DB::statement("CREATE INDEX technical_book_chunks_fts_idx ON technical_book_chunks USING GIN (to_tsvector('portuguese', content))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_book_chunks');
    }
};
