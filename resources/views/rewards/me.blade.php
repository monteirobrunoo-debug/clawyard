{{--
    /rewards/me — personal dashboard. Surfaces:
      • Lifetime points + level + progress bar to next level
      • Current vs best streak
      • Earned badges (placeholder until C4 ships the catalogue)
      • 14-day spark of points/day
      • Last 30 days of earning events with their points

    Layout intentionally tight — this is glance content, not a
    research page. One screen on a 1366×768 laptop, no scroll
    until "recent events".
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            🏆 Os teus rewards
            <span class="ml-2 text-xs font-normal text-gray-500">pontos, nível, badges & atividade recente</span>
        </h2>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4">

        {{-- ── Hero card: points + level + progress ─────────────────── --}}
        <div class="bg-white rounded-xl shadow p-6 mb-4 flex items-center gap-6 flex-wrap">
            <div>
                <div class="text-xs uppercase tracking-wider text-gray-500">Pontos totais</div>
                <div class="text-4xl font-bold text-gray-900">{{ number_format($points->total_points) }}</div>
            </div>
            <div class="border-l border-gray-200 pl-6 flex-1 min-w-[260px]">
                <div class="flex items-baseline justify-between mb-1">
                    <div>
                        <span class="text-sm font-semibold text-indigo-700">Nível {{ $points->level }}</span>
                        <span class="text-sm text-gray-700 ml-2">{{ $points->levelName() }}</span>
                    </div>
                    @if($points->pointsToNextLevel() > 0)
                        <div class="text-xs text-gray-500">
                            faltam <strong>{{ number_format($points->pointsToNextLevel()) }}</strong>
                            para {{ $levelNames[$points->level + 1] ?? '?' }}
                        </div>
                    @else
                        <div class="text-xs text-amber-700 font-semibold">Nível máximo atingido 🎖️</div>
                    @endif
                </div>
                @php
                    $cur  = $thresholds[$points->level] ?? 0;
                    $next = $thresholds[$points->level + 1] ?? null;
                    $pct  = $next ? min(100, round((($points->total_points - $cur) / max(1, $next - $cur)) * 100)) : 100;
                @endphp
                <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-2 bg-indigo-500 rounded-full" style="width: {{ $pct }}%"></div>
                </div>
            </div>
            <div class="border-l border-gray-200 pl-6">
                <div class="text-xs uppercase tracking-wider text-gray-500">Streak atual</div>
                <div class="text-2xl font-bold text-orange-600">🔥 {{ $points->current_streak_days }}</div>
                <div class="text-xs text-gray-500">melhor: {{ $points->best_streak_days }} dias</div>
            </div>
        </div>

        {{-- ── 14-day spark ──────────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <div class="flex items-baseline justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-800">Pontos por dia (últimos 14)</h3>
                <span class="text-xs text-gray-500">{{ array_sum($dailyTotals) }} pts no período</span>
            </div>
            @php $maxDay = max(1, max($dailyTotals)); @endphp
            <div class="flex items-end gap-1 h-20">
                @foreach($dailyTotals as $day => $pts)
                    <div class="flex-1 flex flex-col items-center gap-1" title="{{ $day }}: {{ $pts }} pts">
                        <div class="w-full bg-indigo-200 rounded-t"
                             style="height: {{ max(2, round(($pts / $maxDay) * 64)) }}px;
                                    {{ $pts === 0 ? 'background:#e5e7eb;' : '' }}"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-[10px] text-gray-400 mt-1">
                <span>{{ array_key_first($dailyTotals) }}</span>
                <span>{{ array_key_last($dailyTotals) }}</span>
            </div>
        </div>

        {{-- ── Badges ───────────────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-800 mb-2">Badges</h3>
            @if(empty($points->badges))
                <p class="text-xs text-gray-500 italic">
                    Ainda sem badges. Algumas vão chegar quando fechares o teu primeiro lead, atingires 7 dias de streak, ou usares 5 agentes diferentes.
                </p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach($points->badges as $key)
                        <span class="px-2 py-1 bg-amber-100 text-amber-800 rounded-full text-xs font-semibold">{{ $key }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Recent events table ─────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-baseline justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Atividade — últimos 30 dias</h3>
                <span class="text-xs text-gray-500">{{ $recentEvents->count() }} eventos</span>
            </div>
            @if($recentEvents->isEmpty())
                <p class="px-4 py-6 text-sm text-gray-500 italic">
                    Sem actividade ainda. Importa um concurso, qualifica um lead, ou usa um agente para começar a ganhar pontos.
                </p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-2 text-left">Quando</th>
                            <th class="px-4 py-2 text-left">Evento</th>
                            <th class="px-4 py-2 text-left">Agente</th>
                            <th class="px-4 py-2 text-right">Pontos</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($recentEvents as $ev)
                            <tr>
                                <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ $ev->created_at->diffForHumans() }}</td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-gray-900">{{ str_replace('_', ' ', $ev->event_type) }}</span>
                                    @if(($ev->metadata['cap_reached'] ?? false))
                                        <span class="ml-1 text-[10px] text-gray-400">(cap)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ $ev->agent_key ?? '—' }}</td>
                                <td class="px-4 py-2 text-right font-mono {{ $ev->points > 0 ? 'text-emerald-700' : 'text-gray-400' }}">
                                    {{ $ev->points > 0 ? '+' : '' }}{{ $ev->points }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if(auth()->user()->isManager())
            <div class="mt-4 text-center">
                <a href="{{ route('rewards.leaderboard') }}"
                   class="text-sm text-indigo-700 hover:text-indigo-900 font-semibold">
                    Ver leaderboard H&P →
                </a>
            </div>
        @endif
    </div>
</x-app-layout>
