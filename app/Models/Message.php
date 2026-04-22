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
     * Cast with Laravel's `encrypted` cast so at-rest DB dumps are ciphertext
     * only — decrypted transparently on access. APP_KEY must be kept stable;
     * rotating it requires re-encrypting existing rows via a migration.
     *
     * Note: the `encrypted` cast breaks direct SQL `LIKE` / full-text search
     * on content — if you need those, build a separate (sanitised) search
     * index rather than regressing to plaintext.
     */
    protected $casts = [
        'content'  => 'encrypted',
        'metadata' => 'encrypted:array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
