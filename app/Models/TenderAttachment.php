<?php

namespace App\Models;

use App\Casts\SafeEncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderAttachment extends Model
{
    protected $fillable = [
        'tender_id',
        'original_name', 'disk_path', 'mime_type', 'size_bytes', 'file_hash',
        'extracted_text', 'extracted_chars', 'extraction_status', 'extraction_error',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size_bytes'      => 'integer',
        'extracted_chars' => 'integer',
        // AES-encrypted at rest via APP_KEY. Same pattern as Message.content.
        // Existing plaintext rows return SafeEncryptedString::PLACEHOLDER on
        // decrypt failure until the backfill migration re-encrypts them.
        'extracted_text'  => SafeEncryptedString::class,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    /** 2026-05-18: ficheiro não-PDF guardado sem extracção (xlsx, eml, jpg, …). */
    public const STATUS_SKIPPED = 'skipped';

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Returns the text in a form safe to inject into a prompt — capped, trimmed. */
    public function promptSnippet(int $maxChars = 6000): string
    {
        $text = (string) $this->extracted_text;
        if (mb_strlen($text) <= $maxChars) return $text;
        return mb_substr($text, 0, $maxChars) . "\n\n…[truncado a {$maxChars} caracteres]";
    }
}
