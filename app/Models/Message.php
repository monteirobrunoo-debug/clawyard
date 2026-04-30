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

    /**
     * Side-effect hook: when an assistant message lands in the DB,
     * scan its content for supplier names + email addresses and upsert
     * them into the persistent supplier directory. This is how the
     * `suppliers` table grows from agent extractions over time.
     *
     * Only runs for assistant role + non-empty content + at least one
     * email mention. Wrapped in try/catch so a parse hiccup never
     * breaks the chat path (the user's response must arrive even if
     * we can't extract suppliers).
     *
     * Lives here (not in an Observer file) so the rule is co-located
     * with the model — easier to audit "what side effects does saving
     * a message trigger?".
     */
    protected static function booted(): void
    {
        static::created(function (Message $message) {
            if ($message->role !== 'assistant') return;

            $content = (string) $message->content;
            if ($content === '' || !str_contains($content, '@')) return;

            try {
                app(\App\Services\SupplierAutoExtractor::class)
                    ->extractFrom($content, [
                        'agent'           => (string) $message->agent,
                        'message_id'      => $message->id,
                        'conversation_id' => $message->conversation_id,
                    ]);
            } catch (\Throwable $e) {
                // Logged inside the extractor — swallow here so the
                // chat path never sees the failure.
            }
        });
    }
}
