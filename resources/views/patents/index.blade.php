<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard — Biblioteca de Patentes</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --green: #76b900; --bg: #0a0a0a; --bg2: #111; --bg3: #1a1a1a;
            --border: #1e1e1e; --border2: #2a2a2a; --text: #e5e5e5; --muted: #555;
            --blue: #3b82f6; --purple: #8b5cf6;
        }
        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* ── HEADER ── */
        .header { display:flex; align-items:center; gap:12px; padding:14px 32px; border-bottom:1px solid var(--border); background:var(--bg2); }
        .header-logo { font-size:20px; font-weight:800; color:var(--green); letter-spacing:-0.5px; }
        .header-logo span { color:var(--text); }
        .header-nav { display:flex; gap:8px; margin-left:auto; }
        .header-nav a { color:var(--muted); font-size:13px; text-decoration:none; padding:6px 12px; border-radius:8px; transition:all 0.15s; }
        .header-nav a:hover { color:var(--text); background:var(--bg3); }
        .header-nav a.active { color:var(--green); background:#0a1a00; }

        /* ── MAIN ── */
        .main { max-width:1100px; margin:0 auto; padding:32px 24px; }
        .page-title { font-size:24px; font-weight:800; margin-bottom:4px; }
        .page-subtitle { color:var(--muted); font-size:14px; margin-bottom:28px; }

        /* ── STATS ── */
        .stats { display:flex; gap:16px; margin-bottom:28px; flex-wrap:wrap; }
        .stat-card { background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:16px 20px; flex:1; min-width:140px; }
        .stat-card .num { font-size:28px; font-weight:800; color:var(--green); }
        .stat-card .label { font-size:12px; color:var(--muted); margin-top:2px; }

        /* ── SEARCH / FILTER ── */
        .toolbar { display:flex; gap:10px; margin-bottom:20px; align-items:center; flex-wrap:wrap; }
        .search-box { flex:1; min-width:200px; background:var(--bg2); border:1px solid var(--border2); border-radius:10px; padding:9px 14px; color:var(--text); font-size:13px; outline:none; }
        .search-box:focus { border-color:var(--green); }
        .filter-btn { background:var(--bg2); border:1px solid var(--border2); color:var(--muted); padding:8px 14px; border-radius:10px; font-size:12px; cursor:pointer; transition:all 0.15s; }
        .filter-btn:hover, .filter-btn.active { border-color:var(--green); color:var(--green); background:#0a1a00; }
        .download-btn { background:var(--green); color:#000; border:none; padding:9px 18px; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; text-decoration:none; }
        .download-btn:hover { background:#8fd600; }

        /* ── PATENT GRID ── */
        .patent-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px; }
        .patent-card { background:var(--bg2); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:border-color 0.15s; }
        .patent-card:hover { border-color:var(--border2); }
        .patent-card.us { border-left:3px solid var(--blue); }
        .patent-card.ep { border-left:3px solid var(--green); }
        .patent-card.wo { border-left:3px solid var(--purple); }
        .patent-card.pt { border-left:3px solid #f59e0b; }

        .patent-header { padding:12px 16px; display:flex; align-items:center; gap:10px; border-bottom:1px solid var(--border); }
        .patent-flag { font-size:18px; }
        .patent-number { font-size:14px; font-weight:700; font-family:monospace; color:var(--text); }
        .patent-type { font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; margin-left:auto; }
        .patent-type.us { background:#1a2540; color:var(--blue); }
        .patent-type.ep { background:#0a1a00; color:var(--green); }
        .patent-type.wo { background:#1a1030; color:var(--purple); }
        .patent-type.pt { background:#1a1200; color:#f59e0b; }

        .patent-body { padding:12px 16px; }
        .patent-meta { display:flex; gap:16px; margin-bottom:10px; }
        .patent-meta-item { font-size:11px; color:var(--muted); }
        .patent-meta-item strong { color:#aaa; display:block; font-size:10px; text-transform:uppercase; letter-spacing:.4px; margin-bottom:1px; }

        .patent-actions { padding:10px 16px; display:flex; gap:8px; background:#0a0a0a; border-top:1px solid var(--border); }
        .btn-view { background:var(--green); color:#000; border:none; padding:6px 14px; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
        .btn-view:hover { background:#8fd600; }
        .btn-espacenet { background:none; color:var(--muted); border:1px solid var(--border2); padding:6px 12px; border-radius:7px; font-size:12px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
        .btn-espacenet:hover { border-color:var(--muted); color:#aaa; }

        /* ── EMPTY ── */
        .empty-state { text-align:center; padding:60px 20px; color:var(--muted); }
        .empty-state .icon { font-size:48px; margin-bottom:12px; }
        .empty-state h3 { font-size:16px; color:#aaa; margin-bottom:6px; }
        .empty-state p { font-size:13px; line-height:1.6; }
        .empty-state a { color:var(--green); text-decoration:none; }

        /* ── LOADING ── */
        .loading { text-align:center; padding:40px; color:var(--muted); font-size:14px; }

        /* ── PDF VIEWER MODAL ── */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:var(--bg2); border:1px solid var(--border2); border-radius:14px; width:90vw; max-width:960px; height:90vh; display:flex; flex-direction:column; overflow:hidden; }
        .modal-header { padding:12px 20px; display:flex; align-items:center; gap:10px; border-bottom:1px solid var(--border); }
        .modal-title { font-size:14px; font-weight:700; font-family:monospace; flex:1; }
        .modal-close { background:none; border:none; color:var(--muted); font-size:18px; cursor:pointer; padding:4px 8px; border-radius:6px; }
        .modal-close:hover { background:var(--bg3); color:var(--text); }
        .modal-body { flex:1; overflow:hidden; }
        .modal-body iframe { width:100%; height:100%; border:none; }

        @media (max-width: 600px) {
            .header { padding:12px 16px; }
            .main { padding:20px 16px; }
            .patent-grid { grid-template-columns:1fr; }
            .stats { gap:10px; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">Claw<span>Yard</span></div>
    <div style="font-size:13px;color:var(--muted);margin-left:4px;">/ Patentes</div>
    <div class="header-nav">
        <a href="/dashboard">Dashboard</a>
        <a href="/chat?agent=patent">🏛️ Dra. Sofia IP</a>
        <a href="/chat?agent=quantum">⚛️ Prof. Quantum</a>
        <a href="/patents" class="active">📄 Patentes</a>
    </div>
</div>

<div class="main">
    <div class="page-title">📄 Biblioteca de Patentes</div>
    <div class="page-subtitle">PDFs descarregados automaticamente pela Dra. Sofia IP e Prof. Quantum Leap</div>

    <div class="stats" id="stats">
        <div class="stat-card"><div class="num" id="stat-total">—</div><div class="label">Total patentes</div></div>
        <div class="stat-card"><div class="num" id="stat-downloaded">—</div><div class="label">📥 PDFs locais</div></div>
        <div class="stat-card"><div class="num" id="stat-ep">—</div><div class="label">🇪🇺 EPO (EP)</div></div>
        <div class="stat-card"><div class="num" id="stat-us">—</div><div class="label">🇺🇸 USPTO (US)</div></div>
        <div class="stat-card"><div class="num" id="stat-size">—</div><div class="label">Espaço total</div></div>
    </div>

    <div class="toolbar">
        <input type="text" class="search-box" id="search" placeholder="🔍 Pesquisar por número de patente...">
        <button class="filter-btn active" onclick="setFilter('all', this)">Todas</button>
        <button class="filter-btn" onclick="setFilter('US', this)">🇺🇸 US</button>
        <button class="filter-btn" onclick="setFilter('EP', this)">🇪🇺 EP</button>
        <button class="filter-btn" onclick="setFilter('WO', this)">🌍 WO</button>
        <button class="filter-btn" onclick="setFilter('PT', this)">🇵🇹 PT</button>
        <a href="/chat?agent=patent" class="download-btn">+ Dra. Sofia</a>
    </div>

    <div id="patent-grid" class="patent-grid">
        <div class="loading">⏳ A carregar patentes...</div>
    </div>
</div>

<!-- PDF Viewer Modal -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <div class="patent-flag" id="modal-flag"></div>
            <div class="modal-title" id="modal-title">—</div>
            <a id="modal-download" href="#" download class="btn-view" style="font-size:11px;padding:5px 10px;">⬇️ Download</a>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body">
            <iframe id="modal-iframe" src=""></iframe>
        </div>
    </div>
</div>

<script>
let allPatents = [];
let currentFilter = 'all';

const FLAGS = { US: '🇺🇸', EP: '🇪🇺', WO: '🌍', PT: '🇵🇹' };

async function loadPatents() {
    try {
        const res  = await fetch('/patents');
        const data = await res.json();
        allPatents = data;
        renderStats(data);
        renderGrid(data);
    } catch(e) {
        document.getElementById('patent-grid').innerHTML =
            '<div class="loading" style="color:#ff4444">❌ Erro ao carregar patentes</div>';
    }
}

function renderStats(data) {
    document.getElementById('stat-total').textContent = data.length;
    document.getElementById('stat-downloaded').textContent = data.filter(p => !p.from_db).length;
    document.getElementById('stat-ep').textContent = data.filter(p => p.patent.startsWith('EP')).length;
    document.getElementById('stat-us').textContent = data.filter(p => p.patent.startsWith('US')).length;
    const totalKb = data.reduce((s, p) => s + (p.size_kb || 0), 0);
    document.getElementById('stat-size').textContent = totalKb > 1024
        ? (totalKb/1024).toFixed(1) + ' MB'
        : totalKb + ' KB';
}

function renderGrid(data) {
    const grid = document.getElementById('patent-grid');
    if (!data.length) {
        grid.innerHTML = `
            <div class="empty-state" style="grid-column:1/-1">
                <div class="icon">🏛️</div>
                <h3>Nenhuma patente descarregada ainda</h3>
                <p>A <a href="/chat?agent=patent">Dra. Sofia IP</a> e o <a href="/chat?agent=quantum">Prof. Quantum</a>
                fazem download automático dos PDFs quando analisam patentes.</p>
            </div>`;
        return;
    }

    grid.innerHTML = data.map(p => {
        const prefix = p.patent.match(/^[A-Z]+/)?.[0] || 'XX';
        const flag   = FLAGS[prefix] || '📄';
        const cls    = prefix.toLowerCase();
        const isDB   = !!p.from_db;

        // Build external link
        let extUrl = p.ext_url || '#';
        if (extUrl === '#') {
            if (prefix === 'EP') extUrl = `https://worldwide.espacenet.com/patent/search?q=pn%3D${p.patent}`;
            else if (prefix === 'US') extUrl = `https://patents.google.com/patent/${p.patent}/en`;
            else if (prefix === 'WO') extUrl = `https://patentscope.wipo.int/search/en/detail.jsf?docId=${p.patent.replace('/','').replace('-','')}`;
            else extUrl = `https://patents.google.com/patent/${p.patent}/en`;
        }

        const titleHtml = p.title
            ? `<div style="font-size:11px;color:#aaa;margin-top:4px;line-height:1.4;max-height:40px;overflow:hidden">${p.title}</div>`
            : '';

        const summaryHtml = (isDB && p.summary)
            ? `<div style="font-size:10px;color:#555;margin-top:4px;line-height:1.4;max-height:32px;overflow:hidden">${p.summary}</div>`
            : '';

        const metaHtml = isDB
            ? `<div class="patent-meta">
                <div class="patent-meta-item"><strong>Fonte</strong>🔬 Descoberta</div>
                ${p.date ? `<div class="patent-meta-item"><strong>Data</strong>${p.date}</div>` : ''}
               </div>`
            : `<div class="patent-meta">
                <div class="patent-meta-item"><strong>Tamanho</strong>${p.size_kb} KB</div>
                <div class="patent-meta-item"><strong>Descarregado</strong>${p.date}</div>
               </div>`;

        const actionsHtml = isDB
            ? `<a href="${extUrl}" target="_blank" class="btn-view">🔗 Ver Online</a>
               <span style="font-size:10px;color:#555;align-self:center">PDF não disponível</span>`
            : `<a href="${p.url}" class="btn-view" onclick="openModal(event, '${p.patent}', '${p.url}', '${flag}')">📄 Ver PDF</a>
               <a href="${p.url}" download="${p.patent}.pdf" class="btn-espacenet">⬇️ Download</a>
               <a href="${extUrl}" target="_blank" class="btn-espacenet">🔗 Online</a>`;

        const cardStyle = isDB ? 'opacity:0.75' : '';

        return `
        <div class="patent-card ${cls}" data-patent="${p.patent}" data-prefix="${prefix}" style="${cardStyle}">
            <div class="patent-header">
                <span class="patent-flag">${flag}</span>
                <span class="patent-number">${p.patent}</span>
                <span class="patent-type ${cls}">${isDB ? '🔬' : '📄'} ${prefix}</span>
            </div>
            <div class="patent-body">
                ${metaHtml}
                ${titleHtml}
                ${summaryHtml}
            </div>
            <div class="patent-actions">${actionsHtml}</div>
        </div>`;
    }).join('');
}

function setFilter(type, btn) {
    currentFilter = type;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const q = document.getElementById('search').value.toLowerCase();
    let filtered = allPatents;
    if (currentFilter !== 'all') {
        filtered = filtered.filter(p => p.patent.startsWith(currentFilter));
    }
    if (q) {
        filtered = filtered.filter(p => p.patent.toLowerCase().includes(q));
    }
    renderGrid(filtered);
}

document.getElementById('search').addEventListener('input', applyFilters);

function openModal(e, patent, url, flag) {
    e.preventDefault();
    document.getElementById('modal-title').textContent = patent;
    document.getElementById('modal-flag').textContent = flag;
    document.getElementById('modal-iframe').src = url;
    document.getElementById('modal-download').href = url;
    document.getElementById('modal-download').download = patent + '.pdf';
    document.getElementById('modal').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
    document.getElementById('modal-iframe').src = '';
}

document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

loadPatents();
</script>
</body>
</html>
