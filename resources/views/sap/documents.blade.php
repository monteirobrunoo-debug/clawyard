<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SAP B1 Documentos — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green:   #76b900;
            --cyan:    #06b6d4;
            --bg:      #f5f7fa;
            --surface: #ffffff;
            --border:  #e2e8f0;
            --border2: #cbd5e1;
            --text:    #1e293b;
            --muted:   #64748b;
            --muted2:  #94a3b8;
            --success: #16a34a;
            --success-bg: #dcfce7;
            --danger:  #dc2626;
            --danger-bg: #fee2e2;
            --warn-bg: #fef3c7;
            --warn:    #d97706;
            --sidebar-w: 220px;
        }
        /* This page is LIGHT by default — toggle switches INTO dark. */
        :root[data-theme="light"] {
            --bg:      #0a0a0a;
            --surface: #111;
            --border:  #1e1e1e;
            --border2: #2a2a2a;
            --text:    #e5e5e5;
            --muted:   #888;
            --muted2:  #555;
            --success-bg: #052e16;
            --danger-bg:  #3b0a0a;
            --warn-bg:    #3b2a05;
        }

        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; transition: background .2s, color .2s; }

        /* Dark-mode fine-tuning for hardcoded light accents */
        html[data-theme="light"] .topnav .logo { color: var(--text); }
        html[data-theme="light"] tr:hover td { background: #151515; }
        html[data-theme="light"] .sidebar-item.active { background: #0a1a20; }
        html[data-theme="light"] .act-btn.view:hover { background: #0a1a20; border-color: #0369a1; }
        html[data-theme="light"] .act-btn.print:hover { background: #1a102e; border-color: #6d28d9; }
        html[data-theme="light"] .badge-open { background: #052e16; color: #4ade80; }
        html[data-theme="light"] .badge-closed { background: #1a1a1a; color: #9ca3af; }
        html[data-theme="light"] .order-chip { background: #0a1a2e; color: #60a5fa; border-color: #1e3a5f; }
        html[data-theme="light"] .conn-banner.error { background: #3b0a0a; color: #fca5a5; }
        html[data-theme="light"] .conn-banner.ok { background: #052e16; color: #86efac; }

        /* ── TOP NAV ─────────────────────────────────────────────────── */
        .topnav {
            height: 56px; background: var(--surface); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px; padding: 0 20px;
            position: sticky; top: 0; z-index: 200;
        }
        .topnav .logo { font-size: 17px; font-weight: 800; color: #0f172a; letter-spacing: -.5px; }
        .topnav .logo span { color: var(--cyan); }
        .topnav .badge { font-size: 10px; background: var(--cyan); color: #fff; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .topnav .sap-logo { height: 28px; width: auto; margin-left: 4px; }
        .topnav .divider { width: 1px; height: 22px; background: var(--border); margin: 0 4px; }
        .topnav .back {
            margin-left: auto; font-size: 12px; color: var(--muted); text-decoration: none;
            border: 1px solid var(--border); padding: 5px 14px; border-radius: 8px;
            display: flex; align-items: center; gap: 5px; transition: all .15s;
        }
        .topnav .back:hover { background: var(--bg); color: var(--text); }
        .topnav .user { font-size: 13px; color: var(--muted); margin-left: 8px; }

        /* ── LAYOUT ──────────────────────────────────────────────────── */
        .layout { display: flex; flex: 1; min-height: 0; }

        /* ── SIDEBAR ─────────────────────────────────────────────────── */
        .sidebar {
            width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; padding: 16px 0; position: sticky; top: 56px;
            height: calc(100vh - 56px); overflow-y: auto;
        }
        .sidebar-search {
            margin: 0 12px 12px;
            display: flex; align-items: center; gap: 6px;
            background: var(--bg); border: 1px solid var(--border2); border-radius: 8px;
            padding: 6px 10px;
        }
        .sidebar-search input {
            border: none; background: transparent; outline: none; font-size: 12px;
            color: var(--text); width: 100%;
        }
        .sidebar-search svg { color: var(--muted2); flex-shrink: 0; }
        .sidebar-section { font-size: 10px; font-weight: 700; color: var(--muted2); letter-spacing: .8px; text-transform: uppercase; padding: 10px 16px 4px; }
        .sidebar-item {
            display: flex; align-items: center; gap: 8px; padding: 7px 16px;
            font-size: 13px; color: var(--muted); cursor: pointer; transition: all .12s;
            border-left: 3px solid transparent; text-decoration: none;
        }
        .sidebar-item:hover { background: var(--bg); color: var(--text); }
        .sidebar-item.active { background: #eff6ff; color: var(--cyan); border-left-color: var(--cyan); font-weight: 600; }
        .sidebar-item .icon { width: 16px; text-align: center; }
        .sidebar-divider { border: none; border-top: 1px solid var(--border); margin: 8px 0; }
        .sidebar-badge {
            margin-left: auto; font-size: 10px; background: var(--cyan); color: #fff;
            padding: 1px 6px; border-radius: 10px; font-weight: 700;
        }
        .powered { padding: 12px 16px; margin-top: auto; font-size: 11px; color: var(--muted2); border-top: 1px solid var(--border); }
        .powered strong { color: var(--muted); }
        .sidebar-logo { padding: 14px 16px 10px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); margin-bottom: 6px; }
        .sidebar-logo img { height: 32px; width: auto; }
        .sidebar-logo-text { font-size: 11px; color: var(--muted); line-height: 1.3; }
        .sidebar-logo-text strong { display: block; font-size: 12px; color: var(--text); font-weight: 700; }

        /* ── MAIN CONTENT ────────────────────────────────────────────── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

        /* ── PAGE HEADER ─────────────────────────────────────────────── */
        .page-header {
            background: var(--surface); border-bottom: 1px solid var(--border);
            padding: 16px 24px 0;
        }
        .page-header h1 { font-size: 20px; font-weight: 700; color: var(--text); margin-bottom: 14px; }

        /* ── TIMELINE SLIDER ─────────────────────────────────────────── */
        .timeline-bar {
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 14px;
        }
        .tl-year { font-size: 12px; font-weight: 700; color: var(--text); min-width: 36px; }
        .tl-track {
            flex: 1; position: relative; height: 4px;
            background: var(--border2); border-radius: 4px;
        }
        .tl-range {
            position: absolute; height: 4px; background: var(--cyan);
            border-radius: 4px; top: 0;
        }
        .tl-thumb {
            position: absolute; width: 14px; height: 14px;
            background: var(--surface); border: 2.5px solid var(--cyan); border-radius: 50%;
            top: -5px; transform: translateX(-50%); cursor: pointer;
            box-shadow: 0 1px 4px rgba(0,0,0,.15); transition: box-shadow .12s;
        }
        .tl-thumb:hover { box-shadow: 0 0 0 4px rgba(6,182,212,.15); }
        .tl-thumb.dragging { box-shadow: 0 0 0 6px rgba(6,182,212,.2); }
        .tl-dots { display: flex; justify-content: space-between; pointer-events: none; }
        .tl-dot {
            width: 5px; height: 5px; background: var(--border2);
            border-radius: 50%; position: relative; cursor: pointer;
            pointer-events: all; transition: background .1s;
        }
        .tl-dot:hover { background: var(--cyan); }

        /* ── TOOLBAR ─────────────────────────────────────────────────── */
        .toolbar {
            display: flex; align-items: center; gap: 8px;
            padding: 12px 24px; background: var(--surface); border-bottom: 1px solid var(--border);
        }
        .toolbar .search-wrap {
            display: flex; align-items: center; gap: 6px;
            background: var(--bg); border: 1px solid var(--border2); border-radius: 8px;
            padding: 6px 12px; flex: 1; max-width: 280px;
        }
        .toolbar .search-wrap input {
            border: none; background: transparent; outline: none; font-size: 13px; color: var(--text); width: 100%;
        }
        .toolbar .search-wrap svg { color: var(--muted2); flex-shrink: 0; }
        .toolbar select {
            font-size: 12px; border: 1px solid var(--border2); border-radius: 8px;
            padding: 6px 10px; background: var(--surface); color: var(--text);
            outline: none; cursor: pointer;
        }
        .btn {
            font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 8px;
            border: 1px solid var(--border); cursor: pointer; transition: all .12s;
            display: inline-flex; align-items: center; gap: 5px; background: var(--surface); color: var(--text);
        }
        .btn:hover { background: var(--bg); }
        .btn.primary { background: var(--cyan); color: #fff; border-color: var(--cyan); }
        .btn.primary:hover { background: #0891b2; }
        .count-badge { font-size: 11px; color: var(--muted); margin-left: auto; }

        /* ── TABLE ───────────────────────────────────────────────────── */
        .table-wrap { flex: 1; overflow: auto; padding: 0 24px 24px; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-top: 16px; }
        thead tr { background: var(--bg); }
        th {
            font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px;
            padding: 11px 14px; text-align: left; border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { color: var(--text); }
        th .sort-icon { font-size: 9px; margin-left: 4px; color: var(--muted2); }
        td {
            padding: 12px 14px; font-size: 13px; border-bottom: 1px solid var(--border);
            color: var(--text);
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        /* Doc num */
        td.doc-num { font-weight: 600; color: var(--text); }
        td.doc-num .nref { font-size: 11px; color: var(--muted2); margin-top: 1px; }

        /* Status badges */
        .badge-pill {
            display: inline-block; font-size: 11px; font-weight: 600;
            padding: 3px 10px; border-radius: 20px;
        }
        .badge-open    { background: #f0fdf4; color: #15803d; }
        .badge-closed  { background: #f1f5f9; color: #475569; }
        .badge-success { background: var(--success-bg); color: var(--success); }
        .badge-pending { background: var(--danger-bg); color: var(--danger); }
        .badge-neutral { background: var(--warn-bg); color: var(--warn); }

        /* Sales status — linked order chip */
        .order-chip {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 600; background: #eff6ff; color: #2563eb;
            padding: 3px 9px; border-radius: 6px; border: 1px solid #bfdbfe;
        }

        /* Amount */
        td.amount { font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }

        /* Actions */
        td.actions { text-align: right; white-space: nowrap; }
        .act-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--border);
            background: var(--surface); cursor: pointer; transition: all .12s;
            color: var(--muted); margin-left: 4px;
        }
        .act-btn:hover { background: var(--bg); color: var(--text); border-color: var(--border2); }
        .act-btn.view:hover { color: var(--cyan); border-color: #bae6fd; background: #f0f9ff; }
        .act-btn.print:hover { color: #7c3aed; border-color: #ddd6fe; background: #f5f3ff; }

        /* Empty / loading / error states */
        .state-row td { padding: 48px 14px; text-align: center; color: var(--muted2); }
        .state-icon { font-size: 32px; display: block; margin-bottom: 10px; }
        .state-text { font-size: 14px; font-weight: 500; }
        .state-sub  { font-size: 12px; margin-top: 4px; }

        /* Spinner */
        .spinner {
            width: 20px; height: 20px; border: 2.5px solid var(--border2);
            border-top-color: var(--cyan); border-radius: 50%;
            animation: spin .6s linear infinite; display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Card name in row */
        td.card-name { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: var(--muted); }

        /* Connection banner */
        .conn-banner {
            display: none; align-items: center; gap: 10px;
            padding: 10px 24px; font-size: 13px; font-weight: 500;
            border-bottom: 1px solid var(--border);
        }
        .conn-banner.error  { display:flex; background:#fef2f2; color:#dc2626; }
        .conn-banner.ok     { display:flex; background:#f0fdf4; color:#16a34a; }
        .conn-banner a { color: inherit; font-weight: 700; }

        /* Responsive */
        @media (max-width: 860px) {
            .sidebar { display: none; }
            .tl-year { min-width: 28px; font-size: 11px; }
        }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <div class="logo">Claw<span>Yard</span></div>
    <div class="divider"></div>
    <img src="/images/agents/sap.png" alt="SAP" class="sap-logo">
    <div class="badge">Business One</div>
    <a href="/dashboard" class="back">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Dashboard
    </a>
    <a href="/chat?agent=sap" class="back" style="margin-left:6px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Falar com Richard
    </a>
    <span class="user">{{ auth()->user()->name ?? '' }}</span>
    <button id="cyThemeBtn" class="cy-theme-btn" type="button" aria-label="Toggle theme" style="margin-left:8px">☀️</button>
</nav>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="/images/agents/sap.png" alt="SAP Business One">
            <div class="sidebar-logo-text">
                <strong>SAP Business One</strong>
                PartYard / HP-Group
            </div>
        </div>
        <div class="sidebar-search">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" placeholder="Pesquisar..." id="sidebarSearch">
        </div>

        <div class="sidebar-section">Dashboard</div>
        <a href="/dashboard" class="sidebar-item">
            <span class="icon">⊞</span> Dashboard
        </a>

        <div class="sidebar-section">Vendas</div>
        <a href="#" class="sidebar-item" data-type="quotations" onclick="switchType('quotations');return false;">
            <span class="icon">📄</span> Propostas
        </a>
        <a href="#" class="sidebar-item" data-type="orders" onclick="switchType('orders');return false;">
            <span class="icon">📦</span> Encomendas
        </a>
        <a href="#" class="sidebar-item" data-type="deliveries" onclick="switchType('deliveries');return false;">
            <span class="icon">🚚</span> Entregas
        </a>
        <a href="#" class="sidebar-item active" data-type="invoices" onclick="switchType('invoices');return false;">
            <span class="icon">🧾</span> Faturas
            <span class="sidebar-badge" id="invoicesBadge">—</span>
        </a>
        <a href="#" class="sidebar-item" data-type="returns" onclick="switchType('returns');return false;">
            <span class="icon">↩️</span> Devoluções
        </a>

        <hr class="sidebar-divider">

        <div class="sidebar-section">Compras</div>
        <a href="#" class="sidebar-item" data-type="purchase_orders" onclick="switchType('purchase_orders');return false;">
            <span class="icon">🏭</span> Ordens de Compra
        </a>
        <a href="#" class="sidebar-item" data-type="credit_notes" onclick="switchType('credit_notes');return false;">
            <span class="icon">📝</span> Notas de Crédito
        </a>

        <hr class="sidebar-divider">

        <a href="/chat?agent=sap" class="sidebar-item">
            <span class="icon">🛒</span> Falar com Richard
        </a>
        <a href="/profile" class="sidebar-item">
            <span class="icon">👤</span> Perfil
        </a>

        <div class="powered">Powered by <strong>SAP B1 Service Layer</strong></div>
    </aside>

    <!-- MAIN -->
    <div class="main">

        <!-- CONNECTION BANNER -->
        <div class="conn-banner" id="connBanner"></div>

        <!-- PAGE HEADER + TIMELINE -->
        <div class="page-header">
            <h1 id="pageTitle">Faturas</h1>

            <div class="timeline-bar">
                <div class="tl-year" id="tlFromLabel">—</div>
                <div class="tl-track" id="tlTrack">
                    <div class="tl-range"  id="tlRange"></div>
                    <div class="tl-thumb"  id="tlThumbFrom" title="Início"></div>
                    <div class="tl-thumb"  id="tlThumbTo"   title="Fim"></div>
                </div>
                <div class="tl-year" id="tlToLabel" style="text-align:right">—</div>
            </div>
        </div>

        <!-- TOOLBAR -->
        <div class="toolbar">
            <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" placeholder="Pesquisar cliente, nº doc..." id="searchInput">
            </div>
            <select id="statusFilter">
                <option value="">Todos os estados</option>
                <option value="open">Abertos</option>
                <option value="closed">Fechados</option>
                <option value="pending">Pagamento pendente</option>
                <option value="paid">Pagamento concluído</option>
            </select>
            <button class="btn primary" onclick="loadData()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                Actualizar
            </button>
            <span class="count-badge" id="countBadge"></span>
        </div>

        <!-- TABLE -->
        <div class="table-wrap">
            <table id="docTable">
                <thead>
                    <tr>
                        <th class="sortable" data-col="doc_num">Nº Doc <span class="sort-icon">⇅</span></th>
                        <th>Estado documento</th>
                        <th>Referência cliente</th>
                        <th>Estado pagamento</th>
                        <th class="sortable" data-col="doc_date">Data <span class="sort-icon">⇅</span></th>
                        <th>Cliente / Fornecedor</th>
                        <th class="sortable" data-col="total" style="text-align:right">Total <span class="sort-icon">⇅</span></th>
                        <th style="text-align:right">Acções</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr class="state-row">
                        <td colspan="8">
                            <span class="state-icon">⏳</span>
                            <div class="state-text">A carregar dados SAP B1...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div><!-- .main -->
</div><!-- .layout -->

<script>
// ── State ────────────────────────────────────────────────────────────────────
let currentType = 'invoices';
let allRows     = [];
let sortCol     = 'doc_date';
let sortAsc     = false;
let yearMin     = 2010;
let yearMax     = new Date().getFullYear();
// Default: last 30 days — SAP responds faster with narrow range
let fromDate    = new Date(); fromDate.setDate(fromDate.getDate() - 30);
let toDate      = new Date();
let fromYear    = fromDate.getFullYear();
let toYear      = toDate.getFullYear();
let dragging    = null;

const LABELS = {
    invoices:       'Faturas',
    orders:         'Encomendas de Venda',
    purchase_orders:'Ordens de Compra',
    quotations:     'Propostas / Orçamentos',
    deliveries:     'Entregas',
    returns:        'Devoluções',
    credit_notes:   'Notas de Crédito',
};

// ── DOM shortcuts ─────────────────────────────────────────────────────────────
const $  = id => document.getElementById(id);
const $$ = sel => document.querySelectorAll(sel);

// ── Switch doc type ───────────────────────────────────────────────────────────
function switchType(type) {
    currentType = type;
    $$('.sidebar-item').forEach(el => el.classList.remove('active'));
    const el = document.querySelector(`[data-type="${type}"]`);
    if (el) el.classList.add('active');
    $('pageTitle').textContent = LABELS[type] || type;
    loadYears().then(loadData);
}

// ── Timeline ──────────────────────────────────────────────────────────────────
async function loadYears() {
    const currentYr = new Date().getFullYear();
    try {
        const r = await apiFetch(`/api/sap/years?type=${currentType}`);
        yearMin  = r.min || 2010;
        yearMax  = r.max || currentYr;
    } catch (e) {
        yearMin  = 2010;
        yearMax  = currentYr;
    }
    // Default view: last 12 months — narrow enough for SAP to respond quickly
    fromYear = yearMax - 1;
    toYear   = yearMax;
    renderTimeline();
}

function yearToPercent(y) {
    if (yearMax === yearMin) return 50;
    return ((y - yearMin) / (yearMax - yearMin)) * 100;
}
function percentToYear(pct) {
    return Math.round(yearMin + (pct / 100) * (yearMax - yearMin));
}

function renderTimeline() {
    const fromPct = yearToPercent(fromYear);
    const toPct   = yearToPercent(toYear);
    $('tlFromLabel').textContent = fromYear;
    $('tlToLabel').textContent   = toYear;
    $('tlRange').style.left      = fromPct + '%';
    $('tlRange').style.width     = (toPct - fromPct) + '%';
    $('tlThumbFrom').style.left  = fromPct + '%';
    $('tlThumbTo').style.left    = toPct   + '%';
}

// Drag handling
function setupSlider() {
    const track = $('tlTrack');

    function onMouseMove(e) {
        if (!dragging) return;
        const rect  = track.getBoundingClientRect();
        const pct   = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100));
        const year  = percentToYear(pct);
        if (dragging === 'from') fromYear = Math.min(year, toYear);
        if (dragging === 'to')   toYear   = Math.max(year, fromYear);
        renderTimeline();
    }
    function onMouseUp() {
        if (dragging) {
            dragging = null;
            $$('.tl-thumb').forEach(t => t.classList.remove('dragging'));
            loadData();
        }
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup',   onMouseUp);
    }

    $('tlThumbFrom').addEventListener('mousedown', () => {
        dragging = 'from';
        $('tlThumbFrom').classList.add('dragging');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup',   onMouseUp);
    });
    $('tlThumbTo').addEventListener('mousedown', () => {
        dragging = 'to';
        $('tlThumbTo').classList.add('dragging');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup',   onMouseUp);
    });
}

// ── API fetch ─────────────────────────────────────────────────────────────────
async function apiFetch(url) {
    const resp = await fetch(url, {
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return resp.json();
}

// ── Load data ─────────────────────────────────────────────────────────────────
async function loadData() {
    const tbody = $('tableBody');
    tbody.innerHTML = `<tr class="state-row"><td colspan="8"><span class="spinner"></span><div class="state-text" style="margin-top:10px">A consultar SAP B1...</div></td></tr>`;
    $('countBadge').textContent = '';

    const search = $('searchInput').value.trim();
    // Use exact dates derived from slider years — mid-year clamp avoided
    const dateFrom = `${fromYear}-01-01`;
    const dateTo   = `${toYear}-12-31`;


    const params = new URLSearchParams({
        type: currentType,
        top:  50,
        from: dateFrom,
        to:   dateTo,
    });
    if (search) params.set('search', search);

    try {
        const data = await apiFetch(`/api/sap/table?${params}`);

        if (!data.ok) {
            const hint = data.hint ? `<br><small style="color:#999">${data.hint}</small>` : '';
            showError((data.error || 'Erro desconhecido') + hint);
            return;
        }

        allRows = data.rows || [];
        renderTable();

        // Update invoice badge in sidebar
        if (currentType === 'invoices') {
            $('invoicesBadge').textContent = allRows.length;
        }

    } catch (e) {
        showError('Erro de ligação ao servidor: ' + e.message);
    }
}

function showError(msg) {
    $('tableBody').innerHTML = `<tr class="state-row"><td colspan="8">
        <span class="state-icon">⚠️</span>
        <div class="state-text">Erro ao carregar dados</div>
        <div class="state-sub" style="color:#dc2626">${msg}</div>
    </td></tr>`;
}

// ── Render table ──────────────────────────────────────────────────────────────
function renderTable() {
    const statusFilter = $('statusFilter').value;
    const search       = $('searchInput').value.toLowerCase();

    let rows = [...allRows];

    // Filter
    if (statusFilter === 'open')    rows = rows.filter(r => r.doc_status === 'Open');
    if (statusFilter === 'closed')  rows = rows.filter(r => r.doc_status === 'Closed');
    if (statusFilter === 'pending') rows = rows.filter(r => !r.payment_ok);
    if (statusFilter === 'paid')    rows = rows.filter(r => r.payment_ok);
    if (search) rows = rows.filter(r =>
        (r.card_name || '').toLowerCase().includes(search) ||
        String(r.doc_num).includes(search) ||
        (r.sales_status || '').toLowerCase().includes(search)
    );

    // Sort
    rows.sort((a, b) => {
        let av = a[sortCol], bv = b[sortCol];
        if (sortCol === 'total') { av = +av; bv = +bv; }
        if (av < bv) return sortAsc ? -1 : 1;
        if (av > bv) return sortAsc ?  1 : -1;
        return 0;
    });

    $('countBadge').textContent = `${rows.length} ${rows.length === 1 ? 'registo' : 'registos'}`;

    if (!rows.length) {
        $('tableBody').innerHTML = `<tr class="state-row"><td colspan="8">
            <span class="state-icon">📭</span>
            <div class="state-text">Sem documentos para os filtros seleccionados</div>
            <div class="state-sub">Altera o intervalo de datas ou limpa a pesquisa</div>
        </td></tr>`;
        return;
    }

    $('tableBody').innerHTML = rows.map(row => {
        const docStatusBadge = row.doc_status === 'Open'
            ? `<span class="badge-pill badge-open">Aberto</span>`
            : `<span class="badge-pill badge-closed">Fechado</span>`;

        let payBadge;
        if (row.doc_type === 'invoices' || row.doc_type === 'credit_notes') {
            payBadge = row.payment_ok
                ? `<span class="badge-pill badge-success">Payment successful</span>`
                : `<span class="badge-pill badge-pending">Pending payment</span>`;
        } else {
            payBadge = row.payment_ok
                ? `<span class="badge-pill badge-neutral">Concluído</span>`
                : `<span class="badge-pill badge-open">Em aberto</span>`;
        }

        const salesChip = row.sales_status
            ? `<span class="order-chip">📋 ${escHtml(row.sales_status)}</span>`
            : `<span style="color:var(--muted2);font-size:11px">—</span>`;

        const total = row.total
            ? `${Number(row.total).toLocaleString('pt-PT', {minimumFractionDigits:2,maximumFractionDigits:2})} ${row.currency || 'EUR'}`
            : '—';

        const dateFormatted = row.doc_date
            ? row.doc_date.split('-').reverse().join('/')
            : '—';

        return `<tr>
            <td class="doc-num">
                N° ${escHtml(String(row.doc_num))}
                ${row.doc_entry ? `<div class="nref">Entry: ${escHtml(String(row.doc_entry))}</div>` : ''}
            </td>
            <td>${docStatusBadge}</td>
            <td>${salesChip}</td>
            <td>${payBadge}</td>
            <td>${dateFormatted}</td>
            <td class="card-name" title="${escHtml(row.card_name || '')}">${escHtml(row.card_name || '—')}</td>
            <td class="amount">${escHtml(total)}</td>
            <td class="actions">
                <button class="act-btn print" title="Imprimir" onclick="printDoc(${row.doc_num},'${row.doc_type}')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                </button>
                <button class="act-btn view" title="Ver detalhes" onclick="viewDoc(${row.doc_num},'${row.doc_type}','${escHtml(row.card_name || '')}')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </td>
        </tr>`;
    }).join('');
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Sort ──────────────────────────────────────────────────────────────────────
$$('th.sortable').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (sortCol === col) sortAsc = !sortAsc;
        else { sortCol = col; sortAsc = col !== 'doc_date'; }
        renderTable();
    });
});

// ── Actions ───────────────────────────────────────────────────────────────────
function printDoc(docNum, docType) {
    // Redirect to Richard chat with print instructions
    window.open(`/chat?agent=sap&q=Imprime+o+documento+n%C2%BA+${docNum}+do+tipo+${docType}`, '_blank');
}
function viewDoc(docNum, docType, cardName) {
    window.open(`/chat?agent=sap&q=Mostra+os+detalhes+do+documento+n%C2%BA+${docNum}+${encodeURIComponent(cardName)}`, '_blank');
}

// ── Live search & filter ──────────────────────────────────────────────────────
$('searchInput').addEventListener('input', () => renderTable());
$('statusFilter').addEventListener('change', () => renderTable());

// Sidebar search filters the sidebar items
$('sidebarSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    $$('.sidebar-item').forEach(el => {
        el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// ── SAP connection ping ───────────────────────────────────────────────────────
async function pingSap() {
    const banner = $('connBanner');
    try {
        const r = await apiFetch('/api/sap/ping');
        if (r.ok) {
            banner.className = 'conn-banner ok';
            banner.innerHTML = `✅ ${r.message}`;
            setTimeout(() => { banner.className = 'conn-banner'; }, 5000); // hide after 5s
        } else {
            banner.className = 'conn-banner error';
            const hint = r.hint ? ` — <strong>${r.hint}</strong>` : '';
            banner.innerHTML = `${r.message}${hint} &nbsp;|&nbsp; <a href="/chat?agent=sap">Falar com Richard</a>`;
        }
    } catch (e) {
        banner.className = 'conn-banner error';
        banner.innerHTML = `❌ Erro ao contactar SAP: ${e.message}`;
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────
setupSlider();
pingSap();
loadYears().then(loadData);
</script>
@include('partials.theme-button')
</body>
</html>
