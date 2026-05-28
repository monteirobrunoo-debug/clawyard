<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🩺 Agent Health Dashboard
            <span class="text-sm font-normal text-gray-500">— performance + feedback por agente (30d)</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- ─── Agents table: runs, fails, latency, cost, feedback ───── --}}
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                <h3 class="text-sm font-medium text-gray-700">Saúde de todos os agentes (30d)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Agente</th>
                            <th class="px-4 py-2 text-right">Runs</th>
                            <th class="px-4 py-2 text-right">Fail %</th>
                            <th class="px-4 py-2 text-right">Avg s</th>
                            <th class="px-4 py-2 text-right">p50</th>
                            <th class="px-4 py-2 text-right">p95</th>
                            <th class="px-4 py-2 text-right">Cost / run</th>
                            <th class="px-4 py-2 text-right">👍</th>
                            <th class="px-4 py-2 text-right">👎</th>
                            <th class="px-4 py-2 text-right">Score</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($agents as $a)
                            @php
                                $m = $metrics->get($a->agent_key);
                                $l = $latency->get($a->agent_key);
                                $up    = (int) ($m->thumbs_up ?? 0);
                                $down  = (int) ($m->thumbs_down ?? 0);
                                $total = $up + $down;
                                $score = $total > 0 ? round(($up / $total) * 100, 0) : null;

                                $failClass = $a->fail_rate_pct >= 10
                                    ? 'text-red-600 font-medium'
                                    : ($a->fail_rate_pct >= 3 ? 'text-amber-600' : 'text-gray-600');

                                $scoreClass = $score === null
                                    ? 'text-gray-400'
                                    : ($score >= 80 ? 'text-emerald-600 font-medium'
                                        : ($score >= 50 ? 'text-gray-600' : 'text-red-600 font-medium'));
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 font-mono text-xs">{{ $a->agent_key }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($a->runs) }}</td>
                                <td class="px-4 py-2 text-right {{ $failClass }}">
                                    {{ $a->fail_rate_pct }}%
                                </td>
                                <td class="px-4 py-2 text-right text-gray-600">
                                    {{ $a->avg_seconds !== null ? $a->avg_seconds . 's' : '—' }}
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500 text-xs">
                                    {{ $l ? round($l->p50_ms / 1000, 1) . 's' : '—' }}
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500 text-xs">
                                    {{ $l ? round($l->p95_ms / 1000, 1) . 's' : '—' }}
                                </td>
                                <td class="px-4 py-2 text-right text-gray-600">${{ number_format($a->cost_per_run, 4) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-700">{{ $up }}</td>
                                <td class="px-4 py-2 text-right text-red-700">{{ $down }}</td>
                                <td class="px-4 py-2 text-right {{ $scoreClass }}">
                                    {{ $score === null ? '—' : $score . '%' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">Sem corridas registadas nos últimos 30 dias.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-2 border-t border-gray-100 bg-gray-50 text-xs text-gray-500">
                <strong>Fail %:</strong> ≥10% = 🔴 problema · ≥3% = 🟠 vigiar · <3% = 🟢 ok &nbsp;|&nbsp;
                <strong>Score:</strong> 👍/(👍+👎) — ≥80% = ótimo · &lt;50% = mau
            </div>
        </div>

        {{-- ─── Recent fails (debug) ──────────────────────────────────── --}}
        @if($recentFails->isNotEmpty())
            <div class="bg-white rounded-lg border border-red-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-red-100 bg-red-50">
                    <h3 class="text-sm font-medium text-red-800">Últimas 10 falhas (debug)</h3>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Quando</th>
                            <th class="px-4 py-2 text-left">Agente</th>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-left">Tender</th>
                            <th class="px-4 py-2 text-left">Erro</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($recentFails as $f)
                            <tr>
                                <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($f->created_at)->diffForHumans() }}
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $f->agent_key }}</td>
                                <td class="px-4 py-2 text-xs">{{ $f->user_name ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs">
                                    @if($f->tender_id)
                                        <a href="{{ route('tenders.show', $f->tender_id) }}" class="text-indigo-600 hover:underline">#{{ $f->tender_id }}</a>
                                    @else — @endif
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-700 max-w-md truncate" title="{{ $f->error }}">
                                    {{ \Illuminate\Support\Str::limit($f->error, 120) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="text-xs text-gray-500 text-center">
            Fontes: <code>agent_runs</code> (durations + status) +
            <code>agent_metrics</code> (👍/👎 thumbs). Útil para detectar
            degradação após upgrades de modelo (ex: Opus 4-5 → 4-8 hoje).
        </p>
    </div>
</x-app-layout>
