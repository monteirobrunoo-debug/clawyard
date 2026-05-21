<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21: GIN indexes nas colunas JSONB mais filtradas.
 *
 * Hoje queries do tipo `categories @> '["13"]'` ou `web_intel_products
 * ?| ARRAY['radar', 'sonar']` fazem full scan (~80ms para 873 suppliers,
 * pior à medida que cresce). Com GIN: lookup ~3ms via inverted index.
 *
 * Postgres-only (a sintaxe USING gin não existe em SQLite). Em testes
 * que correm SQLite, o `if Postgres` salta.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite (testes) — skip GIN, full scan é OK para tabelas pequenas
            return;
        }

        // CONCURRENTLY = não bloqueia escritas durante a criação. Lento
        // mas seguro em produção (suppliers tem ~900 rows, tenders ~800).
        // Idempotent — IF NOT EXISTS evita erro em re-run.
        $statements = [
            // Filtros mais quentes — matchLocal usa categories + brands
            'CREATE INDEX IF NOT EXISTS idx_suppliers_categories_gin
                ON suppliers USING gin (categories)',

            'CREATE INDEX IF NOT EXISTS idx_suppliers_subcategories_gin
                ON suppliers USING gin (subcategories)',

            'CREATE INDEX IF NOT EXISTS idx_suppliers_brands_gin
                ON suppliers USING gin (brands)',

            'CREATE INDEX IF NOT EXISTS idx_suppliers_web_intel_products_gin
                ON suppliers USING gin (web_intel_products)',

            // tenders: raw_metadata busca-se por chaves específicas
            // (ex.: WHERE raw_metadata->>\'nipc\' = ...). gin_trgm_ops
            // existe mas requer extensão; basic gin chega para containment.
            'CREATE INDEX IF NOT EXISTS idx_tenders_raw_metadata_gin
                ON tenders USING gin (raw_metadata)',
        ];

        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                // Index já existe ou coluna mudou — log e continua. Não
                // queremos bloquear deploy por uma JSONB que mudou format.
                \Illuminate\Support\Facades\Log::warning(
                    'GIN index create failed (continuing): ' . $e->getMessage()
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        foreach ([
            'idx_suppliers_categories_gin',
            'idx_suppliers_subcategories_gin',
            'idx_suppliers_brands_gin',
            'idx_suppliers_web_intel_products_gin',
            'idx_tenders_raw_metadata_gin',
        ] as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
