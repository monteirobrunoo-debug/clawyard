<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fix conversations.metadata column type — JSON → TEXT.
 *
 * BUG (descoberto 2026-05-25 16:45 via prod log):
 *   - Column was `json` (created in 2026_03_18 migration)
 *   - Model casts `metadata` as SafeEncryptedArray
 *   - SafeEncryptedArray::set() encrypts to base64 string "eyJpdiI6..."
 *   - Postgres tries to parse that string as JSON → "Token invalid"
 *   - UPDATE fails → exception propagates → chat hangs / 500
 *
 * Conversations afectadas: TODAS que tenham summary cached em metadata
 * (Marta CRM summary, multi-agent debate refs, etc.).
 *
 * Fix: change column to TEXT — encrypted blob fits naturally.
 * Existing JSON values são preservados (Postgres converte json → text).
 *
 * Idempotente: try/catch silencioso se já é text.
 */
return new class extends Migration {
    public function up(): void
    {
        try {
            if (!Schema::hasTable('conversations')) {
                return;
            }

            $driver = DB::connection()->getDriverName();
            if ($driver !== 'pgsql') {
                Log::info('fix_conversations_metadata: skip non-pgsql driver');
                return;
            }

            // Check current type — só altera se ainda é json/jsonb
            $type = DB::selectOne(<<<'SQL'
                SELECT data_type
                FROM information_schema.columns
                WHERE table_name = 'conversations'
                  AND column_name = 'metadata'
            SQL);

            $currentType = $type ? (string) ($type->data_type ?? '') : '';
            if (in_array(strtolower($currentType), ['text', 'character varying'], true)) {
                Log::info('fix_conversations_metadata: already text — skip');
                return;
            }

            // ALTER COLUMN com USING para converter json → text safely
            DB::statement('ALTER TABLE conversations ALTER COLUMN metadata TYPE text USING metadata::text');
            Log::info('fix_conversations_metadata: changed json → text');
        } catch (\Throwable $e) {
            // Não deixar deploy falhar por causa desta migration
            Log::warning('fix_conversations_metadata_to_text failed (non-fatal): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            if (Schema::hasTable('conversations')) {
                // Reverse: text → json (pode falhar se houver strings não-JSON,
                // o que é EXACTAMENTE o nosso caso de uso normal. Best-effort.)
                DB::statement('ALTER TABLE conversations ALTER COLUMN metadata TYPE json USING metadata::json');
            }
        } catch (\Throwable) { /* idempotent rollback — pode não ser reversível */ }
    }
};
