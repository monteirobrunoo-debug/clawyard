<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenderAssignRequest;
use App\Http\Requests\TenderUpdateRequest;
use App\Mail\TenderAssignedNotification;
use App\Models\AgentShare;
use App\Models\Tender;
use App\Models\TenderCollaborator;
use App\Models\TenderSavedView;
use App\Services\SapService;
use App\Services\TenderSimilarityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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
    /**
     * 2026-05-19: route alternativa /marine para Marine Department.
     * Reusa toda a logica do index() mas:
     *   * Forca source=marine em todos os filtros
     *   * Override do header title para "Marine Department"
     *
     * Pedido directo Monica: "agora cria um separador em vez de
     * concursos, mas Marine Department, objectivo é colar e abrir
     * analisar os processos como esta agora os concursos".
     */
    public function marine(Request $request)
    {
        return $this->index($request, [
            'force_source' => 'marine',
            'page_title'   => 'Marine Department',
            'header_label' => 'Concursos Marítimos',
            'is_marine'    => true,
        ]);
    }

    public function index(Request $request, array $sectionOverride = [])
    {
        $user         = Auth::user();
        $canViewAll   = $user->can('tenders.view-all');
        $canImport    = $user->can('tenders.import');
        $canAssign    = $user->can('tenders.assign');

        $filters = $this->parseFilters($request, $sectionOverride['force_source'] ?? null);
        $sort    = $this->validateSort($request->string('sort')->trim()->value() ?: null);
        $dir     = $request->string('dir')->trim()->value() === 'desc' ? 'desc' : 'asc';

        // Page size — defaults to 100 (was 50 before 2026-04-27, the
        // user asked for "pelo menos 100 na mesma página"). Allow the
        // operator to bump higher via ?per_page=200 when triaging a
        // big import; cap at 500 so the table doesn't crash a slow
        // browser. Falls back to 100 for any garbage value.
        $perPage = (int) $request->input('per_page', 100);
        $perPage = max(25, min($perPage, 500));

        // 2026-05-19 v3 — Monica clarificou: "catarina e jose iancio
        // pode ver todos tambem, em cima todos e os que esta assigned,
        // no entanto o normal e sempre concursos nspa, depois os users
        // filtram para outro".
        // Resultado: TODOS os users autenticados veem TODOS os tenders
        // no dashboard principal. A coluna Colaborador + filtro
        // collaborator_id permite ao user ver so os seus quando quiser.
        $allQuery = Tender::query()->with('collaborator');
        $this->applyFilters($allQuery, $filters);
        $this->applySort($allQuery, $sort, $dir);
        $all = $allQuery->paginate($perPage)->withQueryString();

        // "Mine" list — always just the logged-in user's own active tenders,
        // regardless of role. For admins this is typically empty (they're
        // not the assignee); for regular users this is the primary view.
        // Same per-page cap as the All list so a heavy assigned user
        // doesn't see a truncated personal view.
        //
        // 2026-04-29 — sortable headers + default 'most recent first'.
        // Independent sort params (mine_sort/mine_dir) so they don't
        // conflict with the manager-table sort. Defaults: created_at desc
        // — users open the page and immediately see what they just
        // imported / what's freshest, not the noisiest deadlines.
        $mineSort = $request->string('mine_sort')->trim()->value() ?: 'created_at';
        $mineDir  = strtolower($request->string('mine_dir')->trim()->value()) === 'asc' ? 'asc' : 'desc';

        // 2026-04-30 — dedicated search box on the personal table.
        // Independent from the manager-table `q` (different param so a
        // regular user filtering their own list doesn't accidentally
        // hide the global table when they have manager rights too).
        // Searches the same surface as the manager filter: title,
        // reference, sap_opportunity_number — plus source, since users
        // sometimes type "NSPA" or "Vortal" expecting it to filter.
        $mineQ = $request->string('mine_q')->trim()->value() ?: null;

        $mineQuery = Tender::query()
            ->forUser($user->id)
            ->active()
            ->with('collaborator');
        if ($mineQ !== null) {
            $needle = '%' . $mineQ . '%';
            $mineQuery->where(function ($w) use ($needle) {
                $w->where('title', 'LIKE', $needle)
                  ->orWhere('reference', 'LIKE', $needle)
                  ->orWhere('sap_opportunity_number', 'LIKE', $needle)
                  ->orWhere('source', 'LIKE', $needle);
            });
        }
        $this->applyMineSort($mineQuery, $mineSort, $mineDir);
        $mine = $mineQuery->limit($perPage)->get();

        $collaborators = TenderCollaborator::active()->orderBy('name')->get();

        // ── Source-restriction transparency banner ────────────────────────
        // If a regular user has been restricted via allowed_sources on
        // their collaborator row(s), surface it. Without this they'd see
        // a partial list and assume the system is broken when an
        // expected tender doesn't appear. Resolution mirrors
        // Tender::scopeForUser semantics: ANY linked row with NULL means
        // unrestricted, otherwise UNION the whitelists; explicit empty
        // means "blocked from all".
        $restriction = null;
        if (!$canViewAll) {
            $rows = TenderCollaborator::query()
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere(function ($qq) use ($user) {
                          $qq->whereNull('user_id')
                             ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim((string) $user->email))]);
                      });
                })
                ->get(['allowed_sources']);

            if ($rows->isNotEmpty()) {
                $unrestricted = $rows->contains(fn($r) => $r->allowed_sources === null);
                if (!$unrestricted) {
                    $allowed = $rows
                        ->flatMap(fn($r) => (array) ($r->allowed_sources ?? []))
                        ->unique()
                        ->values()
                        ->all();
                    $restriction = [
                        'mode'    => empty($allowed) ? 'blocked_all' : 'whitelist',
                        'sources' => $allowed,
                    ];
                }
            }
        }

        // 2026-05-19 v3: contagem dos tenders atribuidos ao user actual.
        // Mostrada no header como badge ao lado do total. Pedido directo:
        //   "em cima todos e os que esta assigned"
        $myAssignedCount = 0;
        try {
            $myCollabIds = TenderCollaborator::where('user_id', $user->id)
                ->pluck('id');
            if ($myCollabIds->isNotEmpty()) {
                $myAssignedCount = Tender::whereIn('assigned_collaborator_id', $myCollabIds)->count();
            }
        } catch (\Throwable $e) { /* best-effort */ }

        return view('tenders.index', [
            'all'              => $all,
            'mine'             => $mine,
            'filters'          => $filters,
            'sort'             => $sort,
            'dir'              => $dir,
            'mineSort'         => $mineSort,
            'mineDir'          => $mineDir,
            'mineQ'            => $mineQ,
            'collaborators'    => $collaborators,
            'canImport'        => $canImport,
            'canAssign'        => $canAssign,
            'canViewAll'       => $canViewAll,
            'restriction'      => $restriction,
            // 2026-05-20: passa $forceSource para os ring-charts ficarem
            // restritos à secção (Marine vê só marine, Concursos vê tudo).
            'stats'            => $this->dashboardStats(
                $canViewAll ? null : $user->id,
                $sectionOverride['force_source'] ?? null
            ),
            'myAssignedCount'  => $myAssignedCount,
            'currentUserId'    => $user->id,
            // Saved views deste user (até 12) — chips clicáveis no header.
            'savedViews'       => TenderSavedView::where('user_id', $user->id)
                                    ->orderBy('sort_order')->orderBy('id')->get(),
            // 2026-05-19 v4: section override para sub-dashboards
            // (Marine Department). Quando $sectionOverride['is_marine']
            // a view mostra cabecalho diferente + esconde dropdown source
            // (ja esta forcado a marine).
            'sectionTitle'     => $sectionOverride['header_label'] ?? null,
            'pageTitle'        => $sectionOverride['page_title']   ?? 'Concursos',
            'isMarine'         => $sectionOverride['is_marine']    ?? false,
        ]);
    }

    /**
     * Sort logic for the user-specific 'mine' tenders table. Reuses
     * the same column keys as applySort() but kept separate so
     * extensions can diverge (e.g. 'imported' as a default that's
     * meaningful on personal views but not on the manager view).
     */
    protected function applyMineSort($query, string $sort, string $dir): void
    {
        $dirSql = $dir === 'asc' ? 'asc' : 'desc';
        switch ($sort) {
            case 'created_at':
                $query->orderBy('created_at', $dirSql)->orderByDesc('id');
                return;
            case 'deadline':
                $query->orderByRaw("deadline_at IS NULL, deadline_at {$dirSql}");
                return;
            case 'source':
                $query->orderBy('source', $dirSql)->orderBy('reference', 'asc');
                return;
            case 'reference':
                $query->orderBy('reference', $dirSql);
                return;
            case 'title':
                $query->orderBy('title', $dirSql);
                return;
            case 'status':
                $query->orderBy('status', $dirSql);
                return;
            case 'sap':
                $query->orderByRaw("(sap_opportunity_number IS NULL OR sap_opportunity_number = ''), sap_opportunity_number {$dirSql}");
                return;
            default:
                $query->orderByDesc('created_at')->orderByDesc('id');
        }
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

    /**
     * Criação manual de concurso por qualquer user autenticado.
     *
     * User feedback 2026-05-06: "users podem criar linhas de novos
     * concursos e referências". Não requer gate `tenders.import`
     * porque essa é só para uploads em massa de Excel — criar 1
     * concurso à mão é trabalho corrente de qualquer colaborador.
     *
     * Auto-atribui o creator como collaborator se ele tem TenderCollaborator
     * com o seu email — assim o concurso aparece logo no "Os meus" dele.
     */
    public function storeManual(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        if (!$user || (method_exists($user, 'isGuest') && $user->isGuest())) {
            abort(403, 'Guests não podem criar concursos.');
        }

        $data = $request->validate([
            'reference'      => ['nullable', 'string', 'max:80'],
            'title'          => ['required', 'string', 'max:500'],
            'source'         => ['required', 'string', 'max:40'],
            'type'           => ['nullable', 'string', 'max:40'],
            'purchasing_org' => ['nullable', 'string', 'max:200'],
            'deadline_at'    => ['nullable', 'date'],
            'notes'          => ['nullable', 'string', 'max:5000'],
            'priority'       => ['nullable', 'string', 'in:low,normal,high,urgent'],
        ]);

        $tender = new Tender();
        $tender->source              = $data['source'];
        // 2026-05-20 fix: `reference` é NOT NULL na BD. Quando o user não
        // submete uma, geramos placeholder MAN-{timestamp}-{rand4} para
        // não rebentar o INSERT.
        $tender->reference           = (string) ($data['reference'] ?? '') !== ''
            ? $data['reference']
            : 'MAN-' . now()->format('YmdHis') . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        $tender->title               = $data['title'];
        $tender->type                = $data['type']           ?? 'manual';
        $tender->purchasing_org      = $data['purchasing_org'] ?? null;
        $tender->deadline_at         = $data['deadline_at']    ?? null;
        $tender->notes               = $data['notes']          ?? null;
        $tender->priority            = $data['priority']       ?? 'normal';
        $tender->status              = Tender::STATUS_PENDING;
        $tender->source_modified_at  = now();
        $tender->save();

        // Auto-link ao colaborador do user (se existir TenderCollaborator
        // com email correspondente, vincula para aparecer em "Os meus").
        try {
            $collab = \App\Models\TenderCollaborator::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->first();
            if ($collab) {
                $tender->assigned_collaborator_id = $collab->id;
                $tender->assigned_at              = now();
                $tender->assigned_by_user_id      = $user->id;
                $tender->save();
            }
        } catch (\Throwable $e) { /* link é best-effort */ }

        return redirect()->route('tenders.show', $tender)
            ->with('status', "✓ Concurso #{$tender->id} criado manualmente.");
    }

    /**
     * "📄 Inserir PDF — análise automática":
     *   User larga 1 PDF, sistema cria o Tender stub, extrai cliente / data /
     *   serviço / peças / fornecedores via Marta CRM, dispara análise
     *   multi-agente, e redirecciona ao /tenders/{id} com tudo pronto.
     *
     * Pedido directo 2026-05-19:
     *   "nao quero botao concurso, quero outro a dizr para isnerir o pdf
     *    apartid ai analisar cliente data, o que é o serviço o upeça e
     *    fornecesore"
     */
    public function quickPdfAnalyse(
        Request $request,
        \App\Services\TenderQuickPdfService $quickPdf
    ): \Illuminate\Http\RedirectResponse {
        $user = Auth::user();
        if (!$user || (method_exists($user, 'isGuest') && $user->isGuest())) {
            abort(403, 'Guests não podem criar concursos.');
        }

        // Aceita 2 modos de input (1 deles obrigatório):
        //   • file → PDF clássico (drag-drop ou click no Explorar)
        //   • text → paste de texto cru no textarea
        // Pedido 2026-05-20: "quero possibilidade de arrastar o pdf e
        // possibilidade de arrastar os caracteres para uma caixa de texto"
        $request->validate([
            'file'   => ['required_without:text', 'nullable', 'file', 'mimes:pdf', 'max:30720'], // 30 MB
            'text'   => ['required_without:file', 'nullable', 'string', 'min:50', 'max:200000'],
            'source' => ['nullable', 'string', 'in:manual,marine,acingov,vortal,anogov,nspa,sam_gov,nato,ungm,other'],
        ], [
            'file.required_without' => 'Selecciona um PDF ou cola texto.',
            'text.required_without' => 'Cola texto ou selecciona um PDF.',
            'file.mimes'    => 'Só PDFs são aceites como ficheiro.',
            'file.max'      => 'PDF máximo 30 MB.',
            'text.min'      => 'Texto demasiado curto — mete pelo menos uma frase com algum contexto.',
            'text.max'      => 'Texto excede 200k chars; corta o essencial e tenta de novo.',
        ]);

        $source = (string) $request->input('source', 'manual');

        try {
            if ($request->hasFile('file')) {
                $result = $quickPdf->handle($request->file('file'), $source);
            } else {
                $result = $quickPdf->handleFromText((string) $request->input('text'), $source);
            }
        } catch (\Throwable $e) {
            Log::error('TenderController::quickPdfAnalyse failed', [
                'user_id' => $user->id,
                'source'  => $source,
                'mode'    => $request->hasFile('file') ? 'pdf' : 'text',
                'error'   => $e->getMessage(),
            ]);
            return back()->with('error', 'Falha na análise automática: ' . $e->getMessage());
        }

        $tender = $result['tender'];
        $extracted = $result['extracted'];
        $hasFields = !empty(array_filter([
            $extracted['cliente']     ?? null,
            $extracted['servico']     ?? null,
            $extracted['pecas']       ?? null,
            $extracted['fornecedores'] ?? null,
        ]));

        $status = "✓ Concurso #{$tender->id} criado a partir do PDF. ";
        $status .= $hasFields
            ? 'Marta extraiu cliente, serviço, peças e fornecedores prováveis. '
            : 'Marta não conseguiu extrair campos estruturados — verifica manualmente. ';
        $status .= $result['analysis_triggered']
            ? 'Painel multi-agente (Cor. Rodrigues + Marco Sales + …) já corrido em baixo.'
            : 'Painel multi-agente não disponível agora — clica "🎯 Análise do serviço" para correr.';

        return redirect()->route('tenders.show', $tender)->with('status', $status);
    }

    // ── Detail view ───────────────────────────────────────────────────────
    public function show(Tender $tender, TenderSimilarityService $similarity)
    {
        $user = Auth::user();
        $this->enforceVisibility($tender, $user);

        $similar = $similarity->findSimilar($tender, 5);

        return view('tenders.show', [
            'tender'  => $tender->load(['collaborator.user', 'lastImport', 'assignedBy', 'attachments']),
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
        //
        // 2026-04-29 — Catarina feedback: when the user edits notes on a
        // tender that has NO sap_opportunity_number yet, the previous
        // version silently skipped the sync and showed only 'Concurso
        // actualizado' — leaving the operator wondering whether SAP
        // got updated. Now we surface the reason explicitly so they
        // know they need to fill in the SAP opp field first (or that
        // there's no SAP opp to create yet).
        $sapStatus = null;
        $seqNo     = $tender->getSapSequentialNo();
        $notesChanged = ((string) $tender->notes) !== $before['notes'];
        $sapConfigured = (bool) config('services.sap.username');

        if ($notesChanged) {
            if (!$sapConfigured) {
                $sapStatus = '⚠ Notas só guardadas localmente — SAP não está configurado no servidor.';
            } elseif (!$seqNo) {
                $sapStatus = '⚠ Notas só guardadas localmente — este concurso não tem Nº Oportunidade SAP. Preenche o campo "Nº Oportunidade SAP" acima para activar sincronização.';
            } else {
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
            // Diagnostic hint — if the tender reference looks like a SAP
            // SequentialNo (≥4 consecutive digits), suggest it. User feedback:
            // "quando se entra existe no sap a oportunidade mas ele não liga".
            // In most of these cases the SAP number was never typed into the
            // dedicated field because the user sees it in the `reference`
            // column already.
            $suggestion = null;
            if (preg_match('/\d{4,}/', (string) $tender->reference, $m)) {
                $suggestion = (int) $m[0];
            }
            return response()->json([
                'state'      => 'empty',
                'message'    => 'Preenche o campo "Nº Oportunidade SAP" acima e guarda para ver os dados da oportunidade.',
                'suggestion' => $suggestion,
            ]);
        }

        $seqNo = $tender->getSapSequentialNo();
        if (!$seqNo) {
            return response()->json([
                'state'   => 'unparseable',
                'message' => "O valor \"{$raw}\" não contém um número SAP reconhecível. Esperado algo como \"16836/2026\" ou só \"16836\".",
                'raw'     => $raw,
            ]);
        }

        // Probe authentication BEFORE fetching the opportunity so we can
        // distinguish "auth refused by SAP" (→ re-grant credentials) from
        // "opportunity doesn't exist" (→ check the number). Without this,
        // both failure modes collapsed into a generic "not found" which is
        // exactly the ambiguity Monica hit: "existe no sap a oportunidade
        // mas ele não liga ao sap".
        try {
            $ok = $sap->login();
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            $err = method_exists($sap, 'getLastError') ? $sap->getLastError() : '';
            Log::warning('sapPreview: SAP login failed', [
                'tender_id' => $tender->id,
                'seq_no'    => $seqNo,
                'error'     => $err,
            ]);
            return response()->json([
                'state'   => 'auth_failed',
                'message' => 'Login SAP recusado. Verifica SAP_B1_USER / SAP_B1_PASSWORD no servidor, '
                    . 'ou confirma que a conta do utilizador do Service Layer não foi bloqueada.'
                    . ($err ? " · Detalhe: {$err}" : ''),
                'seq_no'  => $seqNo,
            ], 502);
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
                'seq_no'  => $seqNo,
            ], 502);
        }

        if (!$opp) {
            // At this point login succeeded, so the 404 really is a
            // missing/archived opportunity, not an auth problem. Surface
            // the lastError from SapService if any (e.g. permission-scoped
            // 403 on a specific opportunity).
            $err = method_exists($sap, 'getLastError') ? $sap->getLastError() : '';
            return response()->json([
                'state'   => 'not_found',
                'message' => "A oportunidade SAP #{$seqNo} não foi encontrada ou o utilizador do Service Layer não tem permissão para a ler."
                    . ($err ? " · Detalhe: {$err}" : ''),
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

        // ── Cache SAP stage on the tender row ────────────────────────────
        // O dashboard de concursos mostra agora o estado SAP como single
        // source of truth (substituiu o $tender->status manual). Para
        // evitar N+1 fetches no render do dashboard, gravamos aqui o
        // stage_no e status sempre que o user abre o detalhe do concurso
        // (esta rota é chamada via JSON pelo card SAP Opportunity).
        try {
            $cachedStage = (int) ($lastStage['StageKey'] ?? ($opp['CurrentStageNo'] ?? 0));
            $oppStatus   = (string) ($opp['Status'] ?? '');
            if ($cachedStage > 0 || $oppStatus !== '') {
                $tender->forceFill([
                    'sap_stage_no'           => $cachedStage > 0 ? $cachedStage : null,
                    'sap_opportunity_status' => $oppStatus !== '' ? $oppStatus : null,
                    'sap_stage_updated_at'   => now(),
                ])->saveQuietly();  // saveQuietly evita disparar observers do tender
            }
        } catch (\Throwable $e) {
            Log::warning('sapPreview: failed to cache stage on tender', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
        }

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

    /**
     * POST /tenders/{tender}/marta-summarize — 2026-05-18.
     *
     * Pedido directo do Bruno: o botão "Pedir à Marta" deve abrir já
     * com os ficheiros em anexo e meter o resumo nas notas do concurso
     * (que sincronizam para SAP Remarks).
     *
     * Server-side flow (em vez de URL ?prompt= como antes):
     *   1. Constrói prompt rico com TODOS os atachments (até 8000 chars
     *      cada) + metadados do tender.
     *   2. Chama CrmAgent::chat() — Marta processa e devolve resumo
     *      em formato plano (≤200 chars) adequado para SAP Remarks.
     *   3. APPEND o resumo a tender.notes (preserva o que já lá estava).
     *   4. Dispara updateOpportunity() no SAP se houver SequentialNo.
     *   5. Redirect → tender.show com flash status.
     *
     * Crash-safe: se o LLM falhar, guarda nota local com erro mas não
     * rebenta a página.
     */
    public function martaSummarize(Tender $tender, SapService $sap)
    {
        $user = Auth::user();
        $this->enforceVisibility($tender, $user);

        // Confidential tenders nunca vão a LLM externo.
        if ($tender->is_confidential) {
            return back()->with('error', 'Concurso confidencial — Marta CRM não pode processar (envia conteúdo a Claude). Desmarca a flag se quiseres correr.');
        }

        // Carrega anexos com texto extraído. Máximo 8KB por anexo para
        // evitar saturar o context window (cada PDF típico tem 2-5 páginas
        // de texto útil; concursos com 20 anexos ficam em ≤160KB total).
        $tender->load('attachments');
        $okAttachments = $tender->attachments->where('extraction_status', 'ok');

        if ($okAttachments->isEmpty()) {
            return back()->with('error', 'Concurso sem anexos com texto extraído. Anexa pelo menos um PDF antes de pedir à Marta.');
        }

        $deadline = $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—';
        // 2026-05-18 — cap removido. Localmente tender.notes é text
        // ilimitado; o SAP B1 trunca em 254 sozinho ao fazer push.
        // Marta dá resumo TÃO DETALHADO quanto necessário — operador
        // vê tudo no dashboard, SAP recebe o essencial truncado.
        $prompt = "Analisa este concurso e dá-me um RESUMO EXECUTIVO COMPLETO para meter nas Notas do ClawYard. "
            . "Sem limite de caracteres — escreve tudo o que for relevante para o operador comercial decidir. "
            . "Inclui: objecto/material com quantidades exactas e P/N quando disponível, organização compradora e contacto se identificável, "
            . "deadline completo, condições de pagamento, prazo de entrega esperado, local de entrega, "
            . "compliance (NATO/CE/EUR.1/Mil-Std), fornecedores candidatos, valor estimado se inferível, "
            . "histórico do cliente se conhecido, e qualquer ponto crítico (urgência, dependências, riscos). "
            . "Formato: texto plano, sem markdown, sem emojis, pontos separados por ' · ' OU em linhas (\\n) para tópicos longos. "
            . "Não há limite — usa o espaço que precisares.\n\n"
            . "=== CONCURSO ===\n"
            . "• Título: {$tender->title}\n"
            . "• Referência: " . ($tender->reference ?: '—') . "\n"
            . "• Fonte: " . strtoupper((string) $tender->source) . "\n"
            . "• Organização: " . ($tender->purchasing_org ?: '—') . "\n"
            . "• Deadline: {$deadline}\n";

        if ($tender->sap_opportunity_number) {
            $prompt .= "• SAP Opp: #{$tender->sap_opportunity_number}\n";
        }

        $prompt .= "\n=== ANEXOS ({$okAttachments->count()}) ===\n";
        $sorAll = [];
        foreach ($okAttachments as $a) {
            $snippet = mb_substr((string) $a->extracted_text, 0, 8000);
            $prompt .= "\n--- {$a->original_name} ({$a->extracted_chars} chars) ---\n";
            $prompt .= $snippet . "\n";
            // 2026-05-18: extrai SoR se existir e adiciona em bloco
            // separado — Marta tem de citar isto explicitamente.
            $sor = $a->extractStatementOfRequirements(8000);
            if ($sor) $sorAll[] = "[{$a->original_name} · SoR]\n" . $sor;
        }
        if (!empty($sorAll)) {
            $prompt .= "\n=== STATEMENT OF REQUIREMENTS (CRÍTICO) ===\n"
                    .  "Esta é a secção que tem as specs técnicas linha-a-linha. "
                    .  "Inclui no resumo TODOS os items/quantidades/normas que aqui aparecem — "
                    .  "sem isto o operador comercial não sabe o que está em jogo.\n\n"
                    .  implode("\n\n", $sorAll)
                    .  "\n=== FIM SoR ===\n";
        }

        $prompt .= "\n=== INSTRUÇÃO ===\n"
            . "Devolve APENAS o resumo. Sem cabeçalhos do tipo 'aqui está', sem markdown, sem emojis. "
            . "Se a deadline for ≤7 dias, começa com 'URGENTE · '. "
            . "Escreve TUDO o que for relevante — o operador no ClawYard vê tudo, e o SAP B1 trunca sozinho em 254 chars (não te preocupes com isso). "
            . "Exemplo de uma linha condensada:\n"
            . "'URGENTE · Fornecimento 50 unidades NETGATE-7100 P/N NG-7100-D · NCIA Bélgica (NSPA - NATO SUPPORT AND PROCUREMENT AGENCY) · deadline 25/05 18:00 CET · ref NSPA-2026-1234 · pagamento 60 dias · entrega Bruxelas · compliance NATO Mil-Std-461 + CE · fornecedor preferido Cisco EU (lead time 12-16 sem) · valor estimado €120-150k baseado em contratos 2024-2025 · cliente prioritário (3 adjudicações em 2024-2025) · risco: stock Europa baixo, considerar Cisco USA como backup'\n\n"
            . "Mas se for um concurso complexo com várias dependências, podes escrever 2-3 parágrafos separados por linhas em branco — desde que cada linha de pensamento esteja completa.";

        try {
            $marta = app(\App\Agents\CrmAgent::class);
            $summary = trim($marta->chat($prompt, []));
        } catch (\Throwable $e) {
            Log::error('Tender martaSummarize: chat failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Erro a contactar a Marta CRM: ' . $e->getMessage());
        }

        // 2026-05-18: cap local REMOVIDO. Pedido directo: "põe sem limite".
        // O tender.notes é uma coluna text (ilimitada) e o SAP B1 trunca
        // sozinho em 254 chars ao fazer push (SapService::createOpportunity
        // → substr(0, 254)). Mantemos só um cap de segurança absoluto a
        // 10k chars para evitar PostgreSQL row bloat acidental se a Marta
        // tiver um bug raro de loop.
        if (mb_strlen($summary) > 10000) {
            $summary = mb_substr($summary, 0, 10000) . '… (cortado em 10k chars — Marta exagerou)';
        }

        // Bloco para meter nas notas. Marcado com data para o operador
        // saber quando foi gerado e poder filtrar / re-correr.
        $date = now()->format('d/m/Y H:i');
        $block = "[Marta CRM · {$date}]\n{$summary}";

        // APPEND preservando notas manuais existentes.
        $existing = trim((string) $tender->notes);
        $tender->notes = $existing === '' ? $block : ($existing . "\n\n" . $block);
        $tender->save();

        // SAP push — mesma lógica de gates do update() normal.
        $seqNo = $tender->getSapSequentialNo();
        $sapConfigured = (bool) config('services.sap.username');
        $sapStatus = '';

        if (!$sapConfigured) {
            $sapStatus = 'SAP não configurado — guardado só local';
        } elseif (!$seqNo) {
            $sapStatus = 'sem Nº Oportunidade SAP — guardado só local. Preenche o campo SAP para activar sync';
        } else {
            try {
                $ok = $sap->updateOpportunity($seqNo, ['Remarks' => (string) $tender->notes]);
                $sapStatus = $ok ? "SAP Opp #{$seqNo} sincronizado" : 'SAP rejeitou: ' . ($sap->getLastError() ?: 'erro desconhecido');
            } catch (\Throwable $e) {
                Log::warning('Tender martaSummarize SAP sync failed', [
                    'tender_id' => $tender->id,
                    'error'     => $e->getMessage(),
                ]);
                $sapStatus = "excepção SAP: {$e->getMessage()}";
            }
        }

        return redirect()
            ->route('tenders.show', $tender)
            ->with('status', "✓ Resumo da Marta gravado nas notas · {$sapStatus}");
    }

    /**
     * POST /tenders/{tender}/create-sap-opp — 2026-05-18.
     *
     * Cria DIRECTAMENTE a SAP Opportunity para o concurso, sem passar
     * por chat. O botão antigo "Pedir à Marta para abrir SAP" abria o
     * /chat com prompt pré-populado mas dependia de a LLM emitir o JSON
     * pending correcto + utilizador confirmar — o que falhava muitas vezes.
     *
     * Este endpoint:
     *   1. Verifica que o tender não tem SAP Opp ainda (se tem, devolve a actual)
     *   2. Constrói payload com tudo o que conseguimos derivar:
     *        OpportunityName = título (capped 100)
     *        Remarks        = título + ref + breve excerto de anexos
     *        CardName       = purchasing_org (SAP resolve para CardCode)
     *        ExpectedClosingDate = deadline_at (ou +30 dias se sem deadline)
     *        MaxLocalTotal  = 1.0 (placeholder — operador acerta no SAP depois)
     *   3. Chama SapService::createOpportunity() directamente
     *   4. Em sucesso: actualiza tender.sap_opportunity_number + sap_stage_no
     *   5. Em falha: flash error com detail
     */
    public function createSapOpp(Tender $tender, SapService $sap)
    {
        $user = Auth::user();
        $this->enforceVisibility($tender, $user);

        if (!$user->isManager() && !$user->can('tenders.create-sap-opp')) {
            // Visibility já garantiu autenticação; falta gate de manager+.
            // Operadores regulares vêem o botão se forem colaboradores, mas
            // só managers podem disparar a criação SAP que mexe em SAP B1.
            abort(403, 'Apenas managers podem abrir oportunidades SAP.');
        }

        if ($tender->is_confidential) {
            return back()->with('error', 'Concurso confidencial — não envia dados para SAP B1.');
        }

        // Idempotência: se já existe SAP Opp ligada, não cria nova.
        if (!empty($tender->sap_opportunity_number)) {
            return back()->with('status', "Concurso já tem SAP Opp #{$tender->sap_opportunity_number}. Para re-sincronizar, edita as Notas e guarda.");
        }

        if (!config('services.sap.username')) {
            return back()->with('error', 'SAP B1 não está configurado no servidor — não posso criar a oportunidade.');
        }

        // Constrói Remarks RICOS — mesmo nível de detalhe que a Marta CRM
        // mete no chat-flow (P/N, quantidades, payment, ship-to, deadline).
        // Pedido directo do operador: "aqui é o mesmo mas já tem tudo
        // pdf info" — agora aproveitamos o extracted_text dos anexos
        // para dar contexto material no Remarks.
        $tender->load('attachments');

        $remarks = $tender->title;
        if ($tender->reference) {
            $remarks .= ' · ref ' . $tender->reference;
        }
        if ($tender->deadline_at) {
            $remarks .= ' · deadline ' . $tender->deadline_at->format('d/m/Y H:i');
        }

        $okAttachments = $tender->attachments->where('extraction_status', 'ok');
        if ($okAttachments->isNotEmpty()) {
            $first = $okAttachments->first();
            $text  = (string) $first->extracted_text;
            // Linhas candidatas — não-vazias, tamanho razoável, sem ruído
            // tipográfico (page numbers, índices).
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\r?\n+/', $text) ?: []),
                fn($l) => mb_strlen($l) >= 8 && mb_strlen($l) <= 300
                    && !preg_match('/^\s*(page\s+\d+|table\s+of\s+contents|índice|sumário)/iu', $l)
            ));
            // Filtro por "signal" — linhas com P/N, qty, payment, ship,
            // valores monetários, deadline. Estas vão primeiro.
            $signalRe = '/(P\/N|Part\s*Number|Item\s*code|NSN|Qty|Quantity|€|US\$|EUR|USD|deadline|payment|pagamento|ship|delivery|entrega|prazo)/iu';
            $signal = [];
            $rest = [];
            foreach ($lines as $l) {
                if (preg_match($signalRe, $l)) {
                    $signal[] = mb_substr($l, 0, 140);
                } else {
                    $rest[] = mb_substr($l, 0, 140);
                }
                if (count($signal) >= 4) break;
            }
            // Se signal é fraco (≤1), completa com primeiras linhas substanciais.
            if (count($signal) < 3) {
                $signal = array_merge($signal, array_slice($rest, 0, 3 - count($signal)));
            }
            if (!empty($signal)) {
                $remarks .= ' · ' . implode(' · ', $signal);
            }
            $remarks .= ' · ' . $okAttachments->count() . ' anexos';
        }
        // 2026-05-18: sem cap local — SapService trunca em 254 sozinho
        // ao fazer POST a SAP (hard limit OOPR.Remarks varchar). O Remarks
        // construído aqui é só o payload da criação; a versão completa
        // entra no tender.notes via martaSummarize() depois.

        // Deadline: se não houver, +30 dias a partir de hoje.
        $closingDate = $tender->deadline_at
            ? $tender->deadline_at->format('Y-m-d')
            : now()->addDays(30)->format('Y-m-d');

        // 2026-05-18 fase 3: SHORTCUT directo source → CardCode quando
        // sabemos exactamente qual o BP usar. Evita o search/ranking/
        // filtro Customer-Lead que pode falhar quando o nome bate vários
        // BPs (ex.: NSPA tem 1 supplier e 2 customers, todos com
        // prefix "NSPA"). Pedido directo do operador:
        // "Anspa op codigo de clinete é C000263".
        $purchasingOrg = trim((string) $tender->purchasing_org);
        $directCardCode = Tender::cardCodeForSource($tender->source);

        if ($directCardCode) {
            // Busca o nome certo do BP a partir do CardCode para confirmar
            // que existe e mostrar no flash. Se falhar, cai para o search.
            try {
                $bp = $sap->getBusinessPartner($directCardCode);
            } catch (\Throwable $e) {
                Log::warning('Tender createSapOpp: direct cardcode lookup failed', [
                    'cardcode' => $directCardCode,
                    'error'    => $e->getMessage(),
                ]);
                $bp = null;
            }

            if ($bp && !empty($bp['CardCode'])) {
                $resolvedCardCode = (string) $bp['CardCode'];
                $resolvedCardName = (string) ($bp['CardName'] ?? $directCardCode);
                $resolvedVia      = 'source_cardcode_map';
                // Pula directo para a construção do Remarks abaixo.
                goto buildPayload;
            }
            // Se o lookup falhou, deixa o pipeline normal continuar.
        }

        $sourceBp = Tender::bpNameForSource($tender->source);

        // Estratégia de resolução em 2 passos:
        //   1. Procurar pelo purchasing_org se existir
        //   2. Se 0 hits, procurar pelo source-canonical (NSPA, NCIA, …)
        $bps = [];
        $resolvedVia = null;

        if ($purchasingOrg !== '') {
            try {
                $bps = $sap->searchBusinessPartners($purchasingOrg, 5);
                if (!empty($bps)) $resolvedVia = 'purchasing_org';
            } catch (\Throwable $e) {
                Log::warning('Tender createSapOpp: BP search by org failed', [
                    'tender_id' => $tender->id,
                    'org'       => $purchasingOrg,
                    'error'     => $e->getMessage(),
                ]);
                // Continua para o fallback de source
            }
        }

        if (empty($bps) && $sourceBp) {
            try {
                $bps = $sap->searchBusinessPartners($sourceBp, 5);
                if (!empty($bps)) {
                    $resolvedVia = 'source_fallback';
                    Log::info('Tender createSapOpp: resolved BP via source fallback', [
                        'tender_id' => $tender->id,
                        'source'    => $tender->source,
                        'bp_name'   => $sourceBp,
                        'found'     => count($bps),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Tender createSapOpp: BP search by source failed', [
                    'tender_id' => $tender->id,
                    'source'    => $tender->source,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // 2026-05-18: SAP B1 Sales Opportunities só aceita Customers (C)
        // e Leads (L) — Suppliers (F prefix, cSupplier) dão "Select
        // business partner of either customer or lead type". Filtrar
        // ANTES do ranking para evitar escolher o supplier canónico
        // (ex.: NSPA está como cSupplier F000366, mas as suas divisões
        // como cCustomer C000263 / C000273 — temos de pegar nas C's).
        $bps = collect($bps)->filter(function ($bp) {
            $type = (string) ($bp['CardType'] ?? '');
            return in_array($type, ['cCustomer', 'cLid', 'C', 'L'], true);
        })->values()->all();

        if (empty($bps)) {
            // Pode ser:
            //   (a) nenhum BP existe com esse nome
            //   (b) BPs existem mas são todos Suppliers (não Customer/Lead)
            $tried = [];
            if ($purchasingOrg !== '') $tried[] = "\"{$purchasingOrg}\"";
            if ($sourceBp)             $tried[] = "\"{$sourceBp}\" (da source {$tender->source})";
            $triedStr = $tried ? ' (tentei: ' . implode(' e ', $tried) . ')' : '';

            return back()->with('error',
                "Não encontrei nenhum Customer ou Lead no SAP B1{$triedStr}. " .
                "Nota: o SAP B1 só aceita Customers (C) ou Leads (L) em Sales Opportunities — Suppliers (F) não servem. " .
                "Resolução: 1) cria/converte o BP para Customer em SAP B1 → Negócios → Parceiros, OU " .
                "2) edita este concurso e ajusta o campo \"Organização Compradora\" para um Customer existente."
            );
        }

        // Ranking dos matches em tiers de qualidade decrescente:
        //   tier 1 — exact match (CardName igual a searchTerm)
        //   tier 2 — prefix match com separador (CardName começa com
        //            "NSPA ", "NSPA-", "NSPA — ", "NSPA:") — divisões ou
        //            sub-unidades da entidade canónica
        //   tier 3 — qualquer prefix (começa com searchTerm sem separator)
        //   tier 4 — só contains (apareceu no resultado mas não no início)
        //
        // Dentro de cada tier, escolhemos o BP com CardName mais curto —
        // é quase sempre o canónico / raiz (ex.: "NSPA - NATO SUPPORT AND
        // PROCUREMENT AGENCY" antes de "NSPA - ... - CIMO").
        $searchTerm = $resolvedVia === 'purchasing_org' ? $purchasingOrg : (string) $sourceBp;
        $needleLow  = mb_strtolower($searchTerm);

        $tier = function (array $bp) use ($needleLow): int {
            $name = mb_strtolower(trim((string) ($bp['CardName'] ?? '')));
            if ($name === $needleLow) return 1;
            // Prefix with explicit separator → almost certainly the canonical entity
            if (preg_match('/^' . preg_quote($needleLow, '/') . '[\s\-—:_]/u', $name)) return 2;
            if (str_starts_with($name, $needleLow)) return 3;
            return 4;
        };

        // Ordena: tier ASC, depois length ASC
        $rankedBps = collect($bps)
            ->map(fn($bp) => ['bp' => $bp, 'tier' => $tier($bp), 'len' => mb_strlen((string) $bp['CardName'])])
            ->sortBy([['tier', 'asc'], ['len', 'asc']])
            ->values();

        $best = $rankedBps->first()['bp'] ?? null;
        $bestTier = $rankedBps->first()['tier'] ?? 99;

        // Só pedimos input manual quando NEM SEQUER há um match prefix/exact.
        // Se há match tier 1, 2 ou 3 → usa o melhor. Tier 4 (só contains)
        // pode ser ruído (ex.: "GKN AEROSPACE" matched "NSPA" porque tem
        // a sequência "NSP" algures), por isso desambiguamos.
        if (!$best || $bestTier >= 4) {
            $opts = $rankedBps->take(3)->map(fn($r) => "{$r['bp']['CardCode']} → {$r['bp']['CardName']}")->implode(' | ');
            return back()->with('error',
                "Não tenho um match claro para \"{$searchTerm}\" no SAP B1. " .
                "Encontrei só matches fracos. Edita o concurso e ajusta \"Organização Compradora\" para um nome mais específico. Sugestões: {$opts}"
            );
        }

        $bp = $best;
        $resolvedCardCode = (string) $bp['CardCode'];
        $resolvedCardName = (string) $bp['CardName'];

        // Se resolvemos via source_fallback (porque purchasing_org estava
        // vazio ou não bateu), auto-preencher purchasing_org com o nome
        // certo do SAP para que da próxima vez bate logo. Pequena ajuda
        // de qualidade-de-dados sem pedir nada ao operador.
        if ($resolvedVia === 'source_fallback' && trim((string) $tender->purchasing_org) === '') {
            $tender->purchasing_org = $resolvedCardName;
            $tender->save();
        }

        buildPayload:
        // 2026-05-18: Information Source inferido do título + descrição
        // + anexo extracted_text. Códigos 100-120 mapeados em
        // Tender::SAP_SOURCE_CATEGORIES. Fallback 106 OUTROS.
        $informationSource = $tender->inferSapInformationSource();

        // 2026-05-19: resolução do SalesPerson (OSLP.SlpCode). Pedido
        // directo do operador "Marta não está a pôr o vendedor correto
        // quando abre uma oportunidade no SAP". Política:
        //   1. Se o tender tem collaborator com user_id → usa esse user
        //   2. Senão → usa o operador autenticado que clicou "Pedir à Marta"
        //   3. Tenta resolver o User name no SAP via searchSalesEmployee
        //   4. Falha → não passa SalesPerson (SAP usa default)
        $salesPersonCode = $this->resolveSalesPersonForTender($tender, $sap);

        $payload = [
            'OpportunityName'     => mb_substr($tender->title, 0, 100),
            'Remarks'             => $remarks,
            'CardCode'            => $resolvedCardCode,        // pre-resolved
            'CardName'            => $resolvedCardName,
            'ExpectedClosingDate' => $closingDate,
            'MaxLocalTotal'       => 1.0,   // placeholder mínimo (SAP requer >0); operador acerta depois
            // 2026-05-18: fase inicial passou de 1 (Prospecção) → 5 (Cotação de
            // Compra). Pedido directo do operador: quando se abre uma Opp via
            // ClawYard, é porque já chegou um RFP/RFQ — o concurso já passou da
            // fase exploratória de "prospecção", está em "vamos cotar a compra
            // aos fornecedores antes de propor ao cliente". Lifecycle SAP B1:
            //   1 Prospecção → 5 Cotação Compra → 6 Cotação Venda → 7 Follow Up
            //   → 8 Possível Venda → 9 Ordem Compra → 10 Ordem Venda
            'StageId'             => 5,     // Cotação de Compra (era 1 Prospecção)
            'InformationSource'   => $informationSource,
        ];

        if ($salesPersonCode !== null) {
            $payload['SalesPerson'] = $salesPersonCode;
        }

        try {
            $result = $sap->createOpportunity($payload);
        } catch (\Throwable $e) {
            Log::error('Tender createSapOpp: exception', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Excepção a criar SAP Opp: ' . $e->getMessage());
        }

        if (!$result || empty($result['SequentialNo'])) {
            $err = $sap->getLastError() ?: 'erro desconhecido';
            return back()->with('error', "SAP rejeitou criação (BP {$resolvedCardCode} resolvido OK): {$err}");
        }

        // Auto-link da SAP Opp ao tender.
        $tender->sap_opportunity_number = (string) $result['SequentialNo'];
        $tender->last_sap_sync_at       = now();
        $tender->save();

        // 2026-05-19: agora que o tender tem sap_opportunity_number, a
        // pasta QNAP "PartYard_{Y}_SAP/CLIENTES/{SOURCE}/{SAP_NUM} - …"
        // recebe o nome correcto. Re-espelha todos os anexos para a nova
        // pasta (idempotente — não duplica os já lá presentes).
        try {
            app(\App\Services\TenderQnapMirror::class)->mirrorAll($tender);
        } catch (\Throwable $e) { /* best-effort */ }

        $viaNote = match ($resolvedVia) {
            'source_cardcode_map' => " (BP mapeado directamente para a fonte {$tender->source})",
            'source_fallback'     => " (BP detectado automaticamente a partir da fonte {$tender->source})",
            default               => '',
        };

        return redirect()
            ->route('tenders.show', $tender)
            ->with('status', "✅ SAP Opportunity #{$result['SequentialNo']} criada para {$resolvedCardName} ({$resolvedCardCode}){$viaNote} e ligada a este concurso. Vai ao SAP B1 → CRM → Sales Opportunities para acertar valor e fase.");
    }

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

    /**
     * Bulk status change. Manager+ only (mesmo gate da bulk assign).
     * Aceita N tender_ids + 1 status novo, faz UPDATE en masse + audit log.
     *
     * Pedido directo 2026-05-20: "bulk actions com checkboxes + barra
     * para atribuir / mudar status / exportar selecção". Atribuição já
     * existia (assign()); este endpoint cobre mudança de estado.
     */
    public function bulkStatus(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        if (!$user || !$user->can('tenders.assign')) {
            abort(403, 'Apenas manager+ pode mudar status em massa.');
        }

        $allowedStatuses = [
            Tender::STATUS_PENDING,
            Tender::STATUS_EM_TRATAMENTO,
            Tender::STATUS_SUBMETIDO,
            Tender::STATUS_AVALIACAO,
            Tender::STATUS_CANCELADO,
            Tender::STATUS_NAO_TRATAR,
            Tender::STATUS_GANHO,
            Tender::STATUS_PERDIDO,
        ];

        $data = $request->validate([
            'tender_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'tender_ids.*' => ['integer'],
            'status'       => ['required', 'string', 'in:' . implode(',', $allowedStatuses)],
        ]);

        $ids     = array_values(array_map('intval', $data['tender_ids']));
        $newSt   = $data['status'];
        $updated = Tender::whereIn('id', $ids)->update([
            'status'     => $newSt,
            'updated_at' => now(),
        ]);

        \App\Models\AuditLog::record('tender.bulk_status', null, [
            'tender_ids' => $ids,
            'count'      => $updated,
            'status'     => $newSt,
        ]);

        return back()->with('status', "✓ {$updated} concurso(s) marcados como «{$newSt}».");
    }

    /**
     * "⚓ Plano Marine" — alternativa leve ao multi-agente para tenders
     * marítimos. 1 LLM call extrai serviço + peças + fornecedores +
     * drafts de email prontos a enviar. Pedido directo 2026-05-20.
     */
    public function marineActionPack(
        Tender $tender,
        \App\Services\MarineActionPackService $service
    ): \Illuminate\Http\JsonResponse {
        $user = Auth::user();
        $this->enforceVisibility($tender, $user);

        if ($tender->source !== 'marine') {
            return response()->json([
                'ok' => false,
                'error' => 'Plano Marine é só para tenders source=marine. Usa o painel multi-agente em Concursos.',
            ], 422);
        }

        try {
            $result = $service->generate($tender);
        } catch (\Throwable $e) {
            Log::error('marineActionPack failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'Erro: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json($result);
    }

    /**
     * Soft-delete de 1 tender. Manager+ only.
     * Reversível via `Tender::withTrashed()->restore()` se for engano.
     *
     * Pedido 2026-05-20: "poe um botao par apagar" — UI para limpar
     * zombies (paste falhados, RFQs duplicados, etc.) sem precisar
     * de ir ao tinker.
     */
    public function destroy(Tender $tender): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        if (!$user || !$user->can('tenders.assign')) {
            abort(403, 'Apenas manager+ pode apagar concursos.');
        }

        $ref = $tender->reference ?: ('#' . $tender->id);
        $isMarine = $tender->source === 'marine';
        $tender->delete();  // SoftDelete — deleted_at preenchido

        \App\Models\AuditLog::record('tender.delete', null, [
            'tender_id' => $tender->id,
            'reference' => $ref,
            'source'    => $tender->source,
        ]);

        return redirect()
            ->to($isMarine ? route('marine.index') : route('tenders.index'))
            ->with('status', "🗑 Concurso «{$ref}» apagado. (recuperável via DB withTrashed)");
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

        $collaborator = $collaboratorId
            ? TenderCollaborator::with('user')->find($collaboratorId)
            : null;
        $label = $collaborator?->name ?? ($collaboratorId ? 'colaborador' : '(sem atribuição)');

        // #8 — Audit assignment for compliance
        \App\Models\AuditLog::record('tender.assign', null, [
            'tender_ids'      => $ids,
            'count'           => $updated,
            'collaborator_id' => $collaboratorId,
            'collaborator'    => $label,
        ]);

        // ── Assignment notification email ──────────────────────────────────
        //
        // User flagged: "os users do dashboard com os processos atribuídos
        // não recebem email para confirmar e entrar". Nothing was being
        // dispatched on assign — the assignee only found out next time they
        // logged in, or via the scheduled digest the next morning.
        //
        // We send one email per bulk-assign POST (not one per tender), to
        // the collaborator's digest_email (explicit `email` column wins,
        // else their linked User's email). Failures are logged but do NOT
        // roll back the assignment — the local DB is still the source of
        // truth; the email is a courtesy notification.
        //
        // Skipped when:
        //   - collaborator_id is null (this is an un-assignment, no one to notify)
        //   - collaborator has no digest_email at all
        //   - $updated is 0 (nothing actually changed, e.g. re-submit)
        // Look up the portal URL for this collaborator's email, if any —
        // used below in the flash so the manager sees exactly where the
        // assignee will see the tenders ("ficar em memória"). We pick the
        // most recent active portal_token for any active AgentShare whose
        // client_email (or additional_emails) matches.
        $portalUrl = null;
        if ($collaborator && $collaborator->digest_email) {
            $portalUrl = $this->findPortalUrlFor($collaborator->digest_email);
        }

        $emailStatus = null;
        if ($collaborator && $updated > 0) {
            $toEmail = $collaborator->digest_email;
            if (!$toEmail) {
                $emailStatus = "⚠ {$collaborator->name} não tem email configurado — notificação não enviada.";
                Log::info('Tender assign: skipped email (no address)', [
                    'collaborator_id' => $collaborator->id,
                    'tender_ids'      => $ids,
                ]);
            } else {
                try {
                    $assignedTenders = Tender::whereIn('id', $ids)->get();
                    Mail::to($toEmail)->send(
                        new TenderAssignedNotification($collaborator, $assignedTenders, $user)
                    );
                    $emailStatus = "📧 Notificação enviada para {$toEmail}.";
                } catch (\Throwable $e) {
                    Log::warning('Tender assign: email dispatch failed', [
                        'collaborator_id' => $collaborator->id,
                        'to'              => $toEmail,
                        'tender_ids'      => $ids,
                        'error'           => $e->getMessage(),
                    ]);
                    $emailStatus = "⚠ Falha ao enviar email para {$toEmail}: {$e->getMessage()}";
                }
            }
        }

        // Pass the just-affected IDs to the view as a one-shot flash so
        // the rows get a visual pulse/highlight after redirect. User rule:
        // "quando faco atribuicao do projecto via este dashboard a um user
        // deve ficar um quadrado com um pisco para saber que foi atribuido".
        //
        // Preserve the filter/pagination state the user was on so they land
        // back on the same view. The form ships these as `return_<key>`
        // hidden inputs (because the page filters are GET params, not part
        // of the form POST body). Without this, the user was dropped on an
        // unfiltered /tenders and the just-assigned rows could end up on
        // page 2+ — which looked like "the pisco never happened".
        $returnParams = [];
        foreach (['source', 'status', 'urgency', 'collaborator_id', 'q', 'sort', 'dir', 'page'] as $k) {
            $v = $request->input("return_{$k}");
            if ($v !== null && $v !== '') $returnParams[$k] = $v;
        }

        $flash = "{$updated} concursos atribuídos a {$label}.";
        if ($emailStatus) $flash .= ' · ' . $emailStatus;
        if ($portalUrl)   $flash .= " · 📫 Visível no portal: {$portalUrl}";

        return redirect()
            ->route('tenders.index', $returnParams)
            ->with('status', $flash)
            ->with('just_assigned', $ids)
            ->with('just_assigned_label', $label);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Resolve the AgentShare portal URL that a given email (the
     * collaborator's digest_email) has access to, if any.
     *
     * Used to enrich the bulk-assign flash so the admin knows where the
     * assignee will actually see the tenders they were just given.
     * Returns the absolute `/p/{portalToken}` URL of the most-recently-
     * created active share that includes this email among its authorised
     * recipients (primary client_email OR additional_emails).
     *
     * Returns null when:
     *   - email is blank / malformed
     *   - no active shares match
     *   - the matching share has no portal_token (single-agent share)
     */
    /**
     * 2026-05-19 — pedido directo do operador
     *   "Marta não está a pôr o vendedor correto quando abre uma
     *    oportunidade no SAP"
     *
     * Resolve o SAP SalesEmployeeCode (OSLP.SlpCode) que deve ficar
     * gravado na OOPR ao criar a Oportunidade via "Pedir à Marta".
     *
     * Política de selecção:
     *   1. Se o tender tem collaborator com user_id → usa esse User
     *      (a pessoa que foi atribuída ao concurso)
     *   2. Caso contrário → o operador autenticado que clicou no botão
     *
     * O nome do User é depois procurado no SAP via searchSalesEmployee.
     * Match exacto preferido; senão melhor parcial. Cache de 1h por nome
     * via SapService::searchSalesEmployee (já implementado).
     *
     * Devolve null se:
     *   - sem User identificável
     *   - SAP devolve 0 matches para o nome
     *   - SAP em erro (rede / 5xx)
     * Nesse caso o SAP usa o default — não bloqueia a criação.
     */
    private function resolveSalesPersonForTender(Tender $tender, SapService $sap): ?int
    {
        // 1. Resolve User candidato — collaborator atribuído tem prioridade
        $user = null;
        try {
            $collab = $tender->collaborator;
            if ($collab && $collab->user_id) {
                $user = \App\Models\User::find($collab->user_id);
            }
        } catch (\Throwable $e) { /* relação opcional */ }
        if (!$user) {
            $user = \Illuminate\Support\Facades\Auth::user();
        }
        if (!$user || !$user->name) {
            Log::info('Tender createSapOpp: no user to resolve SalesPerson', [
                'tender_id' => $tender->id,
            ]);
            return null;
        }

        // 2. Procura no SAP por nome (full name primeiro, depois apelido)
        $userName = trim((string) $user->name);
        $cleanedFull = preg_replace('/\s*\([^\)]*\)\s*/', ' ', $userName); // remove "(PartYard)"
        $cleanedFull = trim(preg_replace('/\s+/', ' ', $cleanedFull) ?: $userName);

        $tries = [$cleanedFull];
        // Adicionar apenas apelido se nome tiver 2+ palavras (improves match)
        $parts = preg_split('/\s+/', $cleanedFull) ?: [];
        if (count($parts) >= 2) {
            $tries[] = end($parts); // apelido único
            $tries[] = $parts[0];   // primeiro nome
        }

        foreach (array_unique($tries) as $needle) {
            try {
                $matches = $sap->searchSalesEmployee($needle, 5);
            } catch (\Throwable $e) {
                Log::warning('Tender createSapOpp: searchSalesEmployee failed', [
                    'needle' => $needle,
                    'error'  => $e->getMessage(),
                ]);
                continue;
            }
            if (empty($matches)) continue;

            // Match exacto (case-insensitive) preferido
            $needleLow = mb_strtolower($needle);
            foreach ($matches as $m) {
                if (mb_strtolower((string) ($m['FullName'] ?? '')) === $needleLow) {
                    Log::info('Tender createSapOpp: SalesPerson resolved (exact)', [
                        'user'    => $userName,
                        'needle'  => $needle,
                        'sap_code'=> $m['EmployeeID'] ?? null,
                        'sap_name'=> $m['FullName']   ?? null,
                    ]);
                    return (int) ($m['EmployeeID'] ?? 0) ?: null;
                }
            }
            // Senão devolve o primeiro hit
            $first = $matches[0];
            $code  = (int) ($first['EmployeeID'] ?? 0);
            if ($code > 0) {
                Log::info('Tender createSapOpp: SalesPerson resolved (partial)', [
                    'user'    => $userName,
                    'needle'  => $needle,
                    'sap_code'=> $code,
                    'sap_name'=> $first['FullName'] ?? null,
                ]);
                return $code;
            }
        }

        Log::warning('Tender createSapOpp: no SalesPerson match in SAP', [
            'tender_id' => $tender->id,
            'user_name' => $userName,
            'tries'     => $tries,
        ]);
        return null;
    }

    private function findPortalUrlFor(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        try {
            $candidates = AgentShare::query()
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
                ->whereNotNull('portal_token')
                ->where(function ($q) use ($email) {
                    $q->whereRaw('LOWER(client_email) = ?', [$email])
                      ->orWhere('additional_emails', 'LIKE', '%' . $email . '%');
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            // Double-check additional_emails via the model accessor in case
            // the LIKE picked up a false positive from a similar substring.
            foreach ($candidates as $share) {
                $authorised = array_map('strtolower', $share->authorisedEmails());
                if (in_array($email, $authorised, true)) {
                    $base = rtrim(config('app.url') ?: URL::to('/'), '/');
                    return $base . '/p/' . $share->portal_token;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('findPortalUrlFor failed', ['email' => $email, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function enforceVisibility(Tender $tender, $user): void
    {
        if ($user->can('tenders.view-all')) return;

        // 2026-05-19: sources públicos internos (Acingov/Vortal/Anogov)
        // são visíveis a todos. Pedido directo da admin Monica.
        if (in_array($tender->source, Tender::PUBLIC_SOURCES, true)) return;

        $collab = $tender->collaborator;
        if (!$collab || $collab->user_id !== $user->id) {
            abort(403, 'Este concurso não está atribuído a si.');
        }
    }

    private function parseFilters(Request $r, ?string $forceSource = null): array
    {
        // 2026-05-19 v3: Monica pediu "o normal e sempre concursos nspa".
        // Quando o user chega à /tenders SEM nenhum filtro source/status/
        // urgency/q/collab, o sistema aplica source=nspa como default.
        // Se o user clica em "Todas as fontes" no dropdown, esse value=''
        // chega como string vazia e nao re-activa o default.
        //
        // $forceSource (2026-05-19 v4): quando o caller é a route /marine,
        // forçar source=marine ignorando completamente o que vem na URL.
        // Permite ter um sub-dashboard isolado para Marine Department.
        if ($forceSource !== null) {
            return [
                'source'           => $forceSource,
                'status'           => $r->string('status')->trim()->value() ?: null,
                'urgency'          => $r->string('urgency')->trim()->value() ?: null,
                'collaborator_id'  => $r->integer('collaborator_id') ?: null,
                'q'                => $r->string('q')->trim()->value() ?: null,
            ];
        }

        $hasAnyFilter = $r->has('source')
                     || $r->filled('status')
                     || $r->filled('urgency')
                     || $r->filled('collaborator_id')
                     || $r->filled('q');
        $sourceRaw = $r->string('source')->trim()->value();
        $source    = $sourceRaw !== '' ? $sourceRaw
                                       : ($hasAnyFilter ? null : 'nspa');

        return [
            'source'           => $source,
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
    /**
     * Export the tenders dashboard to CSV (Excel-compatible).
     * Streamed (chunk-based) so 10k+ rows não rebentam memória.
     *
     * Aplica os MESMOS filtros que index() para que o ficheiro
     * reflicta o que o admin está a ver. Manager+ vê tudo; user
     * normal vê só os seus.
     *
     * BOM (UTF-8 byte order mark) garante que acentos portugueses
     * abrem correctamente no Excel sem o operador ter de mudar
     * encoding manualmente.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user       = Auth::user();
        $canViewAll = $user->can('tenders.view-all');

        $filters = $this->parseFilters($request);
        $sort    = $this->validateSort($request->string('sort')->trim()->value() ?: null);
        $dir     = $request->string('dir')->trim()->value() === 'desc' ? 'desc' : 'asc';

        $query = Tender::query()->with(['collaborator.user']);
        if (!$canViewAll) $query->forUser($user->id);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $sort, $dir);

        $filename = 'clawyard-concursos-' . now()->format('Y-m-d-Hi') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para Excel reconhecer acentos
            fwrite($out, "\xEF\xBB\xBF");
            // Header row
            fputcsv($out, [
                'ID', 'Referência', 'Fonte', 'Título', 'Status', 'Prioridade',
                'Tipo', 'Organização', 'Deadline', 'Dias até deadline',
                'Colaborador', 'Email colab.', 'Atribuído em',
                'Nº Oportunidade SAP', 'Última sync SAP',
                'Categorias', 'Confidencial', 'Valor proposta', 'Moeda',
                'Resultado', 'Notas', 'Importado em', 'Última actualização',
            ], ';');

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    $cats = '';
                    if ($t->prelim_analysis && is_array($t->prelim_analysis['categories'] ?? null)) {
                        $cats = implode(',', $t->prelim_analysis['categories']);
                    }
                    $colab = $t->collaborator;
                    $now = now();
                    $daysToDeadline = $t->deadline_at
                        ? (int) round($t->deadline_at->floatDiffInDays($now, false))
                        : null;

                    fputcsv($out, [
                        $t->id,
                        $t->reference,
                        $t->source,
                        $t->title,
                        $t->status,
                        $t->priority,
                        $t->type,
                        $t->purchasing_org,
                        $t->deadline_at?->format('Y-m-d H:i'),
                        $daysToDeadline,
                        $colab?->name ?? $colab?->user?->name ?? '',
                        $colab?->email ?? $colab?->user?->email ?? '',
                        $t->assigned_at?->format('Y-m-d H:i'),
                        $t->sap_opportunity_number,
                        $t->last_sap_sync_at?->format('Y-m-d H:i'),
                        $cats,
                        $t->is_confidential ? 'sim' : 'não',
                        $t->offer_value,
                        $t->currency,
                        $t->result,
                        mb_substr((string) $t->notes, 0, 1000),
                        $t->created_at?->format('Y-m-d H:i'),
                        $t->updated_at?->format('Y-m-d H:i'),
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-cache, no-store',
        ]);
    }

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
        // 2026-05-19: source agora pode ser cabeça de grupo. Pedido
        // directo: "Junta o Acingov e Vortal e Anogov tudo Acingov/
        // Vortal/Anogov". Filtro expande para WHERE source IN (...).
        if ($f['source']) {
            $expanded = Tender::expandSourceFilter($f['source']);
            $query->whereIn('source', $expanded);
        }
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
    private function dashboardStats(?int $userId, ?string $source = null): array
    {
        $base = Tender::query();
        if ($userId) $base->forUser($userId);

        // 2026-05-20: /marine deve mostrar stats só da sua secção
        // (antes contava tudo, fazendo o ring "Em curso" mostrar 100
        // mesmo quando Marine só tinha 1 tender).
        if ($source) $base->where('source', $source);

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
