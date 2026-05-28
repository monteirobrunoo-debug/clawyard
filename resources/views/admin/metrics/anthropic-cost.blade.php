<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            💰 Anthropic Cost Dashboard
            <span class="text-sm font-normal text-gray-500">— gasto por user/agente (30d)</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- ─── KPI Cards ──────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @php
                $delta = $yesterday > 0 ? round((($today - $yesterday) / $yesterday) * 100, 1) : null;
                $deltaClass = $delta === null
                    ? 'text-gray-500'
                    : ($delta > 0 ? 'text-red-600' : 'text-emerald-600');
                $deltaSign = $delta === null ? '' : ($delta > 0 ? '+' : '');
            @endphp

            <div class="bg-white rounded-lg border {{ $anomalyToday ? 'border-red-300 ring-2 ring-red-100' : 'border-gray-200' }} p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Hoje</div>
                <div class="text-2xl font-bold mt-1">${{ number_format($today, 2) }}</div>
                @if($delta !== null)
                    <div class="text-xs mt-1 {{ $deltaClass }}">
                        {{ $deltaSign }}{{ $delta }}% vs ontem
                    </div>
                @endif
                @if($anomalyToday)
                    <div class="text-xs text-red-700 mt-2 font-medium">
                        ⚠ Anomalia: > 2× média 7d
                    </div>
                @endif
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Ontem</div>
                <div class="text-2xl font-bold mt-1">${{ number_format($yesterday, 2) }}</div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Últimos 7d</div>
                <div class="text-2xl font-bold mt-1">${{ number_format($last7d, 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">média ${{ number_format($avg7d, 2) }}/dia</div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Últimos 30d</div>
                <div class="text-2xl font-bold mt-1">${{ number_format($last30d, 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">extrapolado ${{ number_format($last30d * 12, 0) }}/ano</div>
            </div>
        </div>

        {{-- ─── Daily cost sparkline (30d) ─────────────────────────────── --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium text-gray-700">Custo diário (30d)</h3>
                <span class="text-xs text-gray-500">
                    {{ $dailyCost->count() }} dias com tráfego
                </span>
            </div>
            <canvas id="cost-chart" height="80" class="w-full"></canvas>
        </div>

        {{-- ─── Two columns: Top users + Top agents ───────────────────── --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-sm font-medium text-gray-700">Top 10 users (30d)</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-right">Runs</th>
                            <th class="px-4 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($topUsers as $u)
                            <tr>
                                <td class="px-4 py-2">{{ $u->name }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ number_format($u->runs) }}</td>
                                <td class="px-4 py-2 text-right font-medium">${{ number_format($u->total_cost, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Sem dados nos últimos 30d.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-sm font-medium text-gray-700">Top 10 agentes (30d)</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Agente</th>
                            <th class="px-4 py-2 text-right">Runs</th>
                            <th class="px-4 py-2 text-right">Avg ms</th>
                            <th class="px-4 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($topAgents as $a)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $a->agent_key }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ number_format($a->runs) }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">
                                    {{ $a->avg_ms ? number_format($a->avg_ms) : '—' }}
                                </td>
                                <td class="px-4 py-2 text-right font-medium">${{ number_format($a->total_cost, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Sem dados nos últimos 30d.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-500 text-center">
            Fonte: <code>agent_runs</code> (per-execution audit, populado pelos
            agentes que correm via tool-use loop). Custo zero do dashboard —
            read-only.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const data = @json($dailyCost->map(fn($d) => ['x' => $d->day, 'y' => (float) $d->cost])->values());
        if (!data.length) return;
        const ctx = document.getElementById('cost-chart');
        if (!ctx || !window.Chart) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.x),
                datasets: [{
                    label: 'USD',
                    data: data.map(d => d.y),
                    borderColor: '#76b900',
                    backgroundColor: 'rgba(118,185,0,0.10)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } },
                    y: { beginAtZero: true, ticks: { font: { size: 10 }, callback: v => '$' + v } },
                },
            },
        });
    });
    </script>
</x-app-layout>
