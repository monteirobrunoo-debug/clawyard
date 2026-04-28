@php
    /**
     * Tender detail page.
     *
     *   - Edit form: all fields mutable except deletion (user explicitly asked
     *     "pode editar todos os campos, mas nao pode apagar").
     *   - Observations panel: append-only notes with [timestamp — user] prefix
     *     (serialised into the `notes` column by TenderController@observe).
     *   - Similar opportunities: Jaccard title match with boosts for same type,
     *     purchasing_org and presence of sap_opportunity_number — the "posteriormente
     *     a outra base de dados para sugerir oportunidades iguais" requirement.
     */
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
    $urgencyClasses = [
        'overdue'  => 'bg-red-100 text-red-800 border-red-300',
        'critical' => 'bg-orange-100 text-orange-800 border-orange-300',
        'urgent'   => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'soon'     => 'bg-blue-100 text-blue-800 border-blue-300',
        'normal'   => 'bg-gray-100 text-gray-700 border-gray-300',
        'unknown'  => 'bg-gray-50 text-gray-500 border-gray-200',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('tenders.index') }}" class="text-sm text-indigo-600 hover:underline shrink-0">← Voltar</a>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 truncate">
                    {{ strtoupper($tender->source) }} · {{ $tender->reference }}
                </h2>
            </div>
            <button type="button"
                    onclick="copyTenderInfo()"
                    title="Copia toda a info deste concurso para a clipboard — pronto para colar num agente ou numa nota externa"
                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                📋 <span id="copy-btn-label">Copiar info completa</span>
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- ─── Header card ───────────────────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div class="min-w-0 flex-1">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $tender->title }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex rounded bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                {{ $statusLabels[$tender->status] ?? $tender->status }}
                            </span>
                            @if($tender->type)
                                <span class="inline-flex rounded border border-gray-200 px-2.5 py-1 text-xs text-gray-600">
                                    {{ $tender->type }}
                                </span>
                            @endif
                            <span class="inline-flex rounded border px-2.5 py-1 text-xs font-medium {{ $urgencyClasses[$tender->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                @if($tender->urgency_bucket === 'overdue')
                                    Em atraso {{ abs($tender->days_to_deadline) }}d
                                @elseif($tender->days_to_deadline !== null)
                                    {{ $tender->days_to_deadline }}d até deadline
                                @else
                                    Sem deadline
                                @endif
                            </span>
                        </div>
                    </div>
                    <div class="text-right text-sm">
                        {{-- Single-timezone deadline. The dual PT/LU readout
                             was simplified 2026-04-27 — operators only care
                             about the value as imported from the source. --}}
                        <dl class="space-y-1">
                            <div>
                                <dt class="inline text-gray-500">Deadline:</dt>
                                <dd class="inline font-medium text-gray-800">
                                    {{ $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Colaborador</dt>
                        <dd class="font-medium text-gray-900">{{ $tender->collaborator?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Nº Oportunidade SAP</dt>
                        <dd class="font-mono text-gray-900">{{ $tender->sap_opportunity_number ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Organização</dt>
                        <dd class="text-gray-900">{{ $tender->purchasing_org ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Atribuído por</dt>
                        <dd class="text-gray-900">{{ $tender->assignedBy?->name ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            {{-- ─── SAP B1 Opportunity card ───────────────────────────────
                 Phase 1 of the SAP integration: when the tender has a SAP
                 opportunity number, fetch live data from the Service Layer
                 (via /tenders/{id}/sap-preview JSON endpoint) and show it
                 here. Async so a slow SAP doesn't block the page render.
            --}}
            <section id="sap-opp-card"
                     class="rounded-lg bg-white shadow-sm border border-gray-100 p-5"
                     data-sap-url="{{ route('tenders.sap_preview', $tender) }}">
                <header class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-gray-800 flex items-center gap-2">
                        🔗 SAP Opportunity
                        <span id="sap-opp-ref" class="text-xs font-mono font-normal text-gray-500">{{ $tender->sap_opportunity_number ?: '—' }}</span>
                    </h3>
                    <button type="button" id="sap-opp-refresh"
                            class="text-xs text-indigo-600 hover:text-indigo-800"
                            title="Buscar outra vez ao SAP">↻ Actualizar</button>
                </header>

                <div id="sap-opp-body" class="text-sm text-gray-600">
                    <div class="flex items-center gap-2 text-gray-400">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        A contactar o SAP…
                    </div>
                </div>
            </section>

            <script>
            (function () {
                const root     = document.getElementById('sap-opp-card');
                const body     = document.getElementById('sap-opp-body');
                const refresh  = document.getElementById('sap-opp-refresh');
                if (!root || !body) return;

                const fmtEur = (n) => new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(Number(n) || 0);
                const fmtDate = (iso) => {
                    if (!iso) return '—';
                    const s = String(iso).slice(0, 10);
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
                    const [y, m, d] = s.split('-');
                    return `${d}/${m}/${y}`;
                };
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

                // State → human-friendly rendering
                const renderEmpty = (msg, tone = 'gray') => {
                    const colors = {
                        gray:   'bg-gray-50 border-gray-200 text-gray-600',
                        amber:  'bg-amber-50 border-amber-200 text-amber-800',
                        red:    'bg-red-50 border-red-200 text-red-800',
                    };
                    body.innerHTML = `<div class="rounded-md border p-3 text-xs ${colors[tone] || colors.gray}">${esc(msg)}</div>`;
                };

                const renderOk = (d) => {
                    const statusLabel = { O: 'Open', W: 'Won', L: 'Lost' }[d.status] || d.status || '—';
                    const statusColor = { O: 'bg-blue-100 text-blue-800', W: 'bg-emerald-100 text-emerald-800', L: 'bg-red-100 text-red-800' }[d.status] || 'bg-gray-100 text-gray-800';
                    const remarks = d.remarks ? esc(d.remarks) : '<span class="text-gray-400 italic">(sem Remarks no SAP)</span>';

                    const lastStage = d.last_stage ? `
                        <div class="mt-3 rounded-md bg-gray-50 border border-gray-200 p-2 text-xs text-gray-700">
                            <div class="font-semibold text-gray-800">Último estado (Níveis)</div>
                            <div>Stage #${d.last_stage.stage_key} · ${d.last_stage.percentage_rate}% · Vendedor: ${esc(d.last_stage.sales_employee) || '—'}</div>
                            <div class="text-gray-500">Início: ${fmtDate(d.last_stage.start_date)} · Fecho: ${fmtDate(d.last_stage.close_date)}</div>
                        </div>` : '';

                    body.innerHTML = `
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Estado SAP</div>
                                <span class="inline-flex mt-0.5 rounded px-2 py-0.5 text-xs font-medium ${statusColor}">${esc(statusLabel)}</span>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Cliente (BP)</div>
                                <div class="font-medium text-gray-900 truncate" title="${esc(d.bp_code)} · ${esc(d.bp_name)}">${esc(d.bp_name) || '—'}</div>
                                <div class="text-[10px] font-mono text-gray-400">${esc(d.bp_code) || ''}</div>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Valor</div>
                                <div class="font-semibold text-gray-900">${fmtEur(d.max_local_total)}</div>
                                <div class="text-[10px] text-gray-400">Ponderado: ${fmtEur(d.weighted_total)}</div>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase text-gray-500">Fecho previsto</div>
                                <div class="text-gray-900">${fmtDate(d.predicted_closing)}</div>
                                <div class="text-[10px] text-gray-400">${d.closing_percentage || 0}% prob.</div>
                            </div>
                        </div>
                        ${lastStage}
                        <div class="mt-3">
                            <div class="text-[10px] uppercase text-gray-500 mb-0.5">Remarks (SAP)</div>
                            <div class="rounded-md bg-indigo-50 border border-indigo-200 p-2 text-xs text-indigo-900 whitespace-pre-wrap">${remarks}</div>
                            <div class="text-[10px] text-gray-400 mt-1">
                                Ao guardar o campo <strong>Notas</strong> abaixo, este campo <em>Remarks</em> no SAP é actualizado automaticamente.
                            </div>
                        </div>
                    `;
                };

                const load = () => {
                    body.innerHTML = `<div class="flex items-center gap-2 text-gray-400">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        A contactar o SAP…
                    </div>`;

                    fetch(root.dataset.sapUrl, { headers: { 'Accept': 'application/json' } })
                        .then(r => r.json().then(j => ({ status: r.status, body: j })))
                        .then(({ status, body: payload }) => {
                            switch (payload.state) {
                                case 'ok':          return renderOk(payload.data);
                                case 'empty':
                                    // If the controller suggested a number parsed
                                    // from the tender reference, show it as a
                                    // one-click copy hint — saves Monica the
                                    // "porquê não liga" confusion when the SAP
                                    // number is sitting right there in the ref.
                                    if (payload.suggestion) {
                                        const s = esc(String(payload.suggestion));
                                        body.innerHTML = `
                                            <div class="rounded-md border p-3 text-xs bg-gray-50 border-gray-200 text-gray-700">
                                                ${esc(payload.message)}
                                                <div class="mt-2 flex items-center gap-2">
                                                    <span class="text-gray-500">Sugestão (da referência do concurso):</span>
                                                    <code class="font-mono bg-white border border-gray-300 rounded px-1.5 py-0.5">${s}</code>
                                                </div>
                                            </div>`;
                                        return;
                                    }
                                    return renderEmpty(payload.message, 'gray');
                                case 'unparseable': return renderEmpty(payload.message, 'amber');
                                case 'not_found':   return renderEmpty(payload.message, 'amber');
                                case 'auth_failed': return renderEmpty(payload.message, 'red');
                                case 'disabled':    return renderEmpty(payload.message, 'gray');
                                case 'error':       return renderEmpty(payload.message, 'red');
                                default:            return renderEmpty('Resposta inesperada do servidor.', 'red');
                            }
                        })
                        .catch(err => renderEmpty('Erro de rede: ' + err.message, 'red'));
                };

                refresh.addEventListener('click', load);
                load();
            })();
            </script>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- ─── Edit form (spans 2 cols) ──────────────────────────── --}}
                <section class="lg:col-span-2 rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                    <h3 class="text-base font-semibold text-gray-800 mb-4">Editar concurso</h3>

                    @if(!$canEdit)
                        <p class="text-sm text-gray-500 italic">Apenas leitura — não tem permissões para editar este concurso.</p>
                    @endif

                    <form method="POST" action="{{ route('tenders.update', $tender) }}" class="space-y-4"
                          @if(!$canEdit) inert @endif>
                        @csrf
                        @method('PATCH')

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Título</label>
                            <input type="text" name="title" value="{{ old('title', $tender->title) }}"
                                   maxlength="500"
                                   class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Estado</label>
                                <select name="status" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    @foreach($statusLabels as $k => $label)
                                        <option value="{{ $k }}" @selected(old('status', $tender->status) === $k)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Nº Oportunidade SAP</label>
                                <input type="text" name="sap_opportunity_number"
                                       value="{{ old('sap_opportunity_number', $tender->sap_opportunity_number) }}"
                                       maxlength="64"
                                       placeholder="ex.: SAP-2026-0451"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Tipo</label>
                                <input type="text" name="type" value="{{ old('type', $tender->type) }}"
                                       maxlength="64"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Organização</label>
                                <input type="text" name="purchasing_org" value="{{ old('purchasing_org', $tender->purchasing_org) }}"
                                       maxlength="255"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Deadline (UTC)</label>
                                <input type="datetime-local" name="deadline_at"
                                       value="{{ old('deadline_at', $tender->deadline_at?->format('Y-m-d\TH:i')) }}"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Prioridade</label>
                                <input type="text" name="priority" value="{{ old('priority', $tender->priority) }}"
                                       maxlength="16"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Resultado</label>
                                <input type="text" name="result" value="{{ old('result', $tender->result) }}"
                                       maxlength="64"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Valor da proposta</label>
                                <input type="number" step="0.01" min="0" name="offer_value"
                                       value="{{ old('offer_value', $tender->offer_value) }}"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Moeda</label>
                                <input type="text" name="currency" value="{{ old('currency', $tender->currency) }}"
                                       maxlength="3" placeholder="EUR"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm uppercase">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Horas dedicadas</label>
                                <input type="number" step="0.1" min="0" name="time_spent_hours"
                                       value="{{ old('time_spent_hours', $tender->time_spent_hours) }}"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 flex items-center gap-2">
                                <span>Notas</span>
                                @if($tender->sap_opportunity_number)
                                    <span class="inline-flex items-center gap-1 rounded bg-indigo-50 border border-indigo-200 px-1.5 py-0.5 text-[10px] font-normal text-indigo-700"
                                          title="Guardar aqui faz PATCH ao campo Remarks da oportunidade SAP #{{ $tender->getSapSequentialNo() ?: '?' }}.">
                                        🔗 sincroniza com SAP Remarks
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded bg-yellow-50 border border-yellow-200 px-1.5 py-0.5 text-[10px] font-normal text-yellow-800"
                                          title="Preenche o campo Nº Oportunidade SAP acima para activar a sincronização de notas.">
                                        ⚠ sem nº SAP — notas não sincronizam
                                    </span>
                                @endif
                            </label>
                            <textarea name="notes" rows="4"
                                      class="w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">{{ old('notes', $tender->notes) }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Para histórico inalterável, use o painel de Observações abaixo.
                                @if($tender->sap_opportunity_number)
                                    Ao guardar, o campo <em>Remarks</em> da oportunidade SAP
                                    <strong>#{{ $tender->getSapSequentialNo() ?: '?' }}</strong>
                                    é reescrito com o conteúdo das notas.
                                @endif
                            </p>
                        </div>

                        @if($canEdit)
                            <div class="flex justify-end">
                                <button type="submit"
                                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500">
                                    Guardar alterações
                                </button>
                            </div>
                        @endif
                    </form>
                </section>

                {{-- ─── Similar opportunities ─────────────────────────────── --}}
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                    <h3 class="text-base font-semibold text-gray-800 mb-4">
                        Oportunidades semelhantes
                        <span class="ml-1 text-xs font-normal text-gray-500">(histórico)</span>
                    </h3>

                    @if($similar->isEmpty())
                        <p class="text-sm text-gray-500 italic">
                            Sem histórico semelhante — este título não tem correspondências &gt;15% de similaridade.
                        </p>
                    @else
                        <ul class="space-y-3">
                            @foreach($similar as $s)
                                <li class="border border-gray-100 rounded-md p-3 hover:bg-gray-50">
                                    <a href="{{ route('tenders.show', $s) }}" class="block">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="text-xs font-mono text-gray-500">
                                                {{ strtoupper($s->source) }} · {{ $s->reference }}
                                            </div>
                                            <div class="text-xs font-semibold text-indigo-700">
                                                {{ number_format($s->similarity_score * 100, 0) }}%
                                            </div>
                                        </div>
                                        <div class="mt-1 text-sm text-gray-900 font-medium">
                                            {{ \Illuminate\Support\Str::limit($s->title, 110) }}
                                        </div>
                                        @if($s->sap_opportunity_number)
                                            <div class="mt-1 text-xs font-mono text-green-700">
                                                ✓ SAP: {{ $s->sap_opportunity_number }}
                                            </div>
                                        @endif
                                        <div class="mt-0.5 text-xs text-gray-500">
                                            {{ $statusLabels[$s->status] ?? $s->status }}
                                            @if($s->offer_value)
                                                · {{ number_format($s->offer_value, 2) }} {{ $s->currency }}
                                            @endif
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            {{-- ─── Observations (append-only) ─────────────────────────────── --}}
            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-semibold text-gray-800 mb-4">
                    Observações
                    <span class="text-xs font-normal text-gray-500">(histórico permanente — não pode ser apagado)</span>
                </h3>

                <form method="POST" action="{{ route('tenders.observe', $tender) }}" class="space-y-3">
                    @csrf
                    <textarea name="body" rows="3" maxlength="5000" required
                              placeholder="Adicionar observação…"
                              class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    <div class="flex justify-end">
                        <button type="submit"
                                class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Adicionar observação
                        </button>
                    </div>
                </form>
            </section>

            {{-- ─── Last import provenance ─────────────────────────────────── --}}
            @if($tender->lastImport)
                <section class="rounded-lg bg-gray-50 border border-gray-100 p-4 text-xs text-gray-600">
                    Última importação:
                    <span class="font-mono">{{ $tender->lastImport->file_name }}</span>
                    em {{ $tender->lastImport->created_at->format('d/m/Y H:i') }}
                    ({{ $tender->lastImport->source }})
                </section>
            @endif
        </div>
    </div>

    {{-- ─── "Copy full info" helper ─────────────────────────────────────────
         Builds a server-side plain-text snapshot so agents (Richar, etc.) or
         external notes can receive every relevant field in one paste. The
         payload is JSON-encoded into a data attribute and copied to the
         clipboard on click. Avoids any client-side templating surprises. --}}
    @php
        $payload = collect([
            'CONCURSO'        => strtoupper($tender->source) . ' · ' . $tender->reference,
            'TÍTULO'          => $tender->title,
            'TIPO'            => $tender->type ?: '—',
            'ORGANIZAÇÃO'     => $tender->purchasing_org ?: '—',
            'ESTADO'          => $statusLabels[$tender->status] ?? $tender->status,
            'COLABORADOR'     => $tender->collaborator?->name ?? '—',
            'EMAIL COLAB.'    => $tender->collaborator?->digest_email ?? '—',
            'DEADLINE'        => $tender->deadline_lisbon?->format('d/m/Y H:i') ?: '—',
            'Nº SAP'          => $tender->sap_opportunity_number ?: '—',
            'VALOR'           => $tender->offer_value ? number_format((float) $tender->offer_value, 2, ',', '.') . ' ' . ($tender->currency ?: '') : '—',
            'HORAS GASTAS'    => $tender->time_spent_hours ? (float) $tender->time_spent_hours . 'h' : '—',
            'RESULTADO'       => $tender->result ?: '—',
            'URL'             => rtrim(config('app.url'), '/') . '/tenders/' . $tender->id,
        ])
        ->map(fn($v, $k) => str_pad($k, 17) . ': ' . $v)
        ->implode("\n");

        $payload .= "\n\n=== NOTAS / OBSERVAÇÕES ===\n" . ($tender->notes ?: '(sem notas)');
    @endphp

    <script>
        // Using textarea-based fallback so non-HTTPS localhost also works.
        window.copyTenderInfo = async function () {
            const payload = @json($payload);
            const label   = document.getElementById('copy-btn-label');
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(payload);
                } else {
                    // Legacy fallback for older browsers / insecure contexts.
                    const ta = document.createElement('textarea');
                    ta.value = payload;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                if (label) { label.textContent = '✓ Copiado!'; setTimeout(() => label.textContent = 'Copiar info completa', 2000); }
            } catch (e) {
                alert('Não foi possível copiar: ' + e.message);
            }
        };
    </script>
</x-app-layout>
