<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL GIN index on tsvector of title + summary (fast full-text search)
        DB::statement("
            CREATE INDEX IF NOT EXISTS documents_fts_idx
            ON documents
            USING GIN (to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(summary,'')))
        ");

        // Also a regular index on source for fast filtering
        DB::statement("
            CREATE INDEX IF NOT EXISTS documents_source_idx
            ON documents (source)
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_fts_idx');
        DB::statement('DROP INDEX IF EXISTS documents_source_idx');
    }
};
