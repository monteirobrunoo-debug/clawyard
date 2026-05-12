<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * #8 — Global search across conversations.
     *
     * messages.content is encrypted at rest (Laravel Crypt → AES-256-CBC),
     * which makes Postgres full-text search impossible on the ciphertext.
     *
     * Strategy: maintain a SEPARATE, lower-cardinality "search bag" on
     * the conversation row — extracted nouns/entities from the message
     * stream. NOT PII-sensitive content; just topical terms so admins
     * can find "all conversations about SAP integration" or "tender
     * F-16 spares 2026".
     *
     * Populated by an async listener on Message::created that pulls
     * keywords via TechnicalBookSearch's existing extractor, dedupes,
     * and updates the conversation. Capped at 1000 chars so it fits a
     * standard btree index when needed.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('search_keywords')->nullable()->after('title');
        });

        // Postgres trigram index on search_keywords for ILIKE queries.
        // gin_trgm_ops requires the pg_trgm extension which Forge enables
        // by default; if missing, the CREATE INDEX silently no-ops via
        // the try/catch.
        try {
            \DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            \DB::statement('CREATE INDEX IF NOT EXISTS conversations_search_keywords_trgm ON conversations USING gin (search_keywords gin_trgm_ops)');
        } catch (\Throwable $e) {
            // Non-fatal — falls back to ILIKE without trigram acceleration.
        }
    }

    public function down(): void
    {
        try {
            \DB::statement('DROP INDEX IF EXISTS conversations_search_keywords_trgm');
        } catch (\Throwable $e) {}
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('search_keywords');
        });
    }
};
