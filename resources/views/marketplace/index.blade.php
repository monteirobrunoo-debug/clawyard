{{--
    /marketplace — feed consolidado das peças que os agentes compraram +
    threads de deliberação inline.

    Design (revisão 2026-04-29 — "alinha o topo dos separadores"):
      • Cards em grid 2 colunas com auto-rows-fr → todos exactamente
        a mesma altura, botões alinhados na base.
      • Paleta consistente: header indigo, body branco, footer cinza
        claro. Único colorido é o status badge (sinal funcional).
      • Footer com botões de acção numa linha única, sempre na mesma
        posição independentemente da quantidade de descrição.
      • Stats hero + Top wallets uniformes (mesma altura, mesma sombra).
      • Thread de deliberação sai do card e fica em largura total
        abaixo, expansível.
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            🛒 Marketplace dos agentes
            <span class="ml-2 text-xs font-normal text-gray-500">peças autonomamente compradas + conversas entre agentes</span>
        </h2>
    </x-slot>

    {{-- Inline CSS para o toggle do <details>:
         · Esconde "Ver" quando aberto, mostra "Esconder" quando aberto
           (Tailwind 3.1 não tem variant `group-open:`, daí ser inline)
         · Roda a setinha 180° quando aberto
         · Estiliza o botão "Voltar a agrupar" como uma summary alternativa
           dentro do conteúdo expandido (com `data-action="collapse"` para
           o JS no fim da página detectar o click). --}}
    <style>
        details[open] [data-when="closed"] { display: none; }
        details:not([open]) [data-when="open"] { display: none; }
        details[open] [data-rotate-on-open] { transform: rotate(180deg); }
        [data-rotate-on-open] { transition: transform 150ms ease-out; }
        /* Esconde a setinha nativa (▶) do <summary> — usamos o nosso ▼
           personalizado que roda quando aberto. Cobrir os dois browsers:
           Chromium/Edge usam ::marker, Safari/old WebKit usam ::-webkit-details-marker. */
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details > summary::marker { content: ""; }
    </style>

    <div class="py-6 max-w-6xl mx-auto px-4">

        {{-- ── Stats hero — 5 cards uniformes ───────────────────────── --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            @php
                $heroStats = [
                    ['label' => 'Orders',          'value' => $stats['total_orders'],                         'tone' => 'text-gray-900'],
                    ['label' => 'STL prontos',     'value' => $stats['stl_ready'],                            'tone' => 'text-emerald-700'],
                    ['label' => 'Cancelados',      'value' => $stats['cancelled'],                            'tone' => 'text-gray-500'],
                    ['label' => 'Gasto total',     'value' => '$' . number_format($stats['total_spent_usd'], 2), 'tone' => 'text-amber-700'],
                    ['label' => 'Agentes activos', 'value' => $stats['agents_active'],                        'tone' => 'text-indigo-700'],
                ];
            @endphp
            @foreach($heroStats as $hs)
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 flex flex-col justify-between min-h-[78px]">
                    <div class="text-[10px] uppercase tracking-wider text-gray-500">{{ $hs['label'] }}</div>
                    <div class="text-2xl font-bold {{ $hs['tone'] }}">{{ $hs['value'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- ── Top wallets ────────────────────────────────────────── --}}
        @if($topWallets->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">💰 Top 10 wallets</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
                    @foreach($topWallets as $w)
                        @php $meta = $agentCatalog->get($w->agent_key); @endphp
                        <a href="{{ route('agents.profile', $w->agent_key) }}"
                           class="flex items-center justify-between gap-2 p-2 rounded-lg border border-gray-100 hover:border-indigo-200 hover:bg-indigo-50/30 transition">
                            <span class="truncate flex items-center gap-1">
                                <span class="text-base">{{ $meta['emoji'] ?? '🤖' }}</span>
                                <span class="text-xs text-gray-700 truncate">{{ $meta['name'] ?? $w->agent_key }}</span>
                            </span>
                            <span class="font-mono text-xs font-bold text-emerald-700 whitespace-nowrap shrink-0">${{ number_format((float) $w->balance_usd, 2) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Filtros ─────────────────────────────────────────────── --}}
        <form method="GET" class="flex flex-wrap gap-2 mb-4 items-center text-sm">
            <select name="status" class="border-gray-200 rounded text-xs">
                <option value="">Todos os estados</option>
                @foreach($statusOptions as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ $s }}</option>
                @endforeach
            </select>
            <select name="agent_key" class="border-gray-200 rounded text-xs">
                <option value="">Todos os agentes</option>
                @foreach($agentKeysWithOrders as $key)
                    <option value="{{ $key }}" @selected($filters['agent_key'] === $key)>
                        {{ $agentCatalog->get($key)['name'] ?? $key }}
                    </option>
                @endforeach
            </select>
            <button class="px-3 py-1 bg-indigo-600 text-white rounded text-xs font-semibold hover:bg-indigo-700">Filtrar</button>
            @if($filters['status'] || $filters['agent_key'])
                <a href="{{ route('marketplace.index') }}" class="text-xs text-gray-500">limpar</a>
            @endif
        </form>

        {{-- ── Feed de orders ──────────────────────────────────────── --}}
        @if($orders->isEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
                <div class="text-4xl mb-2">🤷</div>
                <p class="text-sm">
                    Nenhuma peça comprada ainda. O cron <code class="bg-gray-100 px-1 rounded">agents:shop</code> corre Mondays 03:00 Lisbon.
                </p>
                <p class="text-xs mt-2 text-gray-400">
                    Para forçar agora: <code class="bg-gray-100 px-1 rounded">php artisan agents:shop</code> no droplet.
                </p>
            </div>
        @else
            {{-- Grid de cards. items-start (não auto-rows-fr) para que
                 quando um card expande a sua deliberação, o card vizinho
                 NÃO seja esticado com whitespace vazio em baixo.
                 (Issue 2026-04-30: ao abrir colunas, layout ficava
                 desformatado.) --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                @foreach($orders as $order)
                    @php
                        $buyer = $agentCatalog->get($order->agent_key) ?? ['name' => $order->agent_key, 'emoji' => '🤖'];
                        $statusColors = [
                            'committee'  => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                            'searching'  => 'bg-blue-100 text-blue-800 border-blue-200',
                            'purchased'  => 'bg-purple-100 text-purple-800 border-purple-200',
                            'designing'  => 'bg-amber-100 text-amber-800 border-amber-200',
                            'stl_ready'  => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                            'cancelled'  => 'bg-gray-100 text-gray-600 border-gray-200',
                        ];
                        $color = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                    @endphp

                    {{-- Card com flex-col + flex-1 no body para empurrar footer para baixo --}}
                    <article class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">

                        {{-- HEADER: indigo claro, sempre 56px, comprador + status --}}
                        <header class="flex items-center justify-between gap-3 px-4 py-3 bg-indigo-50/40 border-b border-gray-100">
                            <a href="{{ route('agents.profile', $order->agent_key) }}" class="flex items-center gap-2 min-w-0">
                                <span class="text-2xl shrink-0">{{ $buyer['emoji'] ?? '🤖' }}</span>
                                <span class="text-sm font-semibold text-indigo-700 hover:text-indigo-900 truncate">{{ $buyer['name'] }}</span>
                            </a>
                            <span class="px-2 py-0.5 rounded-full border text-[11px] font-semibold whitespace-nowrap shrink-0 {{ $color }}">{{ $order->statusLabel() }}</span>
                        </header>

                        {{-- BODY: cresce, ocupa o espaço necessário --}}
                        <div class="flex-1 px-4 py-3 flex flex-col gap-2">
                            <div class="text-base font-bold text-gray-900 leading-tight">{{ $order->name }}</div>
                            @if($order->description)
                                <div class="text-xs text-gray-600 leading-relaxed line-clamp-3">{{ $order->description }}</div>
                            @endif
                            @if($order->search_query)
                                <div class="text-[11px] text-gray-400">
                                    🔍 <code class="text-gray-500">{{ $order->search_query }}</code>
                                </div>
                            @endif
                        </div>

                        {{-- FOOTER: bg cinza claro, sempre na mesma posição (alinhado em todos os cards) --}}
                        <footer class="flex items-center justify-between gap-2 px-4 py-2.5 bg-gray-50 border-t border-gray-100 text-xs">
                            <span class="font-mono font-bold text-emerald-700">${{ number_format((float) $order->cost_usd, 2) }}</span>
                            <div class="flex items-center gap-3 ml-auto">
                                @if($order->source_url)
                                    <a href="{{ $order->source_url }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline whitespace-nowrap">🔗 fonte</a>
                                @endif
                                @if($order->stl_path)
                                    <a href="{{ $order->stlDownloadUrl() }}" class="text-emerald-700 font-semibold hover:underline whitespace-nowrap">📦 .stl</a>
                                @elseif($order->design_scad)
                                    <span class="text-amber-700 whitespace-nowrap" title="OpenSCAD pronto, STL não renderizado">📐 .scad</span>
                                @endif
                                @if(!empty($order->committee_log))
                                    <span class="text-gray-500 whitespace-nowrap" title="{{ count($order->committee_log) }} mensagens na deliberação">🗣️ {{ count($order->committee_log) }}</span>
                                @endif
                                @php $vs = $order->validationSummary(); @endphp
                                @if($vs['count'] > 0)
                                    <span class="whitespace-nowrap font-semibold {{ $vs['concerns'] > 0 ? 'text-amber-700' : 'text-emerald-700' }}"
                                          title="{{ $vs['approves'] }} aprova / {{ $vs['concerns'] }} concern">
                                        {{ $vs['badge'] }} {{ $vs['count'] }}
                                    </span>
                                @endif
                                <span class="text-gray-400 whitespace-nowrap">{{ $order->created_at->diffForHumans() }}</span>
                            </div>
                        </footer>

                        {{-- Validations expansíveis (peer review) --}}
                        @if(!empty($order->validations))
                            <details class="bg-emerald-50/30 border-t border-emerald-100">
                                <summary class="cursor-pointer flex items-center justify-between gap-2 px-4 py-2 text-[11px] font-semibold text-emerald-800 hover:bg-emerald-50 select-none list-none">
                                    <span class="flex items-center gap-1.5">
                                        <span data-when="closed">🔍 Ver peer review ({{ count($order->validations) }} reviews)</span>
                                        <span data-when="open">🔼 Esconder peer review</span>
                                    </span>
                                    <span data-rotate-on-open class="text-emerald-600">▼</span>
                                </summary>
                                <div class="px-4 py-3 space-y-2 bg-white">
                                    @foreach($order->validations as $v)
                                        @php
                                            $rev = $agentCatalog->get($v['agent_key'] ?? '') ?? ['name' => $v['agent_key'] ?? '?', 'emoji' => '🤖'];
                                            $isApprove = ($v['verdict'] ?? '') === 'approve';
                                        @endphp
                                        <div class="flex items-start gap-2 text-xs">
                                            <span class="text-base shrink-0 mt-0.5">{{ $isApprove ? '✅' : '⚠️' }}</span>
                                            <div class="flex-1 min-w-0">
                                                <span class="font-semibold {{ $isApprove ? 'text-emerald-700' : 'text-amber-700' }}">
                                                    {{ $rev['emoji'] }} {{ $rev['name'] }}
                                                </span>
                                                <span class="text-gray-700">{{ $v['note'] ?? '' }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                    {{-- Botão de fecho dentro do conteúdo expandido — facilita
                                         o utilizador encontrar como voltar a agrupar sem
                                         precisar de scroll-up até à summary. --}}
                                    <div class="pt-2 mt-1 border-t border-emerald-100 flex justify-end">
                                        <button type="button" data-action="collapse"
                                                class="text-[11px] font-semibold text-emerald-700 hover:text-emerald-900 hover:underline">
                                            🔼 Voltar a agrupar peer review
                                        </button>
                                    </div>
                                </div>
                            </details>
                        @endif

                        {{-- DELIBERAÇÃO expansível — fora do flex flow do body para não afectar altura --}}
                        @if(!empty($order->committee_log))
                            <details class="bg-gray-50/50 border-t border-gray-100">
                                <summary class="cursor-pointer flex items-center justify-between gap-2 px-4 py-2 text-[11px] font-semibold text-gray-700 hover:bg-gray-100 select-none list-none">
                                    <span class="flex items-center gap-1.5">
                                        <span data-when="closed">🗣️ Ver deliberação ({{ count($order->committee_log) }} mensagens)</span>
                                        <span data-when="open">🔼 Esconder deliberação</span>
                                    </span>
                                    <span data-rotate-on-open class="text-gray-500">▼</span>
                                </summary>
                                <div class="p-4 space-y-3 bg-white">
                                    @foreach($order->committee_log as $msg)
                                        @php
                                            $who = $agentCatalog->get($msg['agent_key'] ?? '') ?? ['name' => $msg['agent_key'] ?? '?', 'emoji' => '🤖'];
                                            $isBuyer = ($msg['role'] ?? '') === 'buyer';
                                        @endphp
                                        <div class="flex gap-2 {{ $isBuyer ? 'flex-row-reverse' : '' }}">
                                            <div class="text-xl shrink-0 leading-none mt-1">{{ $who['emoji'] ?? '🤖' }}</div>
                                            <div class="flex-1 min-w-0 {{ $isBuyer ? 'text-right' : '' }}">
                                                <div class="text-[10px] text-gray-500 mb-1">
                                                    <span class="font-semibold {{ $isBuyer ? 'text-indigo-700' : 'text-gray-700' }}">{{ $who['name'] ?? '?' }}</span>
                                                    <span class="ml-1 uppercase tracking-wider text-gray-400">{{ $msg['role'] ?? '?' }}</span>
                                                </div>
                                                <div class="inline-block max-w-full px-3 py-2 rounded-lg text-xs leading-relaxed {{ $isBuyer ? 'bg-indigo-100 text-indigo-900' : 'bg-gray-100 text-gray-800 border border-gray-200' }}"
                                                     style="white-space:pre-wrap;text-align:left;">{{ $msg['text'] ?? '' }}</div>
                                                @if(!empty($msg['at']))
                                                    <div class="text-[10px] text-gray-400 mt-1">{{ \Carbon\Carbon::parse($msg['at'])->diffForHumans() }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                    {{-- Botão "Voltar a agrupar" no fim da conversa para
                                         o utilizador fechar facilmente após ler — em vez
                                         de ter de fazer scroll-up até à summary. --}}
                                    <div class="pt-3 mt-1 border-t border-gray-100 flex justify-end">
                                        <button type="button" data-action="collapse"
                                                class="text-[11px] font-semibold text-gray-600 hover:text-gray-900 hover:underline">
                                            🔼 Voltar a agrupar conversa
                                        </button>
                                    </div>
                                </div>
                            </details>
                        @endif

                        {{-- Notas em vermelho discreto, só se houver erro --}}
                        @if($order->notes)
                            <div class="px-4 py-2 bg-red-50/50 border-t border-red-100 text-[11px] text-red-600">
                                📝 {{ $order->notes }}
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Handler para os botões "Voltar a agrupar" — fecha o <details>
         pai e faz scroll suave até ao topo desse <details> para o user
         ver visualmente que voltou a agrupar (sem ficar perdido no meio
         da página). Vanilla JS, sem dependências. --}}
    <script>
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-action="collapse"]');
            if (!btn) return;
            const det = btn.closest('details');
            if (!det) return;
            det.removeAttribute('open');
            // Trazer a summary para vista — o card pode ter ficado off-screen
            // depois de o user ter scrollado dentro de uma deliberação longa.
            det.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    </script>
</x-app-layout>
