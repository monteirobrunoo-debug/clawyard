<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Actividade dos Agentes — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @php
    /* Server-side image detection — no async race condition */
    $agentImages = [];
    $keys = ['briefing','orchestrator','sales','support','email','sap','document','claude','nvidia','aria','quantum','finance','research','acingov','engineer','patent','energy','maritime','cyber'];
    foreach ($keys as $k) {
        foreach (['.png','.jpg','.jpeg','.webp'] as $ext) {
            if (file_exists(public_path('images/agents/'.$k.$ext))) {
                $agentImages[$k] = '/images/agents/'.$k.$ext;
                break;
            }
        }
    }
    @endphp

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green:  #76b900;
            --bg:     #0c0c0c;
            --bg2:    #141414;
            --bg3:    #1c1c1c;
            --card:   #181818;
            --border: #222;
            --text:   #f0f0f0;
            --muted:  #666;
            --muted2: #3a3a3a;
        }

        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* ── NAV ─────────────────────────────────────────────────────── */
        .topnav {
            height: 52px; background: var(--bg2); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px; padding: 0 28px;
            position: sticky; top: 0; z-index: 100;
        }
        .logo { font-size: 17px; font-weight: 800; color: var(--text); letter-spacing: -.5px; }
        .logo span { color: var(--green); }
        .badge-nav { font-size: 10px; background: var(--green); color: #000; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .nav-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .nav-link { font-size: 12px; color: var(--muted); text-decoration: none; border: 1px solid var(--border); padding: 5px 12px; border-radius: 8px; transition: all .15s; }
        .nav-link:hover { color: var(--text); border-color: #444; }
        .update-badge { font-size: 11px; color: var(--muted); }

        /* ── HEADER ──────────────────────────────────────────────────── */
        .page-header { padding: 32px 28px 24px; }
        .page-header h1 { font-size: 22px; font-weight: 800; margin-bottom: 4px; letter-spacing: -.4px; }
        .page-header p  { font-size: 13px; color: var(--muted); }

        /* ── GRID ────────────────────────────────────────────────────── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px; padding: 0 28px 60px;
        }

        /* ── CARD ────────────────────────────────────────────────────── */
        .agent-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 18px; overflow: hidden;
            transition: transform .15s, box-shadow .15s, border-color .15s;
        }
        .agent-card:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(0,0,0,.5); border-color: #2e2e2e; }

        /* ── CARD HEAD ───────────────────────────────────────────────── */
        .card-head { display: flex; align-items: center; gap: 13px; padding: 18px 18px 14px; }

        .avatar-wrap { position: relative; flex-shrink: 0; }
        .avatar {
            width: 50px; height: 50px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; overflow: hidden;
            background: var(--bg3); border: 2px solid var(--border);
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .status-dot {
            position: absolute; bottom: 1px; right: 1px;
            width: 12px; height: 12px; border-radius: 50%;
            border: 2.5px solid var(--card);
        }
        .status-dot.on  { background: #22c55e; animation: pulse-ring 2.5s infinite; }
        .status-dot.off { background: #3a3a3a; }
        @keyframes pulse-ring {
            0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.5); }
            70%  { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
            100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
        }

        .agent-info { flex: 1; min-width: 0; }
        .agent-name { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
        .agent-role { font-size: 11px; color: var(--muted); line-height: 1.4; }
        .status-line {
            display: flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 500; margin-top: 5px;
        }
        .status-line.on  { color: #22c55e; }
        .status-line.off { color: var(--muted); }

        /* Three-dot "working" animation */
        .dots span { display: inline-block; animation: dotBounce 1.2s infinite ease-in-out; }
        .dots span:nth-child(2) { animation-delay: .2s; }
        .dots span:nth-child(3) { animation-delay: .4s; }
        @keyframes dotBounce { 0%,80%,100% { opacity:.3; transform: scale(.8); } 40% { opacity:1; transform: scale(1.2); } }

        .talk-btn {
            font-size: 11px; font-weight: 600; color: var(--muted);
            border: 1px solid var(--border); padding: 5px 12px; border-radius: 8px;
            text-decoration: none; white-space: nowrap; transition: all .12s;
        }
        .talk-btn:hover { color: var(--text); border-color: #555; }

        /* ── SCANNING STRIP ──────────────────────────────────────────── */
        .scan-strip {
            padding: 10px 18px 12px;
            background: #0f1a00; border-top: 1px solid #1a2e00;
        }
        .scan-label { font-size: 10px; font-weight: 700; color: var(--green); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .scan-item {
            display: flex; align-items: center; gap: 9px;
            font-size: 12px; color: #aaa; padding: 3px 0;
            opacity: 0; animation: slideIn .35s ease forwards;
        }
        .scan-item:nth-child(2) { animation-delay: .12s; }
        .scan-item:nth-child(3) { animation-delay: .24s; }
        .scan-item:nth-child(4) { animation-delay: .36s; }
        @keyframes slideIn { from { opacity:0; transform:translateX(-8px); } to { opacity:1; transform:translateX(0); } }
        .scan-check {
            width: 16px; height: 16px; border-radius: 50%; flex-shrink: 0;
            background: rgba(34,197,94,.12); border: 1.5px solid #22c55e;
            display: flex; align-items: center; justify-content: center;
        }
        .scan-check svg { color: #22c55e; }

        /* ── ACTIVITY LIST ───────────────────────────────────────────── */
        .act-divider { height: 1px; background: var(--border); margin: 0 18px; }
        .activity-list { padding: 4px 0 2px; }
        .act-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 9px 18px; transition: background .1s;
        }
        .act-item:hover { background: var(--bg3); }
        .act-bar { width: 3px; min-height: 34px; border-radius: 3px; flex-shrink: 0; margin-top: 3px; }
        .act-body { flex: 1; min-width: 0; }
        .act-title { font-size: 12px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .act-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .act-icon  { font-size: 15px; flex-shrink: 0; margin-top: 2px; }

        /* ── FOOTER ──────────────────────────────────────────────────── */
        .card-footer {
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
            padding: 10px 18px; border-top: 1px solid var(--border);
            background: var(--bg3);
        }
        .stat-chip { font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 20px; background: var(--bg2); color: var(--muted); border: 1px solid var(--border); }
        .stat-chip b { color: var(--text); }
        .foot-time { margin-left: auto; font-size: 11px; color: var(--muted2); }

        /* ── LIGHT CARD (SAP/Email/Finance) ──────────────────────────── */
        .card-light {
            background: #fff; border-color: #e8ecf0; color: #1e293b;
        }
        .card-light:hover { border-color: #cbd5e1; box-shadow: 0 8px 32px rgba(0,0,0,.12); }
        .card-light .avatar         { background: #f1f5f9; border-color: #e2e8f0; }
        .card-light .status-dot.off { background: #cbd5e1; border-color: #fff; }
        .card-light .status-dot.on  { border-color: #fff; }
        .card-light .agent-name     { color: #0f172a; }
        .card-light .agent-role     { color: #64748b; }
        .card-light .status-line.off{ color: #94a3b8; }
        .card-light .status-line.on { color: #16a34a; }
        .card-light .talk-btn       { color: #64748b; border-color: #e2e8f0; }
        .card-light .talk-btn:hover { color: #0f172a; border-color: #94a3b8; }
        .card-light .act-divider    { background: #f1f5f9; }
        .card-light .act-item:hover { background: #f8fafc; }
        .card-light .act-title      { color: #0f172a; }
        .card-light .act-sub        { color: #64748b; }
        .card-light .act-icon       { filter: none; }
        .card-light .card-footer    { background: #f8fafc; border-color: #f1f5f9; }
        .card-light .stat-chip      { background: #fff; color: #64748b; border-color: #e2e8f0; }
        .card-light .stat-chip b    { color: #0f172a; }
        .card-light .foot-time      { color: #94a3b8; }
        .card-light .scan-strip     { background: #f0fdf4; border-color: #bbf7d0; }
        .card-light .scan-label     { color: #16a34a; }
        .card-light .scan-item      { color: #374151; }

        /* ── EMPTY / LOADING ─────────────────────────────────────────── */
        .loading-wrap { grid-column: 1/-1; display:flex; align-items:center; justify-content:center; height:280px; }
        .spinner { width:26px; height:26px; border:3px solid var(--border); border-top-color: var(--green); border-radius:50%; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── RESPONSIVE ──────────────────────────────────────────────── */
        @media (max-width: 640px) {
            .grid { grid-template-columns: 1fr; padding: 0 14px 40px; }
            .page-header { padding: 20px 14px 18px; }
            .topnav { padding: 0 14px; }
        }
    </style>
</head>
<body>

<nav class="topnav">
    <div class="logo">Claw<span>Yard</span></div>
    <span class="badge-nav">Actividade</span>
    <div class="nav-right">
        <span class="update-badge" id="updateBadge"></span>
        <a href="/dashboard" class="nav-link">← Dashboard</a>
    </div>
</nav>

<div class="page-header">
    <h1>Actividade dos Agentes</h1>
    <p>Estado em tempo real — relatórios, descobertas e últimas interacções de cada agente.</p>
</div>

<div id="grid" class="grid">
    <div class="loading-wrap"><div class="spinner"></div></div>
</div>

<script>
const AGENT_IMAGES = @json($agentImages);

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderCard(a) {
    const isLight = ['sap','email','finance','document'].includes(a.key);
    const photo   = AGENT_IMAGES[a.key] || null;

    const avatarHtml = photo
        ? `<img src="${esc(photo)}" alt="${esc(a.name)}">`
        : `<span>${esc(a.emoji)}</span>`;

    const dotClass = a.is_active ? 'on' : 'off';

    const statusHtml = a.is_active
        ? `<span class="status-line on">
               <svg width="8" height="8" viewBox="0 0 10 10" fill="#22c55e"><circle cx="5" cy="5" r="5"/></svg>
               A trabalhar<span class="dots"><span>.</span><span>.</span><span>.</span></span>
           </span>`
        : `<span class="status-line off">${esc(a.last_active || 'Inactivo')}</span>`;

    // Scanning strip — only when active
    const scanHtml = a.is_active && a.actions && a.actions.length ? `
        <div class="scan-strip">
            <div class="scan-label">A executar</div>
            ${a.actions.map(act => `
            <div class="scan-item">
                <div class="scan-check">
                    <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <span>${esc(act)}</span>
            </div>`).join('')}
        </div>` : '';

    // Activity items
    const itemsHtml = (a.items && a.items.length)
        ? `<div class="act-divider"></div>
           <div class="activity-list">
           ${a.items.map(it => `
               <div class="act-item">
                   <div class="act-bar" style="background:${esc(it.color||'#444')}"></div>
                   <div class="act-body">
                       <div class="act-title">${esc(it.title)}</div>
                       <div class="act-sub">${esc(it.sub)}</div>
                   </div>
                   <div class="act-icon">${esc(it.icon||'')}</div>
               </div>`).join('')}
           </div>` : '';

    // Stats footer
    const chips = [];
    if (a.total_reports > 0)     chips.push(`<div class="stat-chip"><b>${a.total_reports}</b> relatórios</div>`);
    if (a.total_discoveries > 0) chips.push(`<div class="stat-chip"><b>${a.total_discoveries}</b> descobertas</div>`);

    return `
    <div class="agent-card${isLight ? ' card-light' : ''}">
        <div class="card-head">
            <div class="avatar-wrap">
                <div class="avatar">${avatarHtml}</div>
                <div class="status-dot ${dotClass}"></div>
            </div>
            <div class="agent-info">
                <div class="agent-name">${esc(a.name)}</div>
                <div class="agent-role">${esc(a.role)}</div>
                ${statusHtml}
            </div>
            <a href="/chat?agent=${esc(a.key)}" class="talk-btn">Falar →</a>
        </div>
        ${scanHtml}
        ${itemsHtml}
        <div class="card-footer">
            ${chips.join('')}
            <span class="foot-time">⟳ agora</span>
        </div>
    </div>`;
}

async function load() {
    try {
        const r = await fetch('/api/agents/activity', {
            headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}
        });
        const d = await r.json();
        if (!d.ok) return;

        document.getElementById('grid').innerHTML = d.agents.map(renderCard).join('');
        document.getElementById('updateBadge').textContent = 'Actualizado às ' + d.updated_at;
    } catch(e) {
        console.error(e);
    }
}

load();
setInterval(load, 30000);
</script>
</body>
</html>
