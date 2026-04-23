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
        'overdue'  => 'bg-red-100 text-red-800 border-red-300',
        'critical' => 'bg-orange-100 text-orange-800 border-orange-300',
        'urgent'   => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'soon'     => 'bg-blue-100 text-blue-800 border-blue-300',
        'normal'   => 'bg-gray-100 text-gray-700 border-gray-300',
        'unknown'  => 'bg-gray-50 text-gray-500 border-gray-200',
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
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Concursos
            </h2>
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
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

            {{-- ─── Flash status ──────────────────────────────────────────── --}}
            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            {{-- ─── Stat cards ────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach([
                    ['label' => 'Total',        'value' => $stats['total'],       'color' => 'text-gray-800'],
                    ['label' => 'Activos',      'value' => $stats['active'],      'color' => 'text-indigo-700'],
                    ['label' => 'Em atraso',    'value' => $stats['overdue'],     'color' => 'text-red-600'],
                    ['label' => 'Urgentes ≤7d', 'value' => $stats['urgent'],      'color' => 'text-orange-600'],
                    ['label' => 'Sem nº SAP',   'value' => $stats['needing_sap'], 'color' => 'text-yellow-700'],
                ] as $card)
                    <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-100">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                        <div class="mt-1 text-2xl font-bold {{ $card['color'] }}">{{ $card['value'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- ─── "Mine" strip ──────────────────────────────────────────── --}}
            @if($mine->count() > 0)
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                    <header class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                        <h3 class="text-sm font-semibold text-gray-800">
                            Os meus concursos activos
                            <span class="ml-2 text-xs font-normal text-gray-500">({{ $mine->count() }})</span>
                        </h3>
                    </header>
                    <ul class="divide-y divide-gray-100">
                        @foreach($mine as $t)
                            <li class="px-4 py-3 hover:bg-gray-50">
                                <a href="{{ route('tenders.show', $t) }}" class="block">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-xs font-mono text-gray-500">{{ $t->reference }}</span>
                                                <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                                    @if($t->urgency_bucket === 'overdue')
                                                        Em atraso {{ abs($t->days_to_deadline) }}d
                                                    @elseif($t->days_to_deadline !== null)
                                                        {{ $t->days_to_deadline }}d
                                                    @else
                                                        —
                                                    @endif
                                                </span>
                                                @if(empty($t->sap_opportunity_number))
                                                    <span class="inline-flex rounded border border-yellow-300 bg-yellow-50 px-2 py-0.5 text-xs text-yellow-800">
                                                        Sem nº SAP
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="mt-1 text-sm font-medium text-gray-900 truncate">{{ $t->title }}</div>
                                        </div>
                                        <div class="text-right text-xs text-gray-500 shrink-0">
                                            @if($t->deadline_at)
                                                <div>🇵🇹 {{ $t->deadline_lisbon->format('d/m H:i') }}</div>
                                                <div>🇱🇺 {{ $t->deadline_luxembourg->format('d/m H:i') }}</div>
                                            @else
                                                <span>sem deadline</span>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
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
                        <option value="overdue"  @selected($filters['urgency'] === 'overdue')>Em atraso</option>
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
                <section class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                    <header class="px-4 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between gap-3 flex-wrap">
                        <h3 class="text-sm font-semibold text-gray-800">
                            @if($canViewAll)
                                Todos os concursos
                            @else
                                Os meus concursos
                            @endif
                            <span class="ml-2 text-xs font-normal text-gray-500">({{ $all->total() }})</span>
                        </h3>

                        @if($canAssign)
                            <div class="flex items-center gap-2">
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
                            </div>
                        @endif
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    @if($canAssign)
                                        <th class="px-3 py-2 w-8">
                                            <input type="checkbox" id="select-all" class="rounded border-gray-300">
                                        </th>
                                    @endif
                                    <th class="px-3 py-2 text-left">Fonte / Ref</th>
                                    <th class="px-3 py-2 text-left">Título</th>
                                    <th class="px-3 py-2 text-left">Colaborador</th>
                                    <th class="px-3 py-2 text-left">Estado</th>
                                    <th class="px-3 py-2 text-left">Nº SAP</th>
                                    <th class="px-3 py-2 text-left">Deadline (PT / LU)</th>
                                    <th class="px-3 py-2 text-left">Urgência</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($all as $t)
                                    <tr class="hover:bg-gray-50 text-sm">
                                        @if($canAssign)
                                            <td class="px-3 py-2">
                                                <input type="checkbox" name="tender_ids[]" value="{{ $t->id }}"
                                                       class="rounded border-gray-300 row-check">
                                            </td>
                                        @endif
                                        <td class="px-3 py-2 align-top">
                                            <div class="text-xs font-semibold uppercase text-gray-600">{{ $t->source }}</div>
                                            <div class="text-xs font-mono text-gray-500">{{ $t->reference }}</div>
                                        </td>
                                        <td class="px-3 py-2 align-top max-w-md">
                                            <a href="{{ route('tenders.show', $t) }}" class="text-indigo-700 hover:underline font-medium">
                                                {{ \Illuminate\Support\Str::limit($t->title, 90) }}
                                            </a>
                                            @if($t->type)
                                                <div class="text-xs text-gray-500 mt-0.5">{{ $t->type }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top text-gray-700">
                                            {{ $t->collaborator?->name ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                                {{ $statusLabels[$t->status] ?? $t->status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 align-top font-mono text-xs">
                                            {{ $t->sap_opportunity_number ?: '—' }}
                                        </td>
                                        <td class="px-3 py-2 align-top text-xs text-gray-600">
                                            @if($t->deadline_at)
                                                <div>🇵🇹 {{ $t->deadline_lisbon->format('d/m/y H:i') }}</div>
                                                <div>🇱🇺 {{ $t->deadline_luxembourg->format('d/m/y H:i') }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <span class="inline-flex rounded border px-2 py-0.5 text-xs font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                                @if($t->urgency_bucket === 'overdue')
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
        </div>
    </div>
</x-app-layout>
