{{-- ═══════════════════════════════════════════════════════════════════════
     Saúde dos Agentes — dashboard manager+
     Métricas dos últimos 7 dias por agente: chats, tokens, custo, latência
     p50/p95, taxa de falha, thumbs. Gráfico de chats por dia.
     ═══════════════════════════════════════════════════════════════════════ --}}
<x-app-layout>
    <x-slot name="title">Saúde dos Agentes · ClawYard</x-slot>

    <div style="max-width:1400px;margin:0 auto;padding:24px 16px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
            <h1 style="margin:0;font-size:22px;font-weight:700;color:var(--text);">🩺 Saúde dos agentes</h1>
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="font-size:12px;color:var(--muted);">Janela:</span>
                <select id="range-select" style="background:var(--surface);color:var(--text);border:1px solid var(--border);padding:6px 10px;border-radius:8px;font-size:12px;">
                    <option value="7d" selected>Últimos 7 dias</option>
                    <option value="30d">Últimos 30 dias</option>
                    <option value="90d">Últimos 90 dias</option>
                </select>
            </div>
        </div>

        {{-- KPIs topo --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:24px;">
            @php
                $kpis = [
                    ['💬', 'Chats',            number_format($totals['chats']),       '#76b900'],
                    ['📥', 'Tokens in',        number_format($totals['tokens_in']),   '#3b82f6'],
                    ['📤', 'Tokens out',       number_format($totals['tokens_out']),  '#8b5cf6'],
                    ['💰', 'Custo total',      '$' . number_format($totals['cost_usd'], 4), '#f59e0b'],
                    ['⚠️', '% falhados',       $totals['failed_pct'] . '%',           $totals['failed_pct'] > 5 ? '#ef4444' : '#22c55e'],
                ];
            @endphp
            @foreach($kpis as [$emoji, $label, $value, $color])
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 16px;">
                    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1.4px;margin-bottom:4px;">{{ $emoji }} {{ $label }}</div>
                    <div style="font-size:22px;font-weight:700;color:{{ $color }};">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        {{-- Gráfico chats/dia --}}
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:24px;">
            <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px;">📊 Volume de chats por dia (top 6 agentes)</div>
            <div style="position:relative;height:300px;">
                <canvas id="chats-chart"></canvas>
            </div>
        </div>

        {{-- Tabela por agente --}}
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:24px;">
            <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
                <span style="font-size:13px;font-weight:600;color:var(--text);">📋 Métricas detalhadas por agente</span>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="background:rgba(0,0,0,0.15);color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">
                            <th style="text-align:left;padding:10px 14px;">Agente</th>
                            <th style="text-align:right;padding:10px 14px;">Chats</th>
                            <th style="text-align:right;padding:10px 14px;">Tokens in/out</th>
                            <th style="text-align:right;padding:10px 14px;">Custo</th>
                            <th style="text-align:right;padding:10px 14px;">Lat. p50</th>
                            <th style="text-align:right;padding:10px 14px;">Lat. p95</th>
                            <th style="text-align:right;padding:10px 14px;">👍 / 👎</th>
                            <th style="text-align:right;padding:10px 14px;">% Falha</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $r)
                        @php
                            $meta = $agentMeta[$r->agent] ?? null;
                            $name = $meta['name'] ?? $r->name;
                            $photo = $meta['photo'] ?? null;
                            $color = $meta['color'] ?? '#76b900';
                            $failClass = $r->fail_pct > 10 ? '#ef4444' : ($r->fail_pct > 3 ? '#f59e0b' : '#22c55e');
                            $latClass  = $r->p95_latency > 15000 ? '#ef4444' : ($r->p95_latency > 8000 ? '#f59e0b' : '#22c55e');
                        @endphp
                        <tr style="border-top:1px solid var(--border);">
                            <td style="padding:10px 14px;">
                                <a href="/chat?agent={{ $r->agent }}" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);">
                                    @if($photo)
                                        <img src="{{ $photo }}" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid {{ $color }};">
                                    @else
                                        <span style="width:28px;height:28px;border-radius:50%;background:{{ $color }}22;border:1.5px solid {{ $color }};display:inline-flex;align-items:center;justify-content:center;">{{ $r->emoji }}</span>
                                    @endif
                                    <span style="font-weight:600;">{{ $name }}</span>
                                </a>
                            </td>
                            <td style="text-align:right;padding:10px 14px;font-weight:700;">{{ number_format($r->chats) }}</td>
                            <td style="text-align:right;padding:10px 14px;color:var(--muted);">
                                {{ number_format($r->tokens_in) }} / {{ number_format($r->tokens_out) }}
                            </td>
                            <td style="text-align:right;padding:10px 14px;font-family:monospace;">
                                {{ $r->cost_usd > 0 ? '$' . number_format($r->cost_usd, 4) : '—' }}
                            </td>
                            <td style="text-align:right;padding:10px 14px;color:{{ $latClass }};">{{ number_format($r->p50_latency) }} ms</td>
                            <td style="text-align:right;padding:10px 14px;color:{{ $latClass }};">{{ number_format($r->p95_latency) }} ms</td>
                            <td style="text-align:right;padding:10px 14px;">
                                <span style="color:#22c55e;">{{ $r->thumbs_up }}</span>
                                <span style="color:var(--muted);">/</span>
                                <span style="color:#ef4444;">{{ $r->thumbs_down }}</span>
                                @if($r->trust_pct !== null)
                                    <span style="color:var(--muted);font-size:10px;margin-left:6px;">({{ $r->trust_pct }}%)</span>
                                @endif
                            </td>
                            <td style="text-align:right;padding:10px 14px;color:{{ $failClass }};font-weight:600;">
                                {{ $r->fail_pct }}%
                                @if($r->failed > 0)
                                    <span style="color:var(--muted);font-weight:normal;font-size:10px;">({{ $r->failed }})</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="padding:20px;text-align:center;color:var(--muted);">Sem dados ainda — aguarda primeiras conversas após a migração.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Prompts falhados recentes --}}
        @if($failedRecent->count() > 0)
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
                <span style="font-size:13px;font-weight:600;color:var(--text);">⚠️ Últimos prompts falhados ({{ $failedRecent->count() }})</span>
                <span style="font-size:11px;color:var(--muted);margin-left:8px;">— marcados via 👎 ou erro no stream</span>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <tbody>
                @foreach($failedRecent as $m)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:8px 14px;color:var(--muted);font-family:monospace;">#{{ $m->id }}</td>
                        <td style="padding:8px 14px;">{{ $m->agent }}</td>
                        <td style="padding:8px 14px;color:var(--muted);">{{ $m->model ?? '—' }}</td>
                        <td style="padding:8px 14px;color:var(--muted);">{{ $m->latency_ms ? number_format($m->latency_ms) . ' ms' : '—' }}</td>
                        <td style="padding:8px 14px;color:var(--muted);text-align:right;">{{ $m->created_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        const COLORS = ['#76b900','#3b82f6','#8b5cf6','#f59e0b','#ec4899','#06b6d4','#22c55e','#ef4444'];
        let chart;

        async function loadChart(range) {
            const res = await fetch(`/agents/health/data?range=${range}`, {
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();
            const agents = Object.keys(data.series).slice(0, 6); // top 6
            const datasets = agents.map((a, i) => ({
                label: a,
                data: data.series[a],
                borderColor: COLORS[i % COLORS.length],
                backgroundColor: COLORS[i % COLORS.length] + '22',
                tension: 0.3,
                fill: false,
            }));
            const ctx = document.getElementById('chats-chart').getContext('2d');
            if (chart) chart.destroy();
            chart = new Chart(ctx, {
                type: 'line',
                data: { labels: data.dates, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { color: '#aaa' } } },
                    scales: {
                        x: { ticks: { color: '#888' }, grid: { color: 'rgba(255,255,255,0.06)' } },
                        y: { ticks: { color: '#888' }, grid: { color: 'rgba(255,255,255,0.06)' }, beginAtZero: true },
                    },
                },
            });
        }

        document.getElementById('range-select').addEventListener('change', e => loadChart(e.target.value));
        loadChart('7d');
    })();
    </script>
</x-app-layout>
