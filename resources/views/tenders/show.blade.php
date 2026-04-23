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
        <div class="flex items-center gap-3">
            <a href="{{ route('tenders.index') }}" class="text-sm text-indigo-600 hover:underline">← Voltar</a>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 truncate">
                {{ strtoupper($tender->source) }} · {{ $tender->reference }}
            </h2>
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
                        <dl class="space-y-1">
                            <div>
                                <dt class="inline text-gray-500">🇵🇹 Lisboa:</dt>
                                <dd class="inline font-medium text-gray-800">
                                    {{ $tender->deadline_lisbon?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="inline text-gray-500">🇱🇺 Luxemburgo:</dt>
                                <dd class="inline font-medium text-gray-800">
                                    {{ $tender->deadline_luxembourg?->format('d/m/Y H:i') ?? '—' }}
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
                            <label class="block text-xs font-medium text-gray-700 mb-1">Notas</label>
                            <textarea name="notes" rows="4"
                                      class="w-full rounded-md border-gray-300 text-sm shadow-sm font-mono">{{ old('notes', $tender->notes) }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Para histórico inalterável, use o painel de Observações abaixo.
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
</x-app-layout>
