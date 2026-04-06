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
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        :root {
            --green:#76b900; --bg:#0c0c0c; --bg2:#141414; --bg3:#1c1c1c;
            --card:#181818; --border:#222; --text:#f0f0f0; --muted:#666; --muted2:#3a3a3a;
        }
        body { font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

        /* NAV */
        .topnav { height:52px; background:var(--bg2); border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; padding:0 28px; position:sticky; top:0; z-index:200; }
        .logo { font-size:17px; font-weight:800; color:var(--text); letter-spacing:-.5px; }
        .logo span { color:var(--green); }
        .badge-nav { font-size:10px; background:var(--green); color:#000; padding:2px 8px; border-radius:20px; font-weight:700; }
        .nav-right { margin-left:auto; display:flex; align-items:center; gap:8px; }
        .nav-link { font-size:12px; color:var(--muted); text-decoration:none; border:1px solid var(--border); padding:5px 12px; border-radius:8px; transition:all .15s; }
        .nav-link:hover { color:var(--text); border-color:#444; }
        .update-badge { font-size:11px; color:var(--muted); }

        /* HEADER */
        .page-header { padding:32px 28px 24px; }
        .page-header h1 { font-size:22px; font-weight:800; margin-bottom:4px; letter-spacing:-.4px; }
        .page-header p  { font-size:13px; color:var(--muted); }

        /* GRID */
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; padding:0 28px 60px; }

        /* CARD */
        .agent-card {
            background:var(--card); border:1px solid var(--border);
            border-radius:18px; overflow:hidden; cursor:pointer;
            transition:transform .15s, box-shadow .15s, border-color .15s;
        }
        .agent-card:hover { transform:translateY(-3px); box-shadow:0 12px 40px rgba(0,0,0,.6); border-color:#2e2e2e; }

        /* CARD HEAD */
        .card-head { display:flex; align-items:center; gap:13px; padding:18px 18px 14px; }
        .avatar-wrap { position:relative; flex-shrink:0; }
        .avatar { width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; overflow:hidden; background:var(--bg3); border:2px solid var(--border); }
        .avatar img { width:100%; height:100%; object-fit:cover; }
        .status-dot { position:absolute; bottom:1px; right:1px; width:12px; height:12px; border-radius:50%; border:2.5px solid var(--card); }
        .status-dot.on  { background:#22c55e; animation:pulse-ring 2.5s infinite; }
        .status-dot.off { background:#3a3a3a; }
        @keyframes pulse-ring { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.5)} 70%{box-shadow:0 0 0 6px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }

        .agent-info { flex:1; min-width:0; }
        .agent-name { font-size:14px; font-weight:700; color:var(--text); margin-bottom:2px; }
        .agent-role { font-size:11px; color:var(--muted); line-height:1.4; }
        .status-line { display:flex; align-items:center; gap:5px; font-size:11px; font-weight:500; margin-top:5px; }
        .status-line.on  { color:#22c55e; }
        .status-line.off { color:var(--muted); }
        .dots span { display:inline-block; animation:dotBounce 1.2s infinite ease-in-out; }
        .dots span:nth-child(2) { animation-delay:.2s; }
        .dots span:nth-child(3) { animation-delay:.4s; }
        @keyframes dotBounce { 0%,80%,100%{opacity:.3;transform:scale(.8)} 40%{opacity:1;transform:scale(1.2)} }

        .talk-btn { font-size:11px; font-weight:600; color:var(--muted); border:1px solid var(--border); padding:5px 12px; border-radius:8px; text-decoration:none; white-space:nowrap; transition:all .12s; flex-shrink:0; }
        .talk-btn:hover { color:var(--text); border-color:#555; background:var(--bg3); }

        /* SCAN STRIP */
        .scan-strip { padding:10px 18px 12px; background:#0f1a00; border-top:1px solid #1a2e00; }
        .scan-label { font-size:10px; font-weight:700; color:var(--green); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
        .scan-item { display:flex; align-items:center; gap:9px; font-size:12px; color:#aaa; padding:3px 0; opacity:0; animation:slideIn .35s ease forwards; }
        .scan-item:nth-child(2){animation-delay:.12s} .scan-item:nth-child(3){animation-delay:.24s} .scan-item:nth-child(4){animation-delay:.36s}
        @keyframes slideIn { from{opacity:0;transform:translateX(-8px)} to{opacity:1;transform:translateX(0)} }
        .scan-check { width:16px; height:16px; border-radius:50%; flex-shrink:0; background:rgba(34,197,94,.12); border:1.5px solid #22c55e; display:flex; align-items:center; justify-content:center; }
        .scan-check svg { color:#22c55e; }

        /* ACTIVITY LIST */
        .act-divider { height:1px; background:var(--border); margin:0 18px; }
        .activity-list { padding:4px 0 2px; }
        .act-item { display:flex; align-items:flex-start; gap:10px; padding:9px 18px; transition:background .1s; }
        .act-item:hover { background:var(--bg3); }
        .act-bar { width:3px; min-height:34px; border-radius:3px; flex-shrink:0; margin-top:3px; }
        .act-body { flex:1; min-width:0; }
        .act-title { font-size:12px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .act-sub   { font-size:11px; color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .act-icon  { font-size:15px; flex-shrink:0; margin-top:2px; }

        /* FOOTER */
        .card-footer { display:flex; align-items:center; gap:6px; flex-wrap:wrap; padding:10px 18px; border-top:1px solid var(--border); background:var(--bg3); }
        .stat-chip { font-size:10px; font-weight:700; padding:3px 8px; border-radius:20px; background:var(--bg2); color:var(--muted); border:1px solid var(--border); }
        .stat-chip b { color:var(--text); }
        .foot-time { margin-left:auto; font-size:11px; color:var(--muted2); }

        /* LIGHT CARD */
        .card-light { background:#fff; border-color:#e8ecf0; color:#1e293b; }
        .card-light:hover { border-color:#cbd5e1; box-shadow:0 8px 32px rgba(0,0,0,.12); }
        .card-light .avatar { background:#f1f5f9; border-color:#e2e8f0; }
        .card-light .status-dot.off { background:#cbd5e1; border-color:#fff; }
        .card-light .status-dot.on  { border-color:#fff; }
        .card-light .agent-name  { color:#0f172a; }
        .card-light .agent-role  { color:#64748b; }
        .card-light .status-line.off { color:#94a3b8; }
        .card-light .status-line.on  { color:#16a34a; }
        .card-light .talk-btn    { color:#64748b; border-color:#e2e8f0; }
        .card-light .talk-btn:hover { color:#0f172a; border-color:#94a3b8; background:#f8fafc; }
        .card-light .act-divider { background:#f1f5f9; }
        .card-light .act-item:hover { background:#f8fafc; }
        .card-light .act-title   { color:#0f172a; }
        .card-light .act-sub     { color:#64748b; }
        .card-light .card-footer { background:#f8fafc; border-color:#f1f5f9; }
        .card-light .stat-chip   { background:#fff; color:#64748b; border-color:#e2e8f0; }
        .card-light .stat-chip b { color:#0f172a; }
        .card-light .foot-time   { color:#94a3b8; }
        .card-light .scan-strip  { background:#f0fdf4; border-color:#bbf7d0; }
        .card-light .scan-label  { color:#16a34a; }
        .card-light .scan-item   { color:#374151; }

        /* ── DETAIL PANEL ──────────────────────────────────────────────── */
        .panel-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:300;
            opacity:0; pointer-events:none; transition:opacity .2s;
        }
        .panel-overlay.open { opacity:1; pointer-events:all; }

        .detail-panel {
            position:fixed; top:0; right:0; bottom:0; width:480px; max-width:100vw;
            background:var(--bg2); border-left:1px solid var(--border);
            z-index:301; display:flex; flex-direction:column;
            transform:translateX(100%); transition:transform .25s cubic-bezier(.4,0,.2,1);
            overflow:hidden;
        }
        .detail-panel.open { transform:translateX(0); }

        .panel-head {
            display:flex; align-items:center; gap:14px;
            padding:20px 20px 16px; border-bottom:1px solid var(--border);
            flex-shrink:0;
        }
        .panel-avatar { width:52px; height:52px; border-radius:50%; overflow:hidden; flex-shrink:0; background:var(--bg3); display:flex; align-items:center; justify-content:center; font-size:24px; }
        .panel-avatar img { width:100%; height:100%; object-fit:cover; }
        .panel-info { flex:1; min-width:0; }
        .panel-name { font-size:16px; font-weight:800; color:var(--text); }
        .panel-role { font-size:12px; color:var(--muted); margin-top:2px; }
        .panel-close { width:32px; height:32px; border-radius:8px; background:var(--bg3); border:1px solid var(--border); color:var(--muted); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:16px; transition:all .12s; flex-shrink:0; }
        .panel-close:hover { color:var(--text); border-color:#555; }
        .panel-talk { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:7px 16px; border-radius:8px; text-decoration:none; transition:all .15s; flex-shrink:0; }
        .panel-talk:hover { filter:brightness(1.1); }

        .panel-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); flex-shrink:0; }
        .tab-btn { flex:1; padding:10px 0; font-size:12px; font-weight:600; color:var(--muted); background:none; border:none; cursor:pointer; border-bottom:2px solid transparent; transition:all .15s; }
        .tab-btn.active { color:var(--green); border-bottom-color:var(--green); }
        .tab-btn:hover { color:var(--text); }

        .panel-body { flex:1; overflow-y:auto; }

        .panel-section { padding:16px 20px; }
        .panel-empty { text-align:center; padding:40px 20px; color:var(--muted); font-size:13px; }

        .p-item { padding:12px 0; border-bottom:1px solid var(--border); }
        .p-item:last-child { border-bottom:none; }
        .p-item-title { font-size:13px; font-weight:600; color:var(--text); margin-bottom:4px; line-height:1.4; }
        .p-item-preview { font-size:12px; color:var(--muted); line-height:1.5; margin-bottom:5px; }
        .p-item-meta { display:flex; align-items:center; gap:8px; }
        .p-item-time { font-size:11px; color:var(--muted2); }
        .p-item-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
        .p-item-link { font-size:11px; color:var(--green); text-decoration:none; }
        .p-item-link:hover { text-decoration:underline; }

        .panel-loading { display:flex; align-items:center; justify-content:center; padding:60px 0; }
        .spinner-sm { width:22px; height:22px; border:2.5px solid var(--border); border-top-color:var(--green); border-radius:50%; animation:spin .7s linear infinite; }
        @keyframes spin { to{transform:rotate(360deg)} }

        /* LOADING */
        .loading-wrap { grid-column:1/-1; display:flex; align-items:center; justify-content:center; height:280px; }
        .spinner { width:26px; height:26px; border:3px solid var(--border); border-top-color:var(--green); border-radius:50%; animation:spin .7s linear infinite; }

        @media (max-width:640px) {
            .grid { grid-template-columns:1fr; padding:0 14px 40px; }
            .page-header { padding:20px 14px 18px; }
            .topnav { padding:0 14px; }
            .detail-panel { width:100vw; }
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
    <p>Clica num agente para ver o histórico completo — relatórios, descobertas e mensagens.</p>
</div>

<div id="grid" class="grid">
    <div class="loading-wrap"><div class="spinner"></div></div>
</div>

<!-- ── DETAIL PANEL ── -->
<div class="panel-overlay" id="overlay" onclick="closePanel()"></div>
<div class="detail-panel" id="detailPanel">
    <div class="panel-head" id="panelHead">
        <div class="panel-avatar" id="panelAvatar"></div>
        <div class="panel-info">
            <div class="panel-name" id="panelName"></div>
            <div class="panel-role" id="panelRole"></div>
        </div>
        <a href="#" class="panel-talk" id="panelTalk" target="_self">Falar →</a>
        <button class="panel-close" onclick="closePanel()">✕</button>
    </div>
    <div class="panel-tabs">
        <button class="tab-btn active" onclick="showTab('reports',this)">Relatórios</button>
        <button class="tab-btn" onclick="showTab('discoveries',this)">Descobertas</button>
        <button class="tab-btn" onclick="showTab('messages',this)">Mensagens</button>
    </div>
    <div class="panel-body" id="panelBody">
        <div class="panel-loading"><div class="spinner-sm"></div></div>
    </div>
</div>

<script>
const AGENT_IMAGES = @json($agentImages);
const CSRF = document.querySelector('meta[name=csrf-token]').content;

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function renderCard(a) {
    const isLight = ['sap','email','finance','document'].includes(a.key);
    const photo   = AGENT_IMAGES[a.key] || null;
    const avatarHtml = photo ? `<img src="${esc(photo)}" alt="${esc(a.name)}">` : `<span>${esc(a.emoji)}</span>`;
    const dotClass = a.is_active ? 'on' : 'off';

    const statusHtml = a.is_active
        ? `<span class="status-line on"><svg width="8" height="8" viewBox="0 0 10 10" fill="#22c55e"><circle cx="5" cy="5" r="5"/></svg>A trabalhar<span class="dots"><span>.</span><span>.</span><span>.</span></span></span>`
        : `<span class="status-line off">${esc(a.last_active || 'Inactivo')}</span>`;

    const scanHtml = a.is_active && a.actions && a.actions.length ? `
        <div class="scan-strip">
            <div class="scan-label">A executar</div>
            ${a.actions.map(act => `<div class="scan-item"><div class="scan-check"><svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"/></svg></div><span>${esc(act)}</span></div>`).join('')}
        </div>` : '';

    const itemsHtml = (a.items && a.items.length)
        ? `<div class="act-divider"></div><div class="activity-list">${a.items.map(it => `
            <div class="act-item">
                <div class="act-bar" style="background:${esc(it.color||'#444')}"></div>
                <div class="act-body">
                    <div class="act-title">${esc(it.title)}</div>
                    <div class="act-sub">${esc(it.sub)}</div>
                </div>
                <div class="act-icon">${esc(it.icon||'')}</div>
            </div>`).join('')}</div>` : '';

    const chips = [];
    if (a.total_reports > 0)     chips.push(`<div class="stat-chip"><b>${a.total_reports}</b> relatórios</div>`);
    if (a.total_discoveries > 0) chips.push(`<div class="stat-chip"><b>${a.total_discoveries}</b> descobertas</div>`);

    return `
    <div class="agent-card${isLight ? ' card-light' : ''}" onclick="openPanel('${esc(a.key)}','${esc(a.chat_url||'/chat?agent='+a.key)}')">
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
            <a href="${esc(a.chat_url||'/chat?agent='+a.key)}" class="talk-btn" onclick="event.stopPropagation()">Falar →</a>
        </div>
        ${scanHtml}
        ${itemsHtml}
        <div class="card-footer">
            ${chips.join('')}
            <span class="foot-time">⟳ agora</span>
        </div>
    </div>`;
}

// ── Detail panel ──────────────────────────────────────────────────────────
let panelData = null;
let currentTab = 'reports';

async function openPanel(key, chatUrl) {
    // Set header immediately from grid data
    const panelEl = document.getElementById('detailPanel');
    const overlay = document.getElementById('overlay');
    document.getElementById('panelTalk').href = chatUrl;
    document.getElementById('panelTalk').style.background = '#76b900';
    document.getElementById('panelTalk').style.color = '#000';
    document.getElementById('panelBody').innerHTML = '<div class="panel-loading"><div class="spinner-sm"></div></div>';

    // Open
    overlay.classList.add('open');
    panelEl.classList.add('open');
    document.body.style.overflow = 'hidden';

    // Load detail
    try {
        const r = await fetch(`/api/agents/activity/${key}`, { headers: {'X-CSRF-TOKEN': CSRF} });
        panelData = await r.json();
        if (!panelData.ok) throw new Error('not found');

        // Avatar
        const photo = AGENT_IMAGES[key];
        document.getElementById('panelAvatar').innerHTML = photo
            ? `<img src="${esc(photo)}" alt="${esc(panelData.name)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`
            : `<span>${esc(panelData.emoji)}</span>`;
        document.getElementById('panelName').textContent = panelData.name;
        document.getElementById('panelRole').textContent = panelData.role;
        document.getElementById('panelTalk').style.background = panelData.color || '#76b900';

        // Reset to reports tab
        showTab('reports', document.querySelector('.tab-btn'));
    } catch(e) {
        document.getElementById('panelBody').innerHTML = '<div class="panel-empty">Erro ao carregar dados.</div>';
    }
}

function closePanel() {
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('overlay').classList.remove('open');
    document.body.style.overflow = '';
    panelData = null;
}

function showTab(tab, btn) {
    currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderPanelTab();
}

function renderPanelTab() {
    if (!panelData) return;
    const body = document.getElementById('panelBody');

    if (currentTab === 'reports') {
        if (!panelData.reports || !panelData.reports.length) {
            body.innerHTML = '<div class="panel-empty">📄 Nenhum relatório ainda.</div>'; return;
        }
        body.innerHTML = `<div class="panel-section">${panelData.reports.map(r => `
            <div class="p-item">
                <div class="p-item-title">${esc(r.title)}</div>
                ${r.preview ? `<div class="p-item-preview">${esc(r.preview)}</div>` : ''}
                <div class="p-item-meta">
                    <span class="p-item-badge" style="background:rgba(118,185,0,.15);color:#76b900">📄 Relatório</span>
                    <span class="p-item-time">${esc(r.date)} · ${esc(r.created_at)}</span>
                </div>
            </div>`).join('')}</div>`;
    }

    if (currentTab === 'discoveries') {
        if (!panelData.discoveries || !panelData.discoveries.length) {
            body.innerHTML = '<div class="panel-empty">🔍 Nenhuma descoberta ainda.</div>'; return;
        }
        body.innerHTML = `<div class="panel-section">${panelData.discoveries.map(d => `
            <div class="p-item">
                <div class="p-item-title">${esc(d.title)}</div>
                <div class="p-item-meta">
                    <span class="p-item-badge" style="background:rgba(153,51,255,.15);color:#9933ff">🔬 ${esc(d.source)}</span>
                    <span class="p-item-time">${esc(d.date)} · ${esc(d.created_at)}</span>
                    ${d.url ? `<a href="${esc(d.url)}" target="_blank" class="p-item-link">Ver →</a>` : ''}
                </div>
            </div>`).join('')}</div>`;
    }

    if (currentTab === 'messages') {
        if (!panelData.messages || !panelData.messages.length) {
            body.innerHTML = '<div class="panel-empty">💬 Nenhuma mensagem ainda.</div>'; return;
        }
        body.innerHTML = `<div class="panel-section">${panelData.messages.map(m => `
            <div class="p-item">
                <div class="p-item-preview">${esc(m.preview)}</div>
                <div class="p-item-meta">
                    <span class="p-item-badge" style="background:rgba(0,204,102,.15);color:#00cc66">💬 Resposta</span>
                    <span class="p-item-time">${esc(m.date)} · ${esc(m.created_at)}</span>
                </div>
            </div>`).join('')}</div>`;
    }
}

// Escape key closes panel
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

// ── Load grid ─────────────────────────────────────────────────────────────
async function load() {
    try {
        const r = await fetch('/api/agents/activity', { headers: {'X-CSRF-TOKEN': CSRF} });
        const d = await r.json();
        if (!d.ok) return;
        document.getElementById('grid').innerHTML = d.agents.map(renderCard).join('');
        document.getElementById('updateBadge').textContent = 'Actualizado às ' + d.updated_at;
    } catch(e) { console.error(e); }
}

load();
setInterval(load, 30000);
</script>
</body>
</html>
