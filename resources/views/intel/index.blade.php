<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — Intel Bus</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0a0a;color:#e5e5e5;font-family:system-ui,sans-serif;min-height:100vh}

        header{display:flex;align-items:center;gap:12px;padding:14px 28px;border-bottom:1px solid #1e1e1e;background:#111}
        .logo{font-size:18px;font-weight:800;color:#76b900}
        .back-btn{color:#555;text-decoration:none;font-size:20px}
        .back-btn:hover{color:#e5e5e5}
        .hdr-right{margin-left:auto;display:flex;gap:8px;align-items:center}
        .btn{font-size:12px;padding:7px 16px;border-radius:8px;border:1px solid #333;background:none;color:#aaa;cursor:pointer;text-decoration:none;transition:all .2s}
        .btn:hover{border-color:#76b900;color:#76b900}

        .container{max-width:1100px;margin:0 auto;padding:32px 24px}
        h1{font-size:26px;font-weight:800;color:#76b900;margin-bottom:6px}
        .subtitle{font-size:13px;color:#555;margin-bottom:24px}

        /* Stats */
        .stats-row{display:flex;gap:10px;margin-bottom:28px;flex-wrap:wrap}
        .stat-chip{background:#111;border:1px solid #1e1e1e;border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:10px;min-width:120px}
        .stat-chip .num{font-size:20px;font-weight:800}
        .stat-chip .lbl{font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.5px}

        /* Bus live indicator */
        .bus-header{display:flex;align-items:center;gap:10px;margin-bottom:18px}
        .pulse{width:10px;height:10px;border-radius:50%;background:#76b900;animation:pulse 2s infinite}
        @keyframes pulse{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(118,185,0,.4)}50%{opacity:.8;box-shadow:0 0 0 8px rgba(118,185,0,0)}}
        .bus-label{font-size:15px;font-weight:700;color:#e5e5e5}
        .bus-sub{font-size:12px;color:#555;margin-left:auto}

        /* Entry cards */
        .entry-card{background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:18px 20px;margin-bottom:12px;transition:border-color .2s}
        .entry-card:hover{border-color:#333}
        .entry-header{display:flex;align-items:center;gap:10px;margin-bottom:10px}
        .agent-badge{display:flex;align-items:center;gap:7px}
        .agent-icon{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid #1e1e1e}
        .agent-icon-emoji{width:32px;height:32px;border-radius:50%;background:#1a1a1a;border:2px solid #1e1e1e;display:flex;align-items:center;justify-content:center;font-size:16px}
        .agent-name{font-size:13px;font-weight:700;color:#e5e5e5}
        .context-key{font-size:11px;color:#555;background:#1a1a1a;padding:2px 8px;border-radius:4px;font-family:monospace}
        .entry-meta{margin-left:auto;display:flex;flex-direction:column;align-items:flex-end;gap:2px}
        .time-badge{font-size:11px;color:#555}
        .expiry-badge{font-size:10px;color:#444}

        .entry-summary{font-size:13px;color:#ccc;line-height:1.6;white-space:pre-wrap;word-break:break-word}

        .tags-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
        .tag{font-size:10px;padding:2px 8px;border-radius:4px;background:#1a1a1a;color:#555;border:1px solid #222;font-family:monospace}

        /* Empty state */
        .empty-state{text-align:center;padding:60px 0;color:#333}
        .empty-icon{font-size:48px;margin-bottom:14px}
        .empty-title{font-size:18px;font-weight:700;color:#444;margin-bottom:8px}
        .empty-desc{font-size:13px;color:#333;max-width:360px;margin:0 auto;line-height:1.5}

        /* Auto-refresh bar */
        .refresh-bar{display:flex;align-items:center;gap:8px;font-size:11px;color:#444;margin-bottom:20px}
        .refresh-bar input[type=checkbox]{accent-color:#76b900}

        /* ── LIGHT THEME OVERRIDES ─────────────────────────────── */
        html[data-theme="light"] body{background:#f8fafc;color:#1f2937}
        html[data-theme="light"] header{background:#ffffff;border-bottom-color:#e5e7eb}
        html[data-theme="light"] .back-btn{color:#6b7280}
        html[data-theme="light"] .back-btn:hover{color:#1f2937}
        html[data-theme="light"] .btn{border-color:#e5e7eb;color:#6b7280}
        html[data-theme="light"] .btn:hover{border-color:#76b900;color:#76b900}
        html[data-theme="light"] .subtitle{color:#6b7280}
        html[data-theme="light"] .stat-chip{background:#ffffff;border-color:#e5e7eb}
        html[data-theme="light"] .stat-chip .lbl{color:#6b7280}
        html[data-theme="light"] .bus-label{color:#1f2937}
        html[data-theme="light"] .bus-sub{color:#6b7280}
        html[data-theme="light"] .entry-card{background:#ffffff;border-color:#e5e7eb}
        html[data-theme="light"] .entry-card:hover{border-color:#d1d5db}
        html[data-theme="light"] .agent-icon-emoji{background:#f1f5f9;border-color:#e5e7eb}
        html[data-theme="light"] .agent-icon{border-color:#e5e7eb}
        html[data-theme="light"] .agent-name{color:#1f2937}
        html[data-theme="light"] .context-key{background:#f1f5f9;color:#6b7280}
        html[data-theme="light"] .time-badge{color:#6b7280}
        html[data-theme="light"] .expiry-badge{color:#9ca3af}
        html[data-theme="light"] .entry-summary{color:#374151}
        html[data-theme="light"] .tag{background:#f1f5f9;border-color:#e5e7eb;color:#6b7280}
        html[data-theme="light"] .empty-title{color:#6b7280}
        html[data-theme="light"] .empty-desc{color:#9ca3af}
        html[data-theme="light"] .refresh-bar{color:#6b7280}
    </style>
</head>
<body>

<header>
    <a href="/dashboard" class="back-btn">←</a>
    <span class="logo">🐾 ClawYard</span>
    <span style="color:#555;font-size:13px;margin-left:4px">/ Intel Bus</span>
    <div class="hdr-right">
        <a href="/dashboard" class="btn">Dashboard</a>
        <a href="/chat" class="btn">Chat</a>
        <button id="cyThemeBtn" class="cy-theme-btn" type="button" aria-label="Toggle theme">☀️</button>
    </div>
</header>

<div class="container">
    <h1>🔗 PSI Intel Bus</h1>
    <p class="subtitle">Descobertas partilhadas em tempo real entre todos os agentes ClawYard</p>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-chip">
            <span class="num" style="color:#76b900">{{ $entries->count() }}</span>
            <span class="lbl">Entradas<br>activas</span>
        </div>
        <div class="stat-chip">
            <span class="num" style="color:#3b82f6">{{ $entries->groupBy('agent_key')->count() }}</span>
            <span class="lbl">Agentes<br>activos</span>
        </div>
        <div class="stat-chip">
            <span class="num" style="color:#f59e0b">{{ $entries->where('created_at', '>=', now()->subHour())->count() }}</span>
            <span class="lbl">Última<br>hora</span>
        </div>
        <div class="stat-chip">
            <span class="num" style="color:#ef4444">{{ $entries->where('created_at', '>=', now()->subMinutes(15))->count() }}</span>
            <span class="lbl">Últimos<br>15 min</span>
        </div>
    </div>

    <!-- Auto-refresh toggle -->
    <div class="refresh-bar">
        <input type="checkbox" id="autoRefresh" checked>
        <label for="autoRefresh">Auto-actualizar a cada 30 segundos</label>
        <span id="nextRefresh" style="margin-left:auto">Próxima actualização em <b>30</b>s</span>
    </div>

    <!-- Bus header -->
    <div class="bus-header">
        <div class="pulse"></div>
        <span class="bus-label">Canal de Inteligência Partilhada</span>
        <span class="bus-sub">TTL: 8 horas · Max 3 por agente · Auto-pruning a cada hora</span>
    </div>

    @if($entries->isEmpty())
        <div class="empty-state">
            <div class="empty-icon">🔕</div>
            <div class="empty-title">Bus vazio</div>
            <div class="empty-desc">Ainda não há inteligência partilhada. O canal preenche automaticamente à medida que os agentes respondem a perguntas.</div>
        </div>
    @else
        @foreach($entries as $entry)
            @php
                $meta = \App\Models\AgentShare::agentMeta();
                $agentInfo = $meta[$entry->agent_key] ?? ['name' => $entry->agent_name ?? $entry->agent_key, 'emoji' => '🤖', 'color' => '#555', 'photo' => null];
                $hasPhoto = $agentInfo['photo'] && file_exists(public_path($agentInfo['photo']));
                $timeAgo = $entry->created_at->diffForHumans();
                $expiresIn = $entry->expires_at ? $entry->expires_at->diffForHumans() : 'sem expiração';
                $tags = is_array($entry->tags) ? $entry->tags : [];
            @endphp
            <div class="entry-card">
                <div class="entry-header">
                    <div class="agent-badge">
                        @if($hasPhoto)
                            <img src="{{ $agentInfo['photo'] }}" alt="{{ $agentInfo['name'] }}" class="agent-icon">
                        @else
                            <div class="agent-icon-emoji">{{ $agentInfo['emoji'] }}</div>
                        @endif
                        <span class="agent-name" style="color:{{ $agentInfo['color'] }}">{{ $agentInfo['name'] }}</span>
                    </div>
                    <span class="context-key">{{ $entry->context_key }}</span>
                    <div class="entry-meta">
                        <span class="time-badge">{{ $timeAgo }}</span>
                        <span class="expiry-badge">expira {{ $expiresIn }}</span>
                    </div>
                </div>

                <div class="entry-summary">{{ $entry->summary }}</div>

                @if(!empty($tags))
                    <div class="tags-row">
                        @foreach($tags as $tag)
                            <span class="tag">#{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>

<script>
(function () {
    let countdown = 30;
    const checkbox = document.getElementById('autoRefresh');
    const counter  = document.querySelector('#nextRefresh b');

    setInterval(() => {
        if (!checkbox.checked) { countdown = 30; counter.textContent = countdown; return; }
        countdown--;
        counter.textContent = countdown;
        if (countdown <= 0) { location.reload(); }
    }, 1000);
})();
</script>

@include('partials.theme-button')
</body>
</html>
