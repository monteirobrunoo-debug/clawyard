@php
    /**
     * Super-user overview — "quem tem o quê" em concursos + agent shares.
     * Read-only. Edit actions are on /tenders and /shares respectively.
     */
    $statusLabels = [
        \App\Models\Tender::STATUS_PENDING       => 'Pendente',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'Em Tratamento',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'Submetido',
        \App\Models\Tender::STATUS_AVALIACAO     => 'Em Avaliação',
    ];
    $urgencyClasses = [
        'expired'  => 'bg-gray-200 text-gray-700 border-gray-400 line-through',
        'overdue'  => 'bg-red-100 text-red-800 border-red-300',
        'critical' => 'bg-orange-100 text-orange-800 border-orange-300',
        'urgent'   => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'soon'     => 'bg-blue-100 text-blue-800 border-blue-300',
        'normal'   => 'bg-gray-100 text-gray-700 border-gray-300',
        'unknown'  => 'bg-gray-50 text-gray-500 border-gray-200',
    ];

    // Stats roll-up for the top strip
    $totalAssigned   = $collaborators->sum(fn($c) => $c->tenders->count());
    $totalUnassigned = $unassigned->count();
    $totalShares     = $agentShares->count();
    $activeColabs    = $collaborators->filter(fn($c) => $c->tenders->count() > 0)->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Partilhados — visão super-user
            </h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('tenders.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700">← Concursos</a>
                <a href="{{ route('tenders.collaborators.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700">👥 Colaboradores</a>
                <a href="{{ route('shares.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700">🔗 Shares (full)</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">

            @if(session('status'))
                <div class="rounded-md bg-yellow-50 border border-yellow-200 p-3 text-sm text-yellow-800">
                    {{ session('status') }}
                </div>
            @endif

            {{-- ─── Stats strip ──────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach([
                    ['label' => 'Concursos atribuídos', 'value' => $totalAssigned,   'color' => 'text-indigo-700'],
                    ['label' => 'Colaboradores activos', 'value' => $activeColabs,    'color' => 'text-green-700'],
                    ['label' => 'Sem atribuição',        'value' => $totalUnassigned, 'color' => $totalUnassigned ? 'text-red-600' : 'text-gray-500'],
                    ['label' => 'Agent shares activos',  'value' => $totalShares,     'color' => 'text-blue-700'],
                ] as $card)
                    <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-100">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                        <div class="mt-1 text-2xl font-bold {{ $card['color'] }}">{{ $card['value'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- ─── Section A: Concursos por pessoa ──────────────────────────── --}}
            <section class="space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <h3 class="text-lg font-semibold text-gray-800">
                        Concursos partilhados por colaborador
                    </h3>
                    <span class="text-xs text-gray-500">
                        só activos (≤{{ \App\Models\Tender::OVERDUE_WINDOW_DAYS }}d atraso) — expirados escondidos
                    </span>
                </div>

                {{-- Unassigned first (red-flag section) --}}
                @if($unassigned->isNotEmpty())
                    <div class="rounded-lg bg-red-50 border border-red-200 overflow-hidden">
                        <header class="px-4 py-3 bg-red-100 border-b border-red-200">
                            <h4 class="text-sm font-semibold text-red-800">
                                ⚠ Sem colaborador atribuído — {{ $unassigned->count() }} concursos
                            </h4>
                        </header>
                        <ul class="divide-y divide-red-100">
                            @foreach($unassigned as $t)
                                <li class="px-4 py-2 hover:bg-red-50 flex items-center gap-3 flex-wrap text-sm">
                                    <span class="text-xs font-mono text-red-800 shrink-0">{{ $t->reference }}</span>
                                    <a href="{{ route('tenders.show', $t) }}"
                                       class="text-indigo-700 hover:underline min-w-0 flex-1 truncate">
                                        {{ \Illuminate\Support\Str::limit($t->title, 90) }}
                                    </a>
                                    <span class="inline-flex rounded border px-2 py-0.5 text-xs font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                        @if($t->urgency_bucket === 'expired') expirado
                                        @elseif($t->urgency_bucket === 'overdue') {{ abs($t->days_to_deadline) }}d atraso
                                        @elseif($t->days_to_deadline !== null) {{ $t->days_to_deadline }}d
                                        @else sem deadline
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Per-collaborator card grid --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @forelse($collaborators as $c)
                        @continue($c->tenders->isEmpty())
                        <article class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                            <header class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold text-gray-900 truncate">
                                            {{ $c->name }}
                                            @if($c->user)
                                                <span class="text-xs font-normal text-gray-400">({{ $c->user->role }})</span>
                                            @endif
                                        </div>
                                        @if($c->digest_email)
                                            <a href="mailto:{{ $c->digest_email }}"
                                               class="text-xs text-gray-500 hover:text-indigo-700">{{ $c->digest_email }}</a>
                                        @else
                                            <span class="text-xs text-red-600">— sem email</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="inline-flex rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                            {{ $c->tenders->count() }} activos
                                        </span>
                                        @if($c->digest_email)
                                            <form method="POST"
                                                  action="{{ route('tenders.overview.remind', $c) }}"
                                                  onsubmit="return confirm('Enviar lembrete por email a {{ $c->name }} ({{ $c->digest_email }}) com os {{ $c->tenders->count() }} concurso(s) activo(s)?')">
                                                @csrf
                                                <button type="submit"
                                                        title="Enviar lembrete por email"
                                                        class="inline-flex items-center gap-1 rounded bg-yellow-50 border border-yellow-200 px-2 py-0.5 text-xs font-medium text-yellow-800 hover:bg-yellow-100">
                                                    📧 Lembrar
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </header>
                            <ul class="divide-y divide-gray-100">
                                @foreach($c->tenders as $t)
                                    <li class="px-4 py-2 hover:bg-gray-50 text-sm">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs font-mono text-gray-500 shrink-0">{{ $t->reference }}</span>
                                            <a href="{{ route('tenders.show', $t) }}"
                                               class="text-indigo-700 hover:underline min-w-0 flex-1 truncate">
                                                {{ \Illuminate\Support\Str::limit($t->title, 70) }}
                                            </a>
                                            <span class="inline-flex rounded border px-2 py-0.5 text-xs font-medium {{ $urgencyClasses[$t->urgency_bucket] ?? $urgencyClasses['unknown'] }}">
                                                @if($t->urgency_bucket === 'expired') expirado
                                                @elseif($t->urgency_bucket === 'overdue') {{ abs($t->days_to_deadline) }}d atraso
                                                @elseif($t->days_to_deadline !== null) {{ $t->days_to_deadline }}d
                                                @else —
                                                @endif
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500 flex gap-2 flex-wrap items-center">
                                            <span>{{ $statusLabels[$t->status] ?? $t->status }}</span>
                                            @if($t->sap_opportunity_number)
                                                <span class="inline-flex items-center gap-1 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 font-mono text-emerald-800"
                                                      title="Nº de oportunidade SAP">
                                                    💼 SAP {{ $t->sap_opportunity_number }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded border border-yellow-200 bg-yellow-50 px-1.5 py-0.5 text-yellow-800"
                                                      title="Falta criar oportunidade em SAP">
                                                    ⚠ sem oportunidade SAP
                                                </span>
                                            @endif
                                            @if($t->assignedBy)
                                                <span>atribuído por {{ $t->assignedBy->name }}</span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @empty
                        <div class="col-span-full rounded-lg bg-white shadow-sm border border-gray-100 p-8 text-center text-sm text-gray-500">
                            Nenhum colaborador tem concursos activos atribuídos.
                        </div>
                    @endforelse
                </div>

                {{-- Collaborators with zero active tenders (collapsed footer) --}}
                @php $idle = $collaborators->filter(fn($c) => $c->tenders->isEmpty()); @endphp
                @if($idle->isNotEmpty())
                    <details class="rounded-lg bg-white shadow-sm border border-gray-100">
                        <summary class="px-4 py-3 cursor-pointer text-sm text-gray-600 hover:bg-gray-50">
                            {{ $idle->count() }} colaborador{{ $idle->count() === 1 ? '' : 'es' }} sem atribuições activas
                        </summary>
                        <ul class="px-4 pb-3 pt-1 divide-y divide-gray-100">
                            @foreach($idle as $c)
                                <li class="py-1.5 text-sm text-gray-600 flex items-center justify-between gap-2">
                                    <span>{{ $c->name }}</span>
                                    <span class="text-xs text-gray-400">{{ $c->digest_email ?? '— sem email' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>

            {{-- ─── Section B: Agent Shares ─────────────────────────────────── --}}
            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800">
                        Agent shares activos
                    </h3>
                    <a href="{{ route('shares.index') }}" class="text-sm text-indigo-700 hover:underline">
                        Gerir shares →
                    </a>
                </div>

                @if($agentShares->isEmpty())
                    <div class="rounded-lg bg-white shadow-sm border border-gray-100 p-8 text-center text-sm text-gray-500">
                        Nenhum agent share activo neste momento.
                    </div>
                @else
                    <div class="rounded-lg bg-white shadow-sm border border-gray-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <th class="px-3 py-2 text-left">Cliente</th>
                                        <th class="px-3 py-2 text-left">Emails</th>
                                        <th class="px-3 py-2 text-left">Agente</th>
                                        <th class="px-3 py-2 text-left">Criado por</th>
                                        <th class="px-3 py-2 text-left">Expira</th>
                                        <th class="px-3 py-2 text-left">Usos</th>
                                        <th class="px-3 py-2 text-left">Segurança</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @foreach($agentShares as $s)
                                        <tr class="text-sm hover:bg-gray-50">
                                            <td class="px-3 py-2">
                                                <div class="font-medium text-gray-900">{{ $s->client_name ?: '—' }}</div>
                                                @if($s->custom_title)
                                                    <div class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($s->custom_title, 50) }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-xs text-gray-700">
                                                @if($s->client_email)
                                                    <div>{{ $s->client_email }}</div>
                                                @endif
                                                @if(is_array($s->additional_emails) && count($s->additional_emails))
                                                    @foreach($s->additional_emails as $e)
                                                        <div class="text-gray-500">+ {{ $e }}</div>
                                                    @endforeach
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-xs font-mono text-gray-700">{{ $s->agent_key }}</td>
                                            <td class="px-3 py-2 text-xs text-gray-600">{{ $s->creator?->name ?? '—' }}</td>
                                            <td class="px-3 py-2 text-xs text-gray-600">
                                                {{ $s->expires_at?->format('d/m/Y') ?? 'nunca' }}
                                            </td>
                                            <td class="px-3 py-2 text-xs font-mono text-gray-700">
                                                {{ $s->usage_count ?? 0 }}
                                                @if($s->last_used_at)
                                                    <div class="text-gray-400">{{ $s->last_used_at->diffForHumans() }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-xs">
                                                @if($s->require_otp)
                                                    <span class="inline-flex rounded bg-green-50 px-1.5 py-0.5 text-green-700">OTP</span>
                                                @endif
                                                @if($s->password_hash)
                                                    <span class="inline-flex rounded bg-blue-50 px-1.5 py-0.5 text-blue-700">pw</span>
                                                @endif
                                                @if($s->lock_to_device)
                                                    <span class="inline-flex rounded bg-purple-50 px-1.5 py-0.5 text-purple-700">lock</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </section>

        </div>
    </div>
</x-app-layout>
