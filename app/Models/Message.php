<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'role', 'agent', 'content', 'metadata',
    ];

    /**
     * SECURITY: content is the full user prompt + assistant reply, potentially
     * containing PII (emails, phone numbers, SAP credentials, client data).
     * Cast so at-rest DB dumps are ciphertext only — decrypted transparently
     * on access. APP_KEY must be kept stable; rotating it without re-encrypting
     * existing rows leaves them unreadable.
     *
     * NOTE: we use SafeEncryptedString / SafeEncryptedArray instead of the
     * built-in `encrypted` / `encrypted:array` casts because a past APP_KEY
     * rotation left ~1003 of ~1019 rows undecryptable under the current key
     * (discovered 2026-04-22). The safe casts swallow DecryptException on
     * reads and return a placeholder so NvidiaController::history() and any
     * JSON-serialising endpoint keeps working instead of returning HTTP 500
     * on every legacy conversation. Writes still use the current APP_KEY, so
     * any NEW row is fully encrypted and decryptable. Swap back to
     * 'encrypted' / 'encrypted:array' once the legacy rows are cleaned up or
     * re-encrypted with the current key.
     *
     * Note: the `encrypted` cast family breaks direct SQL `LIKE` / full-text
     * search on content — if you need those, build a separate (sanitised)
     * search index rather than regressing to plaintext.
     */
    protected $casts = [
        'content'  => \App\Casts\SafeEncryptedString::class,
        'metadata' => \App\Casts\SafeEncryptedArray::class,
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
