<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your stats — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">

    <script>
        (function () {
            try {
                var t = localStorage.getItem('cy-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green: #76b900;
            --bg: #0a0a0a;
            --bg2: #111;
            --bg3: #1a1a1a;
            --border: #1e1e1e;
            --border2: #2a2a2a;
            --text: #e5e5e5;
            --text-strong: #ffffff;
            --muted: #888;
            --muted2: #666;
        }
        :root[data-theme="light"] {
            --bg: #f8fafc;
            --bg2: #ffffff;
            --bg3: #f1f5f9;
            --border: #e5e7eb;
            --border2: #d1d5db;
            --text: #1f2937;
            --text-strong: #111827;
            --muted: #6b7280;
            --muted2: #9ca3af;
        }

        html, body {
            background: var(--bg);
            color: var(--text);
            font-family: Inter, system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            transition: background .2s, color .2s;
        }

        .topbar {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 28px;
            border-bottom: 1px solid var(--border);
            background: var(--bg2);
        }
        .topbar .back { color: var(--muted); text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border2); transition: all .15s; }
        .topbar .back:hover { color: var(--text-strong); border-color: var(--muted); }
        .topbar .spacer { flex: 1; }
        .theme-btn {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; padding: 0;
            background: var(--bg3); border: 1px solid var(--border2); cursor: pointer; color: var(--text);
        }

        .wrap { max-width: 1000px; margin: 0 auto; padding: 28px; }
        h1 { font-size: 26px; color: var(--text-strong); margin-bottom: 4px; }
        .subtitle { font-size: 14px; color: var(--muted); margin-bottom: 24px; }

        .kpi-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 28px;
        }
        .kpi {
            background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
            padding: 20px 22px;
        }
        .kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 8px; }
        .kpi-value { font-size: 30px; font-weight: 800; color: var(--text-strong); line-height: 1; }
        .kpi-sub { font-size: 12px; color: var(--muted2); margin-top: 4px; }

        .card {
            background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
            padding: 22px 24px; margin-bottom: 20px;
        }
        .card h2 {
            font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
            color: var(--muted); margin-bottom: 16px;
        }

        /* Top agents bar list */
        .agent-bar { display: flex; align-items: center; gap: 14px; padding: 10px 0; }
        .agent-bar + .agent-bar { border-top: 1px dashed var(--border); }
        .agent-bar .rank { width: 22px; font-size: 11px; color: var(--muted2); font-weight: 700; text-align: right; }
        .agent-bar .dot {
            width: 30px; height: 30px; border-radius: 50%;
            background: color-mix(in srgb, var(--agent-color, #76b900) 20%, transparent);
            color: var(--text-strong); font-size: 15px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            border: 1px solid color-mix(in srgb, var(--agent-color, #76b900) 50%, transparent);
        }
        .agent-bar .meta { flex: 1; min-width: 0; }
        .agent-bar .name { font-size: 13px; font-weight: 600; color: var(--text-strong); text-decoration: none; }
        .agent-bar .name:hover { color: var(--agent-color, #76b900); }
        .agent-bar .bar-track {
            height: 6px; background: var(--bg3); border-radius: 3px; overflow: hidden; margin-top: 6px;
        }
        .agent-bar .bar-fill { height: 100%; background: var(--agent-color, #76b900); border-radius: 3px; transition: width .4s; }
        .agent-bar .count { font-size: 13px; font-weight: 700; color: var(--text-strong); min-width: 36px; text-align: right; }

        /* Daily / hourly sparklines */
        .chart {
            display: flex; align-items: flex-end; gap: 2px;
            height: 90px; padding-top: 4px;
        }
        .chart .bar {
            flex: 1; background: color-mix(in srgb, var(--green) 30%, transparent);
            border-radius: 2px 2px 0 0; min-height: 2px;
            position: relative;
        }
        .chart .bar.hot { background: var(--green); }
        .chart .bar:hover { background: var(--green); }
        .chart .bar::after {
            content: attr(data-label);
            position: absolute; bottom: calc(100% + 4px); left: 50%;
            transform: translateX(-50%);
            white-space: nowrap; font-size: 10px; color: var(--text-strong);
            background: var(--bg3); padding: 3px 7px; border-radius: 4px;
            border: 1px solid var(--border2);
            opacity: 0; pointer-events: none; transition: opacity .15s;
        }
        .chart .bar:hover::after { opacity: 1; }
        .chart-axis { display: flex; justify-content: space-between; margin-top: 6px; font-size: 10px; color: var(--muted2); }

        .empty { padding: 30px 0; text-align: center; color: var(--muted); font-size: 13px; }

        @media (max-width: 720px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .wrap { padding: 18px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <a href="/dashboard" class="back">← Dashboard</a>
    <div class="spacer"></div>
    <button class="theme-btn" id="themeBtn" title="Toggle theme (t)">🌙</button>
</div>

<div class="wrap">
    <h1>📊 Your usage stats</h1>
    <p class="subtitle">Como usas o ClawYard — apenas os teus dados, ninguém mais vê isto.</p>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total conversations</div>
            <div class="kpi-value">{{ $totalConv }}</div>
            <div class="kpi-sub">desde que começaste</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Total messages</div>
            <div class="kpi-value">{{ $totalMsg }}</div>
            <div class="kpi-sub">trocadas com os agentes</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Messages per conversation</div>
            <div class="kpi-value">{{ $avgMsgs }}</div>
            <div class="kpi-sub">média</div>
        </div>
    </div>

    <div class="card">
        <h2>🏆 Most-used agents</h2>
        @if($byAgent->count() === 0)
            <div class="empty">Sem conversas ainda. Abre o <a href="/dashboard" style="color:inherit;text-decoration:underline">Dashboard</a> e escolhe um agente.</div>
        @else
            @php $max = $byAgent->max('conv_count'); @endphp
            @foreach($byAgent as $i => $row)
                @php
                    $meta  = $agentByKey[$row->agent] ?? null;
                    $label = $meta['name'] ?? ucfirst($row->agent ?: 'Unknown');
                    $emoji = $meta['emoji'] ?? '🤖';
                    $color = $meta['color'] ?? '#76b900';
                    $pct   = $max > 0 ? round(($row->conv_count / $max) * 100) : 0;
                @endphp
                <div class="agent-bar" style="--agent-color: {{ $color }}">
                    <div class="rank">#{{ $i + 1 }}</div>
                    <div class="dot">{{ $emoji }}</div>
                    <div class="meta">
                        @if($meta)
                            <a href="/agents/{{ urlencode($row->agent) }}" class="name">{{ $label }}</a>
                        @else
                            <span class="name" style="color:var(--muted)">{{ $label }}</span>
                        @endif
                        <div class="bar-track"><div class="bar-fill" style="width: {{ $pct }}%"></div></div>
                    </div>
                    <div class="count">{{ $row->conv_count }}</div>
                </div>
            @endforeach
        @endif
    </div>

    <div class="card">
        <h2>📅 Last 30 days</h2>
        @php $dayMax = max(1, collect($days)->max('count')); @endphp
        <div class="chart">
            @foreach($days as $d)
                @php
                    $h   = max(2, round(($d['count'] / $dayMax) * 85));
                    $hot = $d['count'] > 0 && $d['count'] >= ($dayMax * 0.6);
                @endphp
                <div class="bar {{ $hot ? 'hot' : '' }}"
                     style="height: {{ $h }}px"
                     data-label="{{ $d['day'] }} — {{ $d['count'] }} conv"></div>
            @endforeach
        </div>
        <div class="chart-axis">
            <span>{{ $days[0]['day'] ?? '' }}</span>
            <span>hoje</span>
        </div>
    </div>

    <div class="card">
        <h2>🕐 By hour of day</h2>
        @php $hourMax = max(1, collect($hourBuckets)->max('count')); @endphp
        <div class="chart">
            @foreach($hourBuckets as $b)
                @php
                    $h   = max(2, round(($b['count'] / $hourMax) * 85));
                    $hot = $b['count'] > 0 && $b['count'] >= ($hourMax * 0.6);
                @endphp
                <div class="bar {{ $hot ? 'hot' : '' }}"
                     style="height: {{ $h }}px"
                     data-label="{{ str_pad($b['hour'], 2, '0', STR_PAD_LEFT) }}:00 — {{ $b['count'] }} msg"></div>
            @endforeach
        </div>
        <div class="chart-axis">
            <span>00h</span>
            <span>12h</span>
            <span>23h</span>
        </div>
    </div>

    <p style="text-align:center; color: var(--muted2); font-size: 12px; margin-top: 30px;">
        Privado · Só tu vês isto · Calculado em tempo real
    </p>
</div>

<script>
(function () {
    const btn = document.getElementById('themeBtn');
    function applyIcon() { btn.textContent = document.documentElement.getAttribute('data-theme') === 'light' ? '🌙' : '☀️'; }
    applyIcon();
    btn.addEventListener('click', () => {
        const cur  = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const next = cur === 'light' ? 'dark' : 'light';
        if (next === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else                  document.documentElement.removeAttribute('data-theme');
        try { localStorage.setItem('cy-theme', next); } catch (e) {}
        applyIcon();
    });
})();
</script>

@include('partials.keyboard-shortcuts')
</body>
</html>
