{{--
    /marketplace — feed de tudo o que os agentes compraram.

    Layout:
      • Header com stats agregados (total orders, gasto, agentes activos)
      • Top 10 wallets (saldo dos agentes ricos)
      • Filtros (status + agent)
      • Lista de orders, cada uma com:
          - Card do buyer + peça + preço + status + STL link
          - Thread expansível com a deliberação (helper1 → helper2 → buyer)
            renderizada como mensagens estilo chat.
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            🛒 Marketplace dos agentes
            <span class="ml-2 text-xs font-normal text-gray-500">peças autonomamente compradas + conversas entre agentes</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4">

        {{-- ── Stats hero ─────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            <div class="bg-white rounded-xl shadow p-3">
                <div class="text-[10px] uppercase tracking-wider text-gray-500">Orders</div>
                <div class="text-2xl font-bold text-gray-900">{{ $stats['total_orders'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow p-3">
                <div class="text-[10px] uppercase tracking-wider text-gray-500">STL prontos</div>
                <div class="text-2xl font-bold text-emerald-700">{{ $stats['stl_ready'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow p-3">
                <div class="text-[10px] uppercase tracking-wider text-gray-500">Cancelados</div>
                <div class="text-2xl font-bold text-gray-500">{{ $stats['cancelled'] }}</div>
            </div>
            <div class="bg-white rounded-xl shadow p-3">
                <div class="text-[10px] uppercase tracking-wider text-gray-500">Gasto total</div>
                <div class="text-2xl font-bold text-amber-700">${{ number_format($stats['total_spent_usd'], 2) }}</div>
            </div>
            <div class="bg-white rounded-xl shadow p-3">
                <div class="text-[10px] uppercase tracking-wider text-gray-500">Agentes activos</div>
                <div class="text-2xl font-bold text-indigo-700">{{ $stats['agents_active'] }}</div>
            </div>
        </div>

        {{-- ── Top wallets ────────────────────────────────────────── --}}
        @if($topWallets->isNotEmpty())
            <div class="bg-white rounded-xl shadow p-4 mb-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-2">💰 Top 10 wallets</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
                    @foreach($topWallets as $w)
                        @php $meta = $agentCatalog->get($w->agent_key); @endphp
                        <a href="{{ route('agents.profile', $w->agent_key) }}"
                           class="flex items-center justify-between gap-2 p-2 rounded-lg border border-gray-100 hover:bg-gray-50">
                            <span class="truncate">
                                <span class="text-base">{{ $meta['emoji'] ?? '🤖' }}</span>
                                <span class="text-xs text-gray-700 ml-1">{{ $meta['name'] ?? $w->agent_key }}</span>
                            </span>
                            <span class="font-mono text-xs font-bold text-emerald-700 whitespace-nowrap">${{ number_format((float) $w->balance_usd, 2) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Filtros ─────────────────────────────────────────────── --}}
        <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
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
                <a href="{{ route('marketplace.index') }}" class="text-xs text-gray-500 self-center">limpar</a>
            @endif
        </form>

        {{-- ── Feed de orders ──────────────────────────────────────── --}}
        @if($orders->isEmpty())
            <div class="bg-white rounded-xl shadow p-8 text-center text-gray-500">
                <div class="text-4xl mb-2">🤷</div>
                <p class="text-sm">
                    Nenhuma peça comprada ainda. O cron <code class="bg-gray-100 px-1 rounded">agents:shop</code> corre Mondays 03:00 Lisbon.
                </p>
                <p class="text-xs mt-2 text-gray-400">
                    Para forçar agora: <code class="bg-gray-100 px-1 rounded">php artisan agents:shop</code> no droplet.
                </p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($orders as $order)
                    @php
                        $buyer = $agentCatalog->get($order->agent_key) ?? ['name' => $order->agent_key, 'emoji' => '🤖'];
                        $statusColors = [
                            'committee'  => 'bg-yellow-100 text-yellow-800',
                            'searching'  => 'bg-blue-100 text-blue-800',
                            'purchased'  => 'bg-purple-100 text-purple-800',
                            'designing'  => 'bg-amber-100 text-amber-800',
                            'stl_ready'  => 'bg-emerald-100 text-emerald-800',
                            'cancelled'  => 'bg-gray-100 text-gray-600',
                        ];
                        $color = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-700';
                    @endphp

                    <div class="bg-white rounded-xl shadow overflow-hidden">

                        {{-- Header da order: comprador + nome da peça + preço --}}
                        <div class="flex items-start gap-4 p-4 border-b border-gray-100">
                            <a href="{{ route('agents.profile', $order->agent_key) }}" class="text-3xl shrink-0" title="{{ $buyer['name'] }}">
                                {{ $buyer['emoji'] ?? '🤖' }}
                            </a>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2 flex-wrap">
                                    <a href="{{ route('agents.profile', $order->agent_key) }}" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ $buyer['name'] }}</a>
                                    <span class="text-xs text-gray-500">comprou:</span>
                                    <span class="text-base font-bold text-gray-900">{{ $order->name }}</span>
                                </div>
                                @if($order->description)
                                    <div class="text-xs text-gray-600 mt-1">{{ $order->description }}</div>
                                @endif
                                <div class="flex flex-wrap items-center gap-2 mt-2 text-xs">
                                    <span class="px-2 py-0.5 rounded-full font-semibold {{ $color }}">{{ $order->statusLabel() }}</span>
                                    <span class="font-mono text-emerald-700 font-bold">${{ number_format((float) $order->cost_usd, 2) }}</span>
                                    @if($order->source_url)
                                        <a href="{{ $order->source_url }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline">🔗 fonte</a>
                                    @endif
                                    @if($order->stl_path)
                                        <a href="{{ $order->stlDownloadUrl() }}" class="text-emerald-700 font-semibold hover:underline">📦 download .stl</a>
                                    @elseif($order->design_scad)
                                        <span class="text-amber-700" title="OpenSCAD code stored — render later">.scad pronto</span>
                                    @endif
                                    <span class="ml-auto text-gray-400">{{ $order->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Thread da deliberação dos agentes --}}
                        @if(!empty($order->committee_log))
                            <details class="bg-gray-50">
                                <summary class="cursor-pointer px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 select-none">
                                    🗣️ Deliberação ({{ count($order->committee_log) }} mensagens) — clica para expandir
                                </summary>
                                <div class="p-4 space-y-3">
                                    @foreach($order->committee_log as $msg)
                                        @php
                                            $who = $agentCatalog->get($msg['agent_key'] ?? '') ?? ['name' => $msg['agent_key'] ?? '?', 'emoji' => '🤖'];
                                            $isBuyer = ($msg['role'] ?? '') === 'buyer';
                                        @endphp
                                        <div class="flex gap-3 {{ $isBuyer ? 'flex-row-reverse' : '' }}">
                                            <div class="text-2xl shrink-0">{{ $who['emoji'] ?? '🤖' }}</div>
                                            <div class="flex-1 min-w-0 {{ $isBuyer ? 'text-right' : '' }}">
                                                <div class="text-xs text-gray-500 mb-1">
                                                    <span class="font-semibold {{ $isBuyer ? 'text-indigo-700' : 'text-gray-700' }}">{{ $who['name'] ?? '?' }}</span>
                                                    <span class="ml-1 text-[10px] uppercase tracking-wider text-gray-400">{{ $msg['role'] ?? '?' }}</span>
                                                </div>
                                                <div class="inline-block max-w-full px-3 py-2 rounded-lg text-xs leading-relaxed {{ $isBuyer ? 'bg-indigo-100 text-indigo-900' : 'bg-white text-gray-800 border border-gray-200' }}"
                                                     style="white-space:pre-wrap;text-align:left;">
                                                    {{ $msg['text'] ?? '' }}
                                                </div>
                                                @if(!empty($msg['at']))
                                                    <div class="text-[10px] text-gray-400 mt-1">{{ \Carbon\Carbon::parse($msg['at'])->diffForHumans() }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        {{-- Notas / search context --}}
                        @if($order->notes || $order->search_query)
                            <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 text-[11px] text-gray-500 space-y-1">
                                @if($order->search_query)
                                    <div><span class="font-semibold">🔍 search query:</span> <code>{{ $order->search_query }}</code></div>
                                @endif
                                @if($order->notes)
                                    <div><span class="font-semibold">📝 notes:</span> {{ $order->notes }}</div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
