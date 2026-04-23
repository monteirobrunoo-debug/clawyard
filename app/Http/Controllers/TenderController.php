<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenderAssignRequest;
use App\Http\Requests\TenderUpdateRequest;
use App\Models\AgentShare;
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
        $sort    = $this->validateSort($request->string('sort')->trim()->value() ?: null);
        $dir     = $request->string('dir')->trim()->value() === 'desc' ? 'desc' : 'asc';

        // "All" list — manager+ sees every tender; user sees only their own.
        $allQuery = Tender::query()->with('collaborator');
        if (!$canViewAll) {
            $allQuery->forUser($user->id);
        }
        $this->applyFilters($allQuery, $filters);
        $this->applySort($allQuery, $sort, $dir);
        $all = $allQuery->paginate(50)->withQueryString();

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
            'sort'          => $sort,
            'dir'           => $dir,
            'collaborators' => $collaborators,
            'canImport'     => $canImport,
            'canAssign'     => $canAssign,
            'canViewAll'    => $canViewAll,
            'stats'         => $this->dashboardStats($canViewAll ? null : $user->id),
        ]);
    }

    // ── Super-user overview ──────────────────────────────────────────────
    /**
     * Read-only overview page for managers+ to answer two questions in one
     * glance:
     *
     *   "Who is working on which tender right now?"
     *      → section A, active tenders grouped by assigned collaborator,
     *        with an "Unassigned" bucket for orphans.
     *
     *   "What shared-agent links are currently live?"
     *      → section B, active AgentShare rows with client email, agent
     *        key, expiry, access count. Links out to /shares for full
     *        management (create/revoke).
     *
     * Both are intentionally compact — the page is for oversight, not
     * editing. Write actions live on /tenders and /shares respectively.
     */
    public function overview()
    {
        $user = Auth::user();
        if (!$user->can('tenders.view-all')) abort(403);

        // Section A — collaborators with their active tender bucket.
        // Eager-load tenders so the view doesn't N+1 per collaborator.
        $collaborators = TenderCollaborator::query()
            ->active()
            ->with([
                'user:id,name,email,role',
                'tenders' => fn($q) => $q
                    ->active()
                    ->with('assignedBy:id,name')
                    ->orderByRaw('deadline_at IS NULL, deadline_at ASC'),
            ])
            ->orderBy('name')
            ->get();

        // Orphan bucket — active tenders with no assignee. These are the
        // #1 manager action item ("assign someone").
        $unassigned = Tender::query()
            ->active()
            ->whereNull('assigned_collaborator_id')
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->get();

        // Section B — live agent shares. We show active + non-revoked +
        // non-expired so the page is about what's *currently in the wild*,
        // not historical. Full CRUD stays on /shares.
        $agentShares = AgentShare::query()
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        return view('tenders.overview', [
            'collaborators' => $collaborators,
            'unassigned'    => $unassigned,
            'agentShares'   => $agentShares,
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

    /**
     * Whitelist of column keys the user is allowed to sort by. Anything else
     * falls back to the default (deadline ascending, nulls last).
     */
    private function validateSort(?string $sort): ?string
    {
        $allowed = ['source', 'reference', 'title', 'collaborator', 'status', 'sap', 'deadline', 'urgency'];
        return in_array($sort, $allowed, true) ? $sort : null;
    }

    /**
     * Apply ORDER BY based on the user-requested sort key. `urgency` is a
     * synonym for `deadline` since the bucket is computed from deadline_at.
     * For `collaborator` we left-join the tender_collaborators table so we
     * can order by the display name (tenders without an assignee sink).
     */
    private function applySort($query, ?string $sort, string $dir): void
    {
        // SQLite/MySQL-safe NULL sinking: order by the IS NULL flag first,
        // then the value in the chosen direction.
        if (!$sort || $sort === 'deadline' || $sort === 'urgency') {
            $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
            $query->orderByRaw("deadline_at IS NULL, deadline_at {$dirSql}");
            return;
        }

        $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';

        switch ($sort) {
            case 'source':
                $query->orderBy('source', $dir)->orderBy('reference', 'asc');
                break;
            case 'reference':
                $query->orderBy('reference', $dir);
                break;
            case 'title':
                $query->orderBy('title', $dir);
                break;
            case 'status':
                $query->orderBy('status', $dir);
                break;
            case 'sap':
                // Empties sink via COALESCE + a flag column.
                $query->orderByRaw("(sap_opportunity_number IS NULL OR sap_opportunity_number = ''), sap_opportunity_number {$dirSql}");
                break;
            case 'collaborator':
                $query->leftJoin('tender_collaborators', 'tenders.assigned_collaborator_id', '=', 'tender_collaborators.id')
                      ->select('tenders.*')
                      ->orderByRaw("tender_collaborators.name IS NULL, tender_collaborators.name {$dirSql}");
                break;
        }
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
            // Translate urgency bucket → deadline_at window. "overdue" caps at
            // Tender::OVERDUE_WINDOW_DAYS (anything older is "expired").
            $now        = now();
            $overdueCut = $now->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);
            switch ($f['urgency']) {
                case 'overdue':
                    $query->where('deadline_at', '<', $now)
                          ->where('deadline_at', '>=', $overdueCut);
                    break;
                case 'expired':
                    $query->where('deadline_at', '<', $overdueCut);
                    break;
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
            // "Activos" excludes overdue on purpose — overdue has its own card.
            'active'       => (clone $base)->activeInProgress()->count(),
            // Capped at OVERDUE_WINDOW_DAYS — older ones are "expired".
            'overdue'      => (clone $base)->overdue()->count(),
            'urgent'       => (clone $base)->urgent(7)->count(),
            'needing_sap'  => (clone $base)->needingSapOpportunity()->count(),
        ];
    }
}
