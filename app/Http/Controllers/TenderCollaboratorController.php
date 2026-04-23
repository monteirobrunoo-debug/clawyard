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
 *   GET    /tenders/collaborators               index/list + inline create form
 *   POST   /tenders/collaborators               store new collaborator
 *   GET    /tenders/collaborators/{id}/edit     edit form
 *   PATCH  /tenders/collaborators/{id}          update
 *   DELETE /tenders/collaborators/{id}          soft-deactivate (is_active=false)
 *
 * We never hard-delete because tenders may still reference the row via
 * `assigned_collaborator_id` — flipping `is_active` hides them from the
 * assign dropdown without orphaning history.
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
