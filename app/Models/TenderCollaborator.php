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
        'allowed_sources',
        'allowed_statuses',
    ];

    protected function casts(): array
    {
        return [
            'is_active'        => 'boolean',
            // NULL  → no filter (sees every source)
            // []    → explicit "blocked from all sources"
            // array → whitelist
            'allowed_sources'  => 'array',
            // Same semantics as allowed_sources but applied to
            // Tender::status (pending / em_tratamento / submetido / …).
            'allowed_statuses' => 'array',
        ];
    }

    /**
     * Can this collaborator see tenders from `$source`?
     *
     * Rules:
     *   - NULL (`allowed_sources` not set) → yes, all sources allowed.
     *     This is the legacy default so existing users don't lose
     *     visibility when the column is added.
     *   - [] (empty array) → no. Admin explicitly blocked everything.
     *   - Non-empty array → only sources in the list.
     */
    public function canSeeSource(string $source): bool
    {
        $allowed = $this->allowed_sources;
        if ($allowed === null) return true;              // legacy / no filter
        return in_array($source, (array) $allowed, true);
    }

    /**
     * Same shape as canSeeSource, applied to Tender::status. NULL means
     * "no filter, sees every status" (legacy default), [] means
     * "blocked from every status", array is a whitelist.
     */
    public function canSeeStatus(string $status): bool
    {
        $allowed = $this->allowed_statuses;
        if ($allowed === null) return true;
        return in_array($status, (array) $allowed, true);
    }

    /**
     * Two-stage saving hook:
     *
     *   1) ESTABLISH-ONLY auto-link from email → user_id.
     *      Setting an email that matches a User auto-fills user_id so
     *      the manager doesn't have to pick from a dropdown. Crucially
     *      we no longer DESTROY user_id when:
     *        • email is cleared (legacy behaviour silently un-linked the
     *          User; the audit command had to bypass via query builder
     *          to repair phantom rows — that's the footgun we close here)
     *        • email is set to a value that matches no User (legacy
     *          behaviour reset user_id to NULL; now we leave it intact
     *          so admins can use distribution-list emails / aliases
     *          without losing the link).
     *      Net rule: this hook only EVER turns user_id from NULL → id,
     *      or from id_a → id_b when the new email belongs to a different
     *      registered User. It never silently nulls a deliberate link.
     *
     *   2) ANTI-CORRUPTION INVARIANT.
     *      After step 1 (and any direct user_id mutation by the caller),
     *      reject the save if user_id and email contradict each other —
     *      i.e. email belongs to a registered User whose id ≠ user_id.
     *      That contradiction is the exact data shape that caused
     *      catarina.sequeira to inherit monica.pereira's dashboard
     *      (2026-04-24): a row with email=catarina but user_id=monica.
     *      The runtime scopeForUser was hardened against it, but this
     *      invariant prevents it from being written in the first place.
     *      Bypassable only via DB::table()->update() (which the audit
     *      command uses on purpose).
     */
    protected static function booted(): void
    {
        static::saving(function (self $c) {
            // Stage 1 — auto-link email → user_id, but only when user_id
            // is currently NULL. We never silently OVERWRITE an existing
            // user_id link from an email change — that's the legacy
            // behaviour that let "edit Mónica's email to Catarina's"
            // silently retarget the row to Catarina. Now that motion
            // requires either:
            //   • clearing user_id explicitly first, then saving (hook
            //     auto-fills the new user_id from email), or
            //   • running `tenders:audit-collaborator-emails --reattach`
            //     (deliberate, logged, human-confirmed).
            if ($c->isDirty('email') && empty($c->user_id)) {
                $email = trim((string) $c->email);
                if ($email !== '') {
                    $user = User::where('email', $email)->first();
                    if ($user) {
                        $c->user_id = $user->id;
                    }
                }
                // Empty email or unmatched email: leave user_id untouched.
            }

            // Stage 2 — invariant: user_id ↔ email cannot belong to
            // different Users. Aliases (email points to no User) are
            // fine — distribution lists, shared inboxes, forwarders.
            // Only direct contradictions (email belongs to user A,
            // user_id points to user B) are rejected. This is the
            // exact corruption shape that caused the catarina-saw-
            // mónica leak; throwing here means it can never be saved
            // through the model again.
            if ($c->user_id && $c->email) {
                $emailOwner = User::where('email', trim((string) $c->email))->first();
                if ($emailOwner && (int) $emailOwner->id !== (int) $c->user_id) {
                    throw new \DomainException(sprintf(
                        'TenderCollaborator: refusing to save inconsistent link — '
                        . 'user_id=%d but email=%s belongs to user #%d. '
                        . 'Clear one of the fields or align them.',
                        $c->user_id, $c->email, $emailOwner->id
                    ));
                }
            }
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
