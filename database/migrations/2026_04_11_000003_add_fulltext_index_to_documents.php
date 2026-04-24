<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This index relies on PostgreSQL-specific syntax (USING GIN,
        // to_tsvector). On SQLite (used by the automated test suite —
        // see phpunit.xml) the statement throws a syntax error and blocks
        // every feature test from running. Skip the FTS index on non-pgsql
        // drivers — the cheap `source` btree is still useful and is
        // portable, so we keep that one.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE INDEX IF NOT EXISTS documents_fts_idx
                ON documents
                USING GIN (to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(summary,'')))
            ");
        }

        // Portable single-column btree — safe on every driver.
        DB::statement("
            CREATE INDEX IF NOT EXISTS documents_source_idx
            ON documents (source)
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS documents_fts_idx');
        }
        DB::statement('DROP INDEX IF EXISTS documents_source_idx');
    }
};
