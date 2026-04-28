{{--
    /rewards/leaderboard — H&P-wide ranking. Manager+ only (gated in
    the controller). Two stacked panels:
      1. Top users by lifetime points
      2. Top agents by leads_generated (cross-reference for agent A/B)
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            🏆 Leaderboard H&amp;P
            <span class="ml-2 text-xs font-normal text-gray-500">ranking interno + top agentes</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 space-y-6">

        {{-- ── Top users ───────────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-baseline justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Top utilizadores</h3>
                <span class="text-xs text-gray-500">{{ $rows->count() }} ranqueados</span>
            </div>
            @if($rows->isEmpty())
                <p class="px-4 py-6 text-sm text-gray-500 italic">
                    Ainda ninguém ganhou pontos. Assim que os utilizadores começarem a usar o sistema o ranking vai povoar-se automaticamente.
                </p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-2 text-left w-10">#</th>
                            <th class="px-4 py-2 text-left">Nome</th>
                            <th class="px-4 py-2 text-left">Nível</th>
                            <th class="px-4 py-2 text-right">Pontos</th>
                            <th class="px-4 py-2 text-right">Streak</th>
                            <th class="px-4 py-2 text-left">Badges</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rows as $idx => $row)
                            <tr class="{{ $idx < 3 ? 'bg-amber-50' : '' }}">
                                <td class="px-4 py-2 font-mono text-gray-500">
                                    @if($idx === 0) 🥇
                                    @elseif($idx === 1) 🥈
                                    @elseif($idx === 2) 🥉
                                    @else {{ $idx + 1 }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-semibold text-gray-900">
                                    {{ $row->name }}
                                    <span class="ml-1 text-[10px] text-gray-500">{{ $row->role }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-800 font-semibold">
                                        {{ \App\Models\UserPoints::LEVEL_NAMES[$row->level] ?? '?' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right font-mono font-bold">{{ number_format($row->total_points) }}</td>
                                <td class="px-4 py-2 text-right">
                                    🔥 {{ $row->current_streak_days }}
                                    <span class="text-[10px] text-gray-400">/ {{ $row->best_streak_days }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    @php $badges = is_array($row->badges) ? $row->badges : (json_decode($row->badges ?? '[]', true) ?: []); @endphp
                                    @if(empty($badges))
                                        <span class="text-[10px] text-gray-400">—</span>
                                    @else
                                        <span class="text-[11px] text-gray-700">{{ count($badges) }} badge(s)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Top agents by leads_generated ──────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-baseline justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Top agentes (por leads gerados)</h3>
                <span class="text-xs text-gray-500">métricas do swarm</span>
            </div>
            @if($topAgents->isEmpty())
                <p class="px-4 py-6 text-sm text-gray-500 italic">
                    Ainda nenhum agente correu numa chain do swarm. Quando o cron <code>agents:discover-leads</code> arrancar, os agentes começam a aparecer aqui.
                </p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-2 text-left">Agente</th>
                            <th class="px-4 py-2 text-right">Signals</th>
                            <th class="px-4 py-2 text-right">Leads</th>
                            <th class="px-4 py-2 text-right">Won</th>
                            <th class="px-4 py-2 text-right">Win %</th>
                            <th class="px-4 py-2 text-right">$/lead</th>
                            <th class="px-4 py-2 text-right">Trust</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($topAgents as $m)
                            @php $meta = $m->meta(); @endphp
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('agents.profile', $m->agent_key) }}" class="font-semibold text-indigo-700 hover:text-indigo-900">
                                        {{ $meta['emoji'] ?? '🤖' }} {{ $meta['name'] ?? $m->agent_key }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-right font-mono">{{ $m->signals_processed }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $m->leads_generated }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $m->leads_won }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $m->winRate() === null ? '—' : $m->winRate() . '%' }}</td>
                                <td class="px-4 py-2 text-right font-mono text-gray-600">
                                    {{ $m->costPerLead() === null ? '—' : '$' . number_format($m->costPerLead(), 4) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono">
                                    {{ $m->trustPct() === null ? '—' : $m->trustPct() . '%' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
