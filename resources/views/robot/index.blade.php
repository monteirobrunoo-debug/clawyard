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

        {{-- ── Body diagram SVG ──────────────────────────────────────
             Cada slot tem uma posição no corpo. Filled = emerald-500,
             empty = gray-300. Hover mostra o nome da peça (ou owners
             se vazio). Clique = scroll-to-card lá em baixo. --}}
        @php
            $slotByKey = collect($slotRows)->keyBy('key');
            // Posições (cx, cy) para cada slot na SVG 400×600.
            $positions = [
                'brain'           => ['cx' => 200, 'cy' => 100, 'r' => 22, 'shape' => 'circle'],
                'eyes_left'       => ['cx' => 180, 'cy' => 130, 'r' =>  6, 'slot' => 'eyes'],
                'eyes_right'      => ['cx' => 220, 'cy' => 130, 'r' =>  6, 'slot' => 'eyes'],
                'ears_left'       => ['cx' => 156, 'cy' => 138, 'r' =>  5, 'slot' => 'ears'],
                'ears_right'      => ['cx' => 244, 'cy' => 138, 'r' =>  5, 'slot' => 'ears'],
                'voice'           => ['cx' => 200, 'cy' => 155, 'r' =>  8, 'shape' => 'circle'],
                'antenna'         => ['cx' => 200, 'cy' =>  50, 'r' =>  6, 'shape' => 'circle'],
                'heart'           => ['cx' => 200, 'cy' => 230, 'r' => 16, 'shape' => 'heart'],
                'compass'         => ['cx' => 170, 'cy' => 270, 'r' =>  9, 'shape' => 'circle'],
                'ambient_sensors' => ['cx' => 230, 'cy' => 270, 'r' =>  9, 'shape' => 'circle'],
                'branding'        => ['cx' => 200, 'cy' => 305, 'r' => 11, 'shape' => 'rect', 'w' => 26, 'h' => 14],
                'security'        => ['cx' => 200, 'cy' => 340, 'r' =>  8, 'shape' => 'circle'],
                'muscles_left'    => ['cx' => 115, 'cy' => 240, 'r' => 14, 'slot' => 'muscles'],
                'muscles_right'   => ['cx' => 285, 'cy' => 240, 'r' => 14, 'slot' => 'muscles'],
                'hands_left'      => ['cx' =>  90, 'cy' => 320, 'r' => 12, 'slot' => 'hands'],
                'hands_right'     => ['cx' => 310, 'cy' => 320, 'r' => 12, 'slot' => 'hands'],
                'legs_left'       => ['cx' => 175, 'cy' => 460, 'r' => 18, 'slot' => 'legs'],
                'legs_right'      => ['cx' => 225, 'cy' => 460, 'r' => 18, 'slot' => 'legs'],
                'patent_mech'     => ['cx' =>  60, 'cy' => 460, 'r' => 12, 'shape' => 'diamond'],
                'skin'            => ['cx' => 200, 'cy' => 280, 'r' =>  0, 'shape' => 'outline'],
            ];
        @endphp
        <div class="bg-gradient-to-br from-slate-50 to-indigo-50/30 rounded-xl shadow-sm border border-gray-100 p-4 mb-5">
            <div class="flex items-baseline justify-between mb-2">
                <h3 class="text-sm font-semibold text-gray-800">🤖 Body diagram</h3>
                <span class="text-xs text-gray-500">verde = preenchido · cinzento = vazio · clica para detalhe</span>
            </div>
            <div class="flex justify-center">
                <svg viewBox="0 0 400 540" xmlns="http://www.w3.org/2000/svg" style="max-width:380px;width:100%;height:auto;">
                    {{-- Skin outline (chassis) --}}
                    @php $skinFilled = ($slotByKey->get('skin')['filled'] ?? false); @endphp
                    <g stroke="{{ $skinFilled ? '#10b981' : '#d1d5db' }}" stroke-width="2" fill="none">
                        {{-- Antenna stalk --}}
                        <line x1="200" y1="60" x2="200" y2="78" stroke-width="2" />
                        {{-- Head --}}
                        <rect x="155" y="78" width="90" height="90" rx="14" stroke-width="2" />
                        {{-- Body --}}
                        <rect x="135" y="178" width="130" height="200" rx="16" stroke-width="2" />
                        {{-- Left arm --}}
                        <line x1="135" y1="200" x2="100" y2="290" stroke-width="6" stroke-linecap="round" />
                        {{-- Right arm --}}
                        <line x1="265" y1="200" x2="300" y2="290" stroke-width="6" stroke-linecap="round" />
                        {{-- Left leg --}}
                        <line x1="175" y1="378" x2="175" y2="440" stroke-width="6" stroke-linecap="round" />
                        {{-- Right leg --}}
                        <line x1="225" y1="378" x2="225" y2="440" stroke-width="6" stroke-linecap="round" />
                    </g>

                    {{-- Slots ordenados por z-index (background → foreground) --}}
                    @foreach($positions as $posKey => $pos)
                        @php
                            $slotKey = $pos['slot'] ?? $posKey;
                            $row = $slotByKey->get($slotKey);
                            if (!$row) continue;
                            $filled = $row['filled'];
                            $fill   = $filled ? '#10b981' : '#e5e7eb';
                            $stroke = $filled ? '#047857' : '#9ca3af';
                            $emoji  = $row['meta']['emoji'];
                            $label  = $row['meta']['label'];
                            $partName = $row['order']->name ?? '(vazio)';
                            $cost   = $row['order']?->cost_usd ? '$' . number_format((float) $row['order']->cost_usd, 2) : '';
                            $shape  = $pos['shape'] ?? 'circle';
                        @endphp
                        <g class="cursor-pointer" onclick="document.getElementById('slot-{{ $slotKey }}')?.scrollIntoView({behavior:'smooth',block:'center'})">
                            <title>{{ $emoji }} {{ $label }} — {{ $filled ? $partName . ' ' . $cost : 'vazio' }}</title>
                            @if($shape === 'circle')
                                <circle cx="{{ $pos['cx'] }}" cy="{{ $pos['cy'] }}" r="{{ $pos['r'] }}" fill="{{ $fill }}" stroke="{{ $stroke }}" stroke-width="2" />
                            @elseif($shape === 'heart')
                                {{-- Coração simplificado = 2 círculos + triângulo --}}
                                <path d="M {{ $pos['cx'] }} {{ $pos['cy'] + 14 }} C {{ $pos['cx'] - 18 }} {{ $pos['cy'] - 4 }}, {{ $pos['cx'] - 8 }} {{ $pos['cy'] - 18 }}, {{ $pos['cx'] }} {{ $pos['cy'] - 4 }} C {{ $pos['cx'] + 8 }} {{ $pos['cy'] - 18 }}, {{ $pos['cx'] + 18 }} {{ $pos['cy'] - 4 }}, {{ $pos['cx'] }} {{ $pos['cy'] + 14 }} Z" fill="{{ $fill }}" stroke="{{ $stroke }}" stroke-width="2" />
                            @elseif($shape === 'rect')
                                <rect x="{{ $pos['cx'] - $pos['w']/2 }}" y="{{ $pos['cy'] - $pos['h']/2 }}" width="{{ $pos['w'] }}" height="{{ $pos['h'] }}" rx="3" fill="{{ $fill }}" stroke="{{ $stroke }}" stroke-width="2" />
                            @elseif($shape === 'diamond')
                                <polygon points="{{ $pos['cx'] }},{{ $pos['cy'] - $pos['r'] }} {{ $pos['cx'] + $pos['r'] }},{{ $pos['cy'] }} {{ $pos['cx'] }},{{ $pos['cy'] + $pos['r'] }} {{ $pos['cx'] - $pos['r'] }},{{ $pos['cy'] }}" fill="{{ $fill }}" stroke="{{ $stroke }}" stroke-width="2" />
                            @endif
                            {{-- Emoji label sobre o ponto, só visível para os slots maiores --}}
                            @if(($pos['r'] ?? 0) >= 12)
                                <text x="{{ $pos['cx'] }}" y="{{ $pos['cy'] + 4 }}" text-anchor="middle" font-size="13">{{ $emoji }}</text>
                            @endif
                        </g>
                    @endforeach

                    {{-- Wheels base (locomotion) — círculos largos no fundo
                         se 'legs' estiver filled, indica também que o robot já tem mobilidade --}}
                    @php $legsFilled = ($slotByKey->get('legs')['filled'] ?? false); @endphp
                    <circle cx="175" cy="478" r="14" fill="{{ $legsFilled ? '#1f2937' : '#e5e7eb' }}" stroke="{{ $legsFilled ? '#000' : '#9ca3af' }}" stroke-width="2" />
                    <circle cx="225" cy="478" r="14" fill="{{ $legsFilled ? '#1f2937' : '#e5e7eb' }}" stroke="{{ $legsFilled ? '#000' : '#9ca3af' }}" stroke-width="2" />

                    {{-- Labels textuais opcionais (lateral) --}}
                    <text x="200" y="510" text-anchor="middle" font-size="10" fill="#6b7280">{{ $filledSlots }} / {{ $totalSlots }} slots</text>
                </svg>
            </div>
            <div class="flex justify-center gap-4 mt-3 text-[11px] text-gray-600">
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-emerald-500 border border-emerald-700"></span> preenchido</span>
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-gray-200 border border-gray-400"></span> vazio</span>
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

                <article id="slot-{{ $row['key'] }}" class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col overflow-hidden
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
