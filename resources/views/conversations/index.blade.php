<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Conversas — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0a0a;color:#e5e5e5;font-family:system-ui,sans-serif;min-height:100vh;transition:background .2s,color .2s}
        header{display:flex;align-items:center;gap:14px;padding:14px 28px;border-bottom:1px solid #1e1e1e;background:#111;transition:background .2s,border-color .2s}
        .logo{font-size:18px;font-weight:800;color:#76b900}
        .back-btn{color:#555;text-decoration:none;font-size:20px}
        .back-btn:hover{color:#e5e5e5}
        .header-title{font-size:15px;font-weight:600;color:#aaa}
        .container{max-width:960px;margin:0 auto;padding:32px 24px}
        h1{font-size:22px;font-weight:800;margin-bottom:6px}
        .subtitle{color:#555;font-size:13px;margin-bottom:28px}

        .agent-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;padding:3px 10px;border-radius:20px;font-weight:600}
        .agent-quantum{background:#0f0020;color:#cc66ff;border:1px solid #220044}
        .agent-aria{background:#1a0000;color:#ff6666;border:1px solid #330000}
        .agent-sales{background:#1a1000;color:#ffaa00;border:1px solid #332200}
        .agent-email{background:#001a10;color:#00cc66;border:1px solid #003322}
        .agent-support{background:#001020;color:#4499ff;border:1px solid #002244}
        .agent-default{background:#1a1a1a;color:#888;border:1px solid #333}

        .conv-list{display:flex;flex-direction:column;gap:10px}
        .conv-card{background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:16px;transition:border-color .2s,background .2s;text-decoration:none;color:inherit}
        .conv-card:hover{border-color:#333;background:#141414}
        .conv-icon{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;background:#1a1a1a;overflow:hidden;border:2px solid #222}
        .conv-icon img{width:100%;height:100%;object-fit:cover}
        .conv-info{flex:1;min-width:0}
        .conv-session{font-size:13px;font-weight:600;color:#e5e5e5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .conv-meta{font-size:11px;color:#555;margin-top:3px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .conv-preview{font-size:12px;color:#666;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .conv-actions{display:flex;gap:8px;flex-shrink:0}
        .btn{font-size:11px;padding:6px 14px;border-radius:8px;border:1px solid #333;background:none;color:#aaa;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap}
        .btn:hover{border-color:#76b900;color:#76b900}
        .btn-pdf{border-color:#ff6600;color:#ff9944}
        .btn-pdf:hover{background:#1a0800;border-color:#ff9944}
        .btn-del{border-color:#330000;color:#993333}
        .btn-del:hover{background:#1a0000;border-color:#cc0000;color:#ff4444}

        .empty{text-align:center;padding:80px 0;color:#444}
        .empty-icon{font-size:48px;margin-bottom:16px}
        .empty h2{font-size:18px;color:#555;margin-bottom:8px}
        .empty p{font-size:13px}
        .pagination-wrap{margin-top:28px;display:flex;justify-content:center}
        .pagination-wrap .pagination{display:flex;gap:6px;list-style:none}
        .pagination-wrap .page-item .page-link{padding:6px 12px;background:#111;border:1px solid #222;border-radius:6px;color:#aaa;text-decoration:none;font-size:12px}
        .pagination-wrap .page-item.active .page-link{background:#76b900;color:#000;border-color:#76b900}
        .pagination-wrap .page-item.disabled .page-link{opacity:.4;pointer-events:none}
        .stats-bar{display:flex;gap:20px;margin-bottom:28px}
        .stat{background:#111;border:1px solid #1e1e1e;border-radius:10px;padding:14px 20px;flex:1;text-align:center}
        .stat-num{font-size:24px;font-weight:800;color:#76b900}
        .stat-label{font-size:11px;color:#555;margin-top:2px}

        /* ── LIGHT THEME ── */
        :root[data-theme="light"] body{background:#f8fafc;color:#1f2937}
        :root[data-theme="light"] header{background:#fff;border-bottom-color:#e5e7eb}
        :root[data-theme="light"] .back-btn{color:#6b7280}
        :root[data-theme="light"] .back-btn:hover{color:#111}
        :root[data-theme="light"] .header-title{color:#4b5563}
        :root[data-theme="light"] .subtitle{color:#6b7280}
        :root[data-theme="light"] .conv-card{background:#fff;border-color:#e5e7eb}
        :root[data-theme="light"] .conv-card:hover{border-color:#9ca3af;background:#fafafa}
        :root[data-theme="light"] .conv-icon{background:#f3f4f6;border-color:#e5e7eb}
        :root[data-theme="light"] .conv-session{color:#111}
        :root[data-theme="light"] .conv-meta{color:#6b7280}
        :root[data-theme="light"] .conv-preview{color:#9ca3af}
        :root[data-theme="light"] .btn{background:#fff;border-color:#d1d5db;color:#4b5563}
        :root[data-theme="light"] .btn:hover{border-color:#059669;color:#059669}
        :root[data-theme="light"] .stat{background:#fff;border-color:#e5e7eb}
        :root[data-theme="light"] .stat-label{color:#6b7280}
        :root[data-theme="light"] .pagination-wrap .page-item .page-link{background:#fff;border-color:#d1d5db;color:#4b5563}
        :root[data-theme="light"] .empty{color:#9ca3af}
        :root[data-theme="light"] .empty h2{color:#6b7280}
    </style>
</head>
<body>
<header>
    <a href="/dashboard" class="back-btn">←</a>
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:34px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));"></a>
    <span class="header-title">/ Histórico de Conversas</span>
    <div style="margin-left:auto"><button type="button" class="cy-theme-btn" id="cyThemeBtn" title="Toggle theme (t)">🌙</button></div>
</header>

<div class="container">
    <h1>💬 Histórico de Conversas</h1>
    <p class="subtitle">Todas as tuas conversas com os agentes — clica para ver ou exportar em PDF</p>

    @if($conversations->total() > 0)
    <div class="stats-bar">
        <div class="stat">
            <div class="stat-num">{{ $conversations->total() }}</div>
            <div class="stat-label">Total Conversas</div>
        </div>
        <div class="stat">
            <div class="stat-num">{{ $conversations->sum('messages_count') }}</div>
            <div class="stat-label">Total Mensagens</div>
        </div>
    </div>
    @endif

    @if($conversations->isEmpty())
    <div class="empty">
        <div class="empty-icon">💬</div>
        <h2>Sem conversas ainda</h2>
        <p>Começa uma conversa com qualquer agente e ela aparecerá aqui.</p>
    </div>
    @else
    <div class="conv-list">
        @foreach($conversations as $conv)
        @php
            $agent = $conv->agent ?? 'default';
            $agentEmojis = ['quantum'=>'⚛️','aria'=>'🛡️','sales'=>'💼','email'=>'✉️','support'=>'🎧','orchestrator'=>'🤖','auto'=>'🔄','crm'=>'🎯','sap'=>'📊','document'=>'📄','capitao'=>'⚓','claude'=>'🧠','nvidia'=>'⚡','finance'=>'💰','research'=>'🔍','engineer'=>'🔩','patent'=>'🏛️','energy'=>'⚡','kyber'=>'🔒','qnap'=>'🗄️','vessel'=>'⚓','thinking'=>'🧠','batch'=>'📦','mildef'=>'🎖️','cyber'=>'🔐'];
            $emoji = $agentEmojis[$agent] ?? '🤖';
            $sessionLabel = preg_replace('/^u\d+_/', '', $conv->session_id);
            $lastMsg = $conv->messages()->latest()->first();
            $preview = $lastMsg ? \Illuminate\Support\Str::limit(strip_tags($lastMsg->content), 80) : 'Sem mensagens';
            // Agent photo lookup
            $agentPhoto = null;
            foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
                if (file_exists(public_path('images/agents/' . $agent . $ext))) {
                    $agentPhoto = '/images/agents/' . $agent . $ext;
                    break;
                }
            }
        @endphp
        <div class="conv-card">
            <div class="conv-icon">
                @if($agentPhoto)
                    <img src="{{ $agentPhoto }}" alt="{{ ucfirst($agent) }}">
                @else
                    {{ $emoji }}
                @endif
            </div>
            <div class="conv-info">
                <div class="conv-session">{{ $sessionLabel ?: 'Conversa #'.$conv->id }}</div>
                <div class="conv-meta">
                    <span class="agent-badge agent-{{ $agent }}">{{ ucfirst($agent) }}</span>
                    <span>{{ $conv->messages_count }} mensagens</span>
                    <span>{{ $conv->updated_at->diffForHumans() }}</span>
                    <span>{{ $conv->updated_at->format('d/m/Y H:i') }}</span>
                </div>
                <div class="conv-preview">{{ $preview }}</div>
            </div>
            <div class="conv-actions">
                <a href="{{ route('conversations.show', $conv) }}" class="btn">Ver</a>
                <a href="{{ route('conversations.pdf', $conv) }}" class="btn btn-pdf" target="_blank">PDF</a>
                <form method="POST" action="{{ route('conversations.destroy', $conv) }}" onsubmit="return confirm('Eliminar esta conversa?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-del">🗑</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>

    <div class="pagination-wrap">
        {{ $conversations->links() }}
    </div>
    @endif
</div>
@include('partials.theme-button')
@include('partials.keyboard-shortcuts')
</body>
</html>
