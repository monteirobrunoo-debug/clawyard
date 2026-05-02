<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tender PDF attachments. Lets the operator drop the source RFP/
 * RFQ documents directly on the tender page, parse the text, then
 * use that as context for:
 *   • Marta CRM (creates the SAP opportunity from the parsed body)
 *   • Supplier suggester (categories inferred from PDF content)
 *   • Daniel Email (drafts grounded in the actual specs)
 *
 * Storage: file on local disk under storage/app/private/tender-attachments/{tender_id}/{slug}.
 * The DB row stores metadata + extracted_text (TEXT, up to ~50KB
 * after extraction; longer PDFs get truncated). Original binary
 * stays on disk for re-extraction or download.
 *
 * Idempotency: file_hash is unique-per-tender so re-uploading the
 * same PDF doesn't create a duplicate row (controller updates last_seen_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_attachments', function (Blueprint $t) {
            $t->id();

            $t->foreignId('tender_id')
                ->constrained('tenders')
                ->cascadeOnDelete();

            $t->string('original_name', 255);
            $t->string('disk_path', 500);            // relative to the configured disk
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->string('file_hash', 64)->index();    // sha256 hex

            // Extracted text — capped at 50K chars at the service layer
            // so we don't blow Postgres TEXT pages or LLM context windows.
            // NULL = not yet parsed (rare; usually filled within a few
            // seconds of upload via the controller).
            $t->text('extracted_text')->nullable();
            $t->unsignedInteger('extracted_chars')->default(0);
            $t->string('extraction_status', 16)->default('pending');  // pending|ok|failed
            $t->string('extraction_error', 500)->nullable();

            $t->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $t->timestamps();

            $t->unique(['tender_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_attachments');
    }
};
