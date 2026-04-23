<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audit record of one Excel upload. TenderImportService writes exactly one
 * of these per file-upload, even when the file is a re-import (in which
 * case rows_created/updated tell the story).
 *
 * `file_hash` lets us short-circuit duplicate uploads: if a super-user
 * accidentally re-uploads the same file, we can decide (in the UI) whether
 * to force a re-import or display a "nothing changed" notice.
 */
class TenderImport extends Model
{
    protected $fillable = [
        'source',
        'file_name',
        'file_hash',
        'sheet_name',
        'user_id',
        'rows_parsed',
        'rows_created',
        'rows_updated',
        'rows_skipped',
        'errors',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'errors'       => 'array',
            'rows_parsed'  => 'integer',
            'rows_created' => 'integer',
            'rows_updated' => 'integer',
            'rows_skipped' => 'integer',
            'duration_ms'  => 'integer',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class, 'last_import_id');
    }

    // ── Convenience ───────────────────────────────────────────────────────
    public function getSummaryAttribute(): string
    {
        return sprintf(
            '%s: %d parsed → %d created, %d updated, %d skipped (%dms)',
            strtoupper($this->source),
            $this->rows_parsed,
            $this->rows_created,
            $this->rows_updated,
            $this->rows_skipped,
            $this->duration_ms ?? 0
        );
    }
}
