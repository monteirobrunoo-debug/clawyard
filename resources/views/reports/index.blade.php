<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — Relatórios</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background:#0a0a0a; color:#e5e5e5; font-family:system-ui,sans-serif; min-height:100vh; }
        header { display:flex; align-items:center; gap:12px; padding:14px 28px; border-bottom:1px solid #1e1e1e; background:#111; }
        .logo { font-size:18px; font-weight:800; color:#76b900; }
        .back-btn { color:#555; text-decoration:none; font-size:20px; }
        .back-btn:hover { color:#e5e5e5; }
        .hdr-right { margin-left:auto; display:flex; gap:10px; align-items:center; }
        .btn { font-size:12px; padding:7px 16px; border-radius:8px; border:1px solid #333; background:none; color:#aaa; cursor:pointer; text-decoration:none; transition:all .2s; }
        .btn:hover { border-color:#76b900; color:#76b900; }
        .btn-green { background:#76b900; color:#000; border-color:#76b900; font-weight:700; }
        .btn-green:hover { background:#8fd400; color:#000; }

        .container { max-width:1100px; margin:0 auto; padding:32px 24px; }
        h1 { font-size:26px; font-weight:800; color:#76b900; margin-bottom:6px; }
        .subtitle { font-size:13px; color:#555; margin-bottom:28px; }

        /* Filter bar */
        .filter-bar { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; align-items:center; }
        .filter-btn { background:#111; border:1px solid #222; color:#666; padding:6px 14px; border-radius:20px; font-size:12px; cursor:pointer; transition:all .2s; }
        .filter-btn:hover, .filter-btn.active { border-color:#76b900; color:#76b900; background:#0a1500; }
        .filter-btn[data-type="aria"].active { border-color:#ff4444; color:#ff4444; background:#1a0000; }
        .filter-btn[data-type="quantum"].active { border-color:#9933ff; color:#cc66ff; background:#0f0020; }
        .filter-btn[data-type="market"].active { border-color:#ffaa00; color:#ffaa00; background:#1a1000; }
        .search-input { background:#111; border:1px solid #222; color:#e5e5e5; padding:7px 14px; border-radius:20px; font-size:12px; outline:none; min-width:200px; }
        .search-input:focus { border-color:#76b900; }

        /* Report grid */
        .reports-grid { display:grid; gap:14px; }

        .report-card {
            background:#111; border:1px solid #1e1e1e; border-radius:14px;
            padding:18px 20px; display:flex; align-items:flex-start; gap:16px;
            transition:all .2s; cursor:pointer;
        }
        .report-card:hover { border-color:#2a2a2a; background:#141414; transform:translateY(-1px); }

        .report-type-icon { font-size:28px; flex-shrink:0; margin-top:2px; }

        .report-info { flex:1; min-width:0; }
        .report-title { font-size:14px; font-weight:700; color:#e5e5e5; margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .report-summary { font-size:12px; color:#555; line-height:1.5; margin-bottom:10px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .report-meta { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .type-badge { font-size:11px; padding:2px 10px; border-radius:20px; font-weight:600; }
        .type-aria    { background:#1a0000; color:#ff6666; border:1px solid #330000; }
        .type-quantum { background:#0f0020; color:#cc66ff; border:1px solid #220044; }
        .type-market  { background:#1a1000; color:#ffaa00; border:1px solid #332200; }
        .type-custom  { background:#0a1500; color:#76b900; border:1px solid #1a3300; }
        .report-date { font-size:11px; color:#444; }

        .report-actions { display:flex; flex-direction:column; gap:6px; flex-shrink:0; }
        .action-btn { display:flex; align-items:center; gap:5px; font-size:11px; padding:6px 12px; border-radius:7px; cursor:pointer; text-decoration:none; transition:all .2s; white-space:nowrap; border:1px solid #222; background:none; color:#666; }
        .action-btn:hover { border-color:#76b900; color:#76b900; }
        .action-btn.pdf { border-color:#333; color:#888; }
        .action-btn.pdf:hover { border-color:#ff6600; color:#ff6600; }
        .action-btn.del:hover { border-color:#ff4444; color:#ff4444; }

        /* Empty state */
        .empty { text-align:center; padding:80px 20px; color:#333; }
        .empty h3 { font-size:20px; color:#444; margin-bottom:8px; }
        .empty p { font-size:13px; }

        /* Save modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:100; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:#111; border:1px solid #2a2a2a; border-radius:16px; padding:28px; width:100%; max-width:560px; }
        .modal h3 { font-size:16px; font-weight:700; color:#76b900; margin-bottom:20px; }
        .form-group { margin-bottom:14px; }
        .form-group label { display:block; font-size:11px; color:#555; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
        .form-group input, .form-group select, .form-group textarea { width:100%; background:#1a1a1a; border:1px solid #2a2a2a; color:#e5e5e5; padding:10px 12px; border-radius:8px; font-size:13px; outline:none; font-family:inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#76b900; }
        .form-group textarea { resize:vertical; min-height:200px; }
        .modal-actions { display:flex; gap:10px; margin-top:20px; }
        .modal-save { background:#76b900; color:#000; border:none; padding:10px 24px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; }
        .modal-save:hover { background:#8fd400; }
        .modal-cancel { background:none; color:#555; border:1px solid #333; padding:10px 20px; border-radius:8px; font-size:13px; cursor:pointer; }
        .modal-cancel:hover { border-color:#555; color:#aaa; }

        /* Toast */
        .toast { position:fixed; bottom:24px; right:24px; background:#76b900; color:#000; padding:12px 20px; border-radius:10px; font-size:13px; font-weight:700; z-index:200; display:none; animation:slideUp .3s ease; }
        @keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }

        .count-badge { background:#1a1a1a; color:#555; font-size:11px; padding:2px 8px; border-radius:10px; border:1px solid #222; margin-left:auto; }
    </style>
</head>
<body>

<header>
    <a href="/chat" class="back-btn">←</a>
    <span class="logo">🐾 ClawYard</span>
    <span style="font-size:13px;color:#555;">/ Relatórios</span>
    <div class="hdr-right">
        <a href="/schedules" class="btn">🗓️ Schedule</a>
        <button class="btn btn-green" onclick="openSaveModal()">+ Guardar Relatório</button>
        @if(Auth::user()->isAdmin())
        <a href="/admin/users" class="btn" style="border-color:#ff4444;color:#ff6666;">⚙️ Admin</a>
        @endif
    </div>
</header>

<div class="container">
    <h1>📋 Relatórios</h1>
    <p class="subtitle">Análises guardadas pelos agentes ARIA, Prof. Quantum Leap e outros</p>

    <!-- Filter bar -->
    <div class="filter-bar">
        <button class="filter-btn active" data-type="all" onclick="filterReports('all',this)">🗂️ Todos</button>
        <button class="filter-btn" data-type="aria" onclick="filterReports('aria',this)">🔐 ARIA</button>
        <button class="filter-btn" data-type="quantum" onclick="filterReports('quantum',this)">⚛️ Quantum</button>
        <button class="filter-btn" data-type="market" onclick="filterReports('market',this)">📊 Market</button>
        <button class="filter-btn" data-type="custom" onclick="filterReports('custom',this)">📄 Custom</button>
        <input type="text" class="search-input" placeholder="🔍 Pesquisar..." id="search-input" oninput="searchReports(this.value)">
        <span class="count-badge" id="count-badge">{{ $reports->total() }} relatórios</span>
    </div>

    <!-- Reports list -->
    <div class="reports-grid" id="reports-grid">
        @forelse($reports as $report)
        <div class="report-card" data-type="{{ $report->type }}" data-title="{{ strtolower($report->title) }}" data-summary="{{ strtolower($report->summary) }}">
            <div class="report-type-icon">
                {{ $report->type === 'aria' ? '🔐' : ($report->type === 'quantum' ? '⚛️' : ($report->type === 'market' ? '📊' : '📄')) }}
            </div>
            <div class="report-info">
                <div class="report-title">{{ $report->title }}</div>
                <div class="report-summary">{{ $report->summary }}</div>
                <div class="report-meta">
                    <span class="type-badge type-{{ $report->type }}">{{ $report->typeBadge() }}</span>
                    <span class="report-date">{{ $report->created_at->format('d M Y · H:i') }}</span>
                    @if($report->user)
                    <span class="report-date">· {{ $report->user->name }}</span>
                    @endif
                </div>
            </div>
            <div class="report-actions">
                <a href="/reports/{{ $report->id }}" class="action-btn">👁️ Ver</a>
                <a href="/reports/{{ $report->id }}/pdf" target="_blank" class="action-btn pdf">📥 PDF</a>
                <button class="action-btn del" onclick="deleteReport({{ $report->id }}, this)">🗑️</button>
            </div>
        </div>
        @empty
        <div class="empty">
            <h3>Sem relatórios ainda</h3>
            <p>Os agentes ARIA e Prof. Quantum Leap guardam os seus relatórios aqui automaticamente.<br>Podes também guardar manualmente clicando em "+ Guardar Relatório".</p>
        </div>
        @endforelse
    </div>

    @if($reports->hasPages())
    <div style="margin-top:24px;display:flex;justify-content:center;">
        {{ $reports->links() }}
    </div>
    @endif
</div>

<!-- Save Modal -->
<div class="modal-overlay" id="save-modal">
    <div class="modal">
        <h3>📋 Guardar Novo Relatório</h3>
        <div class="form-group">
            <label>Título</label>
            <input type="text" id="modal-title" placeholder="ex: ARIA Scan — partyard.eu — 19 Março 2026">
        </div>
        <div class="form-group">
            <label>Tipo</label>
            <select id="modal-type">
                <option value="aria">🔐 ARIA Security</option>
                <option value="quantum">⚛️ Quantum Leap</option>
                <option value="market">📊 Market Intelligence</option>
                <option value="custom">📄 Custom</option>
            </select>
        </div>
        <div class="form-group">
            <label>Conteúdo do Relatório</label>
            <textarea id="modal-content" placeholder="Cola aqui o conteúdo do relatório..."></textarea>
        </div>
        <div class="modal-actions">
            <button class="modal-save" onclick="saveReport()">💾 Guardar</button>
            <button class="modal-cancel" onclick="closeSaveModal()">Cancelar</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function openSaveModal() {
    document.getElementById('save-modal').classList.add('open');
}
function closeSaveModal() {
    document.getElementById('save-modal').classList.remove('open');
}

async function saveReport() {
    const title   = document.getElementById('modal-title').value.trim();
    const type    = document.getElementById('modal-type').value;
    const content = document.getElementById('modal-content').value.trim();

    if (!title || !content) { showToast('⚠️ Preenche título e conteúdo', '#ff4444'); return; }

    const res = await fetch('/api/reports', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ title, type, content }),
    });
    const data = await res.json();
    if (data.success) {
        showToast('✅ Relatório guardado!');
        closeSaveModal();
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast('❌ Erro ao guardar', '#ff4444');
    }
}

async function deleteReport(id, btn) {
    if (!confirm('Apagar este relatório?')) return;
    const res = await fetch('/reports/'+id, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CSRF },
    });
    const data = await res.json();
    if (data.success) {
        btn.closest('.report-card').style.opacity = '0';
        btn.closest('.report-card').style.transform = 'translateX(20px)';
        btn.closest('.report-card').style.transition = 'all .3s';
        setTimeout(() => btn.closest('.report-card').remove(), 300);
        showToast('🗑️ Relatório apagado');
    }
}

function filterReports(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const cards = document.querySelectorAll('.report-card');
    let count = 0;
    cards.forEach(card => {
        const show = type === 'all' || card.dataset.type === type;
        card.style.display = show ? '' : 'none';
        if (show) count++;
    });
    document.getElementById('count-badge').textContent = count + ' relatórios';
}

function searchReports(query) {
    const q = query.toLowerCase();
    const cards = document.querySelectorAll('.report-card');
    let count = 0;
    cards.forEach(card => {
        const match = !q || card.dataset.title.includes(q) || card.dataset.summary.includes(q);
        card.style.display = match ? '' : 'none';
        if (match) count++;
    });
    document.getElementById('count-badge').textContent = count + ' relatórios';
}

function showToast(msg, color = '#76b900') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = color;
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 3000);
}

// Close modal on overlay click
document.getElementById('save-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeSaveModal();
});
</script>

</body>
</html>
