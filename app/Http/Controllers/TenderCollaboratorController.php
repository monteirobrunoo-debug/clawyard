<?php

namespace App\Http\Controllers;

use App\Models\TenderCollaborator;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
class TenderCollaboratorController extends Controller
{
    public function __construct()
    {
        // Every action on this controller requires manager+ privileges.
        $this->middleware(function ($request, $next) {
            if (!Auth::user()?->can('tenders.collaborators')) abort(403);
            return $next($request);
        });
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
     * Validate the create/update payload. `$ignoreId` lets update skip
     * the normalized-name uniqueness check against the row being edited.
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $normalized = TenderCollaborator::normalize((string) $request->input('name'));

        $uniqueRule = Rule::unique('tender_collaborators', 'normalized_name');
        if ($ignoreId) $uniqueRule->ignore($ignoreId);

        return $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['nullable', 'email', 'max:255'],
            'user_id'   => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'user_id'   => 'User ligado',
        ]) + ['normalized_name' => $normalized];
    }
}
