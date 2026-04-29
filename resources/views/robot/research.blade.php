<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            🔬 Conselho de pesquisa
            <span class="ml-2 text-xs font-normal text-gray-500">agentes a investigar e validar melhorias do robot</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-5 flex items-baseline gap-6 flex-wrap">
            <div>
                <div class="text-[10px] uppercase tracking-wider text-gray-500">Sessões</div>
                <div class="text-2xl font-bold text-gray-900">{{ $totalReports }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wider text-gray-500">Custo total LLM</div>
                <div class="text-2xl font-bold text-amber-700">${{ number_format($totalCost, 4) }}</div>
            </div>
            <div class="ml-auto text-[11px] text-gray-500 max-w-md">
                Cada sessão: 4 agentes pesquisam na net + escrevem findings + o lead sintetiza propostas.
                Cron semanal aos domingos 04:00 Lisbon. Forçar agora: <code class="bg-gray-100 px-1 rounded">php artisan agents:research-council</code>
            </div>
        </div>

        @if($reports->isEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
                <div class="text-4xl mb-2">🤔</div>
                <p class="text-sm">Nenhuma sessão de pesquisa ainda.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($reports as $report)
                    @php
                        $lead = $agentCatalog->get($report->leading_agent) ?? ['name' => $report->leading_agent, 'emoji' => '🤖'];
                    @endphp
                    <article class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

                        {{-- HEADER: tópico + lead + status + cost --}}
                        <header class="flex items-start gap-3 px-5 py-4 border-b border-gray-100 bg-indigo-50/40">
                            <span class="text-3xl shrink-0">{{ $lead['emoji'] ?? '🤖' }}</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-base font-bold text-gray-900 leading-tight">{{ $report->topic }}</div>
                                <div class="text-xs text-gray-600 mt-1">
                                    Liderado por <span class="font-semibold text-indigo-700">{{ $lead['name'] ?? $report->leading_agent }}</span>
                                    @if($report->participants)
                                        @php $coParticipants = collect($report->participants)->reject(fn($k) => $k === $report->leading_agent); @endphp
                                        · com
                                        @foreach($coParticipants as $pkey)
                                            @php $p = $agentCatalog->get($pkey); @endphp
                                            <span class="ml-1">{{ $p['emoji'] ?? '🤖' }} {{ $p['name'] ?? $pkey }}</span>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                            <div class="text-right text-xs">
                                <div class="font-semibold text-gray-700">{{ $report->statusLabel() }}</div>
                                <div class="text-gray-400 mt-0.5">${{ number_format((float) $report->total_cost_usd, 4) }}</div>
                                <div class="text-gray-400">{{ $report->created_at->diffForHumans() }}</div>
                            </div>
                        </header>

                        {{-- BODY: final summary --}}
                        @if($report->final_summary)
                            <div class="px-5 py-4 prose prose-sm max-w-none text-gray-800" style="white-space:pre-wrap;">
                                {{ $report->final_summary }}
                            </div>
                        @endif

                        {{-- PROPOSALS --}}
                        @if(!empty($report->proposals))
                            <div class="px-5 py-3 bg-emerald-50/40 border-t border-emerald-100">
                                <div class="text-xs font-semibold text-emerald-900 mb-2">📋 Propostas accionáveis</div>
                                <ul class="space-y-1.5 text-xs">
                                    @foreach($report->proposals as $p)
                                        <li class="flex items-start gap-2">
                                            <span class="px-1.5 py-0.5 rounded bg-white border border-emerald-200 text-[10px] font-mono text-emerald-700 shrink-0">{{ $p['kind'] ?? '?' }}</span>
                                            <span class="text-gray-800">{{ $p['suggestion'] ?? '(sem sugestão)' }}</span>
                                            @if(!empty($p['target']))
                                                <span class="text-gray-400 text-[10px]">→ {{ $p['target'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- FINDINGS expandable --}}
                        @if(!empty($report->findings))
                            <details class="border-t border-gray-100">
                                <summary class="cursor-pointer px-5 py-2 text-[11px] font-semibold text-gray-700 hover:bg-gray-50 select-none">
                                    🔍 Findings detalhados ({{ count($report->findings) }} contribuições)
                                </summary>
                                <div class="px-5 py-4 space-y-4 bg-gray-50/30">
                                    @foreach($report->findings as $f)
                                        @php $a = $agentCatalog->get($f['agent_key'] ?? '') ?? ['name' => $f['agent_key'] ?? '?', 'emoji' => '🤖']; @endphp
                                        <div>
                                            <div class="text-xs font-semibold text-gray-800 mb-1">
                                                {{ $a['emoji'] ?? '🤖' }} {{ $a['name'] ?? '?' }}
                                                @if(!empty($f['persona_angle']))
                                                    <span class="text-gray-500 font-normal text-[10px]">— {{ $f['persona_angle'] }}</span>
                                                @endif
                                            </div>
                                            <div class="text-xs text-gray-700 leading-relaxed pl-5" style="white-space:pre-wrap;">{{ $f['findings_md'] }}</div>
                                            @if(!empty($f['search_query']))
                                                <div class="text-[10px] text-gray-400 mt-1 pl-5">🔎 search: <code>{{ $f['search_query'] }}</code></div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
