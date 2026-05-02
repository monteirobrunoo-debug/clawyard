<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: encrypt existing extracted_text rows that were stored as
 * plaintext before the SafeEncryptedString cast was added to
 * TenderAttachment (2026-05-02 security hardening).
 *
 * Why raw DB instead of the Eloquent model:
 *   The model now has extracted_text cast to SafeEncryptedString, which
 *   tries to Crypt::decryptString() on read. A plaintext row would
 *   fail that and return a placeholder — so we must bypass the cast
 *   and read the raw ciphertext-or-plaintext value directly.
 *
 * Detection heuristic: a Laravel-encrypted string always starts with
 * "eyJ" (base64 of the JSON envelope {"iv":…,"value":…,"mac":…}).
 * Any row whose extracted_text does NOT start with "eyJ" is treated
 * as plaintext and re-encrypted in-place.
 *
 * Safety:
 *   • Processes in batches of 100 to avoid a single long transaction.
 *   • Skips NULL / empty rows.
 *   • Logs a summary at the end.
 *   • down() is a no-op — decrypting back to plaintext on rollback
 *     would silently expose sensitive data; better to leave rows
 *     encrypted if the migration is rolled back for other reasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        $total   = 0;
        $skipped = 0;
        $errors  = 0;

        DB::table('tender_attachments')
            ->whereNotNull('extracted_text')
            ->where('extracted_text', '!=', '')
            ->orderBy('id')
            ->chunk(100, function ($rows) use (&$total, &$skipped, &$errors) {
                foreach ($rows as $row) {
                    $raw = (string) $row->extracted_text;

                    // Already encrypted — starts with base64 JSON envelope.
                    if (str_starts_with($raw, 'eyJ')) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $encrypted = Crypt::encryptString($raw);
                        DB::table('tender_attachments')
                            ->where('id', $row->id)
                            ->update(['extracted_text' => $encrypted]);
                        $total++;
                    } catch (\Throwable $e) {
                        Log::error('encrypt_tender_attachment_extracted_text: failed row', [
                            'id'    => $row->id,
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                }
            });

        Log::info('encrypt_tender_attachment_extracted_text: complete', [
            'encrypted' => $total,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ]);
    }

    /** No-op — we never silently decrypt sensitive data on rollback. */
    public function down(): void {}
};
