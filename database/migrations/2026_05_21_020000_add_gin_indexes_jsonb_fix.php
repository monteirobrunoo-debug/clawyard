<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-05-21 fix: a migration anterior (010000) tentou criar índice GIN
 * em `tenders.raw_metadata` mas a coluna é `json` (não `jsonb`) — não
 * tem operator class GIN por defeito. A statement falhou DENTRO da
 * transaction da migration, abortando-a e revertendo os 4 índices em
 * suppliers que tinham passado.
 *
 * Esta migration:
 *   1. Salta tenders.raw_metadata (deixaremos como está — não é hot path).
 *   2. Cria os 4 GIN em suppliers fora de transaction (DDL em postgres
 *      é auto-commit cada statement, mas Laravel envolve a closure
 *      do up() em transaction se runsInTransactions=true; viramos false).
 */
return new class extends Migration {
    /**
     * Disable automatic transaction wrap — DDL falhas não devem
     * envenenar todo o batch.
     */
    public bool $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        $statements = [
            'CREATE INDEX IF NOT EXISTS idx_suppliers_categories_gin
                ON suppliers USING gin (categories)',

            'CREATE INDEX IF NOT EXISTS idx_suppliers_subcategories_gin
                ON suppliers USING gin (subcategories)',

            'CREATE INDEX IF NOT EXISTS idx_suppliers_brands_gin
                ON suppliers USING gin (brands)',

            'CREATE INDEX IF NOT EXISTS idx_suppliers_web_intel_products_gin
                ON suppliers USING gin (web_intel_products)',
        ];

        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'GIN index fix failed: ' . $e->getMessage()
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
        ] as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
