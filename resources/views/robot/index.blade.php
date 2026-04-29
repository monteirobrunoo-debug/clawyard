{{--
    /robot — anatomia do robot. Mostra TODOS os slots em grelha com
    estado (preenchido/vazio), peça actual, agente owner, custo, e
    instruções de montagem inline.

    Layout:
      • Hero header: progresso N/M + total gasto USD
      • Missing slots panel (se aplicável): callout dos próximos slots a comprar
      • Grelha 2-col de slot cards, todos com mesma altura, 3 zonas
        (header com slot, body com estado/peça, footer com acção)
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            🤖 Anatomia do robot
            <span class="ml-2 text-xs font-normal text-gray-500">construção autónoma pelos agentes — uma peça por slot</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4">

        {{-- ── Hero progresso ─────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
            <div class="flex items-center gap-6 flex-wrap">
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-gray-500">Progresso</div>
                    <div class="text-3xl font-bold text-gray-900">{{ $filledSlots }} <span class="text-gray-400 font-normal">/ {{ $totalSlots }}</span></div>
                    <div class="text-xs text-gray-500">slots preenchidos</div>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <div class="h-3 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-3 bg-gradient-to-r from-emerald-400 to-indigo-500 rounded-full transition-all"
                             style="width: {{ $totalSlots ? round(($filledSlots / $totalSlots) * 100) : 0 }}%"></div>
                    </div>
                    <div class="text-[10px] text-gray-400 mt-1 text-right">
                        {{ $totalSlots ? round(($filledSlots / $totalSlots) * 100) : 0 }}% do robot construído
                    </div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-gray-500">Total gasto</div>
                    <div class="text-2xl font-bold text-amber-700">${{ number_format($totalCost, 2) }}</div>
                </div>
            </div>
        </div>

        {{-- ── Missing slots callout (se houver) ────────────────────── --}}
        @if(!empty($missingSlots))
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5">
                <div class="flex items-baseline justify-between flex-wrap gap-2">
                    <h3 class="text-sm font-semibold text-amber-900">
                        🎯 Slots em falta ({{ count($missingSlots) }})
                    </h3>
                    <span class="text-xs text-amber-700">
                        Próximas shop rounds vão tentar preencher estes
                    </span>
                </div>
                <div class="flex flex-wrap gap-2 mt-3">
                    @foreach($missingSlots as $row)
                        <span class="px-2 py-1 rounded-md bg-white border border-amber-200 text-xs">
                            {{ $row['meta']['emoji'] }} {{ $row['meta']['label'] }}
                            @if(!empty($row['owners']))
                                <span class="text-gray-500 ml-1">— @foreach($row['owners'] as $o){{ $o['emoji'] ?? '🤖' }}@endforeach</span>
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Grelha de slots — todos com mesma altura ─────────────── --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 auto-rows-fr">
            @foreach($slotRows as $row)
                @php
                    $meta   = $row['meta'];
                    $order  = $row['order'];
                    $filled = $row['filled'];
                @endphp

                <article class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col overflow-hidden
                                {{ $filled ? 'border-emerald-200' : 'border-gray-100' }}">

                    {{-- HEADER do slot — emoji grande + label + status pill --}}
                    <header class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100
                                   {{ $filled ? 'bg-emerald-50/40' : 'bg-gray-50' }}">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-3xl shrink-0">{{ $meta['emoji'] }}</span>
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-gray-900 truncate">{{ $meta['label'] }}</div>
                                <div class="text-[11px] text-gray-500 leading-tight line-clamp-1">{{ $meta['purpose'] }}</div>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap
                                     {{ $filled ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500' }}">
                            {{ $filled ? '✓ filled' : 'empty' }}
                        </span>
                    </header>

                    {{-- BODY: filled state OR empty placeholder --}}
                    <div class="flex-1 px-4 py-3 flex flex-col gap-2">
                        @if($filled)
                            <div class="flex items-baseline justify-between gap-2 flex-wrap">
                                <span class="text-base font-bold text-gray-900">{{ $order->name }}</span>
                                <span class="font-mono text-sm font-bold text-emerald-700">${{ number_format((float) $order->cost_usd, 2) }}</span>
                            </div>
                            @if($order->purpose)
                                <div class="text-xs text-gray-700 italic">{{ $order->purpose }}</div>
                            @elseif($order->description)
                                <div class="text-xs text-gray-600 line-clamp-2">{{ $order->description }}</div>
                            @endif
                            <div class="text-[11px] text-gray-500 flex items-center gap-2 flex-wrap">
                                <span>comprado por</span>
                                <a href="{{ route('agents.profile', $order->agent_key) }}" class="font-semibold text-indigo-700 hover:underline">
                                    {{ $row['agent']['emoji'] ?? '🤖' }} {{ $row['agent']['name'] ?? $order->agent_key }}
                                </a>
                                <span class="text-gray-400">· {{ $order->created_at->diffForHumans() }}</span>
                            </div>
                        @else
                            <div class="text-xs text-gray-500 italic">
                                Slot vazio — espera por uma shop round dos agentes responsáveis.
                            </div>
                            <div class="text-[11px] text-gray-500">
                                <span class="font-semibold">Owners:</span>
                                <span class="ml-1">
                                    @foreach($row['owners'] as $o)
                                        <span class="inline-flex items-center gap-1 mr-2">
                                            {{ $o['emoji'] ?? '🤖' }} {{ $o['name'] ?? $o['key'] ?? '?' }}
                                        </span>
                                    @endforeach
                                </span>
                            </div>
                            <div class="text-[11px] text-gray-400">
                                <span class="font-semibold">Tipos típicos:</span> {{ $meta['typical_parts'] }}
                            </div>
                        @endif
                    </div>

                    {{-- FOOTER: acções (download STL, source, ver assembly notes) --}}
                    @if($filled)
                        <footer class="flex items-center justify-between gap-2 px-4 py-2.5 bg-gray-50 border-t border-gray-100 text-xs">
                            <div class="flex items-center gap-3">
                                @if($order->source_url)
                                    <a href="{{ $order->source_url }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline">🔗 fonte</a>
                                @endif
                                @if($order->stl_path)
                                    <a href="{{ $order->stlDownloadUrl() }}" class="text-emerald-700 font-semibold hover:underline">📦 .stl</a>
                                @endif
                                @if(count($order->committee_log ?? []))
                                    <a href="{{ route('marketplace.index') }}#order-{{ $order->id }}" class="text-gray-500 hover:underline" title="ver deliberação">🗣️ {{ count($order->committee_log) }}</a>
                                @endif
                            </div>
                            <span class="text-gray-400 text-[11px]">{{ $order->statusLabel() }}</span>
                        </footer>

                        {{-- Assembly notes inline (se geradas) --}}
                        @if($order->assembly_notes)
                            <details class="border-t border-gray-100 bg-gray-50/50">
                                <summary class="cursor-pointer px-4 py-2 text-[11px] font-semibold text-gray-700 hover:bg-gray-100 select-none">
                                    📐 Como montar (clica para expandir)
                                </summary>
                                <div class="px-4 py-3 text-xs text-gray-700 leading-relaxed bg-white prose prose-sm max-w-none"
                                     style="white-space:pre-wrap;">{{ $order->assembly_notes }}</div>
                            </details>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</x-app-layout>
