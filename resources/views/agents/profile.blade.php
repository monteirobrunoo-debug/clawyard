<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $agent['name'] }} — ClawYard</title>
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
            --accent: {{ $agent['color'] }};
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

        /* ── Top bar ── */
        .topbar {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 28px;
            border-bottom: 1px solid var(--border);
            background: var(--bg2);
        }
        .topbar .back {
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border2);
            transition: all .15s;
        }
        .topbar .back:hover { color: var(--text-strong); border-color: var(--muted); }
        .topbar .spacer { flex: 1; }
        .topbar button, .topbar a.cta {
            font: 500 13px/1 Inter, sans-serif;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border2);
            background: var(--bg3);
            color: var(--text);
            cursor: pointer;
            text-decoration: none;
            transition: all .15s;
        }
        .topbar a.cta.primary {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
            font-weight: 700;
        }
        .topbar a.cta.primary:hover { filter: brightness(1.1); }
        .theme-btn {
            width: 34px; height: 34px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; padding: 0;
        }

        /* ── Hero ── */
        .hero {
            max-width: 960px; margin: 40px auto 24px;
            padding: 0 28px;
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 28px; align-items: center;
        }
        .hero-avatar {
            width: 120px; height: 120px;
            border-radius: 50%;
            overflow: hidden;
            background: color-mix(in srgb, var(--accent) 18%, transparent);
            display: flex; align-items: center; justify-content: center;
            font-size: 54px;
            border: 2px solid var(--accent);
            box-shadow: 0 0 40px color-mix(in srgb, var(--accent) 25%, transparent);
        }
        .hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .hero-title { font-size: 32px; font-weight: 800; color: var(--text-strong); margin-bottom: 4px; }
        .hero-subtitle { font-size: 14px; color: var(--muted); margin-bottom: 10px; }
        .hero-category {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px;
            color: var(--accent);
            padding: 4px 10px; border-radius: 20px;
            background: color-mix(in srgb, var(--accent) 14%, transparent);
        }
        .hero-role { font-size: 15px; line-height: 1.55; color: var(--text); margin-top: 14px; }

        /* ── Stats grid ── */
        .stats {
            max-width: 960px; margin: 20px auto;
            padding: 0 28px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }
        .stat-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 18px;
        }
        .stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 6px; }
        .stat-value { font-size: 24px; font-weight: 700; color: var(--text-strong); }
        .stat-sub { font-size: 11px; color: var(--muted2); margin-top: 2px; }

        /* ── Sections ── */
        .section {
            max-width: 960px; margin: 32px auto;
            padding: 0 28px;
        }
        .section h2 {
            font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
            color: var(--muted); margin-bottom: 14px;
        }

        /* Starter prompts */
        .starters { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
        .starter-chip {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 13px;
            color: var(--text);
            text-decoration: none;
            display: flex; align-items: center; gap: 10px;
            transition: all .15s;
        }
        .starter-chip:hover {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 8%, var(--bg2));
            transform: translateY(-1px);
        }
        .starter-chip::before {
            content: '💬'; font-size: 16px; flex-shrink: 0;
        }

        /* Recent conversations */
        .conv-list { display: flex; flex-direction: column; gap: 8px; }
        .conv-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 16px;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text);
            transition: all .15s;
        }
        .conv-row:hover { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, var(--bg2)); }
        .conv-main { font-size: 13px; }
        .conv-meta { font-size: 11px; color: var(--muted); }
        .conv-empty {
            padding: 40px 20px; text-align: center; color: var(--muted); font-size: 13px;
            background: var(--bg2); border: 1px dashed var(--border2); border-radius: 10px;
        }

        @media (max-width: 760px) {
            .hero { grid-template-columns: 90px 1fr; gap: 18px; }
            .hero-avatar { width: 90px; height: 90px; font-size: 40px; }
            .hero-title { font-size: 24px; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .starters { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <a href="/dashboard" class="back">← Dashboard</a>
    <div class="spacer"></div>
    <button class="theme-btn" id="themeBtn" title="Toggle theme (t)">🌙</button>
    <a href="/chat?agent={{ urlencode($agent['key']) }}" class="cta primary">Chat with {{ $agent['name'] }} →</a>
</div>

<div class="hero">
    <div class="hero-avatar">
        @if($photo)
            <img src="{{ $photo }}" alt="{{ $agent['name'] }}">
        @else
            {{ $agent['emoji'] }}
        @endif
    </div>
    <div>
        <div class="hero-category">{{ $categories[$agent['category']]['icon'] ?? '🤖' }} {{ $categories[$agent['category']]['title'] ?? ucfirst($agent['category']) }}</div>
        <div class="hero-title" style="margin-top:10px">{{ $agent['name'] }}</div>
        <div class="hero-subtitle">Key: <code>{{ $agent['key'] }}</code></div>
        <div class="hero-role">{{ $agent['role'] }}</div>
    </div>
</div>

<div class="stats">
    <div class="stat-card">
        <div class="stat-label">Total conversations</div>
        <div class="stat-value">{{ $stats['total_conversations'] }}</div>
        <div class="stat-sub">com {{ $agent['name'] }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total messages</div>
        <div class="stat-value">{{ $stats['total_messages'] }}</div>
        <div class="stat-sub">trocadas até hoje</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Last used</div>
        <div class="stat-value" style="font-size:16px">{{ $stats['last_used'] ?? '—' }}</div>
        <div class="stat-sub">{{ $stats['last_used'] ? 'most recent chat' : 'nunca usado ainda' }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">This week</div>
        <div class="stat-value">{{ $stats['week_count'] }}</div>
        <div class="stat-sub">conversas nos últimos 7 dias</div>
    </div>
</div>

<section class="section">
    <h2>💡 Try asking me</h2>
    <div class="starters">
        @foreach($starters as $s)
            <a href="/chat?agent={{ urlencode($agent['key']) }}&q={{ urlencode($s) }}" class="starter-chip">{{ $s }}</a>
        @endforeach
    </div>
</section>

<section class="section">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <h2 style="margin:0;">🕘 Conversas recentes com {{ $agent['name'] }}</h2>
        {{-- Direct link to the full filtered history. The /conversations
             page applies the same agent filter when ?agent=key is set,
             so this is an instant deep-link into the user's archive
             scoped to this specific agent. Asked for 2026-04-27 —
             users wanted "ver histórico desta agente" from the profile. --}}
        <a href="{{ route('conversations', ['agent' => $agent['key']]) }}"
           style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:8px;color:#bcd;font-size:12px;font-weight:600;text-decoration:none;"
           title="Ver TODAS as tuas conversas com {{ $agent['name'] }} — com pesquisa por texto">
            📂 Ver todo o histórico com este agente
        </a>
    </div>
    @if($recentConversations->count() > 0)
        <div class="conv-list">
            @foreach($recentConversations as $conv)
                @php
                    $clientSid = preg_replace('/^u\d+_/', '', (string) $conv->session_id);
                    $url = '/chat?agent=' . urlencode($agent['key']) . '&session=' . urlencode($clientSid);
                @endphp
                <a href="{{ $url }}" class="conv-row">
                    <div class="conv-main">{{ $conv->messages_count }} {{ $conv->messages_count === 1 ? 'mensagem' : 'mensagens' }}</div>
                    <div class="conv-meta">{{ $conv->updated_at->diffForHumans() }}</div>
                </a>
            @endforeach
        </div>
    @else
        <div class="conv-empty">Ainda não tens conversas com {{ $agent['name'] }}. Clica em <strong>Chat</strong> em cima para começar.</div>
    @endif
</section>

<script>
(function () {
    const btn = document.getElementById('themeBtn');
    function applyIcon() {
        btn.textContent = document.documentElement.getAttribute('data-theme') === 'light' ? '🌙' : '☀️';
    }
    applyIcon();
    btn.addEventListener('click', () => {
        const cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const next = cur === 'light' ? 'dark' : 'light';
        if (next === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
        try { localStorage.setItem('cy-theme', next); } catch (e) {}
        applyIcon();
    });
})();
</script>

@include('partials.keyboard-shortcuts')
</body>
</html>
