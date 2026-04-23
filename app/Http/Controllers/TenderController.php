<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenderAssignRequest;
use App\Http\Requests\TenderUpdateRequest;
use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Services\TenderSimilarityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Tender dashboard — list, show, update, bulk assign, observe.
 *
 * Role-aware:
 *   - manager+: sees all tenders, can bulk-assign and import
 *   - regular user: sees only tenders assigned to collaborators linked
 *     to their User account (TenderCollaborator.user_id)
 *
 * The user explicitly asked for dual landing view (their own AND by
 * deadline), so index() returns two named collections in one payload:
 *   - `mine`: assigned to the logged-in user, active, sorted by deadline
 *   - `all`:  every active tender (manager+) or the user's own, paginated
 */
class TenderController extends Controller
{
    // ── Dashboard ─────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user         = Auth::user();
        $canViewAll   = $user->can('tenders.view-all');
        $canImport    = $user->can('tenders.import');
        $canAssign    = $user->can('tenders.assign');

        $filters = $this->parseFilters($request);

        // "All" list — manager+ sees every tender; user sees only their own.
        $allQuery = Tender::query()->with('collaborator');
        if (!$canViewAll) {
            $allQuery->forUser($user->id);
        }
        $this->applyFilters($allQuery, $filters);
        $all = $allQuery
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->paginate(50)
            ->withQueryString();

        // "Mine" list — always just the logged-in user's own active tenders,
        // regardless of role. For admins this is typically empty (they're
        // not the assignee); for regular users this is the primary view.
        $mine = Tender::query()
            ->forUser($user->id)
            ->active()
            ->with('collaborator')
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->limit(50)
            ->get();

        $collaborators = TenderCollaborator::active()->orderBy('name')->get();

        return view('tenders.index', [
            'all'           => $all,
            'mine'          => $mine,
            'filters'       => $filters,
            'collaborators' => $collaborators,
            'canImport'     => $canImport,
            'canAssign'     => $canAssign,
            'canViewAll'    => $canViewAll,
            'stats'         => $this->dashboardStats($canViewAll ? null : $user->id),
        ]);
    }

    // ── Detail view ───────────────────────────────────────────────────────
    public function show(Tender $tender, TenderSimilarityService $similarity)
    {
        $user = Auth::user();
        $this->enforceVisibility($tender, $user);

        $similar = $similarity->findSimilar($tender, 5);

        return view('tenders.show', [
            'tender'  => $tender->load(['collaborator.user', 'lastImport', 'assignedBy']),
            'similar' => $similar,
            'canEdit' => $user->can('tenders.update', $tender),
        ]);
    }

    // ── Inline edit ──────────────────────────────────────────────────────
    public function update(TenderUpdateRequest $request, Tender $tender)
    {
        $tender->fill($request->validated());
        $tender->save();

        return redirect()
            ->route('tenders.show', $tender)
            ->with('status', 'Concurso actualizado.');
    }

    // ── Append-only observation ──────────────────────────────────────────
    /**
     * User asked for append-only observations ("nao pode apagar e adicionar
     * obervacoes"). We serialise them into the existing `notes` column with
     * a "[date — username]: body" prefix so full history is preserved
     * without a dedicated table. Any authenticated, active user can observe
     * — this is our lowest-privilege action.
     */
    public function observe(Request $request, Tender $tender)
    {
        $user = Auth::user();
        if (!$user->can('tenders.observe')) abort(403);
        $this->enforceVisibility($tender, $user);

        $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $stamp = now()->format('Y-m-d H:i');
        $entry = "[{$stamp} — {$user->name}]: " . trim($request->input('body'));

        $tender->notes = $tender->notes
            ? $tender->notes . "\n\n" . $entry
            : $entry;
        $tender->save();

        return redirect()
            ->route('tenders.show', $tender)
            ->with('status', 'Observação adicionada.');
    }

    // ── Bulk assign ──────────────────────────────────────────────────────
    public function assign(TenderAssignRequest $request)
    {
        $data            = $request->validated();
        $collaboratorId  = $data['collaborator_id'] ?? null;
        $user            = Auth::user();

        $updated = Tender::whereIn('id', $data['tender_ids'])->update([
            'assigned_collaborator_id' => $collaboratorId,
            'assigned_at'              => $collaboratorId ? now() : null,
            'assigned_by_user_id'      => $user->id,
        ]);

        $label = $collaboratorId
            ? TenderCollaborator::find($collaboratorId)?->name ?? 'colaborador'
            : '(sem atribuição)';

        return redirect()
            ->route('tenders.index', $request->only(['status', 'source', 'urgency']))
            ->with('status', "{$updated} concursos atribuídos a {$label}.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function enforceVisibility(Tender $tender, $user): void
    {
        if ($user->can('tenders.view-all')) return;

        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $user->id) {
            abort(403, 'Este concurso não está atribuído a si.');
        }
    }

    private function parseFilters(Request $r): array
    {
        return [
            'source'           => $r->string('source')->trim()->value() ?: null,
            'status'           => $r->string('status')->trim()->value() ?: null,
            'urgency'          => $r->string('urgency')->trim()->value() ?: null,
            'collaborator_id'  => $r->integer('collaborator_id') ?: null,
            'q'                => $r->string('q')->trim()->value() ?: null,
        ];
    }

    private function applyFilters($query, array $f): void
    {
        if ($f['source'])          $query->where('source', $f['source']);
        if ($f['status'])          $query->where('status', $f['status']);
        if ($f['collaborator_id']) $query->where('assigned_collaborator_id', $f['collaborator_id']);
        if ($f['q']) {
            $q = '%' . $f['q'] . '%';
            $query->where(function ($w) use ($q) {
                $w->where('title', 'LIKE', $q)
                  ->orWhere('reference', 'LIKE', $q)
                  ->orWhere('sap_opportunity_number', 'LIKE', $q);
            });
        }
        if ($f['urgency']) {
            // Translate urgency bucket → deadline_at window
            $now = now();
            switch ($f['urgency']) {
                case 'overdue':  $query->where('deadline_at', '<', $now); break;
                case 'critical': $query->whereBetween('deadline_at', [$now, $now->copy()->addDays(3)]); break;
                case 'urgent':   $query->whereBetween('deadline_at', [$now, $now->copy()->addDays(7)]); break;
                case 'soon':     $query->whereBetween('deadline_at', [$now, $now->copy()->addDays(14)]); break;
                case 'normal':   $query->where('deadline_at', '>', $now->copy()->addDays(14)); break;
            }
        }
    }

    private function dashboardStats(?int $userId): array
    {
        $base = Tender::query();
        if ($userId) $base->forUser($userId);

        return [
            'total'        => (clone $base)->count(),
            'active'       => (clone $base)->active()->count(),
            'overdue'      => (clone $base)->active()->where('deadline_at', '<', now())->count(),
            'urgent'       => (clone $base)->urgent(7)->count(),
            'needing_sap'  => (clone $base)->needingSapOpportunity()->count(),
        ];
    }
}
