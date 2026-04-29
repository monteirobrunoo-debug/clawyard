@php
    /**
     * Concursos dashboard — dual landing:
     *   - "Mine" strip: the logged-in user's own active tenders, deadline-sorted.
     *   - "All" table: paginated full list (manager+) or own list (regular user),
     *     with filters + bulk-assign for manager+.
     *
     * The five urgency buckets map to Tailwind chip colours. Deadlines are shown
     * in BOTH Europe/Lisbon and Europe/Luxembourg wall-clock (stored UTC).
     */
    $urgencyClasses = [
        'expired'  => 'bg-gray-200 text-gray-800 border-gray-400 line-through',
        'overdue'  => 'bg-red-100 text-red-800 border-red-300',
        'critical' => 'bg-orange-100 text-orange-800 border-orange-300',
        'urgent'   => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'soon'     => 'bg-blue-100 text-blue-800 border-blue-300',
        'normal'   => 'bg-gray-100 text-gray-700 border-gray-300',
        'unknown'  => 'bg-gray-50 text-gray-500 border-gray-200',
    ];
    // One-shot flash after bulk-assign — list of tender IDs that were
    // just attributed. The view lights up matching rows with a pulse
    // animation so the user can see at a glance what they changed.
    $justAssigned      = array_map('intval', session('just_assigned', []));
    $justAssignedLabel = session('just_assigned_label');

    $statusLabels = [
        \App\Models\Tender::STATUS_PENDING       => 'Pendente',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'Em Tratamento',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'Submetido',
        \App\Models\Tender::STATUS_AVALIACAO     => 'Em Avaliação',
        \App\Models\Tender::STATUS_CANCELADO     => 'Cancelado',
        \App\Models\Tender::STATUS_NAO_TRATAR    => 'Não Tratar',
        \App\Models\Tender::STATUS_GANHO         => 'Ganho',
        \App\Models\Tender::STATUS_PERDIDO       => 'Perdido',
    ];

    // User feedback: "a tabela deveria ter o estado a ver, tem um grande gap
    // nas linhas em extenso branco". Flat gray chips were invisible in the
    // whitespace. Each status now has its own colour so the Estado column
    // reads at a glance instead of disappearing between the title and the
    // deadline. Ordered by typical pipeline flow: inbox → active → closed.
    $statusStyles = [
        \App\Models\Tender::STATUS_PENDING       => 'bg-gray-100 text-gray-800 border-gray-300',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'bg-blue-100 text-blue-800 border-blue-300',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'bg-indigo-100 text-indigo-800 border-indigo-300',
        \App\Models\Tender::STATUS_AVALIACAO     => 'bg-amber-100 text-amber-900 border-amber-300',
        \App\Models\Tender::STATUS_GANHO         => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        \App\Models\Tender::STATUS_PERDIDO       => 'bg-red-100 text-red-800 border-red-300',
        \App\Models\Tender::STATUS_CANCELADO     => 'bg-gray-200 text-gray-600 border-gray-300 line-through',
        \App\Models\Tender::STATUS_NAO_TRATAR    => 'bg-stone-100 text-stone-600 border-stone-300',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Concursos
            </h2>
            <div class="flex items-center gap-2">
                @if($canViewAll)
                    <a href="{{ route('tenders.overview') }}"
                       class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        🔎 Partilhados
                    </a>
                @endif
                @if($canAssign)
                    <a href="{{ route('tenders.collaborators.index') }}"
                       class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        👥 Colaboradores
                    </a>
                @endif
                @if($canImport)
                    <a href="{{ route('tenders.import.create') }}"
                       class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Importar Excel
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    {{-- ─── Just-assigned pulse animation ──────────────────────────────
         Runs ~5s (4 iterations × 1.2s), then settles into a persistent
         indigo left-border so the row is still findable on the page after
         the blink stops. Matches the user's "quadrado com um pisco"
         request — a visible square marker that pulses.
    --}}
    <style>
        @keyframes just-assigned-pulse {
            0%, 100% { background-color: rgb(238 242 255); }  /* indigo-50 */
            50%      { background-color: rgb(199 210 254); }  /* indigo-200 */
        }
        tr.just-assigned {
            animation: just-assigned-pulse 1.2s ease-in-out 4;
            border-left: 4px solid rgb(79 70 229);            /* indigo-600 */
            background-color: rgb(238 242 255);               /* indigo-50 after animation */
        }
        tr.just-assigned > td:first-child {
            position: relative;
        }
        .just-assigned-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.1rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.65rem;
            font-weight: 600;
            color: rgb(55 48 163);                            /* indigo-800 */
            background: rgb(224 231 255);                     /* indigo-100 */
            border: 1px solid rgb(165 180 252);               /* indigo-300 */
            animation: just-assigned-pulse 1.2s ease-in-out 4;
        }
    </style>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

            {{-- ─── Flash status ──────────────────────────────────────────── --}}
            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                    @if(count($justAssigned) > 0)
                        <span class="ml-2 text-xs text-green-700">
                            — {{ count($justAssigned) }} linha(s) marcada(s) a pisco.
                        </span>
                    @endif
                </div>
            @endif

            {{-- ─── Just-assigned banner ──────────────────────────────────
                 Even if the rows aren't on the current page (different
                 filter / pagination), the user sees exactly what they just
                 changed with a direct link to each. Solves the "atribui mas
                 não vejo o pisco" complaint — the pulse only fires on rows
                 rendered on-screen, but the banner proves the write happened
                 and lets the user jump to each affected tender. --}}
            @if(count($justAssigned) > 0)
                @php
                    $justAssignedTenders = \App\Models\Tender::whereIn('id', $justAssigned)
                        ->with('collaborator')
                        ->get()
                        ->keyBy('id');
                @endphp
                <div id="just-assigned-banner"
                     class="rounded-md bg-indigo-50 border border-indigo-200 p-4 text-sm text-indigo-900">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold">
                                ✨ {{ count($justAssigned) }}
                                {{ count($justAssigned) === 1 ? 'concurso atribuído' : 'concursos atribuídos' }}
                                @if($justAssignedLabel)
                                    a <span class="underline">{{ $justAssignedLabel }}</span>
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($justAssigned as $jid)
                                    @php $jt = $justAssignedTenders[$jid] ?? null; @endphp
                                    @if($jt)
                                        <a href="{{ route('tenders.show', $jt) }}"
                                           class="inline-flex items-center gap-1 rounded border border-indigo-300 bg-white px-2 py-1 text-xs font-mono text-indigo-800 hover:bg-indigo-100">
                                            {{ $jt->reference ?: ('#' . $jt->id) }}
                                            <span class="text-indigo-500">→</span>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        <button type="button" onclick="document.getElementById('just-assigned-banner').remove()"
                                class="shrink-0 text-xs text-indigo-600 hover:text-indigo-800">
                            dispensar ✕
                        </button>
                    </div>
                </div>
            @endif

            {{-- ─── Stat cards ──────────────────────────────────────────────
                 All numbers reflect the "live pipeline" — active status
                 (pending/em_tratamento/submetido/avaliação) AND not expired
                 past the {{ \App\Models\Tender::OVERDUE_WINDOW_DAYS }}-day overdue window. Terminal
                 statuses (ganho/perdido/cancelado/não tratar) and long-
                 expired rows are intentionally excluded so the headline
                 reflects actionable work, not lifetime imports. --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach([
                    ['label' => 'Em curso',               'hint' => 'activos + dentro do prazo', 'value' => $stats['total'],       'color' => 'text-gray-800'],
                    ['label' => 'Dentro do prazo',        'hint' => 'deadline no futuro',        'value' => $stats['active'],      'color' => 'text-indigo-700'],
                    ['label' => 'Em atraso ≤'.\App\Models\Tender::OVERDUE_WINDOW_DAYS.'d', 'hint' => 'ainda recuperáveis', 'value' => $stats['overdue'], 'color' => 'text-red-600'],
                    ['label' => 'Urgentes ≤7d',           'hint' => 'deadline em ≤7 dias',       'value' => $stats['urgent'],      'color' => 'text-orange-600'],
                    ['label' => 'Sem nº SAP',             'hint' => 'atribuídos, sem oportunidade', 'value' => $stats['needing_sap'], 'color' => 'text-yellow-700'],
                ] as $card)
                    <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-100">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                        <div class="mt-1 text-2xl font-bold {{ $card['color'] }}">{{ $card['value'] }}</div>
                        <div class="mt-0.5 text-[10px] text-gray-400">{{ $card['hint'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- ─── Source-restriction transparency banner ──────────────────
                 Shown ONLY to users whose collaborator row has a non-NULL
                 allowed_sources. Without this they'd see a partial list and
                 wonder why an expected tender is missing — the banner makes
                 the filter explicit and points them at the admin to widen
                 access. Hidden for managers (canViewAll) and for users with
                 no restriction. --}}
            @if($restriction)
                @php
                    $sourceLabels = [
                        'nspa' => 'NSPA', 'nato' => 'NATO', 'sam_gov' => 'SAM.gov',
                        'ncia' => 'NCIA', 'acingov' => 'Acingov', 'vortal' => 'Vortal',
                        'ungm' => 'UNGM', 'unido' => 'UNIDO', 'other' => 'Outras',
                    ];
                @endphp
                @if($restriction['mode'] === 'whitelist')
                    <div class="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm flex items-start gap-3">
                        <span class="text-indigo-600 font-semibold">ℹ</span>
                        <div class="flex-1">
                            <div class="text-indigo-900">
                                Vês apenas concursos das fontes:
                                @foreach($restriction['sources'] as $s)
                                    <span class="inline-flex items-center px-2 py-0.5 mx-0.5 rounded-md bg-indigo-100 text-indigo-800 text-xs font-medium border border-indigo-200">{{ $sourceLabels[$s] ?? strtoupper($s) }}</span>
                                @endforeach
                            </div>
                            <div class="text-indigo-700/80 text-xs mt-0.5">
                                Para acederes a fontes adicionais, contacta um administrador.
                            </div>
                        </div>
                    </div>
                @else
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm flex items-start gap-3">
                        <span class="text-amber-700 font-semibold">⚠</span>
                        <div class="flex-1">
                            <div class="text-amber-900 font-medium">
                                Estás bloqueado de todas as fontes — não vês concursos.
                            </div>
                            <div class="text-amber-700/80 text-xs mt-0.5">
                                Contacta um administrador para activar pelo menos uma fonte.
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            {{-- ─── "Os meus concursos" mini-table ──────────────────────────
                 Same column structure as the full /tenders table (fonte,
                 título, colaborador, estado, deadline) so a regular user
                 sees a consistent layout regardless of role. Asked for
                 explicitly 2026-04-27 — users were getting a different,
                 less informative strip than the managers' view. --}}
            @if($mine->count() > 0)
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                    <header class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                        <h3 class="text-sm font-semibold text-gray-800">
                            Os meus concursos activos
                            <span class="ml-2 text-xs font-normal text-gray-500">({{ $mine->count() }})</span>
                        </h3>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">Fonte</th>
                                    <th class="px-3 py-2 text-left">Título</th>
                                    <th class="px-3 py-2 text-left">Colaborador</th>
                                    <th class="px-3 py-2 text-left">Estado</th>
                                    <th class="px-3 py-2 text-left">Nº SAP</th>
                                    <th class="px-3 py-2 text-left">Deadline</th>
                                    <th class="px-3 py-2 text-right">Prazo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($mine as $t)
                                    <tr class="hover:bg-gray-50 align-middle">
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            <div class="text-xs font-semibold uppercase text-gray-600">{{ $t->source }}</div>
                                            <div class="text-xs font-mono text-gray-500">{{ $t->reference }}</div>
                                        </td>
                                        <td class="px-3 py-2 max-w-md">
                                            <a href="{{ route('tenders.show', $t) }}" class="text-indigo-700 hover:underline font-medium">
                                                {{ \Illuminate\Support\Str::limit($t->title, 90) }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $t->collaborator?->name ?? '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            <span class="inline-flex items-center rounded-md border px-2 py-1 text-xs font-semibold {{ $statusStyles[$t->status] ?? 'bg-gray-100 text-gray-700 border-gray-300' }}">
                                                {{ $statusLabels[$t->status] ?? $t->status }}
                                            </span>
                                        </td>
                                        {{-- Nº SAP — coluna explícita para o user ver de imediato
                                             quais concursos estão linkados ao SAP. Os utilizadores
                                             pediram esta coluna depois de não saberem se as suas
                                             notes iam sincronizar (sem sap_opp = não sincroniza). --}}
                                        <td class="px-3 py-2 whitespace-nowrap font-mono text-xs">
                                            @if($t->sap_opportunity_number)
                                                <span class="inline-flex items-center rounded bg-green-50 border border-green-200 px-2 py-0.5 text-green-800" title="Notas guardadas aqui sincronizam com SAP Opp #{{ $t->getSapSequentialNo() }}">
                                                    ✓ {{ $t->sap_opportunity_number }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded bg-yellow-50 border border-yellow-200 px-2 py-0.5 text-yellow-800" title="Sem oportunidade SAP — notas guardam-se só localmente. Preenche o campo no detalhe do concurso para activar sincronização.">
                                                    ⚠ sem nº
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                            @if($t->deadline_at)
                                                {{ $t->deadline_lisbon->format('d/m/y H:i') }}
                                            @else
                                                <span class="text-gray-400">sem deadline</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-right">
                                            <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                                @if($t->urgency_bucket === 'overdue')
                                                    -{{ abs($t->days_to_deadline) }}d
                                                @elseif($t->days_to_deadline !== null)
                                                    {{ $t->days_to_deadline }}d
                                                @else
                                                    —
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            {{-- ─── Filters ───────────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-4">
                <form method="GET" action="{{ route('tenders.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                    <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Pesquisar título / ref / nº SAP"
                           class="sm:col-span-2 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

                    <select name="source" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Todas as fontes</option>
                        @foreach(\App\Models\Tender::SOURCES as $src)
                            <option value="{{ $src }}" @selected($filters['source'] === $src)>{{ strtoupper($src) }}</option>
                        @endforeach
                    </select>

                    <select name="status" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Todos estados</option>
                        @foreach($statusLabels as $k => $label)
                            <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <select name="urgency" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Qualquer urgência</option>
                        <option value="overdue"  @selected($filters['urgency'] === 'overdue')>Em atraso ≤{{ \App\Models\Tender::OVERDUE_WINDOW_DAYS }}d</option>
                        <option value="expired"  @selected($filters['urgency'] === 'expired')>Expirados &gt;{{ \App\Models\Tender::OVERDUE_WINDOW_DAYS }}d</option>
                        <option value="critical" @selected($filters['urgency'] === 'critical')>Críticos ≤3d</option>
                        <option value="urgent"   @selected($filters['urgency'] === 'urgent')>Urgentes ≤7d</option>
                        <option value="soon"     @selected($filters['urgency'] === 'soon')>Brevemente ≤14d</option>
                        <option value="normal"   @selected($filters['urgency'] === 'normal')>Normal &gt;14d</option>
                    </select>

                    @if($canAssign)
                        <select name="collaborator_id" class="rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="">Todos colaboradores</option>
                            @foreach($collaborators as $c)
                                <option value="{{ $c->id }}" @selected((int)$filters['collaborator_id'] === $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    @endif

                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Filtrar
                        </button>
                        <a href="{{ route('tenders.index') }}"
                           class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            Limpar
                        </a>
                    </div>
                </form>
            </section>

            {{-- ─── All tenders table ─────────────────────────────────────── --}}
            <form method="POST" action="{{ route('tenders.assign') }}" id="bulk-assign-form">
                @csrf
                {{-- Preserve the current filter/pagination state across the
                     redirect that happens after bulk-assign. Without these
                     hidden inputs, $request->only([...]) in the controller
                     comes back empty (these are GET params on the page URL,
                     not part of the form POST body), so the user was landing
                     on an unfiltered /tenders and not seeing "their" view
                     anymore — which is why the pulse felt invisible. --}}
                @php
                    $preserveFilters = [
                        'source'          => $filters['source'],
                        'status'          => $filters['status'],
                        'urgency'         => $filters['urgency'],
                        'collaborator_id' => $filters['collaborator_id'],
                        'q'               => $filters['q'],
                        'sort'            => $sort,
                        'dir'             => $dir,
                        'page'            => request()->integer('page') ?: null,
                    ];
                @endphp
                @foreach($preserveFilters as $pk => $pv)
                    @if($pv !== null && $pv !== '')
                        <input type="hidden" name="return_{{ $pk }}" value="{{ $pv }}">
                    @endif
                @endforeach
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                    @php
                        /*
                         * "Mais recentes" reset-sort URL — drops the sort/dir/page
                         * query params but keeps whatever filters the user has
                         * applied (source / status / urgency / etc). User feedback:
                         * "falta botão para aparecer sempre os últimos". The default
                         * sort in applySort() is already newest-first, but once a
                         * column header is clicked the user is stuck on that sort.
                         * This button gives them an explicit one-click escape.
                         */
                        $resetParams = array_filter([
                            'source'          => $filters['source'],
                            'status'          => $filters['status'],
                            'urgency'         => $filters['urgency'],
                            'collaborator_id' => $filters['collaborator_id'],
                            'q'               => $filters['q'],
                        ], fn($v) => $v !== null && $v !== '');
                        $resetUrl     = route('tenders.index', $resetParams);
                        $isDefaultSort = !$sort;
                    @endphp
                    <header class="px-4 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between gap-3 flex-wrap">
                        <h3 class="text-sm font-semibold text-gray-800">
                            @if($canViewAll)
                                Todos os concursos
                            @else
                                Os meus concursos
                            @endif
                            <span class="ml-2 text-xs font-normal text-gray-500">({{ $all->total() }})</span>
                        </h3>

                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ $resetUrl }}"
                               title="Remover ordenação manual e mostrar os concursos mais recentes primeiro"
                               class="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-xs font-semibold shadow-sm
                                      {{ $isDefaultSort
                                          ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                          : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                                ⏱ Mais recentes
                                @if($isDefaultSort)
                                    <span class="text-[10px] text-indigo-500">(activo)</span>
                                @endif
                            </a>

                            @if($canAssign)
                                <select name="collaborator_id" class="rounded-md border-gray-300 text-xs shadow-sm">
                                    <option value="">(sem atribuição)</option>
                                    @foreach($collaborators as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit"
                                        onclick="return confirm('Atribuir os seleccionados?')"
                                        class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                                    Atribuir seleccionados
                                </button>
                            @endif
                        </div>
                    </header>

                    @php
                        /**
                         * Build a sortable column header.
                         * Clicking: if currently sorted by this column ASC, next click is DESC;
                         * otherwise (not sorted or sorted DESC), next click is ASC.
                         * Pagination is reset to page=1 so we don't land on an empty page.
                         */
                        $sortLink = function (string $key, string $label) use ($sort, $dir) {
                            // Default order is now "newest imports first" (no column
                            // highlighted) so users see fresh work up top. A column
                            // only shows the arrow once explicitly clicked.
                            $isActive = $sort === $key;
                            $nextDir  = ($isActive && $dir === 'asc') ? 'desc' : 'asc';
                            $arrow    = '';
                            if ($isActive) {
                                $arrow = $dir === 'asc' ? '▲' : '▼';
                            }
                            $url = request()->fullUrlWithQuery([
                                'sort' => $key,
                                'dir'  => $nextDir,
                                'page' => 1,
                            ]);
                            return compact('url', 'label', 'arrow', 'isActive');
                        };
                    @endphp

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    @if($canAssign)
                                        <th class="px-3 py-2 w-8">
                                            <input type="checkbox" id="select-all" class="rounded border-gray-300">
                                        </th>
                                    @endif
                                    @foreach([
                                        ['source',       'Fonte / Ref'],
                                        ['title',        'Título'],
                                        ['collaborator', 'Colaborador'],
                                        ['status',       'Estado'],
                                        ['sap',          'Nº SAP'],
                                        ['deadline',     'Deadline (PT / LU)'],
                                        ['urgency',      'Urgência'],
                                    ] as [$key, $label])
                                        @php $h = $sortLink($key, $label); @endphp
                                        <th class="px-3 py-2 text-left">
                                            <a href="{{ $h['url'] }}"
                                               class="inline-flex items-center gap-1 select-none {{ $h['isActive'] ? 'text-indigo-700' : 'hover:text-gray-700' }}">
                                                <span>{{ $h['label'] }}</span>
                                                @if($h['arrow'])
                                                    <span class="text-indigo-600">{{ $h['arrow'] }}</span>
                                                @else
                                                    <span class="text-gray-300">⇅</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                {{-- Row vertical-alignment rule: middle, not top.
                                     Long titles make the row tall, and with
                                     align-top every short cell floated up
                                     leaving a big whitespace strip below
                                     (user: "tem um grande gap nas linhas em
                                     extenso branco"). align-middle centres the
                                     chips so the status is always visible next
                                     to the title. --}}
                                @forelse($all as $t)
                                    @php
                                        $wasJustAssigned = in_array($t->id, $justAssigned, true);
                                        $hasAssignee     = !empty($t->assigned_collaborator_id);
                                    @endphp
                                    <tr class="hover:bg-gray-50 text-sm align-middle {{ $wasJustAssigned ? 'just-assigned' : '' }}">
                                        @if($canAssign)
                                            <td class="px-3 py-2 align-middle">
                                                <input type="checkbox" name="tender_ids[]" value="{{ $t->id }}"
                                                       class="rounded border-gray-300 row-check">
                                            </td>
                                        @endif
                                        <td class="px-3 py-2 align-middle whitespace-nowrap">
                                            <div class="text-xs font-semibold uppercase text-gray-600">{{ $t->source }}</div>
                                            <div class="text-xs font-mono text-gray-500">{{ $t->reference }}</div>
                                            {{-- Two flavours of the "atribuído" pill:
                                                 (a) just-assigned-chip — ✨ + animated, only on the rows
                                                     that the manager just bulk-assigned in this request
                                                     (one-shot session flash, fades on next page load).
                                                 (b) persistent assigned-pill — small green tag that stays
                                                     for ANY row with assigned_collaborator_id, so the
                                                     manager can scan the whole table and instantly see
                                                     which processes have already been delegated. Asked
                                                     for explicitly 2026-04-27. --}}
                                            @if($wasJustAssigned)
                                                <div class="mt-1">
                                                    <span class="just-assigned-chip" title="Atribuído agora mesmo{{ $justAssignedLabel ? ' a ' . $justAssignedLabel : '' }}">
                                                        ✨ atribuído
                                                    </span>
                                                </div>
                                            @elseif($hasAssignee)
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800"
                                                          title="Já atribuído a {{ $t->collaborator?->name ?? 'alguém' }}">
                                                        ✓ atribuído
                                                    </span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-middle max-w-md">
                                            <a href="{{ route('tenders.show', $t) }}" class="text-indigo-700 hover:underline font-medium">
                                                {{ \Illuminate\Support\Str::limit($t->title, 90) }}
                                            </a>
                                            @if($t->type)
                                                <div class="text-xs text-gray-500 mt-0.5">{{ $t->type }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-middle text-gray-700 whitespace-nowrap">
                                            {{ $t->collaborator?->name ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 align-middle whitespace-nowrap">
                                            <span class="inline-flex items-center rounded-md border px-2 py-1 text-xs font-semibold {{ $statusStyles[$t->status] ?? 'bg-gray-100 text-gray-700 border-gray-300' }}">
                                                {{ $statusLabels[$t->status] ?? $t->status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 align-middle font-mono text-xs whitespace-nowrap">
                                            @if($t->sap_opportunity_number)
                                                <span class="inline-flex items-center rounded bg-green-50 border border-green-200 px-2 py-0.5 text-green-800">
                                                    ✓ {{ $t->sap_opportunity_number }}
                                                </span>
                                            @else
                                                <span class="text-yellow-700">⚠ sem nº</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-middle text-xs text-gray-600 whitespace-nowrap">
                                            @if($t->deadline_at)
                                                {{-- Single-timezone deadline display. The dual PT/LU
                                                     readout was simplified 2026-04-27 — operators only
                                                     care about the value as imported from the source
                                                     Excel, not a translated wall-clock. --}}
                                                {{ $t->deadline_lisbon->format('d/m/y H:i') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-middle whitespace-nowrap">
                                            <span class="inline-flex rounded border px-2 py-0.5 text-xs font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                                @if($t->urgency_bucket === 'expired')
                                                    Expirado {{ abs($t->days_to_deadline) }}d
                                                @elseif($t->urgency_bucket === 'overdue')
                                                    {{ abs($t->days_to_deadline) }}d atraso
                                                @elseif($t->days_to_deadline !== null)
                                                    {{ $t->days_to_deadline }}d
                                                @else
                                                    —
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-3 py-8 text-center text-sm text-gray-500">
                                            Nenhum concurso corresponde aos filtros.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <footer class="px-4 py-3 border-t border-gray-100 bg-gray-50">
                        {{ $all->links() }}
                    </footer>
                </section>
            </form>

            @if($canAssign)
                <script>
                    document.getElementById('select-all')?.addEventListener('change', function (e) {
                        document.querySelectorAll('.row-check').forEach(cb => cb.checked = e.target.checked);
                    });
                </script>
            @endif

            {{-- Auto-scroll to first just-assigned row (if rendered on this
                 page) so the "pisco" is immediately visible without the user
                 having to scan the table. If the rows aren't on this page
                 (filtered out / on next page), the banner above is their cue. --}}
            @if(count($justAssigned) > 0)
                <script>
                    (function () {
                        const firstRow = document.querySelector('tr.just-assigned');
                        if (firstRow && typeof firstRow.scrollIntoView === 'function') {
                            setTimeout(() => {
                                firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }, 200);
                        }
                    })();
                </script>
            @endif
        </div>
    </div>
</x-app-layout>
