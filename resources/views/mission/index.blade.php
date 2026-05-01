{{--
    /mission — Mission Control. Single-pane view for managers.
    Auto-refreshes every 60s via meta. Deliberately data-dense.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 flex items-center gap-2">
                🛰️ Mission Control
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-800 px-2 py-0.5 text-[10px] font-bold">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-600 animate-pulse"></span>
                    LIVE
                </span>
            </h2>
            <div class="text-xs text-gray-500">Auto-refresh 60s · {{ now()->format('d/m H:i') }}</div>
        </div>
    </x-slot>

    {{-- Auto-reload silently every 60s + activity-toasts opt-in.
         The toast system sees this meta and starts polling /api/activity-feed
         to surface new events as glass toasts. Only on Mission since the
         operator opens it deliberately to monitor activity. --}}
    @push('head')
        <meta http-equiv="refresh" content="60">
        <meta name="cy-activity-toasts" content="enabled">
    @endpush

    <div class="py-4">
        <div class="mx-auto max-w-[1600px] px-2 sm:px-4 lg:px-6 space-y-3">

            {{-- Top KPI strip --}}
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                @php $tot = max(1, (int) $pipelineStats['live_tenders']); @endphp
                @include('partials.ring-chart', ['label' => 'Concursos live',  'value' => $pipelineStats['live_tenders'],    'total' => $pipelineStats['live_tenders'], 'tone' => 'gray'])
                @include('partials.ring-chart', ['label' => 'Em atraso',       'value' => $pipelineStats['overdue'],         'total' => $tot, 'tone' => 'red'])
                @include('partials.ring-chart', ['label' => 'Sem SAP',         'value' => $pipelineStats['need_sap'],        'total' => $tot, 'tone' => 'amber'])
                @include('partials.ring-chart', ['label' => 'Leads confident', 'value' => $pipelineStats['confident_leads'], 'total' => max(1,$pipelineStats['confident_leads']+$pipelineStats['review_leads']), 'tone' => 'emerald'])
                @include('partials.ring-chart', ['label' => 'Leads review',    'value' => $pipelineStats['review_leads'],    'total' => max(1,$pipelineStats['confident_leads']+$pipelineStats['review_leads']), 'tone' => 'blue'])
                @include('partials.ring-chart', ['label' => 'Drafts pending',  'value' => $pipelineStats['pending_drafts'],  'total' => max(1,$pipelineStats['pending_drafts']),  'tone' => 'indigo'])
            </div>

            {{-- 3-column workspace --}}
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">

                {{-- COL 1 — Concursos --}}
                <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                    <header class="px-4 py-2 bg-gradient-to-r from-indigo-50 to-white border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">📋 Concursos</h3>
                    </header>

                    @if($criticalTenders->isNotEmpty())
                    <div class="px-3 pt-2">
                        <div class="text-[10px] uppercase tracking-wider text-amber-700 font-bold mb-1">⏰ Críticos (≤7d)</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($criticalTenders as $t)
                                <li class="py-1.5 flex items-center justify-between gap-2 text-xs">
                                    <a href="{{ route('tenders.show', $t) }}" class="text-indigo-700 hover:underline truncate">{{ \Illuminate\Support\Str::limit($t->title, 50) }}</a>
                                    <span class="font-mono text-amber-700 shrink-0">{{ $t->days_to_deadline }}d</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @if($overdueTenders->isNotEmpty())
                    <div class="px-3 pt-3">
                        <div class="text-[10px] uppercase tracking-wider text-red-700 font-bold mb-1">🔴 Em atraso</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($overdueTenders as $t)
                                <li class="py-1.5 flex items-center justify-between gap-2 text-xs">
                                    <a href="{{ route('tenders.show', $t) }}" class="text-red-700 hover:underline truncate">{{ \Illuminate\Support\Str::limit($t->title, 50) }}</a>
                                    <span class="font-mono text-red-700 shrink-0">-{{ abs($t->days_to_deadline) }}d</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @if($needSapTenders->isNotEmpty())
                    <div class="px-3 py-3">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 font-bold mb-1">⚠ Sem nº SAP</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($needSapTenders as $t)
                                <li class="py-1.5 text-xs">
                                    <a href="{{ route('tenders.show', $t) }}" class="text-gray-700 hover:text-indigo-700 hover:underline">
                                        {{ \Illuminate\Support\Str::limit($t->title, 60) }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                </section>

                {{-- COL 2 — Leads + drafts --}}
                <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                    <header class="px-4 py-2 bg-gradient-to-r from-emerald-50 to-white border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">⚡ Leads + Drafts</h3>
                    </header>

                    @if($confidentLeads->isNotEmpty())
                    <div class="px-3 pt-2">
                        <div class="text-[10px] uppercase tracking-wider text-emerald-700 font-bold mb-1">🟢 Confident (top 10)</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($confidentLeads as $l)
                                <li class="py-1.5 flex items-center justify-between gap-2 text-xs">
                                    <a href="{{ route('leads.show', $l) }}" class="text-emerald-700 hover:underline truncate flex-1">
                                        {{ \Illuminate\Support\Str::limit($l->title, 50) }}
                                    </a>
                                    <span class="font-bold text-emerald-700 shrink-0">{{ $l->score }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @if($pendingDrafts->isNotEmpty())
                    <div class="px-3 pt-3 pb-3">
                        <div class="text-[10px] uppercase tracking-wider text-amber-700 font-bold mb-1">📝 Drafts pendentes de aprovação</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($pendingDrafts as $l)
                                <li class="py-1.5 flex items-center justify-between gap-2 text-xs">
                                    <a href="{{ route('leads.show', $l) }}" class="text-amber-700 hover:underline truncate">
                                        {{ \Illuminate\Support\Str::limit($l->title, 50) }}
                                    </a>
                                    <span class="text-gray-500 shrink-0">{{ $l->outreach_drafted_at?->diffForHumans(['short' => true]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @if($reviewLeads->isNotEmpty())
                    <div class="px-3 pb-3">
                        <div class="text-[10px] uppercase tracking-wider text-blue-700 font-bold mb-1">🔵 Review</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($reviewLeads as $l)
                                <li class="py-1.5 flex items-center justify-between gap-2 text-xs">
                                    <a href="{{ route('leads.show', $l) }}" class="text-blue-700 hover:underline truncate">{{ \Illuminate\Support\Str::limit($l->title, 50) }}</a>
                                    <span class="text-gray-500 shrink-0">{{ $l->score }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                </section>

                {{-- COL 3 — Directório fornecedores --}}
                <section class="rounded-lg bg-white border border-gray-100 shadow-sm overflow-hidden">
                    <header class="px-4 py-2 bg-gradient-to-r from-blue-50 to-white border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">🏭 Fornecedores</h3>
                    </header>
                    <div class="px-3 py-3 grid grid-cols-2 gap-2">
                        @include('partials.ring-chart', ['label' => 'Aprovados', 'value' => $supplierStats['approved'],   'total' => max(1, $supplierStats['total']), 'tone' => 'emerald'])
                        @include('partials.ring-chart', ['label' => 'Pending',   'value' => $supplierStats['pending'],    'total' => max(1, $supplierStats['total']), 'tone' => 'amber'])
                        @include('partials.ring-chart', ['label' => 'Email',     'value' => $supplierStats['with_email'], 'total' => max(1, $supplierStats['total']), 'tone' => 'blue'])
                        @include('partials.ring-chart', ['label' => 'Hoje',      'value' => $supplierStats['enriched_today'], 'total' => max(1, $supplierStats['enriched_today'] + 1), 'tone' => 'indigo', 'subline' => 'Web-enriched'])
                    </div>

                    @if($recentlyContacted->isNotEmpty())
                    <div class="px-3 pb-3">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 font-bold mb-1">📨 Contactados recentemente</div>
                        <ul class="divide-y divide-gray-50">
                            @foreach($recentlyContacted as $s)
                                <li class="py-1.5 flex items-center justify-between gap-2 text-xs">
                                    <a href="{{ route('suppliers.show', $s) }}" class="text-blue-700 hover:underline truncate">
                                        {{ \Illuminate\Support\Str::limit($s->name, 40) }}
                                    </a>
                                    <span class="text-gray-500 shrink-0">{{ $s->last_contacted_at?->diffForHumans(['short' => true]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <div class="px-3 pb-3">
                        @if($supplierStats['pending'] > 0)
                            <a href="{{ route('suppliers.review') }}" class="block w-full rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white text-center hover:bg-amber-500">
                                📋 Rever {{ $supplierStats['pending'] }} pending
                            </a>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
