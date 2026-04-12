<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — Descobertas & Patentes</title>
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

        .container { max-width:1400px; margin:0 auto; padding:28px 24px; }
        h1 { font-size:24px; font-weight:800; color:#76b900; margin-bottom:4px; }
        .subtitle { font-size:13px; color:#555; margin-bottom:20px; }

        /* Stats bar */
        .stats-bar { display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap; }
        .stat-box { background:#111; border:1px solid #1e1e1e; border-radius:12px; padding:14px 20px; text-align:center; flex:1; min-width:100px; }
        .stat-num { font-size:24px; font-weight:800; color:#76b900; display:block; }
        .stat-num.act-now { color:#ff4444; }
        .stat-num.monitor { color:#ffaa00; }
        .stat-label { font-size:11px; color:#555; text-transform:uppercase; letter-spacing:.5px; }

        /* Filter row */
        .filter-row { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
        .filter-select { background:#111; border:1px solid #222; color:#aaa; padding:7px 12px; border-radius:8px; font-size:12px; outline:none; cursor:pointer; }
        .filter-select:focus { border-color:#76b900; }
        .search-input { background:#111; border:1px solid #222; color:#e5e5e5; padding:7px 14px; border-radius:20px; font-size:12px; outline:none; min-width:220px; }
        .search-input:focus { border-color:#76b900; }
        .count-badge { background:#1a1a1a; color:#555; font-size:11px; padding:3px 10px; border-radius:10px; border:1px solid #222; margin-left:auto; }
        .filter-btn-clear { background:none; border:1px solid #222; color:#555; padding:7px 14px; border-radius:8px; font-size:12px; cursor:pointer; }
        .filter-btn-clear:hover { border-color:#ff4444; color:#ff6666; }

        /* Table */
        .table-wrap { overflow-x:auto; border-radius:12px; border:1px solid #1e1e1e; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { background:#111; padding:11px 14px; text-align:left; font-size:11px; color:#555; text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid #1e1e1e; white-space:nowrap; }
        tbody tr { border-bottom:1px solid #161616; transition:background .15s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#0f0f0f; }
        td { padding:12px 14px; vertical-align:top; }

        /* Source badge */
        .source-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; white-space:nowrap; }
        .src-arxiv         { background:#1a0a00; color:#ff9955; border:1px solid #331500; }
        .src-epo           { background:#001020; color:#66aaff; border:1px solid #003366; }
        .src-peerj         { background:#001a10; color:#44ddaa; border:1px solid #00331a; }
        .src-uspto         { background:#001030; color:#8899ff; border:1px solid #002266; }
        .src-google_patents { background:#001a0a; color:#66ddaa; border:1px solid #003311; }

        /* Priority badge */
        .pri-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
        .pri-act_now   { background:#1a0000; color:#ff4444; border:1px solid #330000; }
        .pri-monitor   { background:#1a1000; color:#ffaa00; border:1px solid #332200; }
        .pri-watch     { background:#1a1a00; color:#dddd00; border:1px solid #333300; }
        .pri-awareness { background:#0a1500; color:#76b900; border:1px solid #1a3300; }

        /* Category badge */
        .cat-badge { display:inline-block; font-size:11px; padding:2px 8px; border-radius:6px; font-weight:600; }

        /* Activity type chips */
        .activity-chips { display:flex; gap:4px; flex-wrap:wrap; margin-top:5px; }
        .activity-chip { font-size:10px; padding:2px 7px; border-radius:4px; background:#1a1a1a; border:1px solid #2a2a2a; color:#aaa; white-space:nowrap; }

        /* Score bar */
        .score-wrap { display:flex; align-items:center; gap:6px; }
        .score-bar { height:4px; border-radius:2px; background:#1a1a1a; flex:1; overflow:hidden; min-width:50px; }
        .score-fill { height:100%; border-radius:2px; background:linear-gradient(90deg, #76b900, #8fd400); }
        .score-num { font-size:12px; font-weight:700; color:#76b900; width:14px; }

        /* Title cell */
        .title-cell { max-width:320px; }
        .disc-title { font-size:13px; font-weight:600; color:#e5e5e5; margin-bottom:3px; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .disc-authors { font-size:11px; color:#444; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:300px; }

        /* Opportunity cell */
        .opp-cell { max-width:260px; font-size:12px; color:#666; line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }

        /* Actions */
        .row-actions { display:flex; gap:6px; white-space:nowrap; }
        .row-btn { font-size:11px; padding:5px 10px; border-radius:6px; cursor:pointer; border:1px solid #222; background:none; color:#666; text-decoration:none; transition:all .15s; display:inline-block; }
        .row-btn:hover { border-color:#76b900; color:#76b900; }
        .row-btn.del:hover { border-color:#ff4444; color:#ff4444; }

        /* Expand row */
        .expand-row { display:none; background:#0d0d0d; }
        .expand-row.open { display:table-row; }
        .expand-content { padding:16px 20px; border-top:1px solid #1e1e1e; }
        .expand-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        .expand-section h4 { font-size:11px; color:#555; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
        .expand-section p { font-size:13px; color:#aaa; line-height:1.6; }

        /* Empty */
        .empty { text-align:center; padding:80px 20px; color:#333; }
        .empty h3 { font-size:20px; color:#444; margin-bottom:8px; }
        .empty p { font-size:13px; }

        /* Toast */
        .toast { position:fixed; bottom:24px; right:24px; background:#76b900; color:#000; padding:12px 20px; border-radius:10px; font-size:13px; font-weight:700; z-index:200; display:none; animation:slideUp .3s ease; }
        @keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }

        /* Pagination */
        .pagination-wrap { margin-top:20px; display:flex; justify-content:center; }
        .pagination-wrap nav { display:flex; gap:4px; }
    </style>
</head>
<body>

<header>
    <a href="/dashboard" class="back-btn">←</a>
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/setq-logo.svg" alt="SETQ.AI" style="height:32px;filter:drop-shadow(0 0 1px rgba(255,255,255,0.1));"></a>
    <span style="font-size:13px;color:#555;">/ Descobertas & Patentes</span>
    <div class="hdr-right">
        <a href="/reports" class="btn">📋 Relatórios</a>
        <a href="/schedules" class="btn">🗓️ Schedule</a>
        @if(Auth::user()->isAdmin())
        <a href="/admin/users" class="btn" style="border-color:#ff4444;color:#ff6666;">⚙️ Admin</a>
        @endif
    </div>
</header>

<div class="container">
    <h1>🔬 Descobertas & Patentes</h1>
    <p class="subtitle">Análise diária de papers arXiv + patentes USPTO · Categorização por área de actividade PartYard / HP-Group</p>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat-box">
            <span class="stat-num">{{ $stats['total'] }}</span>
            <span class="stat-label">Total</span>
        </div>
        <div class="stat-box">
            <span class="stat-num act-now">{{ $stats['act_now'] }}</span>
            <span class="stat-label">🔴 Actuar Já</span>
        </div>
        <div class="stat-box">
            <span class="stat-num monitor">{{ $stats['monitor'] }}</span>
            <span class="stat-label">🟠 Monitorizar</span>
        </div>
        <div class="stat-box">
            <span class="stat-num" style="color:#aaa;">{{ $stats['today'] }}</span>
            <span class="stat-label">📅 Hoje</span>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="/discoveries" id="filter-form">
        <div class="filter-row">
            <select name="source" class="filter-select" onchange="document.getElementById('filter-form').submit()">
                <option value="">📡 Todas as fontes</option>
                @foreach($sources as $key => $src)
                <option value="{{ $key }}" {{ request('source') === $key ? 'selected' : '' }}>
                    {{ $src['icon'] }} {{ $src['label'] }}
                </option>
                @endforeach
            </select>

            <select name="category" class="filter-select" onchange="document.getElementById('filter-form').submit()">
                <option value="">🏷️ Todas as categorias</option>
                @foreach($categories as $key => $cat)
                <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>
                    {{ $cat['icon'] }} {{ $cat['label'] }}
                </option>
                @endforeach
            </select>

            <select name="priority" class="filter-select" onchange="document.getElementById('filter-form').submit()">
                <option value="">⚡ Todas as prioridades</option>
                @foreach($priorities as $key => $pri)
                <option value="{{ $key }}" {{ request('priority') === $key ? 'selected' : '' }}>
                    {{ $pri['badge'] }} {{ $pri['label'] }}
                </option>
                @endforeach
            </select>

            <input type="text" name="q" value="{{ request('q') }}" class="search-input"
                   placeholder="🔍 Pesquisar título ou resumo..."
                   onkeydown="if(event.key==='Enter'){document.getElementById('filter-form').submit()}">

            @if(request()->hasAny(['source','category','priority','q']))
            <a href="/discoveries" class="filter-btn-clear">✕ Limpar</a>
            @endif

            <span class="count-badge">{{ $discoveries->total() }} descobertas</span>
        </div>
    </form>

    <!-- Table -->
    @if($discoveries->count() > 0)
    <div class="table-wrap">
        <table id="disc-table">
            <thead>
                <tr>
                    <th>Fonte</th>
                    <th>Título & Autores</th>
                    <th>Categoria</th>
                    <th>Tipos de Actividade</th>
                    <th>Prioridade</th>
                    <th>Score</th>
                    <th>Data</th>
                    <th>Oportunidade</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($discoveries as $disc)
                @php
                    $catInfo  = $categories[$disc->category] ?? ['label' => $disc->category, 'icon' => '📄', 'color' => '#555'];
                    $srcInfo  = $sources[$disc->source]   ?? ['label' => $disc->source,    'icon' => '📰', 'color' => '#aaa'];
                    $priInfo  = $priorities[$disc->priority] ?? ['label' => $disc->priority, 'badge' => '🟢', 'color' => '#76b900'];
                    $activities = is_array($disc->activity_types) ? $disc->activity_types : json_decode($disc->activity_types, true) ?? [];
                @endphp
                <tr class="disc-row" id="row-{{ $disc->id }}">
                    <td>
                        <span class="source-badge src-{{ $disc->source }}">
                            {{ $srcInfo['icon'] }} {{ $srcInfo['label'] }}
                        </span>
                        @if($disc->reference_id)
                        <div style="font-size:10px;color:#444;margin-top:4px;">{{ $disc->reference_id }}</div>
                        @endif
                    </td>

                    <td class="title-cell">
                        <div class="disc-title">
                            @if($disc->url)
                            <a href="{{ $disc->url }}" target="_blank" style="color:#e5e5e5;text-decoration:none;" onmouseover="this.style.color='#76b900'" onmouseout="this.style.color='#e5e5e5'">{{ $disc->title }}</a>
                            @else
                            {{ $disc->title }}
                            @endif
                        </div>
                        @if($disc->authors)
                        <div class="disc-authors">{{ $disc->authors }}</div>
                        @endif
                    </td>

                    <td>
                        <span class="cat-badge" style="background:{{ $catInfo['color'] }}22;color:{{ $catInfo['color'] }};border:1px solid {{ $catInfo['color'] }}44;">
                            {{ $catInfo['icon'] }} {{ $catInfo['label'] }}
                        </span>
                    </td>

                    <td>
                        <div class="activity-chips">
                            @foreach($activities as $act)
                            <span class="activity-chip">{{ $act }}</span>
                            @endforeach
                        </div>
                    </td>

                    <td>
                        <span class="pri-badge pri-{{ $disc->priority }}">
                            {{ $priInfo['badge'] }} {{ $priInfo['label'] }}
                        </span>
                    </td>

                    <td>
                        <div class="score-wrap">
                            <span class="score-num">{{ $disc->relevance_score }}</span>
                            <div class="score-bar">
                                <div class="score-fill" style="width:{{ $disc->relevance_score * 10 }}%;
                                    @if($disc->relevance_score >= 8) background:linear-gradient(90deg,#ff4444,#ff6666);
                                    @elseif($disc->relevance_score >= 6) background:linear-gradient(90deg,#ffaa00,#ffcc44);
                                    @else background:linear-gradient(90deg,#555,#777); @endif"></div>
                            </div>
                        </div>
                    </td>

                    <td style="white-space:nowrap;font-size:11px;color:#555;">
                        @if($disc->published_date)
                        {{ $disc->published_date->format('d M Y') }}
                        @else
                        {{ $disc->created_at->format('d M Y') }}
                        @endif
                    </td>

                    <td class="opp-cell">{{ $disc->opportunity }}</td>

                    <td>
                        <div class="row-actions">
                            <button class="row-btn" onclick="toggleExpand({{ $disc->id }})">🔍</button>
                            @if($disc->url)
                            <a href="{{ $disc->url }}" target="_blank" class="row-btn">🔗</a>
                            @endif
                            <button class="row-btn del" onclick="deleteDisc({{ $disc->id }})">🗑️</button>
                        </div>
                    </td>
                </tr>

                <!-- Expanded row -->
                <tr class="expand-row" id="expand-{{ $disc->id }}">
                    <td colspan="9">
                        <div class="expand-content">
                            <div class="expand-grid">
                                <div class="expand-section">
                                    <h4>📝 Resumo</h4>
                                    <p>{{ $disc->summary }}</p>
                                </div>
                                @if($disc->recommendation)
                                <div class="expand-section">
                                    <h4>🎯 Recomendação Estratégica</h4>
                                    <p>{{ $disc->recommendation }}</p>
                                </div>
                                @endif
                                @if($disc->opportunity && strlen($disc->opportunity) > 80)
                                <div class="expand-section">
                                    <h4>💡 Oportunidade Completa</h4>
                                    <p>{{ $disc->opportunity }}</p>
                                </div>
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($discoveries->hasPages())
    <div class="pagination-wrap">
        {{ $discoveries->links() }}
    </div>
    @endif

    @else
    <div class="empty">
        <div style="font-size:48px;margin-bottom:16px;">🔬</div>
        <h3>Sem descobertas ainda</h3>
        <p>O <strong>Prof. Quantum Leap</strong> analisa arXiv e USPTO diariamente e preenche esta tabela automaticamente.<br>
        Podes também pedir ao agente: <em>"Faz o digest completo de hoje: papers arXiv + patentes USPTO para PartYard"</em></p>
        <br>
        <a href="/chat?agent=quantum" class="btn" style="border-color:#9933ff;color:#cc66ff;padding:10px 24px;font-size:13px;">
            ⚛️ Abrir Prof. Quantum Leap
        </a>
    </div>
    @endif
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function toggleExpand(id) {
    const row = document.getElementById('expand-' + id);
    row.classList.toggle('open');
}

async function deleteDisc(id) {
    if (!confirm('Apagar esta descoberta?')) return;
    const res = await fetch('/discoveries/' + id, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CSRF },
    });
    const data = await res.json();
    if (data.success) {
        const row = document.getElementById('row-' + id);
        const exp = document.getElementById('expand-' + id);
        [row, exp].forEach(r => {
            if (r) { r.style.opacity = '0'; r.style.transition = 'opacity .3s'; }
        });
        setTimeout(() => { row?.remove(); exp?.remove(); }, 300);
        showToast('🗑️ Descoberta apagada');
    }
}

function showToast(msg, color = '#76b900') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = color;
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 3000);
}
</script>

</body>
</html>
