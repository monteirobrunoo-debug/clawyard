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
        'submitted'=> 'bg-emerald-50 text-emerald-700 border-emerald-300',
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

    // Source labels — globais (usados em vários blocos da view).
    // 2026-05-19: acingov + vortal + anogov unificados sob "Acingov/Vortal/Anogov"
    // (pedido directo do operador). São 3 plataformas PT equivalentes
    // (procurement publico) — team trata-as como um único bucket.
    $sourceLabels = [
        'nspa'    => 'NSPA',
        'nato'    => 'NATO',
        'sam_gov' => 'SAM.gov',
        'ncia'    => 'NCIA',
        'acingov' => 'Acingov/Vortal/Anogov',
        'vortal'  => 'Acingov/Vortal/Anogov',
        'anogov'  => 'Acingov/Vortal/Anogov',
        'ungm'    => 'UNGM',
        'unido'   => 'UNIDO',
        'marine'  => 'Marine Department',
        'other'   => 'Outras',
    ];

    // 2026-05-19: keys a mostrar no dropdown de filtros. Grupos colapsados:
    // só 'acingov' (cabeça de grupo) aparece, e o filtro expande no backend
    // para WHERE source IN (acingov,vortal,anogov) via Tender::SOURCE_GROUPS.
    $sourceFilterKeys = [
        'nspa', 'nato', 'sam_gov', 'ncia',
        'acingov', // grupo Acingov/Vortal/Anogov
        'ungm', 'unido', 'other',
    ];

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
                {{ $pageTitle ?? 'Concursos' }}
                @if(!empty($isMarine))
                    <span class="text-sm font-normal text-cyan-700 ml-2">⚓ Marine Department</span>
                @endif
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

                {{-- Export CSV — preserva os filtros activos para que o ficheiro
                     reflicta exactamente o que está na tabela. Abre directamente
                     no Excel (BOM UTF-8 + separador ;). --}}
                <a href="{{ route('tenders.export', request()->query()) }}"
                   class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-emerald-500"
                   title="Exporta a tabela actual (com os filtros aplicados) para CSV — abre no Excel principal">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                    </svg>
                    Exportar Excel (CSV)
                </a>

                {{-- 2026-05-19 — pedido directo do operador:
                       "nao quero botao concurso, quero outro a dizr para
                        isnerir o pdf apartid ai analisar cliente data, o
                        que é o serviço o upeça e fornecesore"
                     Substitui o + Novo concurso pela acção PDF-first:
                     larga o PDF → Marta extrai cliente/data/serviço/peças/
                     fornecedores → painel multi-agente (Cor. Rodrigues +
                     Marco Sales + …) corre logo. O modal manual antigo
                     continua acessível via "criar manualmente" secundário. --}}
                <button type="button"
                        onclick="document.getElementById('tender-quick-pdf-modal').classList.remove('hidden')"
                        class="inline-flex items-center gap-2 rounded-md {{ ($isMarine ?? false) ? 'bg-blue-700 hover:bg-blue-600' : 'bg-violet-700 hover:bg-violet-600' }} px-4 py-2 text-sm font-semibold text-white shadow"
                        title="Larga 1 PDF (RFP/RFQ). Marta extrai cliente, data, serviço, peças e fornecedores prováveis. Painel multi-agente corre logo.">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-3-3v6M7 4h10a2 2 0 012 2v14l-7-3-7 3V6a2 2 0 012-2z"/>
                    </svg>
                    @if($isMarine ?? false)
                        📄 Inserir PDF marítimo — análise auto
                    @else
                        📄 Inserir PDF — análise auto
                    @endif
                </button>

                {{-- Fallback secundário: criação 100% manual sem PDF.
                     Texto pequeno para não competir visualmente. --}}
                <button type="button"
                        onclick="document.getElementById('tender-manual-modal').classList.remove('hidden')"
                        class="text-xs text-gray-500 hover:text-gray-700 underline self-center"
                        title="Cria concurso manualmente sem PDF (form com campos a preencher).">
                    criar manualmente
                </button>
            </div>
        </div>
    </x-slot>

    {{-- Modal "Novo concurso manual" — escondido por defeito. Form
         POST para tenders.storeManual. Validação server-side; se falhar
         o user vê os errors no campo (Laravel old() + flash). --}}
    <div id="tender-manual-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-start justify-center pt-16 px-4">
        <div class="bg-white rounded-lg shadow-xl max-w-xl w-full">
            <div class="border-b border-gray-200 px-5 py-3 flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-800">
                    @if($isMarine ?? false)
                        ⚓ + Novo concurso marítimo
                    @else
                        + Novo concurso (manual)
                    @endif
                </h3>
                <button type="button"
                        onclick="document.getElementById('tender-manual-modal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-700 text-2xl leading-none">×</button>
            </div>
            <form method="POST" action="{{ route('tenders.storeManual') }}" class="px-5 py-4 space-y-3 text-sm">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-gray-700">Referência</span>
                        <input type="text" name="reference" placeholder="ex: RFP-2026-001"
                               class="mt-1 block w-full rounded border-gray-300 text-sm" maxlength="80">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-gray-700">Fonte *</span>
                        {{-- 2026-05-19: on /marine, pre-select source=marine so the
                             button feels native to the section. User can still
                             override via the dropdown. --}}
                        <select name="source" required class="mt-1 block w-full rounded border-gray-300 text-sm">
                            <option value="marine" @selected($isMarine ?? false)>⚓ Marine Department</option>
                            <option value="manual" @selected(!($isMarine ?? false))>Manual</option>
                            <option value="email">Email</option>
                            <option value="acingov">AcinGov</option>
                            <option value="vortal">Vortal</option>
                            <option value="ungm">UNGM</option>
                            <option value="nspa">NSPA</option>
                            <option value="sam_gov">SAM.gov</option>
                            <option value="other">Outro</option>
                        </select>
                    </label>
                </div>
                <label class="block">
                    <span class="text-xs font-semibold text-gray-700">Título *</span>
                    <input type="text" name="title" required maxlength="500"
                           placeholder="ex: Provision of NETGATE Hardware"
                           class="mt-1 block w-full rounded border-gray-300 text-sm">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-gray-700">Organização</span>
                        <input type="text" name="purchasing_org" maxlength="200"
                               placeholder="ex: NCIA, OceanPact, NSPA"
                               class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-gray-700">Tipo</span>
                        <input type="text" name="type" maxlength="40" placeholder="Supply / Service"
                               class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-gray-700">Deadline</span>
                        <input type="datetime-local" name="deadline_at"
                               class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-gray-700">Prioridade</span>
                        <select name="priority" class="mt-1 block w-full rounded border-gray-300 text-sm">
                            <option value="normal">Normal</option>
                            <option value="low">Baixa</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </label>
                </div>
                <label class="block">
                    <span class="text-xs font-semibold text-gray-700">Notas</span>
                    <textarea name="notes" rows="3" maxlength="5000"
                              data-autogrow
                              placeholder="Detalhes adicionais — equipamentos, contactos, etc."
                              class="mt-1 block w-full rounded border-gray-300 text-sm"></textarea>
                </label>

                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 mt-3">
                    <button type="button"
                            onclick="document.getElementById('tender-manual-modal').classList.add('hidden')"
                            class="px-4 py-2 text-sm rounded border border-gray-300 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="px-4 py-2 text-sm rounded bg-amber-600 text-white font-semibold hover:bg-amber-500">
                        Criar concurso
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ─── Modal "Inserir PDF — análise automática" ─────────────────────
         Único campo: 1 PDF. Após submit, o server cria o Tender, anexa
         o PDF, extrai cliente / data / serviço / peças / fornecedores
         via Marta CRM, e corre análise multi-agente. Demora 15-30s
         por isso o submit mostra spinner com aviso explícito. --}}
    <div id="tender-quick-pdf-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-start justify-center pt-16 px-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="border-b border-gray-200 px-5 py-3 flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-800">
                    📄 Inserir PDF · análise automática
                </h3>
                <button type="button"
                        onclick="document.getElementById('tender-quick-pdf-modal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-700 text-2xl leading-none">×</button>
            </div>
            <form method="POST" action="{{ route('tenders.quickPdfAnalyse') }}" enctype="multipart/form-data"
                  class="px-5 py-4 space-y-4 text-sm"
                  id="tender-quick-pdf-form">
                @csrf
                <input type="hidden" name="source" value="{{ ($isMarine ?? false) ? 'marine' : 'manual' }}">

                <div class="rounded-md bg-violet-50 border border-violet-200 p-3 text-xs text-violet-900">
                    Larga 1 PDF (RFP/RFQ/spec) e a Marta CRM extrai automaticamente:
                    <ul class="mt-1 ml-4 list-disc">
                        <li><strong>Cliente</strong> (entidade compradora) + NIPC se aparecer</li>
                        <li><strong>Data limite</strong> da proposta</li>
                        <li><strong>Serviço</strong> a executar (o que é o concurso)</li>
                        <li><strong>Peças / equipamentos</strong> mencionados</li>
                        <li><strong>Fornecedores prováveis</strong> (OEM ecosystem)</li>
                    </ul>
                    Em seguida, o painel multi-agente — 🎖️ Cor. Rodrigues, 💼 Marco Sales,
                    🔩 Eng. Victor, 🚚 Logística @if($isMarine ?? false), ⚓ Capt. Porto, ⚓ Capt. Vasco @endif —
                    corre análise técnica completa.
                </div>

                <label class="block">
                    <span class="text-xs font-semibold text-gray-700">PDF do concurso *</span>
                    <input type="file" name="file" accept=".pdf,application/pdf"
                           required
                           class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-violet-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-violet-700 hover:file:bg-violet-100">
                    <span class="mt-1 block text-[11px] text-gray-500">Máx. 30 MB · só PDF (xlsx/docx anexam-se depois)</span>
                </label>

                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <button type="button"
                            onclick="document.getElementById('tender-quick-pdf-modal').classList.add('hidden')"
                            class="px-4 py-2 text-sm rounded border border-gray-300 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            id="tender-quick-pdf-submit"
                            class="px-4 py-2 text-sm rounded bg-violet-700 text-white font-semibold hover:bg-violet-600 disabled:opacity-60 disabled:cursor-wait">
                        Analisar PDF →
                    </button>
                </div>

                <p id="tender-quick-pdf-pending" class="hidden text-xs text-violet-700 text-right">
                    ⏳ Marta a ler PDF + painel multi-agente a correr… 15-30s.
                </p>
            </form>
        </div>
    </div>

    <script>
    (function () {
        const f = document.getElementById('tender-quick-pdf-form');
        const btn = document.getElementById('tender-quick-pdf-submit');
        const pending = document.getElementById('tender-quick-pdf-pending');
        if (!f || !btn) return;
        f.addEventListener('submit', () => {
            btn.disabled = true;
            btn.textContent = '⏳ A analisar…';
            pending?.classList.remove('hidden');
        });
    })();
    </script>

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
            {{-- Stats hero — ring charts with the live pipeline as denominator
                 so each slice shows what fraction of "actionable work" is in
                 each state at a glance. --}}
            @php $tdrTotal = max(1, (int) $stats['total']); @endphp
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                @include('partials.ring-chart', ['label' => 'Em curso',         'value' => $stats['total'],       'total' => $stats['total'],   'tone' => 'gray',    'subline' => 'pipeline live'])
                @include('partials.ring-chart', ['label' => 'Dentro do prazo', 'value' => $stats['active'],      'total' => $tdrTotal,         'tone' => 'indigo',  'subline' => 'deadline futura'])
                @include('partials.ring-chart', ['label' => 'Em atraso ≤'.\App\Models\Tender::OVERDUE_WINDOW_DAYS.'d', 'value' => $stats['overdue'], 'total' => $tdrTotal, 'tone' => 'red',     'subline' => 'recuperáveis'])
                @include('partials.ring-chart', ['label' => 'Urgentes ≤7d',     'value' => $stats['urgent'],      'total' => $tdrTotal,         'tone' => 'amber',   'subline' => 'deadline ≤7d'])
                @include('partials.ring-chart', ['label' => 'Sem nº SAP',       'value' => $stats['needing_sap'], 'total' => $tdrTotal,         'tone' => 'amber',   'subline' => 'atribuídos s/ opp'])
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
                    // $sourceLabels já definido no topo da view (scope global).
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
                 2026-05-19: REMOVIDA por pedido directo da admin Monica:
                 "dashboard de concursos põe igual para administrador e
                  users. exatamente igual as tabelas, alteraste tudo e
                  ficou diferente no user jose ou rio, vai ficar igual
                  para administrador".

                 Antes do refactor de visibility, regular users so viam
                 seus tenders via forUser scope, e a mini-tabela era util
                 como atalho. Depois do refactor (assignment-based), o
                 main table ja inclui (assigned-to-me + pool aberto sem
                 assignment), pelo que a mini-tabela passou a ser duplicacao.

                 Para reactivar: trocar @if(false) por o predicado original. --}}
            @if(false && ($mine->count() > 0 || !empty($mineQ)))
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                    <header class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap bg-gray-50">
                        <h3 class="text-sm font-semibold text-gray-800">
                            Os meus concursos activos
                            <span class="ml-2 text-xs font-normal text-gray-500">({{ $mine->count() }})</span>
                        </h3>

                        {{-- Lupa de pesquisa — filtra a tabela "Os meus
                             concursos" por título / referência / nº SAP /
                             fonte. Param `mine_q` independente do `q` da
                             tabela global. Os mine_sort/mine_dir actuais
                             são preservados via hidden inputs para que a
                             pesquisa não quebre a ordenação que o user
                             escolheu. --}}
                        <form method="GET" action="{{ route('tenders.index') }}"
                              class="flex items-center gap-2 w-full sm:w-auto">
                            @foreach(['mine_sort' => $mineSort, 'mine_dir' => $mineDir] as $hk => $hv)
                                @if($hv !== null && $hv !== '')
                                    <input type="hidden" name="{{ $hk }}" value="{{ $hv }}">
                                @endif
                            @endforeach
                            {{-- Preserve the manager-table state too so a
                                 manager browsing /tenders?status=submetido
                                 doesn't lose their filter when searching
                                 the mine box. --}}
                            @foreach(['source','status','urgency','collaborator_id','q','sort','dir','per_page'] as $k)
                                @if(!empty(request()->query($k)))
                                    <input type="hidden" name="{{ $k }}" value="{{ request()->query($k) }}">
                                @endif
                            @endforeach

                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400">
                                    {{-- Magnifying-glass icon (Heroicons outline). --}}
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z" />
                                    </svg>
                                </span>
                                <input type="search" name="mine_q"
                                       value="{{ $mineQ }}"
                                       placeholder="Pesquisar título / ref / nº SAP…"
                                       class="rounded-md border-gray-300 text-xs shadow-sm pl-7 pr-2 py-1.5 focus:border-indigo-500 focus:ring-indigo-500 w-72">
                            </div>

                            @if(!empty($mineQ))
                                {{-- "Limpar" link strips just mine_q,
                                     keeping every other query param. --}}
                                @php
                                    $clearParams = request()->query();
                                    unset($clearParams['mine_q']);
                                    $clearUrl = route('tenders.index') . (empty($clearParams) ? '' : '?' . http_build_query($clearParams));
                                @endphp
                                <a href="{{ $clearUrl }}" class="text-[11px] text-gray-500 hover:text-gray-700 underline">
                                    Limpar
                                </a>
                            @endif

                            <button type="submit"
                                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                                Pesquisar
                            </button>
                        </form>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            {{-- Headers clicáveis com toggle ASC/DESC. Default
                                 'created_at desc' (mais recente primeiro), mas
                                 o user pode trocar por Título / Estado / Deadline
                                 / Nº SAP. Setinha indica direcção activa. --}}
                            @php
                                $mineSortLink = function (string $key, string $label) use ($mineSort, $mineDir) {
                                    $isActive = $mineSort === $key;
                                    $nextDir  = $isActive && $mineDir === 'desc' ? 'asc' : 'desc';
                                    $arrow    = $isActive ? ($mineDir === 'desc' ? '↓' : '↑') : '';
                                    $params   = array_merge(request()->query(), [
                                        'mine_sort' => $key,
                                        'mine_dir'  => $nextDir,
                                    ]);
                                    return [
                                        'url'      => request()->url() . '?' . http_build_query($params),
                                        'label'    => $label,
                                        'isActive' => $isActive,
                                        'arrow'    => $arrow,
                                    ];
                                };
                            @endphp
                            <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                <tr>
                                    @foreach([
                                        ['source',     'Fonte'],
                                        ['title',      'Título'],
                                        ['collaborator', 'Colaborador'],
                                        ['status',     'Estado'],
                                        ['sap',        'Nº SAP'],
                                        ['deadline',   'Deadline'],
                                        ['created_at', 'Importado'],
                                    ] as [$key, $label])
                                        @php $h = $key === 'collaborator' ? null : $mineSortLink($key, $label); @endphp
                                        <th class="px-3 py-2 text-left">
                                            @if($h)
                                                <a href="{{ $h['url'] }}" class="inline-flex items-center gap-1 select-none {{ $h['isActive'] ? 'text-indigo-700' : 'hover:text-gray-700' }}">
                                                    <span>{{ $h['label'] }}</span>
                                                    @if($h['arrow'])
                                                        <span class="text-indigo-600">{{ $h['arrow'] }}</span>
                                                    @else
                                                        <span class="text-gray-300">⇅</span>
                                                    @endif
                                                </a>
                                            @else
                                                {{ $label }}
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @if($mine->count() === 0 && !empty($mineQ))
                                    {{-- Empty result for an active search — give
                                         the user a clear way out without making
                                         them rebuild their query manually. --}}
                                    <tr>
                                        <td colspan="7" class="px-3 py-6 text-center text-sm text-gray-500">
                                            Nenhum concurso encontrado para
                                            <span class="font-semibold text-gray-700">"{{ $mineQ }}"</span>.
                                            <a href="{{ $clearUrl ?? route('tenders.index') }}" class="ml-1 text-indigo-600 hover:underline">limpar pesquisa</a>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($mine as $t)
                                    <tr class="hover:bg-gray-50 align-middle">
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            {{-- Source label: usa $sourceLabels (definido no bloco PHP do topo)
                                                 para unificar acingov + vortal sob "Acingov/Vortal/PT Concursos". --}}
                                            <div class="text-xs font-semibold uppercase text-gray-600">{{ $sourceLabels[$t->source] ?? strtoupper($t->source) }}</div>
                                            <div class="text-xs font-mono text-gray-500">{{ $t->reference }}</div>
                                            {{-- 2026-05-18: pill com nome do colaborador para consistência
                                                 com a vista "all tenders". Útil para concursos partilhados
                                                 onde o user actual NÃO é o colaborador principal. --}}
                                            @if($t->collaborator?->name)
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800"
                                                          title="Atribuído a {{ $t->collaborator->name }}">
                                                        ✓ atribuído · {{ $t->collaborator->name }}
                                                    </span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 max-w-md">
                                            <a href="{{ route('tenders.show', $t) }}" class="text-indigo-700 hover:underline font-medium">
                                                {{ \Illuminate\Support\Str::limit($t->title, 90) }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $t->collaborator?->name ?? '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            {{-- Estado: derivado SEMPRE do SAP (sapStageLabel) — substituiu
                                                 o legacy $t->status manual em 2026-05-15. Single source of
                                                 truth. Cache populado pelo /sap-preview JSON endpoint
                                                 quando o user abre o detalhe do concurso. --}}
                                            <span class="inline-flex items-center rounded-md border px-2 py-1 text-xs font-semibold {{ $t->sapStageBadgeClasses() }}"
                                                  title="@if($t->sap_stage_updated_at)Sincronizado {{ $t->sap_stage_updated_at->diffForHumans() }} @else Ainda não sincronizado — abre o concurso para puxar do SAP @endif">
                                                {{ $t->sapStageLabel() }}
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
                                                <div>{{ $t->deadline_lisbon->format('d/m/y H:i') }}</div>
                                                <div class="mt-0.5">
                                                    <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                                        @if($t->urgency_bucket === 'submitted')
                                                            ✓
                                                        @elseif($t->urgency_bucket === 'overdue')
                                                            -{{ abs($t->days_to_deadline) }}d
                                                        @elseif($t->days_to_deadline !== null)
                                                            {{ $t->days_to_deadline }}d
                                                        @else
                                                            —
                                                        @endif
                                                    </span>
                                                </div>
                                            @else
                                                <span class="text-gray-400">sem deadline</span>
                                            @endif
                                        </td>
                                        {{-- 'Importado' = created_at — coluna nova para o user
                                             saber que tenders chegaram mais recentemente. Default
                                             ordenação é created_at desc. --}}
                                        <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                            <span title="Importado em {{ $t->created_at->format('d/m/Y H:i') }}">
                                                {{ $t->created_at->diffForHumans(null, true) }}
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
                    {{-- Lupa icon prefix for visual parity with the
                         "Os meus concursos" search box. The input itself
                         keeps name="q" so existing bookmarks/links work. --}}
                    <div class="relative sm:col-span-2">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z" />
                            </svg>
                        </span>
                        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Pesquisar título / ref / nº SAP"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm pl-8 focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <select name="source" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">Todas as fontes</option>
                        @foreach($sourceFilterKeys as $src)
                            <option value="{{ $src }}" @selected($filters['source'] === $src)>{{ $sourceLabels[$src] ?? strtoupper($src) }}</option>
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
                        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2 flex-wrap">
                            {{-- 2026-05-19 v3: header unificado + duplo contador
                                 Pedido directo Monica: "em cima todos e os que esta
                                 assigned". Mostra total visível + atribuídos a este user. --}}
                            Concursos
                            <span class="text-xs font-normal text-gray-500">({{ $all->total() }})</span>
                            @if(!empty($myAssignedCount) && $myAssignedCount > 0)
                                <a href="{{ route('tenders.index', ['collaborator_id' => collect($collaborators)->firstWhere('user_id', $currentUserId)?->id]) }}"
                                   title="Filtrar só os concursos atribuídos a si"
                                   class="inline-flex items-center gap-1 rounded-md bg-emerald-100 border border-emerald-300 text-emerald-800 text-xs font-semibold px-2 py-0.5 hover:bg-emerald-200">
                                    ✓ Atribuídos a mim · {{ $myAssignedCount }}
                                </a>
                            @endif
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

                    {{-- ─── Mobile cards (viewport <sm) ─────────────────────
                         A tabela tem 7-8 colunas e em mobile ficava com scroll
                         horizontal — illegível. Cards verticais mostram a
                         mesma info essencial empilhada: ref + título + status
                         + colaborador + deadline + chip atribuído. Tocar no
                         título abre o /tenders/{id} como na tabela.
                         2026-05-19. --}}
                    <div class="block sm:hidden divide-y divide-gray-100">
                        @forelse($all as $t)
                            @php
                                $wasJustAssigned = in_array($t->id, $justAssigned, true);
                                $hasAssignee     = !empty($t->assigned_collaborator_id);
                                $deadlinePT      = $t->deadline_lisbon?->format('d/m/Y H:i');
                            @endphp
                            <article class="p-3 hover:bg-gray-50 {{ $wasJustAssigned ? 'just-assigned' : '' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="font-semibold uppercase text-gray-600">{{ $sourceLabels[$t->source] ?? strtoupper($t->source) }}</span>
                                            <span class="font-mono text-gray-500 truncate">{{ $t->reference }}</span>
                                        </div>
                                        <a href="{{ route('tenders.show', $t) }}"
                                           class="block mt-1 text-sm font-medium text-indigo-700 hover:underline leading-snug">
                                            {{ \Illuminate\Support\Str::limit($t->title, 110) }}
                                        </a>
                                    </div>
                                    @if($canAssign)
                                        <input type="checkbox" name="tender_ids[]" value="{{ $t->id }}"
                                               class="row-check mt-1 rounded border-gray-300">
                                    @endif
                                </div>

                                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                    @if($t->collaborator?->name)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700">
                                            👤 {{ $t->collaborator->name }}
                                        </span>
                                    @endif
                                    @if($wasJustAssigned)
                                        <span class="just-assigned-chip">✨ atribuído</span>
                                    @elseif($hasAssignee)
                                        <span class="rounded border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-800">
                                            ✓ atribuído
                                        </span>
                                    @endif
                                    @if($t->sap_opportunity_number)
                                        <span class="rounded border border-cyan-300 bg-cyan-50 px-1.5 py-0.5 font-mono text-cyan-800">
                                            SAP #{{ $t->sap_opportunity_number }}
                                        </span>
                                    @endif
                                    @if($deadlinePT)
                                        <span class="rounded bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-amber-900">
                                            ⏰ {{ $deadlinePT }}
                                        </span>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="p-6 text-center text-sm text-gray-500">
                                Nenhum concurso corresponde aos filtros.
                            </div>
                        @endforelse
                    </div>

                    {{-- ─── Desktop / tablet table (sm+) ──────────────────── --}}
                    <div class="hidden sm:block overflow-x-auto">
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
                                            {{-- Source label: usa $sourceLabels (definido no bloco PHP do topo)
                                                 para unificar acingov + vortal sob "Acingov/Vortal/PT Concursos". --}}
                                            <div class="text-xs font-semibold uppercase text-gray-600">{{ $sourceLabels[$t->source] ?? strtoupper($t->source) }}</div>
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
                                            {{-- 2026-05-18: a pill agora mostra o NOME do colaborador
                                                 junto, conforme pedido: "quando tem nome de colaborador
                                                 adicionar na frente atribuído e nome do colaborador
                                                 conforme tabela do Excel". O nome vem da coluna
                                                 Colaborador da importação — TenderCollaborator::name. --}}
                                            @if($wasJustAssigned)
                                                <div class="mt-1">
                                                    <span class="just-assigned-chip" title="Atribuído agora mesmo{{ $justAssignedLabel ? ' a ' . $justAssignedLabel : '' }}">
                                                        ✨ atribuído{{ $t->collaborator?->name ? ' · ' . $t->collaborator->name : '' }}
                                                    </span>
                                                </div>
                                            @elseif($hasAssignee)
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800"
                                                          title="Atribuído a {{ $t->collaborator?->name ?? 'alguém' }} via import Excel ou atribuição manual">
                                                        ✓ atribuído{{ $t->collaborator?->name ? ' · ' . $t->collaborator->name : '' }}
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
                                            {{-- Estado: derivado SEMPRE do SAP (sapStageLabel) — substituiu
                                                 o legacy $t->status manual em 2026-05-15. Single source of
                                                 truth. Cache populado pelo /sap-preview JSON endpoint
                                                 quando o user abre o detalhe do concurso. --}}
                                            <span class="inline-flex items-center rounded-md border px-2 py-1 text-xs font-semibold {{ $t->sapStageBadgeClasses() }}"
                                                  title="@if($t->sap_stage_updated_at)Sincronizado {{ $t->sap_stage_updated_at->diffForHumans() }} @else Ainda não sincronizado — abre o concurso para puxar do SAP @endif">
                                                {{ $t->sapStageLabel() }}
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
                                                @if($t->urgency_bucket === 'submitted')
                                                    ✓ Submetido
                                                @elseif($t->urgency_bucket === 'expired')
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
