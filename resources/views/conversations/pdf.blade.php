<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Conversa {{ $conversation->agent }} — ClawYard</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#fff;color:#111;font-family:Georgia,serif;font-size:13px;line-height:1.7;padding:40px}
        @media print{
            body{padding:20px}
            .no-print{display:none!important}
            .page-break{page-break-before:always}
        }

        .print-btn{position:fixed;top:20px;right:20px;background:#76b900;color:#000;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;z-index:999}
        .print-btn:hover{background:#5e9400}

        .doc-header{border-bottom:3px solid #111;padding-bottom:20px;margin-bottom:28px}
        .company{font-size:11px;color:#666;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px}
        h1{font-size:22px;font-weight:700;margin-bottom:8px}
        .doc-meta{font-size:11px;color:#666;display:flex;gap:20px;flex-wrap:wrap}
        .badge{display:inline-block;background:#f0f0f0;padding:2px 10px;border-radius:4px;font-size:11px;font-weight:700}

        .messages{display:flex;flex-direction:column;gap:20px}
        .msg{padding:14px 18px;border-radius:4px;border-left:4px solid #ddd}
        .msg.user{border-left-color:#76b900;background:#f9fbf5}
        .msg.agent{border-left-color:#4488ff;background:#f5f8ff}
        .msg-header{display:flex;justify-content:space-between;margin-bottom:8px;font-size:11px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.5px}
        .msg-content{font-size:13px;line-height:1.7;white-space:pre-wrap;word-break:break-word}
        .msg-content strong{font-weight:700}
        .msg-content em{font-style:italic;color:#555}
        .msg-content code{font-family:monospace;background:#f0f0f0;padding:1px 5px;border-radius:3px;font-size:11px}
        .msg-content pre{background:#f5f5f5;padding:12px;border-radius:4px;margin:8px 0;overflow-x:auto;font-size:11px;font-family:monospace}
        .msg-content h1,.msg-content h2,.msg-content h3{margin:10px 0 5px;font-weight:700}
        .msg-content ul,.msg-content ol{padding-left:20px;margin:6px 0}
        .msg-content li{margin:2px 0}

        .doc-footer{margin-top:40px;padding-top:16px;border-top:1px solid #ddd;font-size:10px;color:#999;text-align:center}
        .separator{text-align:center;margin:4px 0;font-size:10px;color:#aaa}
    </style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>

@php
    $agent = $conversation->agent ?? 'default';
    $agentNames = ['quantum'=>'Professor Quantum Leap','aria'=>'ARIA Security','sales'=>'Sales Agent','email'=>'Email Agent','support'=>'Marcus Support','orchestrator'=>'Orchestrator','auto'=>'Auto Agent'];
    $agentName = $agentNames[$agent] ?? ucfirst($agent);
    $sessionLabel = preg_replace('/^u\d+_/', '', $conversation->session_id);
@endphp

<div class="doc-header">
    <div class="company">PartYard_B.Mont_H&P Group rights reserved 2026</div>
    <h1>Conversa com {{ $agentName }}</h1>
    <div class="doc-meta">
        <span><strong>Sessão:</strong> {{ $sessionLabel ?: '#'.$conversation->id }}</span>
        <span><strong>Agente:</strong> <span class="badge">{{ strtoupper($agent) }}</span></span>
        <span><strong>Início:</strong> {{ $conversation->created_at->format('d/m/Y H:i') }}</span>
        <span><strong>Fim:</strong> {{ $conversation->updated_at->format('d/m/Y H:i') }}</span>
        <span><strong>Mensagens:</strong> {{ $messages->count() }}</span>
        <span><strong>Exportado:</strong> {{ now()->format('d/m/Y H:i') }}</span>
    </div>
</div>

<div class="messages">
    @foreach($messages as $i => $msg)
    @php $isUser = $msg->role === 'user'; @endphp
    @if($i > 0 && $i % 20 === 0)
    <div class="page-break"></div>
    @endif
    <div class="msg {{ $isUser ? 'user' : 'agent' }}">
        <div class="msg-header">
            <span>{{ $isUser ? '👤 Tu' : '🤖 '.$agentName }}</span>
            <span>{{ $msg->created_at->format('H:i:s') }}</span>
        </div>
        <div class="msg-content" data-raw="{{ e($msg->content) }}"></div>
    </div>
    @endforeach
</div>

<div class="doc-footer">
    Documento ClawYard AI · © PartYard_B.Mont_H&P Group rights reserved 2026 · {{ now()->format('d/m/Y') }}
</div>

<script>
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
        .replace(/(<li>[^<]*<\/li>\n?)+/g, m => '<ul>'+m+'</ul>')
        .replace(/\n\n/g, '<br><br>')
        .replace(/\n/g, '<br>');
}
document.querySelectorAll('.msg-content[data-raw]').forEach(el => {
    el.innerHTML = renderMarkdown(el.dataset.raw);
});
</script>
</body>
</html>
