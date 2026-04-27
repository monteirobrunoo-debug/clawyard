<?php

namespace App\Http\Controllers;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD for the TenderCollaborator roster.
 *
 * Why it exists: the import auto-creates collaborators from the Excel's
 * `Colaborador` column, but the user wants to be able to add/edit names
 * manually too — e.g. a new hire whose name hasn't appeared in a sheet
 * yet, or to fix a typo, or to attach an email + link to a User account.
 *
 * Routes (manager+ only):
 *   GET    /tenders/collaborators                      index/list + inline create form
 *   POST   /tenders/collaborators                      store new collaborator
 *   GET    /tenders/collaborators/{id}/edit            edit form
 *   PATCH  /tenders/collaborators/{id}                 update
 *   DELETE /tenders/collaborators/{id}                 soft-deactivate (is_active=false)
 *   POST   /tenders/collaborators/{id}/reactivate      flip is_active=true again
 *   DELETE /tenders/collaborators/{id}/force           hard delete — ONLY when tenders_count==0
 *
 * Deletion policy:
 *   - Soft (DELETE) is the default: flips `is_active` to false so the row
 *     disappears from assign dropdowns and digests but history is kept.
 *   - Hard (force) is only allowed for rows that were never assigned to a
 *     tender (tenders_count == 0). Typos, duplicates, people added by mistake.
 *     The tenders FK is `nullOnDelete`, so a hard delete on a row with
 *     history would silently unassign every tender — which is why we block
 *     that path at the controller level.
 *   - Reactivate is a simple flip back to is_active=true. No side effects.
 */
class TenderCollaboratorController extends Controller implements HasMiddleware
{
    /**
     * Laravel 11+ removed the `$this->middleware()` helper from the base
     * Controller. The replacement is the `HasMiddleware` contract, which
     * declares middleware statically (still per-controller scoped).
     *
     * We use a closure to defer to the `tenders.collaborators` Gate — every
     * action on this controller is manager+ only.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                if (!Auth::user()?->can('tenders.collaborators')) abort(403);
                return $next($request);
            }),
        ];
    }

    public function index(Request $request)
    {
        $collaborators = TenderCollaborator::query()
            ->withCount('tenders')
            ->with('user:id,name,email,role')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $linkableUsers = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('tenders.collaborators.index', [
            'collaborators' => $collaborators,
            'linkableUsers' => $linkableUsers,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $collab = new TenderCollaborator();
        $collab->fill($data);
        $collab->normalized_name = TenderCollaborator::normalize($data['name']);
        $collab->is_active       = $data['is_active'] ?? true;
        $collab->save();

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', "Colaborador \"{$collab->name}\" adicionado.");
    }

    public function edit(TenderCollaborator $collaborator)
    {
        $linkableUsers = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('tenders.collaborators.edit', [
            'collaborator'  => $collaborator,
            'linkableUsers' => $linkableUsers,
        ]);
    }

    public function update(Request $request, TenderCollaborator $collaborator)
    {
        $data = $this->validatePayload($request, $collaborator->id);

        $collaborator->fill($data);
        $collaborator->normalized_name = TenderCollaborator::normalize($data['name']);
        $collaborator->is_active       = (bool) ($data['is_active'] ?? false);
        $collaborator->save();

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', "Colaborador \"{$collaborator->name}\" actualizado.");
    }

    public function destroy(TenderCollaborator $collaborator)
    {
        // Soft-deactivate instead of DELETE to preserve tender history.
        $collaborator->is_active = false;
        $collaborator->save();

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', "Colaborador \"{$collaborator->name}\" desactivado (histórico preservado).");
    }

    /**
     * Flip is_active back to true — reverse of destroy().
     *
     * Why POST (not PATCH): it's a single-field state change that the UI
     * fires from a small inline button, mirrors the `destroy` verb-shape
     * (simple action URL, no payload), and avoids having to expose the full
     * update form just to toggle one boolean. The gate is the same as every
     * other action on this controller (manager+ via HasMiddleware).
     */
    public function reactivate(TenderCollaborator $collaborator)
    {
        if ($collaborator->is_active) {
            return back()->with('status', "\"{$collaborator->name}\" já está activo.");
        }

        $collaborator->is_active = true;
        $collaborator->save();

        Log::info('TenderCollaborator: reactivated', [
            'collaborator_id' => $collaborator->id,
            'name'            => $collaborator->name,
            'by_user_id'      => auth()->id(),
        ]);

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', "Colaborador \"{$collaborator->name}\" reactivado.");
    }

    /**
     * Fuse two collaborator rows that represent the same person.
     *
     * Real-world trigger: an Excel re-import wrote the import column
     * "Mónica" instead of "Mónica Pereira", creating row #20 alongside
     * the existing #4. 73 tenders bound to the new row, the user lost
     * her dashboard view. This endpoint moves the work to the original
     * row, registers "monica" as an alias on #4 so future imports
     * never re-create the duplicate, and deactivates #20.
     *
     * Order matters: `from` is the row to be absorbed (deactivated);
     * `into` is the survivor.
     *
     * Side effects (single transaction):
     *   1. Tenders with assigned_collaborator_id = from.id  →  into.id
     *   2. into.aliases gets `from.normalized_name` appended (idempotent)
     *   3. from.is_active = false (preserve history; admin can hard-
     *      delete via /force if there's no remaining attachments)
     *
     * The endpoint is reversible by hand (move tenders back, drop the
     * alias, re-activate from). Logged loudly so operators can audit.
     */
    public function merge(TenderCollaborator $from, TenderCollaborator $into)
    {
        if ($from->id === $into->id) {
            return back()->withErrors(['merge' => 'Não podes fundir uma row consigo mesma.']);
        }
        if (!$from->is_active && !$into->is_active) {
            return back()->withErrors(['merge' => 'Ambas as rows estão inactivas — reactiva uma primeiro.']);
        }

        $movedCount = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($from, $into, &$movedCount) {
            // 1) Move tenders.
            $movedCount = \App\Models\Tender::query()
                ->where('assigned_collaborator_id', $from->id)
                ->update(['assigned_collaborator_id' => $into->id]);

            // 2) Add the absorbed row's normalised name to the survivor's
            //    aliases. Idempotent — re-merging the same pair (after
            //    reactivating) doesn't duplicate.
            $aliases = (array) ($into->aliases ?? []);
            if ($from->normalized_name !== ''
                && !in_array($from->normalized_name, $aliases, true)) {
                $aliases[] = $from->normalized_name;
            }
            // Carry across the absorbed row's existing aliases too — if
            // the operator merges A→B then later C→B, B should know
            // every variant ever seen.
            foreach ((array) ($from->aliases ?? []) as $a) {
                if ($a !== '' && !in_array($a, $aliases, true)) $aliases[] = $a;
            }
            $into->aliases = $aliases;
            $into->save();

            // 3) Deactivate the absorbed row. Tenders are already moved;
            //    deactivation just hides it from the assign dropdown
            //    and the digest.
            $from->is_active = false;
            $from->save();
        });

        Log::info('TenderCollaborator: merged', [
            'from_id'        => $from->id,
            'from_name'      => $from->name,
            'into_id'        => $into->id,
            'into_name'      => $into->name,
            'tenders_moved'  => $movedCount,
            'aliases_added'  => $into->fresh()->aliases,
            'by_user_id'     => auth()->id(),
        ]);

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', sprintf(
                "Fundidos: \"%s\" → \"%s\" — %d tender(s) movido(s), \"%s\" desactivado, alias \"%s\" guardado.",
                $from->name, $into->name, $movedCount, $from->name, $from->normalized_name
            ));
    }

    /**
     * Toggle ONE source in the collaborator's allowed_sources whitelist.
     *
     * Semantics (mirrors TenderCollaborator::canSeeSource):
     *   - allowed_sources NULL → starts as "all allowed". First toggle
     *     materialises the whitelist as `Tender::SOURCES minus [source]`
     *     (the admin is EXCLUDING that source from an otherwise-full set).
     *     This matches user intent: "click to turn off NSPA for this user"
     *     should disable NSPA, not suddenly disable everything else too.
     *   - allowed_sources is an array:
     *       • source present → remove it
     *       • source absent  → add it
     *
     * Returns JSON: { ok, allowed_sources, has_source, mode } where `mode`
     * is 'unrestricted' when allowed_sources is NULL (only possible if the
     * admin added back every source → we collapse back to NULL instead of
     * carrying a 9-element whitelist that means the same thing).
     */
    public function toggleSource(TenderCollaborator $collaborator, string $source)
    {
        $source = strtolower(trim($source));
        if (!in_array($source, \App\Models\Tender::SOURCES, true)) {
            return response()->json([
                'ok'    => false,
                'error' => "Fonte desconhecida: `{$source}`. Aceites: "
                           . implode(', ', \App\Models\Tender::SOURCES),
            ], 422);
        }

        $current = $collaborator->allowed_sources;

        if ($current === null) {
            // First toggle on a legacy (NULL) row → treat as "full set minus
            // this one". The admin's first click on NSPA means "block NSPA",
            // not "block everything except NSPA".
            $next = array_values(array_diff(\App\Models\Tender::SOURCES, [$source]));
        } elseif (in_array($source, $current, true)) {
            $next = array_values(array_filter($current, fn($s) => $s !== $source));
        } else {
            $next = array_values(array_unique(array_merge($current, [$source])));
        }

        // Collapse back to NULL when every source is present — same
        // meaning, cleaner on disk, and avoids the UI chip row looking
        // all-green-forever-with-no-way-to-reset.
        if (!empty($next) && count(array_diff(\App\Models\Tender::SOURCES, $next)) === 0) {
            $next = null;
        }

        $collaborator->allowed_sources = $next;
        $collaborator->save();

        Log::info('TenderCollaborator: allowed_sources toggled', [
            'collaborator_id' => $collaborator->id,
            'name'            => $collaborator->name,
            'source'          => $source,
            'new_value'       => $next,
            'by_user_id'      => auth()->id(),
        ]);

        return response()->json([
            'ok'              => true,
            'allowed_sources' => $next,
            'has_source'      => $next === null || in_array($source, $next, true),
            'mode'            => $next === null ? 'unrestricted' : (empty($next) ? 'blocked_all' : 'whitelist'),
        ]);
    }

    /**
     * Same shape as toggleSource but flips a single status in
     * allowed_statuses. The full enum is sourced from the runtime
     * union of ACTIVE_STATUSES + TERMINAL_STATUSES on Tender.
     */
    public function toggleStatus(TenderCollaborator $collaborator, string $status)
    {
        $status   = strtolower(trim($status));
        $statuses = array_values(array_unique(array_merge(
            \App\Models\Tender::ACTIVE_STATUSES,
            \App\Models\Tender::TERMINAL_STATUSES
        )));
        if (!in_array($status, $statuses, true)) {
            return response()->json([
                'ok'    => false,
                'error' => "Estado desconhecido: `{$status}`. Aceites: " . implode(', ', $statuses),
            ], 422);
        }

        $current = $collaborator->allowed_statuses;
        if ($current === null) {
            $next = array_values(array_diff($statuses, [$status]));
        } elseif (in_array($status, $current, true)) {
            $next = array_values(array_filter($current, fn($s) => $s !== $status));
        } else {
            $next = array_values(array_unique(array_merge($current, [$status])));
        }

        if (!empty($next) && count(array_diff($statuses, $next)) === 0) {
            $next = null;
        }

        $collaborator->allowed_statuses = $next;
        $collaborator->save();

        Log::info('TenderCollaborator: allowed_statuses toggled', [
            'collaborator_id' => $collaborator->id,
            'name'            => $collaborator->name,
            'status'          => $status,
            'new_value'       => $next,
            'by_user_id'      => auth()->id(),
        ]);

        return response()->json([
            'ok'               => true,
            'allowed_statuses' => $next,
            'has_status'       => $next === null || in_array($status, $next, true),
            'mode'             => $next === null ? 'unrestricted' : (empty($next) ? 'blocked_all' : 'whitelist'),
        ]);
    }

    /**
     * Bulk-apply a source whitelist across every active collaborator row.
     *
     * Use case: a new tender source (e.g. Acingov) just got activated in
     * the import pipeline. By default every existing collaborator has
     * allowed_sources=NULL, so they immediately see the new source.
     * Sometimes you want the inverse: lock everyone to NSPA only until
     * you've curated who gets Acingov. Doing that one-by-one across N
     * collaborators is painful — this endpoint does it in one call.
     *
     * Modes:
     *   action=set      → replace each row's allowed_sources with the
     *                     payload (or NULL when payload covers every
     *                     source — same collapse rule as toggleSource).
     *   action=add      → union the payload into each row's whitelist.
     *                     Rows with NULL stay NULL (already see this
     *                     source).
     *   action=remove   → diff the payload out of each row's whitelist.
     *                     Rows with NULL materialise to the full set
     *                     minus the payload (same first-click rule as
     *                     toggleSource).
     *
     * Returns JSON with the count of rows touched + a per-row breakdown
     * of new states so the UI can render a confirmation summary.
     */
    public function bulkSetSources(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'action'  => ['required', 'in:set,add,remove'],
            'sources' => ['required', 'array'],
            'sources.*' => ['string', Rule::in(\App\Models\Tender::SOURCES)],
        ]);

        $action  = $data['action'];
        $payload = array_values(array_unique($data['sources']));

        $rows = TenderCollaborator::active()->get();
        $changes = [];

        foreach ($rows as $row) {
            $current = $row->allowed_sources;

            switch ($action) {
                case 'set':
                    $next = $payload;
                    break;
                case 'add':
                    if ($current === null) {
                        // Already sees everything — no-op.
                        $next = null;
                    } else {
                        $next = array_values(array_unique(array_merge($current, $payload)));
                    }
                    break;
                case 'remove':
                    if ($current === null) {
                        $next = array_values(array_diff(\App\Models\Tender::SOURCES, $payload));
                    } else {
                        $next = array_values(array_diff($current, $payload));
                    }
                    break;
            }

            // Same collapse-to-NULL rule as toggleSource for consistency.
            if (is_array($next) && count(array_diff(\App\Models\Tender::SOURCES, $next)) === 0) {
                $next = null;
            }

            // Skip the write if nothing actually changed.
            if ($current === $next) continue;

            $row->allowed_sources = $next;
            $row->save();

            $changes[] = [
                'collaborator_id' => $row->id,
                'name'            => $row->name,
                'before'          => $current,
                'after'           => $next,
            ];
        }

        Log::info('TenderCollaborator: bulk allowed_sources', [
            'action'      => $action,
            'sources'     => $payload,
            'touched'     => count($changes),
            'by_user_id'  => auth()->id(),
        ]);

        return response()->json([
            'ok'      => true,
            'action'  => $action,
            'sources' => $payload,
            'touched' => count($changes),
            'changes' => $changes,
        ]);
    }

    /**
     * Hard-delete the collaborator row — ONLY when it was never assigned
     * to a tender. This is the escape hatch for typos, duplicates and
     * people who were created by mistake and should disappear entirely.
     *
     * Why the guard: the tenders table has a `nullOnDelete` FK on
     * assigned_collaborator_id. Hard-deleting a row with history would
     * silently nullify every assignment pointing to it — which destroys
     * attribution. If the user really wants to remove a row with history,
     * they should desactivate it (soft) so assignments still show who did
     * the work.
     *
     * We do NOT cascade to the linked User account here — provisioning
     * and deprovisioning users is /admin/users territory. This action
     * only removes the roster entry.
     */
    public function forceDestroy(TenderCollaborator $collaborator)
    {
        $tendersCount = $collaborator->tenders()->count();

        if ($tendersCount > 0) {
            return back()->withErrors([
                'tenders' => "\"{$collaborator->name}\" tem {$tendersCount} concurso(s) atribuído(s). "
                    . "Desactiva em vez de excluir para preservar o histórico.",
            ]);
        }

        Log::info('TenderCollaborator: force-deleted', [
            'collaborator_id' => $collaborator->id,
            'name'            => $collaborator->name,
            'email'           => $collaborator->email,
            'had_user_link'   => (bool) $collaborator->user_id,
            'by_user_id'      => auth()->id(),
        ]);

        $name = $collaborator->name;
        $collaborator->delete();

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', "Colaborador \"{$name}\" excluído permanentemente.");
    }

    /**
     * Provision a User account from an existing collaborator row.
     *
     * Why: the dropdown on the edit page only lists existing Users. If the
     * collaborator is someone new (e.g. Mónica, Eduardo R.) who has never
     * logged into the app, there's nothing to link to. This action
     * bootstraps the User from what the roster already knows (name + email)
     * and auto-links the collaborator in the same transaction, so the next
     * time the edit page loads they appear in the dropdown and — more
     * importantly — they start seeing "Os meus concursos" on /dashboard and
     * receiving the daily digest.
     *
     * We do NOT set a password chosen by the super-user. A random secret is
     * hashed (so the row is valid) and we immediately trigger Laravel's
     * built-in password reset flow — the new user clicks the email, picks
     * their own password. This keeps the manager out of the credential
     * loop and matches how HR typically onboards.
     */
    public function createUser(TenderCollaborator $collaborator)
    {
        if ($collaborator->user_id) {
            return back()->with('status', "\"{$collaborator->name}\" já tem User ligado.");
        }

        $email = trim((string) $collaborator->email);
        if ($email === '') {
            return back()->withErrors([
                'email' => 'Preenche primeiro o email do colaborador — é esse email que vai ser a conta.',
            ]);
        }

        // Belt-and-braces: if a User with this email already exists, reuse it
        // instead of crashing the unique constraint. This covers cases where
        // the person was onboarded through /admin/users earlier but never
        // linked to the roster row.
        $existing = User::where('email', $email)->first();
        if ($existing) {
            $collaborator->user_id = $existing->id;
            $collaborator->save();

            return redirect()
                ->route('tenders.collaborators.edit', $collaborator)
                ->with('status', "\"{$collaborator->name}\" ligado ao User existente {$existing->email}.");
        }

        $user = User::create([
            'name'      => $collaborator->name,
            'email'     => $email,
            'password'  => Hash::make(Str::random(40)),  // placeholder; user sets real password via reset link
            'role'      => 'user',
            'is_active' => true,
        ]);

        $collaborator->user_id = $user->id;
        $collaborator->save();

        // Send the password-setup email. We ignore the broker's status here
        // because the User is already created and linked — even if SMTP
        // hiccups, the super-user can trigger "forgot password" again from
        // /admin/users without re-running this action.
        try {
            Password::sendResetLink(['email' => $user->email]);
            $msg = "User criado para \"{$collaborator->name}\" e enviado email de activação para {$user->email}.";
        } catch (\Throwable $e) {
            Log::warning('createUser: password reset send failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            $msg = "User criado para \"{$collaborator->name}\" mas falhou envio do email — pede-lhe para usar 'Esqueci a password' em {$user->email}.";
        }

        return redirect()
            ->route('tenders.collaborators.edit', $collaborator)
            ->with('status', $msg);
    }

    /**
     * Bulk variant of createUser(). Iterates collaborators that have an
     * email and no linked User, and provisions accounts for each. Idempotent
     * — running it twice doesn't double-create because we check user_id.
     */
    public function createUsersBatch(Request $request)
    {
        $eligible = TenderCollaborator::query()
            ->whereNull('user_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('is_active', true)
            ->get();

        $created  = 0;
        $linked   = 0;
        $skipped  = 0;

        foreach ($eligible as $c) {
            $email = trim((string) $c->email);
            if ($email === '') { $skipped++; continue; }

            $existing = User::where('email', $email)->first();
            if ($existing) {
                $c->user_id = $existing->id;
                $c->save();
                $linked++;
                continue;
            }

            $user = User::create([
                'name'      => $c->name,
                'email'     => $email,
                'password'  => Hash::make(Str::random(40)),
                'role'      => 'user',
                'is_active' => true,
            ]);
            $c->user_id = $user->id;
            $c->save();

            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                Log::warning('createUsersBatch: reset link failed', ['user' => $user->email, 'err' => $e->getMessage()]);
            }
            $created++;
        }

        $parts = [];
        if ($created > 0) $parts[] = "{$created} User(s) criados + email de activação enviado";
        if ($linked  > 0) $parts[] = "{$linked} ligados a Users já existentes";
        if ($skipped > 0) $parts[] = "{$skipped} ignorados (sem email)";
        if (!$parts)      $parts[] = "nenhum colaborador elegível (todos já ligados ou sem email)";

        return redirect()
            ->route('tenders.collaborators.index')
            ->with('status', implode(' · ', $parts));
    }

    /**
     * Validate the create/update payload. `$ignoreId` lets update skip
     * the normalized-name uniqueness check against the row being edited.
     *
     * user_id is intentionally NOT in the accepted payload — the
     * TenderCollaborator model links to a User automatically whenever
     * the email changes (saving hook). Keeping it out of fillable input
     * means a stray hidden field on an old form can't override the
     * auto-link with a stale value.
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $normalized = TenderCollaborator::normalize((string) $request->input('name'));

        $uniqueRule = Rule::unique('tender_collaborators', 'normalized_name');
        if ($ignoreId) $uniqueRule->ignore($ignoreId);

        return $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['normalized_name' => $normalized];
    }
}
