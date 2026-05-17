<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversa — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <!-- Structured token rendering: Excel/CSV/PDF/Chart/PPT exports nas conversas históricas -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js" defer></script>
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

        /* Structured token cards (TABLE/CHART/PPT) — 2026-05-17 visual polish.
           Cor de destaque baseada na agent-color (passada inline via PHP, default verde). */
        .table-card{
            --tc-accent: var(--agent-color, #76b900);
            background: #0f1115;
            border: 1px solid color-mix(in srgb, var(--tc-accent) 18%, transparent);
            border-left: 4px solid var(--tc-accent);
            border-radius: 14px;
            margin: 12px 0;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0,0,0,0.18);
            animation: tc-fade-in 0.32s ease-out;
        }
        @keyframes tc-fade-in { from { opacity:0; transform: translateY(6px); } to { opacity:1; transform: translateY(0); } }
        .table-card-header{
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 18px;
            background: linear-gradient(90deg, color-mix(in srgb, var(--tc-accent) 14%, transparent) 0%, transparent 70%);
            border-bottom: 1px solid color-mix(in srgb, var(--tc-accent) 18%, transparent);
            font-size: 13px; font-weight: 700; color: var(--tc-accent); gap: 12px;
        }
        .table-card-header small{
            opacity: .7; font-size: 11px; font-weight: 500; color: #aaa;
            background: rgba(255,255,255,0.04); padding: 3px 10px;
            border-radius: 999px; border: 1px solid #2a2f36;
        }
        .table-wrap{ overflow-x: auto; max-height: 440px; overflow-y: auto; }
        .table-wrap table{ width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .table-wrap th, .table-wrap td{
            padding: 9px 14px; text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: #e6e6e6; vertical-align: top; line-height: 1.45;
        }
        .table-wrap th{
            background: rgba(0,0,0,0.35);
            color: color-mix(in srgb, var(--tc-accent) 75%, #ffffff);
            font-weight: 700; font-size: 11px; text-transform: uppercase;
            letter-spacing: 0.6px; position: sticky; top: 0; z-index: 2;
            border-bottom: 1px solid color-mix(in srgb, var(--tc-accent) 22%, #000);
        }
        .table-wrap tbody tr:nth-child(even) td { background: rgba(255,255,255,0.018); }
        .table-wrap tbody tr:hover td {
            background: color-mix(in srgb, var(--tc-accent) 8%, transparent);
            transition: background 0.12s ease;
        }
        .table-analysis{
            padding: 11px 18px;
            background: color-mix(in srgb, var(--tc-accent) 6%, transparent);
            color: #b8d1bf; font-size: 12.5px; line-height: 1.6;
            border-top: 1px solid rgba(255,255,255,0.04);
        }
        .table-recommendation{
            padding: 10px 18px 12px; background: rgba(244,195,97,.06);
            color: #f4c361; font-size: 12.5px; line-height: 1.55;
            border-top: 1px solid rgba(255,255,255,0.04); font-weight: 600;
        }
        .table-actions{
            display: flex; gap: 8px; padding: 10px 16px;
            background: rgba(0,0,0,0.22);
            border-top: 1px solid rgba(255,255,255,0.04); flex-wrap: wrap;
        }
        .table-excel-btn, .table-copy-btn{
            background: var(--tc-accent); border: 1px solid var(--tc-accent);
            color: #fff; padding: 7px 14px; border-radius: 8px;
            cursor: pointer; font-size: 12px; font-weight: 600;
            transition: transform 0.1s, filter 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .table-excel-btn:hover, .table-copy-btn:hover { filter: brightness(1.15); transform: translateY(-1px); }

        :root[data-theme="light"] .table-card{background:#fff;border-color:color-mix(in srgb, var(--tc-accent) 18%, transparent);box-shadow:0 4px 14px rgba(0,0,0,0.06)}
        :root[data-theme="light"] .table-card-header{color:var(--tc-accent)}
        :root[data-theme="light"] .table-card-header small{background:rgba(0,0,0,0.04);color:#6b7280;border-color:#e5e7eb}
        :root[data-theme="light"] .table-wrap th,
        :root[data-theme="light"] .table-wrap td{color:#374151;border-bottom-color:rgba(0,0,0,0.06)}
        :root[data-theme="light"] .table-wrap th{background:rgba(0,0,0,0.04);color:var(--tc-accent)}
        :root[data-theme="light"] .table-wrap tbody tr:nth-child(even) td{background:rgba(0,0,0,0.025)}
        :root[data-theme="light"] .table-actions{background:rgba(0,0,0,0.03)}

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
// ── Markdown + structured-token rendering (shared logic com welcome.blade.php) ──
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]);
}
function renderMarkdown(text) {
    return text
        .replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>')
        .replace(/```([\s\S]*?)```/g, (_, c) => '<pre><code>' + esc(c) + '</code></pre>')
        .replace(/`([^`]+)`/g, (_, c) => '<code>' + esc(c) + '</code>')
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

let _chartCardCounter = 0;
let _tableCardCounter = 0;

// LLM JSON sanitizer — same as welcome.blade. Lida com smart quotes,
// trailing commas e comentários ocasionais que JSON.parse rejeita.
function sanitizeLlmJson(s) {
    s = s
        .replace(/[“”„‟″❝❞]/g, '"')
        .replace(/[‘’‚‛′❛❜]/g, '\'')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/,(\s*[}\]])/g, '$1');
    // Escape control chars dentro de strings JSON (newline/tab/etc)
    let out = '', inString = false, escape = false;
    for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (escape) { out += ch; escape = false; continue; }
        if (ch === '\\') { out += ch; escape = true; continue; }
        if (ch === '"')  { out += ch; inString = !inString; continue; }
        if (inString) {
            const code = ch.charCodeAt(0);
            if (ch === '\n') { out += '\\n'; continue; }
            if (ch === '\r') { out += '\\r'; continue; }
            if (ch === '\t') { out += '\\t'; continue; }
            if (code < 0x20) { out += '\\u' + code.toString(16).padStart(4,'0'); continue; }
        }
        out += ch;
    }
    return out;
}

function parseStructuredBlocks(text) {
    const TOKENS = ['__TABLE__', '__CHART__', '__PPT__'];
    const blocks = [];
    let cursor = 0;
    while (cursor < text.length) {
        let earliest = -1, earliestToken = null;
        for (const tok of TOKENS) {
            const i = text.indexOf(tok, cursor);
            if (i !== -1 && (earliest === -1 || i < earliest)) { earliest = i; earliestToken = tok; }
        }
        if (earliest === -1) {
            const rest = text.slice(cursor);
            if (rest.trim()) blocks.push({ type: 'text', content: rest });
            break;
        }
        if (earliest > cursor) {
            const pre = text.slice(cursor, earliest);
            if (pre.trim()) blocks.push({ type: 'text', content: pre });
        }
        let i = earliest + earliestToken.length;
        while (i < text.length && text[i] !== '{') i++;
        if (i >= text.length) break;
        let depth = 0, inString = false, escape = false;
        const start = i;
        for (; i < text.length; i++) {
            const ch = text[i];
            if (escape) { escape = false; continue; }
            if (ch === '\\') { escape = true; continue; }
            // Aceitar straight " E curly “/” como string delimiters
            if (ch === '"' || ch === '“' || ch === '”') {
                inString = !inString;
                continue;
            }
            if (inString) continue;
            if (ch === '{') depth++;
            else if (ch === '}') { depth--; if (depth === 0) { i++; break; } }
        }
        if (depth !== 0) { blocks.push({ type: 'text', content: text.slice(earliest) }); break; }
        const json = text.slice(start, i);
        // Try raw, then sanitized
        let data = null;
        try {
            data = JSON.parse(json);
        } catch (e1) {
            try {
                data = JSON.parse(sanitizeLlmJson(json));
                console.warn('show.blade: JSON saved by sanitizer', { token: earliestToken });
            } catch (e2) {
                console.error('show.blade: JSON failed', { token: earliestToken, e1: e1.message, e2: e2.message });
                blocks.push({ type: 'text', content: text.slice(earliest, i) });
                cursor = i;
                continue;
            }
        }
        const valid =
            (earliestToken === '__TABLE__' && Array.isArray(data.columns) && Array.isArray(data.rows)) ||
            (earliestToken === '__CHART__' && Array.isArray(data.labels)  && Array.isArray(data.datasets)) ||
            (earliestToken === '__PPT__'   && Array.isArray(data.slides));
        if (valid) {
            if (earliestToken === '__TABLE__') blocks.push({ type: 'table', data });
            else if (earliestToken === '__CHART__') {
                blocks.push({ type: 'chart', data, canvasId: 'chart_' + Date.now() + '_' + (++_chartCardCounter) });
            } else blocks.push({ type: 'ppt', data });
        } else {
            blocks.push({ type: 'text', content: text.slice(earliest, i) });
        }
        cursor = i;
    }
    return blocks;
}

function buildTableCard(data) {
    const id = 'tbl_' + Date.now() + '_' + (++_tableCardCounter);
    const headers = data.columns.map(c => `<th>${esc(c)}</th>`).join('');
    const rows = data.rows.map(r => `<tr>${r.map(c => `<td>${esc(String(c))}</td>`).join('')}</tr>`).join('');
    return `
    <div class="table-card" id="${id}">
        <div class="table-card-header">
            <span>📊 ${esc(data.title || 'Tabela')}</span>
            <small>${data.rows.length} itens</small>
        </div>
        <div class="table-wrap"><table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></div>
        ${data.analysis ? `<div class="table-analysis">🔍 ${esc(data.analysis)}</div>` : ''}
        ${data.recommendation ? `<div class="table-recommendation">✅ ${esc(data.recommendation)}</div>` : ''}
        <div class="table-actions">
            <button class="table-excel-btn" onclick="exportXlsx('${id}')">📥 Excel</button>
            <button class="table-excel-btn" onclick="exportExcel('${id}')" style="background:#445;border-color:#556">📄 CSV</button>
            <button class="table-excel-btn" onclick="exportTablePdf('${id}')" style="background:#a83232;border-color:#c84444">📑 PDF</button>
            <button class="table-copy-btn" onclick="copyTable('${id}')">📋 Copiar</button>
        </div>
    </div>`;
}

function buildChartCard(data, canvasId) {
    const id = canvasId;
    return `
    <div class="table-card">
        <div class="table-card-header">
            <span>📊 ${esc(data.title || 'Gráfico')}</span>
            <small>${data.labels.length} pontos · ${data.type || 'bar'}</small>
        </div>
        <div style="padding:14px;background:#0f1115"><canvas id="${id}" width="700" height="380"></canvas></div>
        ${data.analysis ? `<div class="table-analysis">🔍 ${esc(data.analysis)}</div>` : ''}
        <div class="table-actions">
            <button class="table-excel-btn" onclick="exportChartPng('${id}','${(data.title||'chart').replace(/'/g,'\\\'')}')">⬇ PNG</button>
        </div>
    </div>`;
}

function renderChart(data, canvasId) {
    if (typeof Chart === 'undefined') return setTimeout(() => renderChart(data, canvasId), 400);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const defaultColors = ['#76b900','#3b82f6','#ec4899','#f59e0b','#a855f7','#10b981','#ef4444','#06b6d4'];
    const datasets = data.datasets.map((d, idx) => ({
        label: d.label || `Série ${idx+1}`,
        data: d.data,
        backgroundColor: d.color || defaultColors[idx % defaultColors.length],
        borderColor: d.color || defaultColors[idx % defaultColors.length],
        borderWidth: 2, tension: 0.3,
    }));
    try {
        new Chart(canvas.getContext('2d'), {
            type: data.type || 'bar',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#e6e6e6' } } },
                scales: (['pie','doughnut','radar'].includes(data.type)) ? {} : {
                    x: { ticks: { color: '#9ca3af' }, grid: { color: '#2a2f36' } },
                    y: { ticks: { color: '#9ca3af' }, grid: { color: '#2a2f36' } },
                },
            },
        });
    } catch (e) { console.error('Chart render failed:', e); }
}

function buildPptCard(pd) {
    const slides = pd.slides || [];
    return `
    <div class="table-card">
        <div class="table-card-header">
            <span>📊 ${esc(pd.title || 'PowerPoint')}</span>
            <small>${slides.length} slide${slides.length===1?'':'s'}</small>
        </div>
        <div style="padding:14px;color:#e6e6e6;font-size:13px;line-height:1.6">
            ${pd.author ? `<div style="opacity:.65;margin-bottom:8px">Autor: ${esc(pd.author)}</div>` : ''}
            <ol style="margin:0;padding-left:20px">${slides.map(s => `<li>${esc(s.title || '(sem título)')}</li>`).join('')}</ol>
        </div>
        <div class="table-actions">
            <button class="table-excel-btn" onclick='exportPpt(${JSON.stringify(pd).replace(/'/g,"\\'")})' style="background:#c2410c;border-color:#ea580c">📊 Download .pptx</button>
        </div>
    </div>`;
}

function exportXlsx(id) {
    if (typeof XLSX === 'undefined') return exportExcel(id);
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','').trim() || 'tabela';
    const rows = Array.from(card.querySelectorAll('table tr')).map(tr =>
        Array.from(tr.querySelectorAll('th,td')).map(c => {
            const v = c.textContent.trim();
            if (/^-?\d+([.,]\d+)?$/.test(v)) return parseFloat(v.replace(',', '.'));
            return v;
        })
    );
    if (!rows.length) return;
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = Array(rows[0].length).fill(0).map((_, i) => ({ wch: Math.min(Math.max(Math.max(...rows.map(r => String(r[i] ?? '').length)) + 2, 10), 60) }));
    ws['!autofilter'] = { ref: ws['!ref'] };
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, title.replace(/[^a-zA-Z0-9 _-]/g,'').slice(0,31) || 'Tabela');
    XLSX.writeFile(wb, title.replace(/[^a-zA-Z0-9_\-]/g,'_').slice(0,80) + '.xlsx');
}
function exportExcel(id) {
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','').trim() || 'tabela';
    const rows = Array.from(card.querySelectorAll('table tr'));
    const csv = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => '"' + c.textContent.replace(/"/g,'""') + '"').join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = title.replace(/[^a-zA-Z0-9_\-]/g,'_') + '.csv'; a.click();
}
function exportTablePdf(id) {
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','').trim() || 'tabela';
    const tableHtml = card.querySelector('table').outerHTML;
    const w = window.open('', '_blank');
    w.document.write(`<!DOCTYPE html><html><head><title>${esc(title)}</title><style>body{font-family:system-ui;padding:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f4f4f4}</style></head><body><h2>${esc(title)}</h2>${tableHtml}<script>window.onload=()=>setTimeout(()=>window.print(),300);<\/script></body></html>`);
    w.document.close();
}
function copyTable(id) {
    const rows = Array.from(document.getElementById(id).querySelectorAll('table tr'));
    const tsv = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => c.textContent).join('\t')).join('\n');
    navigator.clipboard.writeText(tsv);
}
function exportChartPng(canvasId, title) {
    const c = document.getElementById(canvasId); if (!c) return;
    const a = document.createElement('a');
    a.href = c.toDataURL('image/png', 1.0);
    a.download = title.replace(/[^a-zA-Z0-9_\-]/g,'_') + '.png';
    a.click();
}
function exportPpt(pd) {
    if (typeof PptxGenJS === 'undefined') { alert('PptxGenJS a carregar — tenta novamente'); return; }
    const pptx = new PptxGenJS();
    pptx.author = pd.author || 'ClawYard';
    pptx.company = 'HP-Group / PartYard';
    pptx.title = pd.title || 'ClawYard Deck';
    pptx.layout = 'LAYOUT_WIDE';
    const title = pptx.addSlide();
    title.background = { color: '0F1115' };
    title.addText(pd.title || 'Deck', { x:0.5, y:2.5, w:12, h:1.2, fontSize:36, bold:true, color:'FFFFFF', align:'center' });
    if (pd.author) title.addText('por ' + pd.author, { x:0.5, y:4, w:12, h:0.6, fontSize:18, color:'EC4899', align:'center' });
    (pd.slides || []).forEach((s, idx) => {
        const slide = pptx.addSlide();
        slide.addText(s.title || `Slide ${idx+1}`, { x:0.5, y:0.3, w:12, h:0.8, fontSize:28, bold:true, color:'76B900' });
        if (s.kpi) {
            slide.addText(s.kpi.value || '', { x:0.5, y:1.8, w:12, h:2, fontSize:72, bold:true, color:'76B900', align:'center' });
            slide.addText(s.kpi.label || '', { x:0.5, y:4.2, w:12, h:0.8, fontSize:22, color:'666666', align:'center' });
            if (s.kpi.delta) slide.addText(s.kpi.delta, { x:0.5, y:5.2, w:12, h:0.6, fontSize:20, color: s.kpi.delta.startsWith('-') ? 'EF4444' : '10B981', align:'center' });
        } else if (Array.isArray(s.bullets)) {
            slide.addText(s.bullets.map(b => ({ text:b, options:{bullet:true} })), { x:0.5, y:1.5, w:12, h:5, fontSize:16, color:'222222' });
        } else if (s.body) {
            slide.addText(s.body, { x:0.5, y:1.5, w:12, h:5.5, fontSize:14, color:'222222' });
        }
    });
    pptx.writeFile({ fileName: (pd.title || 'deck').replace(/[^a-zA-Z0-9_\-]/g,'_').slice(0,60) + '.pptx' });
}

// ── Apply rendering per message ────────────────────────────────────────
const chartsToInit = [];
document.querySelectorAll('.msg-content[data-raw]').forEach(el => {
    const raw = el.dataset.raw;
    if (raw.includes('__TABLE__') || raw.includes('__CHART__') || raw.includes('__PPT__')) {
        const blocks = parseStructuredBlocks(raw);
        if (blocks.some(b => b.type !== 'text')) {
            el.style.whiteSpace = 'normal'; // override pre-wrap so cards render properly
            el.innerHTML = blocks.map(b => {
                if (b.type === 'text') return `<div>${renderMarkdown(b.content)}</div>`;
                if (b.type === 'table') return buildTableCard(b.data);
                if (b.type === 'chart') {
                    chartsToInit.push({ data: b.data, canvasId: b.canvasId });
                    return buildChartCard(b.data, b.canvasId);
                }
                if (b.type === 'ppt') return buildPptCard(b.data);
                return '';
            }).join('');
            return;
        }
    }
    el.innerHTML = renderMarkdown(raw);
});
// Instantiate charts after DOM ready (Chart.js needs canvases attached)
window.addEventListener('load', () => {
    chartsToInit.forEach(c => renderChart(c.data, c.canvasId));
});
</script>
@include('partials.theme-button')
@include('partials.keyboard-shortcuts')
</body>
</html>
