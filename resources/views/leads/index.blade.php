{{--
    /leads — agent-swarm-discovered opportunities, manager+ only.

    Layout philosophy: it's a TRIAGE board, not a polished CRM. The
    admin needs to scan 30 leads in 90 seconds, dismiss the duds,
    assign the rest, and move on. So:
      • One row per lead with score chip + customer/equipment hint.
      • Inline status select to flip "review → contacted" without a
        modal (the form auto-submits onchange).
      • Drill-down to /leads/{id} surfaces the full chain_log so a
        sceptical admin can see exactly what each agent said.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                🎯 Lead Opportunities
                <span class="ml-2 text-xs font-normal text-gray-500">descobertas pelo agent-swarm</span>
            </h2>
            <div class="flex items-center gap-2 flex-wrap text-xs">
                <span class="rounded-full bg-emerald-100 text-emerald-800 px-2 py-1 font-semibold">
                    {{ $counts[\App\Models\LeadOpportunity::STATUS_CONFIDENT] ?? 0 }} confident
                </span>
                <span class="rounded-full bg-blue-100 text-blue-800 px-2 py-1 font-semibold">
                    {{ $counts[\App\Models\LeadOpportunity::STATUS_REVIEW] ?? 0 }} review
                </span>
                <span class="rounded-full bg-indigo-100 text-indigo-800 px-2 py-1 font-semibold">
                    {{ $counts[\App\Models\LeadOpportunity::STATUS_CONTACTED] ?? 0 }} contacted
                </span>
                <span class="rounded-full bg-gray-100 text-gray-700 px-2 py-1">
                    {{ $counts[\App\Models\LeadOpportunity::STATUS_DRAFT] ?? 0 }} drafts
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-4">

            @if(session('status'))
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-2 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Filters bar — kept as a simple GET form so the admin can
                 bookmark a filtered view ("hot leads" = ?status=confident&min_score=80). --}}
            <form method="GET" class="flex flex-wrap items-center gap-2 rounded-lg bg-white border border-gray-200 px-4 py-3 shadow-sm">
                <input type="text" name="q" value="{{ $filters['q'] }}"
                       placeholder="🔍 Procurar (título, cliente, equipamento)…"
                       class="flex-1 min-w-[240px] rounded-md border-gray-300 text-sm">
                <select name="status" class="rounded-md border-gray-300 text-sm">
                    <option value="">Todos (excepto drafts)</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                <select name="min_score" class="rounded-md border-gray-300 text-sm">
                    <option value="0">Qualquer score</option>
                    @foreach([30, 50, 70, 85] as $threshold)
                        <option value="{{ $threshold }}" @selected($filters['min_score'] === $threshold)>≥ {{ $threshold }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Filtrar</button>
                @if($filters['q'] !== '' || $filters['status'] !== '' || $filters['min_score'] > 0)
                    <a href="{{ route('leads.index') }}" class="text-xs text-gray-500 hover:underline">✗ Limpar</a>
                @endif
            </form>

            {{-- Leads list --}}
            @if($leads->total() === 0)
                <div class="rounded-lg bg-white border border-gray-200 p-10 text-center text-sm text-gray-500">
                    @if($filters['q'] !== '' || $filters['status'] !== '' || $filters['min_score'] > 0)
                        Sem resultados para os filtros actuais.
                        <a href="{{ route('leads.index') }}" class="text-indigo-600 ml-2">Limpar →</a>
                    @else
                        <p class="text-base font-medium text-gray-700 mb-2">Ainda sem leads descobertos.</p>
                        <p>O comando <code>php artisan agents:discover-leads</code> vai criar leads à medida que processa novos signals.</p>
                    @endif
                </div>
            @else
                <div class="rounded-lg bg-white shadow-sm border border-gray-200 overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left">Score</th>
                                <th class="px-3 py-2 text-left">Lead</th>
                                <th class="px-3 py-2 text-left">Origem</th>
                                <th class="px-3 py-2 text-left">Cliente / Equipamento</th>
                                <th class="px-3 py-2 text-left">Estado</th>
                                <th class="px-3 py-2 text-left">Atribuído</th>
                                <th class="px-3 py-2 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($leads as $lead)
                                @php
                                    $scoreClass = $lead->score >= 70
                                        ? 'bg-emerald-100 text-emerald-800 border-emerald-300'
                                        : ($lead->score >= 30
                                            ? 'bg-blue-100 text-blue-800 border-blue-300'
                                            : 'bg-gray-100 text-gray-600 border-gray-300');
                                @endphp
                                <tr class="hover:bg-gray-50 align-middle">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded border px-2 py-1 text-xs font-bold {{ $scoreClass }}">
                                            {{ $lead->score }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 max-w-md">
                                        <a href="{{ route('leads.show', $lead) }}" class="font-medium text-indigo-700 hover:underline">
                                            {{ \Illuminate\Support\Str::limit($lead->title, 80) }}
                                        </a>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ \Illuminate\Support\Str::limit($lead->summary, 100) }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap">
                                        <span class="font-mono text-gray-500">{{ $lead->source_signal_type }}</span>
                                        @if($lead->source_signal_id)
                                            <span class="text-gray-400">#{{ $lead->source_signal_id }}</span>
                                        @endif
                                        <div class="text-gray-400">{{ optional($lead->swarmRun)->chain_name }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        <div class="text-gray-700">{{ $lead->customer_hint ?? '—' }}</div>
                                        <div class="text-gray-500">{{ $lead->equipment_hint ?? '—' }}</div>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <form method="POST" action="{{ route('leads.update', $lead) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <select name="status" onchange="this.form.submit()"
                                                    class="text-xs rounded border-gray-300 bg-white text-gray-700">
                                                @foreach($statuses as $s)
                                                    <option value="{{ $s }}" @selected($lead->status === $s)>{{ ucfirst($s) }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    </td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap text-gray-700">
                                        @if($lead->assignedUser)
                                            {{ $lead->assignedUser->name }}
                                        @else
                                            <form method="POST" action="{{ route('leads.update', $lead) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <select name="assigned_user_id" onchange="this.form.submit()"
                                                        class="text-xs rounded border-gray-300 bg-white text-gray-700">
                                                    <option value="">Atribuir…</option>
                                                    @foreach($assignableUsers as $u)
                                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('leads.show', $lead) }}" class="text-xs text-indigo-600 hover:underline">Detalhe →</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    {{ $leads->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
