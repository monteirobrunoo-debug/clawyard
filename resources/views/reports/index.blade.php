<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — Relatórios</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0a0a;color:#e5e5e5;font-family:system-ui,sans-serif;min-height:100vh}
        header{display:flex;align-items:center;gap:12px;padding:14px 28px;border-bottom:1px solid #1e1e1e;background:#111}
        .logo{font-size:18px;font-weight:800;color:#76b900}
        .back-btn{color:#555;text-decoration:none;font-size:20px}
        .back-btn:hover{color:#e5e5e5}
        .hdr-right{margin-left:auto;display:flex;gap:8px;align-items:center}
        .btn{font-size:12px;padding:7px 16px;border-radius:8px;border:1px solid #333;background:none;color:#aaa;cursor:pointer;text-decoration:none;transition:all .2s}
        .btn:hover{border-color:#76b900;color:#76b900}
        .btn-green{background:#76b900;color:#000;border-color:#76b900;font-weight:700}
        .btn-green:hover{background:#8fd400;color:#000}

        .container{max-width:1100px;margin:0 auto;padding:32px 24px}
        h1{font-size:26px;font-weight:800;color:#76b900;margin-bottom:6px}
        .subtitle{font-size:13px;color:#555;margin-bottom:24px}

        /* Stats row */
        .stats-row{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
        .stat-chip{background:#111;border:1px solid #1e1e1e;border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:10px;min-width:120px}
        .stat-chip .num{font-size:20px;font-weight:800}
        .stat-chip .lbl{font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.5px}

        /* Agent tabs */
        .agent-tabs{display:flex;gap:0;margin-bottom:24px;background:#111;border:1px solid #1e1e1e;border-radius:12px;padding:6px;flex-wrap:wrap;gap:4px}
        .agent-tab{display:flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:none;color:#555;transition:all .2s;white-space:nowrap}
        .agent-tab:hover{color:#aaa;background:#1a1a1a}
        .agent-tab.active{background:#1a1a1a;color:#e5e5e5}
        .agent-tab.active[data-type="all"]      {background:#0a1500;color:#76b900;box-shadow:0 0 0 1px #1e3300}
        .agent-tab.active[data-type="quantum"]  {background:#0f0020;color:#cc66ff;box-shadow:0 0 0 1px #220044}
        .agent-tab.active[data-type="aria"]     {background:#1a0000;color:#ff6666;box-shadow:0 0 0 1px #330000}
        .agent-tab.active[data-type="briefing"] {background:#001a2a;color:#00aaff;box-shadow:0 0 0 1px #002244}
        .agent-tab.active[data-type="sales"]    {background:#1a1000;color:#ffaa00;box-shadow:0 0 0 1px #332200}
        .agent-tab.active[data-type="email"]    {background:#001a10;color:#00cc66;box-shadow:0 0 0 1px #003322}
        .agent-tab.active[data-type="support"]  {background:#001020;color:#4499ff;box-shadow:0 0 0 1px #002244}
        .agent-tab.active[data-type="orchestrator"]{background:#1a1a1a;color:#aaa;box-shadow:0 0 0 1px #333}
        .agent-tab.active[data-type="custom"]   {background:#0a1500;color:#76b900;box-shadow:0 0 0 1px #1e3300}
        .tab-count{font-size:10px;padding:1px 6px;border-radius:8px;background:#1e1e1e;color:#555}

        /* Search */
        .search-bar{margin-bottom:20px}
        .search-input{width:100%;background:#111;border:1px solid #222;color:#e5e5e5;padding:10px 16px;border-radius:10px;font-size:13px;outline:none}
        .search-input:focus{border-color:#76b900}

        /* Report grid */
        .reports-grid{display:grid;gap:12px}
        .report-card{background:#111;border:1px solid #1e1e1e;border-radius:14px;padding:18px 20px;display:flex;align-items:flex-start;gap:16px;transition:all .2s}
        .report-card:hover{border-color:#2a2a2a;background:#141414}
        .report-type-icon{font-size:28px;flex-shrink:0;margin-top:2px;width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#1a1a1a}
        .report-info{flex:1;min-width:0}
        .report-title{font-size:14px;font-weight:700;color:#e5e5e5;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .report-summary{font-size:12px;color:#555;line-height:1.5;margin-bottom:10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .report-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .type-badge{font-size:11px;padding:2px 10px;border-radius:20px;font-weight:600}
        .type-quantum   {background:#0f0020;color:#cc66ff;border:1px solid #220044}
        .type-aria      {background:#1a0000;color:#ff6666;border:1px solid #330000}
        .type-briefing  {background:#001a2a;color:#00aaff;border:1px solid #002244}
        .type-sales     {background:#1a1000;color:#ffaa00;border:1px solid #332200}
        .type-email     {background:#001a10;color:#00cc66;border:1px solid #003322}
        .type-support   {background:#001020;color:#4499ff;border:1px solid #002244}
        .type-orchestrator{background:#1a1a1a;color:#999;border:1px solid #333}
        .type-market    {background:#1a1000;color:#ffaa00;border:1px solid #332200}
        .type-custom    {background:#0a1500;color:#76b900;border:1px solid #1a3300}
        .report-date{font-size:11px;color:#444}
        .report-actions{display:flex;flex-direction:column;gap:6px;flex-shrink:0}
        .action-btn{display:flex;align-items:center;gap:5px;font-size:11px;padding:6px 12px;border-radius:7px;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap;border:1px solid #222;background:none;color:#666}
        .action-btn:hover{border-color:#76b900;color:#76b900}
        .action-btn.pdf:hover{border-color:#ff6600;color:#ff6600}
        .action-btn.del:hover{border-color:#ff4444;color:#ff4444}

        .empty{text-align:center;padding:60px 20px;color:#333}
        .empty h3{font-size:18px;color:#444;margin-bottom:8px}
        .empty p{font-size:13px;line-height:1.6}

        /* Custom info box */
        .custom-info{background:#0a1500;border:1px solid #1e3300;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:12px;color:#76b900;display:none;align-items:flex-start;gap:10px}
        .custom-info.show{display:flex}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:100;align-items:center;justify-content:center}
        .modal-overlay.open{display:flex}
        .modal{background:#111;border:1px solid #2a2a2a;border-radius:16px;padding:28px;width:100%;max-width:580px}
        .modal h3{font-size:16px;font-weight:700;color:#76b900;margin-bottom:20px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:11px;color:#555;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
        .form-group input,.form-group select,.form-group textarea{width:100%;background:#1a1a1a;border:1px solid #2a2a2a;color:#e5e5e5;padding:10px 12px;border-radius:8px;font-size:13px;outline:none;font-family:inherit}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#76b900}
        .form-group textarea{resize:vertical;min-height:180px}
        .form-hint{font-size:11px;color:#444;margin-top:4px}
        .modal-actions{display:flex;gap:10px;margin-top:20px}
        .modal-save{background:#76b900;color:#000;border:none;padding:10px 24px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer}
        .modal-save:hover{background:#8fd400}
        .modal-cancel{background:none;color:#555;border:1px solid #333;padding:10px 20px;border-radius:8px;font-size:13px;cursor:pointer}
        .modal-cancel:hover{color:#aaa}

        .toast{position:fixed;bottom:24px;right:24px;background:#76b900;color:#000;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:700;z-index:200;display:none}
    </style>
</head>
<body>

<header>
    <a href="/chat" class="back-btn">←</a>
    <span class="logo">⚡ ClawYard</span>
    <span style="font-size:13px;color:#555">/ Relatórios</span>
    <div class="hdr-right">
        <a href="/briefing" class="btn" style="border-color:#00aaff;color:#00aaff">📊 Briefing</a>
        <button class="btn btn-green" onclick="openSaveModal()">+ Novo Relatório</button>
        @if(Auth::user()->isAdmin())
        <a href="/admin/users" class="btn" style="border-color:#ff4444;color:#ff6666">⚙️ Admin</a>
        @endif
    </div>
</header>

<div class="container">
    <h1>📋 Relatórios</h1>
    <p class="subtitle">Análises automáticas de todos os agentes · PartYard / HP-Group</p>

    @php
        $allReports = \App\Models\Report::orderBy('created_at','desc')->get();
        $typeCounts = $allReports->groupBy('type')->map->count();
    @endphp

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-chip">
            <div><div class="num" style="color:#e5e5e5">{{ $allReports->count() }}</div><div class="lbl">Total</div></div>
        </div>
        <div class="stat-chip">
            <div><div class="num" style="color:#cc66ff">{{ $typeCounts['quantum'] ?? 0 }}</div><div class="lbl">Quantum</div></div>
        </div>
        <div class="stat-chip">
            <div><div class="num" style="color:#ff6666">{{ $typeCounts['aria'] ?? 0 }}</div><div class="lbl">ARIA</div></div>
        </div>
        <div class="stat-chip">
            <div><div class="num" style="color:#00aaff">{{ $typeCounts['briefing'] ?? 0 }}</div><div class="lbl">Briefing</div></div>
        </div>
        <div class="stat-chip">
            <div><div class="num" style="color:#ffaa00">{{ $typeCounts['sales'] ?? 0 }}</div><div class="lbl">Sales</div></div>
        </div>
        <div class="stat-chip">
            <div><div class="num" style="color:#00cc66">{{ ($typeCounts['email'] ?? 0) + ($typeCounts['support'] ?? 0) }}</div><div class="lbl">Email/Suporte</div></div>
        </div>
    </div>

    <!-- Agent tabs -->
    <div class="agent-tabs">
        @php
            $tabs = [
                'all'          => ['🗂️', 'Todos',       $allReports->count()],
                'briefing'     => ['📊', 'Briefing',    $typeCounts['briefing'] ?? 0],
                'quantum'      => ['⚛️', 'Quantum',     $typeCounts['quantum'] ?? 0],
                'aria'         => ['🔐', 'ARIA',        $typeCounts['aria'] ?? 0],
                'sales'        => ['💼', 'Sales',       $typeCounts['sales'] ?? 0],
                'email'        => ['✉️', 'Email',       $typeCounts['email'] ?? 0],
                'support'      => ['🎧', 'Suporte',     $typeCounts['support'] ?? 0],
                'orchestrator' => ['🤖', 'Orchestrator',$typeCounts['orchestrator'] ?? 0],
                'custom'       => ['📝', 'Custom',      $typeCounts['custom'] ?? 0],
            ];
        @endphp
        @foreach($tabs as $type => [$icon, $label, $count])
        <button class="agent-tab {{ $type === 'all' ? 'active' : '' }}"
                data-type="{{ $type }}"
                onclick="filterByAgent('{{ $type }}', this)">
            {{ $icon }} {{ $label }}
            <span class="tab-count">{{ $count }}</span>
        </button>
        @endforeach
    </div>

    <!-- Custom info box -->
    <div class="custom-info" id="custom-info">
        <span>📝</span>
        <div><strong>Relatórios Custom</strong> — São relatórios que tu próprio escreves manualmente clicando em "+ Novo Relatório". Podes colar qualquer texto, análise ou nota que queiras guardar no histórico da empresa.</div>
    </div>

    <!-- Search -->
    <div class="search-bar">
        <input type="text" class="search-input" placeholder="🔍 Pesquisar em relatórios..." id="search-input" oninput="searchReports(this.value)">
    </div>

    <!-- Reports list -->
    <div class="reports-grid" id="reports-grid">
        @php
            $typeIcons = [
                'quantum'=>'⚛️','aria'=>'🔐','briefing'=>'📊','sales'=>'💼',
                'email'=>'✉️','support'=>'🎧','orchestrator'=>'🤖',
                'market'=>'📈','custom'=>'📝',
            ];
        @endphp
        @forelse($allReports as $report)
        <div class="report-card"
             data-type="{{ $report->type }}"
             data-title="{{ strtolower($report->title) }}"
             data-summary="{{ strtolower($report->summary ?? '') }}">
            <div class="report-type-icon">{{ $typeIcons[$report->type] ?? '📄' }}</div>
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
        <div class="empty" id="empty-state">
            <h3>Sem relatórios ainda</h3>
            <p>Os agentes guardam relatórios automaticamente após cada resposta.<br>
            Começa uma conversa com o Quantum, ARIA, Sales ou outros agentes.</p>
        </div>
        @endforelse
    </div>

    <div id="no-results" style="display:none" class="empty">
        <h3>Nenhum resultado</h3>
        <p>Nenhum relatório encontrado para este filtro.</p>
    </div>
</div>

<!-- Save Modal -->
<div class="modal-overlay" id="save-modal">
    <div class="modal">
        <h3>📝 Novo Relatório Custom</h3>
        <div class="form-group">
            <label>Título</label>
            <input type="text" id="modal-title" placeholder="ex: Análise Mercado MTU — Março 2026">
        </div>
        <div class="form-group">
            <label>Tipo</label>
            <select id="modal-type">
                <option value="custom">📝 Custom (manual)</option>
                <option value="quantum">⚛️ Quantum Leap</option>
                <option value="aria">🔐 ARIA Security</option>
                <option value="briefing">📊 Briefing Executivo</option>
                <option value="sales">💼 Sales</option>
                <option value="email">✉️ Email</option>
                <option value="support">🎧 Suporte</option>
                <option value="market">📈 Market Intelligence</option>
            </select>
            <div class="form-hint">Custom = escreves tu. Os outros tipos são guardados automaticamente pelos agentes.</div>
        </div>
        <div class="form-group">
            <label>Conteúdo</label>
            <textarea id="modal-content" placeholder="Cola aqui a análise, notas ou conteúdo que queres guardar..."></textarea>
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
let currentFilter = 'all';

function filterByAgent(type, btn) {
    currentFilter = type;
    document.querySelectorAll('.agent-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Show/hide custom info
    document.getElementById('custom-info').classList.toggle('show', type === 'custom');

    applyFilters();
}

function searchReports(q) {
    applyFilters(q.toLowerCase());
}

function applyFilters(query = '') {
    const cards = document.querySelectorAll('.report-card');
    let visible = 0;
    cards.forEach(card => {
        const typeMatch = currentFilter === 'all' || card.dataset.type === currentFilter;
        const searchMatch = !query || card.dataset.title.includes(query) || card.dataset.summary.includes(query);
        const show = typeMatch && searchMatch;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('no-results').style.display = visible === 0 && cards.length > 0 ? 'block' : 'none';
    const emptyState = document.getElementById('empty-state');
    if (emptyState) emptyState.style.display = 'none';
}

function openSaveModal() { document.getElementById('save-modal').classList.add('open'); }
function closeSaveModal() { document.getElementById('save-modal').classList.remove('open'); }

async function saveReport() {
    const title   = document.getElementById('modal-title').value.trim();
    const type    = document.getElementById('modal-type').value;
    const content = document.getElementById('modal-content').value.trim();
    if (!title || !content) { showToast('⚠️ Preenche título e conteúdo', '#ff4444'); return; }
    const res  = await fetch('/api/reports', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({title, type, content}),
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
    const res  = await fetch('/reports/'+id, {method:'DELETE',headers:{'X-CSRF-TOKEN':CSRF}});
    const data = await res.json();
    if (data.success) {
        const card = btn.closest('.report-card');
        card.style.transition = 'all .3s';
        card.style.opacity = '0';
        card.style.transform = 'translateX(20px)';
        setTimeout(() => { card.remove(); applyFilters(document.getElementById('search-input').value.toLowerCase()); }, 300);
        showToast('🗑️ Apagado');
    }
}

function showToast(msg, color='#76b900') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.style.background = color; t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 3000);
}

document.getElementById('save-modal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeSaveModal();
});
</script>
</body>
</html>
