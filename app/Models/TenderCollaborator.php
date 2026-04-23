<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A person who appears in the `Colaborador` column of an imported tender
 * sheet. Decoupled from User so we can track assignees who don't (yet)
 * have an account — e.g. "Sala Procurement" or a new hire appearing in
 * the Excel before IT provisions them.
 *
 * Identity rule (2026-04 simplification): email IS the identity. When a
 * User exists with the same email as the collaborator, this model auto-
 * links them via user_id — the manager doesn't have to pick anyone from
 * a dropdown. Set the email, get the login. The dropdown UI was confusing
 * ("já insiro o email, porque tenho de ligar outra vez?") and has been
 * retired.
 */
class TenderCollaborator extends Model
{
    protected $fillable = [
        'name',
        'normalized_name',
        'user_id',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Auto-link to a User whenever the email changes. Keeps user_id in
     * sync with email without requiring the manager to do anything on
     * the edit form — they put the email in, save, and if a matching
     * User exists the link is set silently.
     *
     * Clearing the email clears user_id too (the dropdown concept is
     * gone — email drives the link).
     */
    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if (!$c->isDirty('email')) return;

            $email = trim((string) $c->email);
            if ($email === '') {
                $c->user_id = null;
                return;
            }

            $user = User::where('email', $email)->first();
            $c->user_id = $user?->id;
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class, 'assigned_collaborator_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    // ── Name matching ─────────────────────────────────────────────────────
    /**
     * Normalise a name for deterministic lookup.
     *
     * Rules:
     *   • lowercase
     *   • strip accents (á→a, ç→c, …)
     *   • collapse internal whitespace
     *   • trim ends
     *
     * "Zé Inácio " → "ze inacio"
     * "ANA  CARLA" → "ana carla"
     */
    public static function normalize(?string $name): string
    {
        if ($name === null) return '';
        $ascii = Str::ascii(trim($name));
        $lower = mb_strtolower($ascii);
        return preg_replace('/\s+/', ' ', $lower);
    }

    /**
     * Idempotent upsert by normalised name. Used by TenderImportService to
     * resolve the `Colaborador` cell without creating duplicates across
     * re-imports of the same file.
     *
     * If the name is blank or the placeholder "-", returns null so the
     * tender row is kept unassigned.
     */
    public static function findOrCreateByName(?string $name): ?self
    {
        $norm = self::normalize($name);
        if ($norm === '' || $norm === '-') {
            return null;
        }
        return self::firstOrCreate(
            ['normalized_name' => $norm],
            ['name' => trim((string) $name), 'is_active' => true]
        );
    }

    /**
     * Effective email for digest routing: explicit field wins, else linked
     * user's email, else null (caller decides whether to skip the send).
     */
    public function getDigestEmailAttribute(): ?string
    {
        return $this->email ?: $this->user?->email;
    }
}
