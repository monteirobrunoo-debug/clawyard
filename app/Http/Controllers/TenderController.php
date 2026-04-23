<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenderAssignRequest;
use App\Http\Requests\TenderUpdateRequest;
use App\Models\AgentShare;
use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Services\SapService;
use App\Services\TenderSimilarityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

        // "Active but not expired" — matches the behaviour the user asked
        // for: the overview is a live action board, not an archive. A
        // tender is kept here if it's in an active status AND either has
        // no deadline, is still in the future, or is overdue by ≤15 days
        // (the OVERDUE_WINDOW_DAYS window). Anything older than that is
        // "expired" and belongs to the historical view, not here.
        $expiredCut = now()->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);
        $notExpired = function ($q) use ($expiredCut) {
            $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', $expiredCut);
        };

        // Section A — collaborators with their active-and-not-expired
        // tender bucket. Eager-load tenders so the view doesn't N+1 per
        // collaborator.
        $collaborators = TenderCollaborator::query()
            ->active()
            ->with([
                'user:id,name,email,role',
                'tenders' => fn($q) => $q
                    ->active()
                    ->where($notExpired)
                    ->with('assignedBy:id,name')
                    ->orderByRaw('deadline_at IS NULL, deadline_at ASC'),
            ])
            ->orderBy('name')
            ->get();

        // Orphan bucket — active, non-expired tenders with no assignee.
        // These are the #1 manager action item ("assign someone").
        $unassigned = Tender::query()
            ->active()
            ->where($notExpired)
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

    /**
     * Manual "remind this collaborator" button on /tenders/overview.
     *
     * The super-user may spot that a person is sitting on a pile of
     * active tenders and want to nudge them NOW rather than waiting for
     * the scheduled digest. This endpoint sends a one-shot email listing
     * that collaborator's currently-active, not-yet-expired tenders
     * (same bucket the overview page shows).
     *
     * Manager-only. No-op (with a warning flash) if the collaborator has
     * no email to reach, or no active tenders.
     */
    public function sendReminder(TenderCollaborator $collaborator)
    {
        $user = Auth::user();
        if (!$user->can('tenders.view-all')) abort(403);

        $email = $collaborator->digest_email; // email field first, else linked user email
        if (!$email) {
            return back()->with('status', "⚠ {$collaborator->name} não tem email — não foi enviado nada.");
        }

        $expiredCut = now()->copy()->subDays(Tender::OVERDUE_WINDOW_DAYS);
        $tenders = $collaborator->tenders()
            ->active()
            ->where(function ($q) use ($expiredCut) {
                $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', $expiredCut);
            })
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->get();

        if ($tenders->isEmpty()) {
            return back()->with('status', "✓ {$collaborator->name} não tem concursos activos — não foi enviado nada.");
        }

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(
                new \App\Mail\TenderCollaboratorReminder($collaborator, $tenders, $user)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Manual tender reminder failed', [
                'collaborator_id' => $collaborator->id,
                'email'           => $email,
                'error'           => $e->getMessage(),
            ]);
            return back()->with('status', "✗ Falha ao enviar lembrete para {$collaborator->name}: {$e->getMessage()}");
        }

        return back()->with(
            'status',
            "📧 Lembrete enviado para {$collaborator->name} ({$email}) — {$tenders->count()} concurso" . ($tenders->count() === 1 ? '' : 's') . "."
        );
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
    public function update(TenderUpdateRequest $request, Tender $tender, SapService $sap)
    {
        $before = [
            'notes'      => (string) $tender->notes,
            'sap_number' => (string) $tender->sap_opportunity_number,
        ];

        $tender->fill($request->validated());
        $tender->save();

        // Fase 2 of SAP integration — push Notas → SAP Opportunity Remarks.
        //
        // Triggered only when:
        //   1. The tender has a parseable SAP SequentialNo, AND
        //   2. The `notes` column actually changed in this save (avoid
        //      silently rewriting Remarks on every unrelated edit), AND
        //   3. The Service Layer is configured (username + password).
        //
        // Any SAP failure is logged but does NOT rollback the local save —
        // the ClawYard record of truth is saved first, SAP sync is a
        // side-effect. Users see the sync status in the flash message.
        $sapStatus = null;
        $seqNo     = $tender->getSapSequentialNo();
        $notesChanged = ((string) $tender->notes) !== $before['notes'];

        if ($seqNo && $notesChanged && config('services.sap.username')) {
            try {
                $ok = $sap->updateOpportunity($seqNo, [
                    'Remarks' => (string) $tender->notes,
                ]);
                $sapStatus = $ok
                    ? "✓ Notas sincronizadas com SAP Opp #{$seqNo}"
                    : "⚠ Falha a sincronizar com SAP Opp #{$seqNo}: " . ($sap->getLastError() ?: 'erro desconhecido');
            } catch (\Throwable $e) {
                Log::warning('Tender update → SAP sync failed', [
                    'tender_id' => $tender->id,
                    'seq_no'    => $seqNo,
                    'error'     => $e->getMessage(),
                ]);
                $sapStatus = "⚠ Excepção ao sincronizar com SAP Opp #{$seqNo}: {$e->getMessage()}";
            }
        }

        $msg = 'Concurso actualizado.';
        if ($sapStatus) $msg .= ' · ' . $sapStatus;

        return redirect()
            ->route('tenders.show', $tender)
            ->with('status', $msg);
    }

    // ── SAP Opportunity preview (Fase 1 — read-only) ──────────────────────
    /**
     * JSON endpoint that fetches the SAP B1 Opportunity linked to this tender
     * (via sap_opportunity_number) so the show page can render a live status
     * card without blocking the server-side render.
     *
     * Response shape:
     *   { state: "ok",         data: {...} }
     *   { state: "empty",      message: "Preenche Nº Oportunidade SAP primeiro" }
     *   { state: "unparseable",message: "'ABC' não é um nº SAP válido" }
     *   { state: "not_found",  message: "Opp #16836 não existe no SAP" }
     *   { state: "disabled",   message: "Integração SAP não configurada" }
     *   { state: "error",      message: "..." }
     *
     * Stays GET + cacheable at the view layer — we don't mutate anything
     * here. Visibility is enforced so regular users can only peek at SAP
     * for tenders they can see.
     */
    public function sapPreview(Tender $tender, SapService $sap): JsonResponse
    {
        $user = Auth::user();
        $this->enforceVisibility($tender, $user);

        if (!config('services.sap.username') || !config('services.sap.password')) {
            return response()->json([
                'state'   => 'disabled',
                'message' => 'Integração SAP não configurada — falta SAP_B1_USER / SAP_B1_PASSWORD no .env do servidor.',
            ]);
        }

        $raw = (string) $tender->sap_opportunity_number;
        if ($raw === '') {
            return response()->json([
                'state'   => 'empty',
                'message' => 'Preenche o campo "Nº Oportunidade SAP" acima e guarda para ver os dados da oportunidade.',
            ]);
        }

        $seqNo = $tender->getSapSequentialNo();
        if (!$seqNo) {
            return response()->json([
                'state'   => 'unparseable',
                'message' => "O valor \"{$raw}\" não contém um número SAP reconhecível. Esperado algo como \"16836/2026\" ou só \"16836\".",
            ]);
        }

        try {
            $opp = $sap->getOpportunityWithStages($seqNo);
        } catch (\Throwable $e) {
            Log::warning('sapPreview: getOpportunityWithStages failed', [
                'tender_id' => $tender->id,
                'seq_no'    => $seqNo,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'state'   => 'error',
                'message' => 'Erro a contactar o SAP Service Layer: ' . $e->getMessage(),
            ], 502);
        }

        if (!$opp) {
            return response()->json([
                'state'   => 'not_found',
                'message' => "A oportunidade SAP #{$seqNo} não foi encontrada. Verifica o número ou confirma que não está arquivada.",
                'seq_no'  => $seqNo,
            ], 404);
        }

        // Compact the SAP payload to what the UI actually needs — avoid
        // shipping the 30+ raw SAP fields to the browser.
        $lines = $opp['SalesOpportunitiesLines'] ?? [];
        if (is_array($lines) && count($lines) > 1) {
            usort($lines, fn($a, $b) => ((int) ($a['LineNum'] ?? 0)) <=> ((int) ($b['LineNum'] ?? 0)));
        }
        $lastStage = !empty($lines) ? end($lines) : null;

        return response()->json([
            'state' => 'ok',
            'data'  => [
                'seq_no'               => (int) ($opp['SequentialNo'] ?? $seqNo),
                'name'                 => (string) ($opp['OpportunityName'] ?? ''),
                'status'               => (string) ($opp['Status'] ?? ''),
                'bp_code'              => (string) ($opp['CardCode'] ?? ''),
                'bp_name'              => (string) ($opp['CardName'] ?? ''),
                'max_local_total'      => (float)  ($opp['MaxLocalTotal']    ?? 0),
                'weighted_total'       => (float)  ($opp['WeightedSumLC']    ?? 0),
                'closing_percentage'   => (float)  ($opp['ClosingPercentage'] ?? 0),
                'predicted_closing'    => (string) ($opp['PredictedClosingDate'] ?? ''),
                'start_date'           => (string) ($opp['StartDate'] ?? ''),
                'current_stage_no'     => (int)    ($opp['CurrentStageNo'] ?? 0),
                'remarks'              => (string) ($opp['Remarks'] ?? ''),
                'stages_count'         => is_array($lines) ? count($lines) : 0,
                'last_stage'           => $lastStage ? [
                    'line_num'         => (int) ($lastStage['LineNum'] ?? 0),
                    'stage_key'        => (int) ($lastStage['StageKey'] ?? 0),
                    'percentage_rate'  => (float) ($lastStage['PercentageRate'] ?? 0),
                    'start_date'       => substr((string) ($lastStage['StartDate'] ?? ''), 0, 10),
                    'close_date'       => substr((string) ($lastStage['CloseDate'] ?? ($lastStage['ClosedDate'] ?? '')), 0, 10),
                    'sales_employee'   => (string) ($lastStage['SalesEmployee'] ?? ''),
                ] : null,
            ],
        ]);
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

        // Normalise the tender_ids payload to ints so we can reliably
        // compare against $t->id in the view (mixed strings break
        // in_array strict checks).
        $ids = array_values(array_map('intval', $data['tender_ids']));

        $updated = Tender::whereIn('id', $ids)->update([
            'assigned_collaborator_id' => $collaboratorId,
            'assigned_at'              => $collaboratorId ? now() : null,
            'assigned_by_user_id'      => $user->id,
        ]);

        $label = $collaboratorId
            ? TenderCollaborator::find($collaboratorId)?->name ?? 'colaborador'
            : '(sem atribuição)';

        // Pass the just-affected IDs to the view as a one-shot flash so
        // the rows get a visual pulse/highlight after redirect. User rule:
        // "quando faco atribuicao do projecto via este dashboard a um user
        // deve ficar um quadrado com um pisco para saber que foi atribuido".
        return redirect()
            ->route('tenders.index', $request->only(['status', 'source', 'urgency']))
            ->with('status', "{$updated} concursos atribuídos a {$label}.")
            ->with('just_assigned', $ids)
            ->with('just_assigned_label', $label);
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
     *
     * Default (no explicit sort): newest imports first. User rule:
     * "os primeiros a aparecer devem ser sempre os últimos" — the row
     * that just arrived should float to the top so the super-user sees
     * fresh work without having to dig.
     */
    private function applySort($query, ?string $sort, string $dir): void
    {
        // Default view: newest imports first. Secondary sort on id so
        // rows created in the same second don't flip between reloads.
        if (!$sort) {
            $query->orderByDesc('created_at')->orderByDesc('id');
            return;
        }

        // SQLite/MySQL/Postgres-safe NULL sinking: order by the IS NULL
        // flag first, then the value in the chosen direction.
        if ($sort === 'deadline' || $sort === 'urgency') {
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

    /**
     * Header stat cards on /tenders.
     *
     * The headline card is the "live pipeline" — active status AND not
     * expired past the 15-day overdue window. That way the big number
     * reflects actual actionable work, not lifetime imports (which was
     * previously counting 280 including 100+ expired / terminal rows and
     * making the dashboard look busier than it really was).
     *
     * All downstream breakdowns are subsets of that pipeline so the sum
     * of the slices is consistent with the headline.
     */
    private function dashboardStats(?int $userId): array
    {
        $base = Tender::query();
        if ($userId) $base->forUser($userId);

        $pipeline = (clone $base)->livePipeline();

        return [
            // Live pipeline: active status + within 15d overdue window.
            // This is the number managers care about.
            'total'        => (clone $pipeline)->count(),
            // Active AND deadline still in the future (or no deadline).
            'active'       => (clone $pipeline)
                ->where(fn($w) => $w->whereNull('deadline_at')->orWhere('deadline_at', '>=', now()))
                ->count(),
            // 0..OVERDUE_WINDOW_DAYS past deadline — still rescuable.
            'overdue'      => (clone $base)->overdue()->count(),
            // ≤7d AND still in the future (fixed: was including past-deadline).
            'urgent'       => (clone $base)->urgent(7)->count(),
            // Assigned rows inside the live pipeline missing a SAP opportunity.
            'needing_sap'  => (clone $base)->needingSapOpportunity()->count(),
        ];
    }
}
