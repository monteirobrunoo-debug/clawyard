<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Actividade dos Agentes — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green:  #76b900;
            --bg:     #0f0f0f;
            --bg2:    #161616;
            --bg3:    #1e1e1e;
            --card:   #1a1a1a;
            --border: #252525;
            --text:   #e5e5e5;
            --muted:  #666;
            --muted2: #444;
        }

        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* ── TOP NAV ──────────────────────────────────────────────────── */
        .topnav {
            height: 52px; background: var(--bg2); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px; padding: 0 24px;
            position: sticky; top: 0; z-index: 100;
        }
        .logo { font-size: 17px; font-weight: 800; color: var(--text); letter-spacing: -.5px; }
        .logo span { color: var(--green); }
        .badge-nav { font-size: 10px; background: var(--green); color: #000; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .nav-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .nav-link {
            font-size: 12px; color: var(--muted); text-decoration: none;
            border: 1px solid var(--border); padding: 5px 12px; border-radius: 8px; transition: all .15s;
        }
        .nav-link:hover { color: var(--text); border-color: #333; }
        .update-time { font-size: 11px; color: var(--muted2); }

        /* ── PAGE HEADER ──────────────────────────────────────────────── */
        .page-header { padding: 32px 24px 20px; }
        .page-header h1 { font-size: 24px; font-weight: 800; margin-bottom: 4px; }
        .page-header p  { font-size: 13px; color: var(--muted); }

        /* ── GRID ─────────────────────────────────────────────────────── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px; padding: 0 24px 40px;
        }

        /* ── AGENT CARD ───────────────────────────────────────────────── */
        .agent-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            transition: transform .15s, box-shadow .15s;
        }
        .agent-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0,0,0,.4);
        }

        /* Card header */
        .card-head {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 18px 14px;
            border-bottom: 1px solid var(--border);
        }
        .avatar {
            width: 46px; height: 46px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0; position: relative;
            background: var(--bg3);
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .status-dot {
            position: absolute; bottom: 1px; right: 1px;
            width: 11px; height: 11px; border-radius: 50%;
            border: 2px solid var(--card);
        }
        .status-dot.active  { background: #22c55e; animation: pulse-dot 2s infinite; }
        .status-dot.idle    { background: var(--muted2); }
        @keyframes pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(34,197,94,.4); }
            50%       { box-shadow: 0 0 0 5px rgba(34,197,94,0); }
        }

        .agent-info { flex: 1; min-width: 0; }
        .agent-name { font-size: 14px; font-weight: 700; color: var(--text); }
        .agent-role { font-size: 11px; color: var(--muted); margin-top: 1px; }
        .agent-status {
            display: flex; align-items: center; gap: 5px;
            font-size: 11px; margin-top: 4px;
        }
        .agent-status.active { color: #22c55e; }
        .agent-status.idle   { color: var(--muted2); }
        .agent-status svg    { flex-shrink: 0; }

        .chat-btn {
            font-size: 11px; font-weight: 600;
            background: transparent; border: 1px solid var(--border);
            color: var(--muted); padding: 5px 11px; border-radius: 8px;
            cursor: pointer; transition: all .12s; text-decoration: none;
            white-space: nowrap;
        }
        .chat-btn:hover { color: var(--text); border-color: #555; }

        /* Scanning strip — shown when active */
        .scanning-strip {
            display: none; padding: 10px 18px;
            background: var(--bg3); border-bottom: 1px solid var(--border);
        }
        .scanning-strip.visible { display: block; }
        .scan-item {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; color: var(--muted); padding: 3px 0;
            animation: fadeSlideIn .3s ease forwards;
            opacity: 0;
        }
        .scan-item:nth-child(1) { animation-delay: 0s; }
        .scan-item:nth-child(2) { animation-delay: .15s; }
        .scan-item:nth-child(3) { animation-delay: .3s; }
        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateX(-6px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        .scan-check {
            width: 15px; height: 15px; border-radius: 50%;
            background: rgba(34,197,94,.15); border: 1px solid #22c55e;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .scan-check svg { color: #22c55e; }

        /* Activity items */
        .activity-list { padding: 6px 0; }
        .activity-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 9px 18px; transition: background .1s;
        }
        .activity-item:hover { background: var(--bg3); }
        .act-bar {
            width: 3px; height: 36px; border-radius: 3px;
            flex-shrink: 0; margin-top: 2px;
        }
        .act-body { flex: 1; min-width: 0; }
        .act-title {
            font-size: 12px; font-weight: 600; color: var(--text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .act-sub { font-size: 11px; color: var(--muted); margin-top: 2px; }
        .act-icon { font-size: 15px; flex-shrink: 0; margin-top: 2px; }

        /* Card footer */
        .card-footer {
            display: flex; align-items: center; gap: 6px;
            padding: 10px 18px; border-top: 1px solid var(--border);
            background: var(--bg3);
        }
        .stat-chip {
            font-size: 10px; font-weight: 700; padding: 3px 8px;
            border-radius: 20px; background: var(--bg2); color: var(--muted);
            border: 1px solid var(--border);
        }
        .stat-chip span { color: var(--text); }
        .footer-time { margin-left: auto; font-size: 11px; color: var(--muted2); }

        /* Summary card — "overnight/morning" style */
        .summary-card {
            background: #fff; color: #1e293b;
            border-radius: 16px; overflow: hidden;
            border: none; box-shadow: 0 2px 12px rgba(0,0,0,.08);
        }
        .summary-card .card-head { border-bottom: 1px solid #f1f5f9; background: #fff; }
        .summary-card .agent-name { color: #1e293b; }
        .summary-card .agent-role { color: #64748b; }
        .summary-card .agent-status.idle { color: #94a3b8; }
        .summary-card .activity-item { padding: 10px 18px; }
        .summary-card .activity-item:hover { background: #f8fafc; }
        .summary-card .act-title { color: #1e293b; font-size: 13px; }
        .summary-card .act-sub   { color: #64748b; }
        .summary-card .card-footer { background: #f8fafc; border-top: 1px solid #f1f5f9; }
        .summary-card .stat-chip  { background: #fff; color: #64748b; border-color: #e2e8f0; }
        .summary-card .stat-chip span { color: #1e293b; }
        .summary-card .footer-time { color: #94a3b8; }
        .summary-card .chat-btn { color: #64748b; border-color: #e2e8f0; }
        .summary-card .chat-btn:hover { color: #1e293b; }

        /* Loading */
        .loading-grid { display: flex; align-items: center; justify-content: center; height: 300px; }
        .spinner { width: 28px; height: 28px; border: 3px solid #1e1e1e; border-top-color: var(--green); border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Responsive */
        @media (max-width: 600px) {
            .grid { grid-template-columns: 1fr; padding: 0 12px 40px; }
            .page-header { padding: 20px 12px 16px; }
        }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <div class="logo">Claw<span>Yard</span></div>
    <div class="badge-nav">Agentes</div>
    <div class="nav-right">
        <span class="update-time" id="updateTime"></span>
        <a href="/dashboard" class="nav-link">← Dashboard</a>
    </div>
</nav>

<!-- HEADER -->
<div class="page-header">
    <h1>Actividade dos Agentes</h1>
    <p>Estado em tempo real — relatórios, descobertas e últimas interacções de cada agente.</p>
</div>

<!-- CARDS -->
<div id="cardsContainer" class="grid">
    <div class="loading-grid" style="grid-column:1/-1">
        <div class="spinner"></div>
    </div>
</div>

<script>
const AGENT_IMAGES = {};
// Pre-detect images that exist
const IMAGE_KEYS = ['briefing','acingov','quantum','sap','email','finance','patent','energy','engineer','cyber','sales','support'];
IMAGE_KEYS.forEach(k => {
    ['png','jpg','jpeg','webp'].forEach(ext => {
        const img = new Image();
        img.onload = () => { AGENT_IMAGES[k] = `/images/agents/${k}.${ext}`; };
        img.src = `/images/agents/${k}.${ext}`;
    });
});

function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderAgentCard(a) {
    const isLight = ['sap','email','finance'].includes(a.key);
    const imgSrc  = AGENT_IMAGES[a.key];

    const avatarInner = imgSrc
        ? `<img src="${imgSrc}" alt="${esc(a.name)}">`
        : `<span>${esc(a.emoji)}</span>`;

    const statusDot  = a.is_active
        ? `<div class="status-dot active"></div>`
        : `<div class="status-dot idle"></div>`;

    const statusText = a.is_active
        ? `<svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg> A trabalhar...`
        : `<svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg> ${esc(a.last_active)}`;

    // Scanning strip (only when active)
    const scanStrip = a.is_active ? `
        <div class="scanning-strip visible">
            ${a.actions.map(action => `
            <div class="scan-item">
                <div class="scan-check">
                    <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                ${esc(action)}
            </div>`).join('')}
        </div>` : '';

    // Activity items
    const actItems = a.items.map(item => `
        <div class="activity-item">
            <div class="act-bar" style="background:${esc(item.color)}"></div>
            <div class="act-body">
                <div class="act-title">${esc(item.title)}</div>
                <div class="act-sub">${esc(item.sub)}</div>
            </div>
            <div class="act-icon">${esc(item.icon)}</div>
        </div>`).join('');

    // Stats
    const stats = [];
    if (a.total_reports > 0)      stats.push(`<div class="stat-chip"><span>${a.total_reports}</span> relatórios</div>`);
    if (a.total_discoveries > 0)  stats.push(`<div class="stat-chip"><span>${a.total_discoveries}</span> descobertas</div>`);

    return `
    <div class="agent-card ${isLight ? 'summary-card' : ''}">
        <div class="card-head">
            <div class="avatar" style="border: 2px solid ${esc(a.color)}20">
                ${avatarInner}
                ${statusDot}
            </div>
            <div class="agent-info">
                <div class="agent-name">${esc(a.name)}</div>
                <div class="agent-role">${esc(a.role)}</div>
                <div class="agent-status ${a.is_active ? 'active' : 'idle'}">${statusText}</div>
            </div>
            <a href="/chat?agent=${esc(a.key)}" class="chat-btn">Falar →</a>
        </div>
        ${scanStrip}
        <div class="activity-list">${actItems}</div>
        <div class="card-footer">
            ${stats.join('')}
            <div class="footer-time">⟳ actualizado agora</div>
        </div>
    </div>`;
}

async function loadActivity() {
    try {
        const resp = await fetch('/api/agents/activity', {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
        });
        const data = await resp.json();
        if (!data.ok) return;

        const container = document.getElementById('cardsContainer');
        container.innerHTML = data.agents.map(renderAgentCard).join('');
        document.getElementById('updateTime').textContent = 'Actualizado às ' + data.updated_at;
    } catch (e) {
        console.error('Activity fetch error:', e);
    }
}

// Initial load + auto-refresh every 30s
loadActivity();
setInterval(loadActivity, 30000);
</script>
</body>
</html>
