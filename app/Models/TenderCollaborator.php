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
        'aliases',
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
            // List of additional normalised name variants that should
            // resolve to this row when the import sees them. See
            // findOrCreateByName + the merge endpoint.
            'aliases'          => 'array',
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
     * Resolution order (changed 2026-04-28 to prevent the
     * "Zé Inácio vs Jose Inacio = 2 rows for one person" bug):
     *
     *   1. Exact match on normalized_name (active rows).
     *   2. Alias match (active rows).
     *   3. **Fuzzy match — auto-link to the closest candidate** instead
     *      of creating a duplicate. The previous behaviour was "log a
     *      warning + create anyway" which kept producing parallel rows
     *      for every name variant ("Mónica" / "Monica Pereira",
     *      "Catarina" / "Catarina Sequeira", "Zé Inácio" / "Jose
     *      Inacio"). The new rule: if findCloseMatches returns ≥1
     *      candidate, use the BEST one and write the incoming
     *      variant into its `aliases` array so future imports skip
     *      the fuzzy step entirely. No new row is created.
     *   4. Genuinely new name (no fuzzy candidate) — create the row,
     *      one source of truth from the start.
     *
     * Auto-linking on fuzzy is logged at INFO level so an admin can
     * audit ("did I really mean to fuse 'Zé' into 'Jose Inácio'?").
     * If the auto-link is wrong, the admin can split via the merge UI
     * (un-merge isn't built — they'd manually clear the alias and
     * re-create the second row).
     *
     * Pass `$strict = true` to disable creation entirely — useful for
     * a "manual triage queue" workflow where unmatched names produce
     * unassigned tenders that the admin reviews. Returns null when
     * no match found in strict mode.
     */
    public static function findOrCreateByName(?string $name, bool $strict = false): ?self
    {
        $norm = self::normalize($name);
        if ($norm === '' || $norm === '-') {
            return null;
        }

        // 2026-05-21 GUARD: recusar valores com ≤2 chars. Pedido directo:
        // "muitos processos acingov aparecem erradamente atribuidos a
        // Monica Pereira". Causa: Excel CONCURSOS_VICENCIO usa iniciais
        // 2-letras (MM, ER, JI, CS, GG, SO) nas células Colaborador.
        // A alias "mm" da row da Monica matchava qualquer "MM" → 133
        // tenders atribuídos por engano. "JI", "ER" idem.
        //
        // Política: aliases <3 chars são demasiado ambíguos para
        // auto-link. Refuse e log para o operador rever manualmente
        // (ver /tenders/collaborators). O bulk-assign UI continua a
        // permitir aliases de qualquer tamanho — só o auto-link no
        // import é que fica conservador.
        if (mb_strlen($norm) <= 2) {
            \Illuminate\Support\Facades\Log::info(
                'TenderCollaborator: incoming name ≤2 chars, recusado auto-link',
                ['incoming' => $name, 'normalized' => $norm,
                 'hint' => 'Use full name (ex.: "Monica Pereira") em vez de iniciais.'],
            );
            return null;
        }

        // 1. Exact match on normalized_name AMONG ACTIVE rows. Filtering
        //    by active is critical: if an admin previously merged
        //    "Monica" → "Monica Pereira" (the absorbed row is now
        //    inactive but still has normalized_name='monica'), a fresh
        //    import of "Monica" must NOT resurrect that ghost row — it
        //    must hit the alias path on the active survivor.
        if ($exact = self::where('normalized_name', $norm)->where('is_active', true)->first()) {
            // 2026-05-18: se a row já existir mas sem user_id, tentar
            // back-fill via match com User::name (caso esse User tenha
            // sido criado depois do collaborator). Pedido directo:
            // "quando o supervisor importa, os nomes dos colaboradores
            //  ficam automaticamente atribuídos aos users".
            self::backfillUserIdByName($exact, $name);
            return $exact;
        }

        // 2. Alias match — caller may have curated a row to also accept
        //    this short / variant form ("monica" → "Monica Pereira"
        //    row, populated by the merge endpoint or an admin edit).
        //    Active-only for the same reason.
        if ($byAlias = self::findByAlias($norm)) {
            self::backfillUserIdByName($byAlias, $name);
            return $byAlias;
        }

        // 3. Fuzzy match — auto-link to closest candidate.
        //    findCloseMatches returns rows whose normalized_name is
        //    within Levenshtein 3 OR is a prefix relation OR shares
        //    the first token. Picks the one with the lowest distance;
        //    ties broken by alphabetical normalized_name so the
        //    behaviour is deterministic across re-imports.
        $close = self::findCloseMatches($norm, threshold: 3);
        if ($close->isNotEmpty()) {
            $best = $close->sortBy(fn($c) => [
                levenshtein($c->normalized_name, $norm),
                $c->normalized_name,
            ])->first();

            // Append the incoming variant to the survivor's aliases so
            // the next import of this same variant hits step 2 (cheap
            // alias lookup) instead of re-running fuzzy.
            $aliases = (array) ($best->aliases ?? []);
            if (!in_array($norm, $aliases, true)) {
                $aliases[] = $norm;
                $best->aliases = $aliases;
                $best->save();
            }

            \Illuminate\Support\Facades\Log::info(
                'TenderCollaborator: auto-linked import variant to existing row',
                [
                    'incoming'    => $name,
                    'normalized'  => $norm,
                    'linked_to'   => ['id' => $best->id, 'name' => $best->name, 'normalized' => $best->normalized_name],
                    'hint'        => 'If this auto-link is wrong, edit the row and remove the alias.',
                ],
            );

            self::backfillUserIdByName($best, $name);
            return $best;
        }

        // 4. Genuinely new name. Strict mode refuses to create here so
        //    a triage queue picks it up; default mode creates the row
        //    so imports keep flowing.
        if ($strict) {
            \Illuminate\Support\Facades\Log::warning(
                'TenderCollaborator: strict mode — name has no match, NOT creating',
                ['incoming' => $name, 'normalized' => $norm],
            );
            return null;
        }

        // 2026-05-18: ao criar de raiz, já tentar resolver o User por nome.
        // O atributo user_id é null se nenhum User bater certo — o admin
        // pode atribuir depois manualmente em /tenders/collaborators.
        $userId = self::matchUserIdByName($name);

        return self::create([
            'name'            => trim((string) $name),
            'normalized_name' => $norm,
            'user_id'         => $userId,
            'is_active'       => true,
        ]);
    }

    /**
     * Procura um User cujo `name` corresponde (normalizado) ao nome
     * dado, e devolve o id. Caso contrário null.
     *
     * 2026-05-18: pedido directo "quando o supervisor importa, os nomes
     * dos colaboradores com os concursos atribuídos ficam automaticamente
     * atribuídos aos users". Resolução em 3 níveis:
     *   1) Exact match (User.name normalizado == nome importado normalizado)
     *   2) Primeiro nome match (single token) — útil para "Mónica" → "Mónica Pereira"
     *   3) Last name match — útil para "Pereira" → "Mónica Pereira"
     * Só faz link se houver UM resultado único — múltiplos matches deixam
     * em null para evitar enganos (admin escolhe depois).
     */
    public static function matchUserIdByName(?string $name): ?int
    {
        // 2026-05-18 fix: strip sufixos de organização e role-tokens
        // antes de tokenizar para evitar matches falsos onde TODOS os
        // colaboradores têm "(PartYard)" no nome e o último token bate
        // a um único user com o mesmo sufixo.
        $stripped = self::stripOrgSuffix($name);
        $norm = self::normalize($stripped);
        if ($norm === '' || $norm === '-') return null;

        // Carrega todos os users activos uma vez — não há muitos.
        $users = \App\Models\User::where('is_active', true)
            ->select(['id', 'name'])
            ->get();
        if ($users->isEmpty()) return null;

        $usersByNorm = $users->map(function ($u) {
            $clean = self::normalize(self::stripOrgSuffix($u->name));
            $parts = array_values(array_filter(preg_split('/\s+/', $clean) ?: []));
            return [
                'id'    => $u->id,
                'norm'  => $clean,
                'parts' => $parts,
            ];
        });

        // Nível 1: exact full match (após strip de sufixos org)
        $exact = $usersByNorm->where('norm', $norm);
        if ($exact->count() === 1) return $exact->first()['id'];

        // Nível 2 e 3: token match — usar APENAS tokens que sejam
        // nomes reais (≥3 chars, alfa-only). Rejeita tokens genéricos
        // como "logistica", "financeiro", "operacoes", role-words.
        $genericTokens = ['logistica', 'logistica', 'financeiro', 'operacoes', 'operacao',
                          'administracao', 'admin', 'comercial', 'tecnico', 'gestao',
                          'gerente', 'director', 'manager', 'staff', 'rh', 'sgq', 'qhse',
                          'compras', 'vendas', 'partyard', 'hp', 'group', 'team', 'bu'];
        $isRealNameToken = fn(string $t) => mb_strlen($t) >= 3
            && preg_match('/^[a-z]+$/u', $t)
            && !in_array($t, $genericTokens, true);

        $incomingParts = array_values(array_filter(
            preg_split('/\s+/', $norm) ?: [],
            $isRealNameToken
        ));
        if (empty($incomingParts)) return null;

        $first = $incomingParts[0];
        $last  = end($incomingParts);

        // Primeiro nome — match único entre os users (também com tokens reais)
        $byFirst = $usersByNorm->filter(function ($u) use ($first, $isRealNameToken) {
            $realParts = array_values(array_filter($u['parts'], $isRealNameToken));
            return !empty($realParts) && $realParts[0] === $first;
        });
        if ($byFirst->count() === 1) return $byFirst->first()['id'];

        // Último apelido — match único entre os tokens reais
        if ($first !== $last) {
            $byLast = $usersByNorm->filter(function ($u) use ($last, $isRealNameToken) {
                $realParts = array_values(array_filter($u['parts'], $isRealNameToken));
                if (empty($realParts)) return false;
                return end($realParts) === $last;
            });
            if ($byLast->count() === 1) return $byLast->first()['id'];
        }

        // Múltiplos matches ambíguos OU zero matches: deixa null
        return null;
    }

    /**
     * Remove sufixos de organização entre parêntesis ou após delimitadores
     * para que "GOMES Luis (PartYard)" → "GOMES Luis", evitando matches
     * falsos onde o sufixo é partilhado por toda a equipa.
     */
    private static function stripOrgSuffix(?string $name): string
    {
        $s = (string) $name;
        // Remove conteúdo dentro de () [] {}
        $s = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/u', '', $s) ?? $s;
        // Remove sufixos comuns após " - " ou " | " ou " / "
        $s = preg_replace('/\s+[\-\|\/]\s+(partyard|hp[-\s]?group|h&p|hpg|defense|marine|industrial|military)\b.*$/iu', '', $s) ?? $s;
        return trim($s);
    }

    /**
     * Helper interno para o findOrCreateByName: se um TenderCollaborator
     * já existe sem user_id, tenta resolver via nome e gravar. Não
     * sobrescreve user_id se já estiver definido.
     */
    private static function backfillUserIdByName(self $collab, ?string $incomingName): void
    {
        if (!empty($collab->user_id)) return;
        // Tenta por nome incoming primeiro (mais específico), depois
        // pelo nome do collab actual como fallback.
        $userId = self::matchUserIdByName($incomingName) ?? self::matchUserIdByName($collab->name);
        if ($userId === null) return;
        try {
            $collab->user_id = $userId;
            $collab->save();
            \Illuminate\Support\Facades\Log::info(
                'TenderCollaborator: back-filled user_id by name match on import',
                ['collab_id' => $collab->id, 'collab_name' => $collab->name, 'user_id' => $userId],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'TenderCollaborator: user_id backfill failed',
                ['collab_id' => $collab->id, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * Look up a collaborator whose `aliases` JSON array contains the
     * given normalised name. Drivers handled:
     *
     *   • Postgres / MySQL — native `whereJsonContains`.
     *   • SQLite (test env)— LIKE on the JSON-encoded blob, which is
     *     good enough because `aliases` only stores normalised
     *     lowercase strings (no quotes inside the value to trip us up).
     */
    public static function findByAlias(string $normalized): ?self
    {
        if ($normalized === '') return null;
        $q = self::query()->where('is_active', true);
        $driver = (new self)->getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return $q->where('aliases', 'like', '%"' . $normalized . '"%')->first();
        }
        return $q->whereJsonContains('aliases', $normalized)->first();
    }

    /**
     * Return active rows whose normalised name is within `threshold`
     * Levenshtein distance of `$normalized` (and is NOT equal to it —
     * exact matches are handled before this is called).
     *
     * Pulled in PHP because the table is small (<200 rows in any
     * realistic deployment) and SQL Levenshtein support is patchy
     * across drivers.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function findCloseMatches(string $normalized, int $threshold = 3): \Illuminate\Support\Collection
    {
        if ($normalized === '') return collect();

        $needleFirstToken = explode(' ', $normalized, 2)[0];

        return self::active()
            ->get(['id', 'name', 'normalized_name'])
            ->filter(function (self $c) use ($normalized, $needleFirstToken, $threshold) {
                if ($c->normalized_name === $normalized) return false;
                if ($c->normalized_name === '') return false;

                // Three signals — any one of them flags a candidate:
                //
                //   (a) Levenshtein distance ≤ threshold — typical typo
                //       case ("Monica" vs "Monoca").
                //   (b) Prefix relation — one is a prefix of the other.
                //       "monica" is a prefix of "monica pereira", which is
                //       almost always the same person in our data.
                //   (c) First-token equality — both start with the same
                //       word, e.g. "Monica" vs "Monica Pinto" both start
                //       with "monica". Catches the "Excel uses just first
                //       name" bug shape.
                //
                // (a) needs the length-difference guard so we don't run
                //     levenshtein on wildly different strings.
                $diff = abs(mb_strlen($c->normalized_name) - mb_strlen($normalized));
                if ($diff <= $threshold
                    && levenshtein($c->normalized_name, $normalized) <= $threshold) {
                    return true;
                }
                if ($diff > 0
                    && (str_starts_with($c->normalized_name, $normalized)
                        || str_starts_with($normalized, $c->normalized_name))) {
                    return true;
                }
                $cFirst = explode(' ', $c->normalized_name, 2)[0];
                if ($needleFirstToken !== '' && $cFirst === $needleFirstToken) {
                    return true;
                }
                return false;
            })
            ->values();
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
