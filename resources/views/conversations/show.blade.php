<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversa — ClawYard</title>
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
        .hdr-right{margin-left:auto;display:flex;gap:8px;align-items:center}
        .btn{font-size:12px;padding:7px 16px;border-radius:8px;border:1px solid #333;background:none;color:#aaa;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap}
        .btn:hover{border-color:#76b900;color:#76b900}
        .btn-pdf{border-color:#ff6600;color:#ff9944}
        .btn-pdf:hover{background:#1a0800;border-color:#ff9944}

        .container{max-width:820px;margin:0 auto;padding:32px 24px}
        .conv-header{margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid #1e1e1e}
        .agent-badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:4px 12px;border-radius:20px;font-weight:600;margin-bottom:10px}
        .agent-quantum{background:#0f0020;color:#cc66ff;border:1px solid #220044}
        .agent-aria{background:#1a0000;color:#ff6666;border:1px solid #330000}
        .agent-sales{background:#1a1000;color:#ffaa00;border:1px solid #332200}
        .agent-email{background:#001a10;color:#00cc66;border:1px solid #003322}
        .agent-support{background:#001020;color:#4499ff;border:1px solid #002244}
        .agent-default{background:#1a1a1a;color:#888;border:1px solid #333}
        h1{font-size:20px;font-weight:800;margin-bottom:6px}
        .meta{font-size:12px;color:#555;display:flex;gap:14px;flex-wrap:wrap}

        .messages{display:flex;flex-direction:column;gap:16px}
        .msg{display:flex;gap:12px;align-items:flex-start}
        .msg.user{flex-direction:row-reverse}
        .msg-avatar{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;background:#1a1a1a}
        .msg-bubble{max-width:72%;background:#141414;border:1px solid #1e1e1e;border-radius:12px;padding:12px 16px}
        .msg.user .msg-bubble{background:#0d1a00;border-color:#1e3300}
        .msg-role{font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
        .msg.user .msg-role{color:#4d7a00}
        .msg-content{font-size:13px;line-height:1.65;color:#ccc;white-space:pre-wrap;word-break:break-word}
        .msg.user .msg-content{color:#b8d980}
        .msg-time{font-size:10px;color:#333;margin-top:6px}

        /* Markdown-like rendering */
        .msg-content strong{color:#e5e5e5;font-weight:700}
        .msg-content em{color:#aaa;font-style:italic}
        .msg-content code{background:#0d0d0d;border:1px solid #222;border-radius:4px;padding:1px 6px;font-family:monospace;font-size:11px;color:#76b900}
        .msg-content pre{background:#0d0d0d;border:1px solid #222;border-radius:8px;padding:12px;margin:8px 0;overflow-x:auto}
        .msg-content pre code{border:none;padding:0;background:none}
        .msg-content h1,.msg-content h2,.msg-content h3{color:#e5e5e5;margin:12px 0 6px}
        .msg-content ul,.msg-content ol{padding-left:20px;margin:6px 0}
        .msg-content li{margin:3px 0}
        .msg-content a{color:#76b900}

        .empty-msgs{text-align:center;padding:40px;color:#444;font-size:13px}

        /* ── LIGHT THEME ── */
        :root[data-theme="light"] body{background:#f8fafc;color:#1f2937}
        :root[data-theme="light"] header{background:#fff;border-bottom-color:#e5e7eb}
        :root[data-theme="light"] .back-btn{color:#6b7280}
        :root[data-theme="light"] .back-btn:hover{color:#111}
        :root[data-theme="light"] .btn{background:#fff;border-color:#d1d5db;color:#4b5563}
        :root[data-theme="light"] .btn:hover{border-color:#059669;color:#059669}
        :root[data-theme="light"] .conv-header{border-bottom-color:#e5e7eb}
        :root[data-theme="light"] .meta{color:#6b7280}
        :root[data-theme="light"] .msg-bubble{background:#fff;border-color:#e5e7eb}
        :root[data-theme="light"] .msg.user .msg-bubble{background:#ecfccb;border-color:#bef264}
        :root[data-theme="light"] .msg-avatar{background:#f3f4f6}
        :root[data-theme="light"] .msg-role{color:#9ca3af}
        :root[data-theme="light"] .msg.user .msg-role{color:#365314}
        :root[data-theme="light"] .msg-content{color:#374151}
        :root[data-theme="light"] .msg.user .msg-content{color:#1a2e05}
        :root[data-theme="light"] .msg-content strong{color:#111}
        :root[data-theme="light"] .msg-content em{color:#4b5563}
        :root[data-theme="light"] .msg-content code{background:#f3f4f6;border-color:#e5e7eb;color:#059669}
        :root[data-theme="light"] .msg-content pre{background:#f3f4f6;border-color:#e5e7eb}
        :root[data-theme="light"] .msg-content h1,
        :root[data-theme="light"] .msg-content h2,
        :root[data-theme="light"] .msg-content h3{color:#111}
        :root[data-theme="light"] .empty-msgs{color:#9ca3af}
    </style>
</head>
<body>
<header>
    <a href="{{ route('conversations') }}" class="back-btn">←</a>
    <span class="logo">⚡ ClawYard</span>
    <div class="hdr-right">
        <a href="{{ route('conversations.pdf', $conversation) }}" class="btn btn-pdf" target="_blank">⬇ Exportar PDF</a>
        <button type="button" class="cy-theme-btn" id="cyThemeBtn" title="Toggle theme (t)">🌙</button>
    </div>
</header>

<div class="container">
    @php
        $agent = $conversation->agent ?? 'default';
        $agentEmojis = ['quantum'=>'⚛️','aria'=>'🛡️','sales'=>'💼','email'=>'✉️','support'=>'🎧','orchestrator'=>'🤖','auto'=>'🔄'];
        $emoji = $agentEmojis[$agent] ?? '🤖';
        $sessionLabel = preg_replace('/^u\d+_/', '', $conversation->session_id);
    @endphp

    <div class="conv-header">
        <div class="agent-badge agent-{{ $agent }}">{{ $emoji }} {{ ucfirst($agent) }}</div>
        <h1>{{ $sessionLabel ?: 'Conversa #'.$conversation->id }}</h1>
        <div class="meta">
            <span>📅 Iniciada: {{ $conversation->created_at->format('d/m/Y \à\s H:i') }}</span>
            <span>🔄 Última: {{ $conversation->updated_at->format('d/m/Y \à\s H:i') }}</span>
            <span>💬 {{ $messages->count() }} mensagens</span>
        </div>
    </div>

    @if($messages->isEmpty())
        <div class="empty-msgs">Sem mensagens nesta conversa.</div>
    @else
    <div class="messages" id="messages">
        @foreach($messages as $msg)
        @php $isUser = $msg->role === 'user'; @endphp
        <div class="msg {{ $isUser ? 'user' : 'agent' }}">
            <div class="msg-avatar">{{ $isUser ? '👤' : $emoji }}</div>
            <div class="msg-bubble">
                <div class="msg-role">{{ $isUser ? 'Tu' : ucfirst($agent) }}</div>
                <div class="msg-content" data-raw="{{ e($msg->content) }}"></div>
                <div class="msg-time">{{ $msg->created_at->format('H:i') }}</div>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>

<script>
// Simple markdown renderer
function renderMarkdown(text) {
    return text
        .replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>')
        .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>')
        .replace(/\n\n/g, '<br><br>')
        .replace(/\n/g, '<br>');
}
document.querySelectorAll('.msg-content[data-raw]').forEach(el => {
    el.innerHTML = renderMarkdown(el.dataset.raw);
});
</script>
@include('partials.theme-button')
@include('partials.keyboard-shortcuts')
</body>
</html>
