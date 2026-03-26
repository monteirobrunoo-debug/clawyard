<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Briefing Executivo — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0a0a;color:#e5e5e5;font-family:system-ui,sans-serif;min-height:100vh}
        header{display:flex;align-items:center;gap:14px;padding:14px 28px;border-bottom:1px solid #1e1e1e;background:#111}
        .logo{font-size:18px;font-weight:800;color:#76b900}
        .back-btn{color:#555;text-decoration:none;font-size:20px}
        .back-btn:hover{color:#e5e5e5}
        .hdr-right{margin-left:auto;display:flex;gap:8px;align-items:center}
        .btn{font-size:12px;padding:8px 18px;border-radius:8px;border:1px solid #333;background:none;color:#aaa;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap;display:inline-flex;align-items:center;gap:6px}
        .btn:hover{border-color:#76b900;color:#76b900}
        .btn-primary{background:#76b900;border-color:#76b900;color:#000;font-weight:700}
        .btn-primary:hover{background:#5e9400;border-color:#5e9400;color:#000}
        .btn-pdf{border-color:#ff6600;color:#ff9944}
        .btn-pdf:hover{background:#1a0800}
        .btn:disabled{opacity:.4;cursor:not-allowed}

        .container{max-width:920px;margin:0 auto;padding:32px 24px}
        .page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:16px}
        h1{font-size:24px;font-weight:800}
        .subtitle{color:#555;font-size:13px;margin-top:4px}

        /* Status banner */
        .status-banner{background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px}
        .status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
        .status-dot.ready{background:#76b900;box-shadow:0 0 8px #76b900}
        .status-dot.pending{background:#ffaa00;animation:pulse 1s infinite}
        .status-dot.empty{background:#333}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
        .status-text{flex:1}
        .status-title{font-size:14px;font-weight:600}
        .status-sub{font-size:12px;color:#555;margin-top:2px}

        /* Output area */
        #output{display:none}
        .briefing-content{background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:28px 32px;line-height:1.75;font-size:14px}
        .briefing-content h1{font-size:22px;font-weight:800;color:#e5e5e5;margin:0 0 20px;border-bottom:2px solid #1e1e1e;padding-bottom:16px}
        .briefing-content h2{font-size:16px;font-weight:700;color:#76b900;margin:28px 0 12px}
        .briefing-content h3{font-size:14px;font-weight:700;color:#ccc;margin:16px 0 8px}
        .briefing-content p{color:#bbb;margin:8px 0}
        .briefing-content strong{color:#e5e5e5;font-weight:700}
        .briefing-content em{color:#aaa;font-style:italic}
        .briefing-content ul,.briefing-content ol{padding-left:20px;margin:8px 0;color:#bbb}
        .briefing-content li{margin:5px 0}
        .briefing-content hr{border:none;border-top:1px solid #1e1e1e;margin:20px 0}
        .briefing-content code{background:#0d0d0d;border:1px solid #222;border-radius:4px;padding:1px 6px;font-family:monospace;font-size:12px;color:#76b900}
        .briefing-content blockquote{border-left:3px solid #76b900;padding-left:14px;color:#888;margin:8px 0}
        .briefing-content a{color:#76b900}

        /* Streaming cursor */
        .cursor{display:inline-block;width:2px;height:1em;background:#76b900;vertical-align:middle;animation:blink .7s infinite}
        @keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

        /* Generating state */
        #generating-banner{display:none;background:#0d1a00;border:1px solid #1e3300;border-radius:10px;padding:14px 20px;margin-bottom:20px;display:none;align-items:center;gap:12px;font-size:13px;color:#76b900}
        .spinner{width:16px;height:16px;border:2px solid #1e3300;border-top-color:#76b900;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0}
        @keyframes spin{to{transform:rotate(360deg)}}

        .actions-bar{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}

        /* Previous briefing */
        .prev-briefing{background:#0d0d0d;border:1px solid #1a1a1a;border-radius:12px;padding:20px 24px;margin-bottom:24px}
        .prev-title{font-size:13px;font-weight:600;color:#666;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px}
        .prev-content{font-size:13px;color:#555;line-height:1.6;white-space:pre-wrap}
    </style>
</head>
<body>
<header>
    <a href="/dashboard" class="back-btn">←</a>
    <span class="logo">⚡ ClawYard</span>
    <span style="color:#555;font-size:13px">/ Briefing Executivo</span>
    <div class="hdr-right">
        <a href="/briefing/latest/pdf" class="btn btn-pdf" id="btn-pdf" target="_blank" style="{{ $todayBriefing ? '' : 'display:none' }}">⬇ PDF</a>
        <button class="btn btn-primary" id="btn-generate" onclick="startBriefing()">⚡ Gerar Briefing Diário</button>
    </div>
</header>

<div class="container">
    <div class="page-header">
        <div>
            <h1>📊 Briefing Executivo</h1>
            <p class="subtitle">Inteligência consolidada de todos os agentes · PartYard / HP-Group · {{ now()->format('d/m/Y') }}</p>
        </div>
    </div>

    @if($todayBriefing)
    <div class="status-banner">
        <div class="status-dot ready"></div>
        <div class="status-text">
            <div class="status-title">Briefing de hoje disponível</div>
            <div class="status-sub">Gerado às {{ $todayBriefing->created_at->format('H:i') }} · Clica "Gerar" para atualizar</div>
        </div>
    </div>
    @else
    <div class="status-banner">
        <div class="status-dot empty"></div>
        <div class="status-text">
            <div class="status-title">Nenhum briefing gerado hoje</div>
            <div class="status-sub">Clica "⚡ Gerar Briefing Diário" para criar o relatório executivo de hoje</div>
        </div>
    </div>
    @endif

    <div id="generating-banner" style="display:none">
        <div class="spinner"></div>
        <span id="gen-status">A reunir inteligência de todos os agentes...</span>
    </div>

    <div id="output">
        <div class="briefing-content" id="briefing-text"></div>
        <div class="actions-bar">
            <a href="/briefing/latest/pdf" class="btn btn-pdf" target="_blank">⬇ Guardar como PDF</a>
            <button class="btn" onclick="startBriefing()">🔄 Regenerar</button>
            <a href="/reports" class="btn">📋 Ver em Relatórios</a>
        </div>
    </div>

    @if($todayBriefing && !request()->has('refresh'))
    <div id="existing-output">
        <div class="briefing-content" id="existing-text"></div>
        <div class="actions-bar">
            <a href="/briefing/latest/pdf" class="btn btn-pdf" target="_blank">⬇ Guardar como PDF</a>
            <button class="btn" onclick="startBriefing()">🔄 Atualizar</button>
        </div>
    </div>
    @endif
</div>

<script>
const existingContent = @json($todayBriefing?->content ?? '');

// Render existing briefing on load
if (existingContent) {
    document.getElementById('existing-text').innerHTML = renderMarkdown(existingContent);
}

function startBriefing() {
    const btn = document.getElementById('btn-generate');
    const genBanner = document.getElementById('generating-banner');
    const output = document.getElementById('output');
    const existingOutput = document.getElementById('existing-output');
    const pdfBtn = document.getElementById('btn-pdf');
    const textEl = document.getElementById('briefing-text');
    const statusEl = document.getElementById('gen-status');

    btn.disabled = true;
    btn.textContent = '⏳ A gerar...';
    genBanner.style.display = 'flex';
    if (existingOutput) existingOutput.style.display = 'none';
    output.style.display = 'none';
    textEl.innerHTML = '';

    const statusMessages = {
        'gathering intelligence': '📡 A reunir inteligência de todos os agentes...',
        'analysing': '🧠 A analisar dados do dia...',
        'generating': '✍️ A redigir briefing executivo...',
        'streaming': '📝 A gerar análise estratégica...',
    };

    let fullText = '';
    const es = new EventSource('/briefing/stream');

    es.onmessage = function(e) {
        if (e.data === '[DONE]') {
            es.close();
            btn.disabled = false;
            btn.textContent = '⚡ Gerar Briefing Diário';
            genBanner.style.display = 'none';
            output.style.display = 'block';
            if (pdfBtn) pdfBtn.style.display = 'inline-flex';
            textEl.innerHTML = renderMarkdown(fullText);
            return;
        }
        try {
            const d = JSON.parse(e.data);
            if (d.type === 'start' || d.type === 'meta') return;
            if (d.error) {
                textEl.innerHTML = '<p style="color:#ff4444">❌ ' + d.error + '</p>';
                genBanner.style.display = 'none';
                output.style.display = 'block';
                es.close();
                btn.disabled = false;
                btn.textContent = '⚡ Gerar Briefing Diário';
                return;
            }
            if (d.chunk) {
                fullText += d.chunk;
                textEl.innerHTML = renderMarkdown(fullText) + '<span class="cursor"></span>';
                output.style.display = 'block';
            }
        } catch(err) {}
    };

    es.addEventListener('error', function() {
        if (es.readyState === EventSource.CLOSED) return;
        // Heartbeat comments (: heartbeat status) are ignored automatically
    });

    // Update status from heartbeat
    const origOnMsg = es.onmessage;
    const origAddListener = es.addEventListener.bind(es);
    origAddListener('message', function(e) {});
}

// Listen for heartbeat comments to update status
function renderMarkdown(text) {
    if (!text) return '';
    return text
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/```[\s\S]*?```/g, m => '<pre><code>' + m.slice(3,-3) + '</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^---$/gm, '<hr>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>[^]*?<\/li>\n?)+/g, m => '<ul>' + m + '</ul>')
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        .replace(/\n\n/g, '</p><p>')
        .replace(/^(?!<[hul]|<li|<hr|<pre|<\/p|<p)(.+)$/gm, '<p>$1</p>')
        .replace(/<p><\/p>/g, '');
}
</script>
</body>
</html>
