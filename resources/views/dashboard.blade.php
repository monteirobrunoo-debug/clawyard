<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard — Agents</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">

    {{-- Apply saved theme BEFORE first paint to avoid FOUC (flash of wrong theme) --}}
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
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── DARK (default) ── */
        :root {
            --green: #76b900;
            --green-hover: #8fd400;
            --bg: #0a0a0a;
            --bg2: #111;
            --bg3: #1a1a1a;
            --border: #1e1e1e;
            --border2: #2a2a2a;
            --text: #e5e5e5;
            --text-strong: #ffffff;
            --muted: #555;
            --muted2: #888;
            --role: #666;
            --section-dim: #2a2a2a;
            --cat-bg: rgba(255,255,255,0.02);
            --cat-border: rgba(255,255,255,0.06);
            --search-bg: #111;
            --search-border: #2a2a2a;
            --search-focus: #3a3a3a;
        }

        /* ── LIGHT ── */
        :root[data-theme="light"] {
            --green: #5a9300;
            --green-hover: #6ead00;
            --bg: #f7f8fa;
            --bg2: #ffffff;
            --bg3: #f1f3f5;
            --border: #e5e7eb;
            --border2: #d1d5db;
            --text: #1f2937;
            --text-strong: #0f172a;
            --muted: #6b7280;
            --muted2: #4b5563;
            --role: #64748b;
            --section-dim: #9ca3af;
            --cat-bg: rgba(15,23,42,0.015);
            --cat-border: rgba(15,23,42,0.08);
            --search-bg: #ffffff;
            --search-border: #e5e7eb;
            --search-focus: #9ca3af;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg); color: var(--text); min-height: 100vh;
            transition: background 0.2s, color 0.2s;
        }

        /* ── HEADER ── */
        .header {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 32px; border-bottom: 1px solid var(--border); background: var(--bg2);
            position: sticky; top: 0; z-index: 100;
        }
        .logo { font-size: 20px; font-weight: 800; color: var(--green); letter-spacing: -0.5px; }
        .badge { font-size: 10px; background: var(--green); color: #000; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .nav-links { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .nav-link {
            font-size: 12px; color: var(--muted); text-decoration: none;
            border: 1px solid var(--border2); padding: 5px 12px; border-radius: 8px;
            transition: all 0.15s; display: flex; align-items: center; gap: 5px;
        }
        .nav-link:hover { color: var(--text); border-color: var(--muted); }
        .nav-link.admin { color: #ef4444; border-color: #fca5a5; }
        :root[data-theme="dark"] .nav-link.admin,
        :root:not([data-theme]) .nav-link.admin { color: #ff6666; border-color: #ff4444; }
        .nav-link.admin:hover { background: color-mix(in srgb, #ef4444 10%, transparent); }
        .nav-link.briefing { color: var(--green); border-color: color-mix(in srgb, var(--green) 30%, transparent); background: color-mix(in srgb, var(--green) 6%, transparent); font-weight: 700; }
        .nav-link.briefing:hover { background: color-mix(in srgb, var(--green) 14%, transparent); }
        .user-name { font-size: 13px; color: var(--muted2); font-weight: 500; }
        .logout-form { display: inline; }
        .logout-btn {
            font-size: 12px; color: var(--muted); background: none; border: 1px solid var(--border2);
            padding: 5px 12px; border-radius: 8px; cursor: pointer; transition: all 0.15s;
        }
        .logout-btn:hover { color: var(--text); border-color: var(--muted); }

        /* ── THEME TOGGLE ── */
        .theme-toggle {
            width: 32px; height: 32px; border-radius: 50%;
            border: 1px solid var(--border2); background: transparent;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: var(--muted2); transition: all 0.15s;
        }
        .theme-toggle:hover { color: var(--text); border-color: var(--muted); transform: rotate(20deg); }
        .theme-icon-dark, .theme-icon-light { display: none; }
        :root[data-theme="light"] .theme-icon-dark { display: inline; }
        :root[data-theme="dark"] .theme-icon-light,
        :root:not([data-theme]) .theme-icon-light { display: inline; }

        /* ── HERO ── */
        .hero { text-align: center; padding: 52px 32px 28px; }
        .hero-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 2px; margin-bottom: 14px; }
        .hero h1 { font-size: 38px; font-weight: 800; color: var(--text-strong); margin-bottom: 10px; letter-spacing: -1px; }
        .hero h1 span { color: var(--green); }
        .hero p { font-size: 14px; color: var(--muted); max-width: 480px; margin: 0 auto; line-height: 1.6; }

        /* ── SEARCH BAR ── */
        .search-wrap {
            max-width: 520px; margin: 18px auto 0; position: relative;
        }
        .search-input {
            width: 100%; padding: 11px 16px 11px 42px; font-size: 14px;
            background: var(--search-bg); color: var(--text);
            border: 1px solid var(--search-border); border-radius: 999px;
            outline: none; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .search-input::placeholder { color: var(--muted); }
        .search-input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--green) 18%, transparent);
        }
        .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--muted); font-size: 14px; pointer-events: none;
        }
        .search-clear {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            width: 24px; height: 24px; border-radius: 50%; border: none;
            background: var(--border2); color: var(--text); cursor: pointer;
            display: none; align-items: center; justify-content: center; font-size: 12px;
        }
        .search-clear.visible { display: flex; }

        /* ── CATEGORIES ── */
        .categories-wrap { max-width: 1280px; margin: 40px auto 0; padding: 0 32px 60px; }
        .category-section { margin-bottom: 44px; }
        .category-section.empty { display: none; }
        .category-header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 18px;
            padding: 12px 16px; background: var(--cat-bg);
            border: 1px solid var(--cat-border); border-radius: 12px;
        }
        .category-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: color-mix(in srgb, var(--cat-color, var(--green)) 16%, transparent);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            border: 1px solid color-mix(in srgb, var(--cat-color, var(--green)) 30%, transparent);
        }
        .category-title {
            font-size: 14px; font-weight: 700; color: var(--text-strong);
            letter-spacing: 0.3px;
        }
        .category-subtitle {
            font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1.5px;
            margin-top: 2px;
        }
        .category-count {
            margin-left: auto; font-size: 11px; color: var(--muted);
            padding: 3px 9px; border: 1px solid var(--border2); border-radius: 20px;
        }

        /* ── GRID ── */
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 16px;
        }

        /* ── CARD ── */
        .agent-card {
            background: var(--bg2); border: 1px solid var(--border); border-radius: 18px;
            padding: 28px 20px 22px; text-align: center; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s, background 0.2s;
            text-decoration: none; display: block; position: relative; overflow: hidden;
        }
        .agent-card.hidden-by-search { display: none; }
        .agent-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: var(--card-color, var(--green)); opacity: 0.6;
            transition: opacity 0.2s;
        }
        .agent-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px color-mix(in srgb, var(--card-color, var(--green)) 20%, transparent);
            border-color: color-mix(in srgb, var(--card-color, var(--green)) 40%, transparent);
        }
        .agent-card:hover::before { opacity: 1; }

        .agent-card .avatar {
            width: 80px; height: 80px; border-radius: 50%; margin: 0 auto 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; background: var(--bg3);
            border: 2px solid var(--border2); overflow: hidden;
            transition: border-color 0.2s;
        }
        .agent-card:hover .avatar {
            border-color: color-mix(in srgb, var(--card-color, var(--green)) 60%, transparent);
        }
        .agent-card .avatar img { width: 100%; height: 100%; object-fit: cover; }

        .agent-name { font-size: 14px; font-weight: 700; color: var(--text-strong); margin-bottom: 6px; }
        .agent-role { font-size: 12px; color: var(--role); margin-bottom: 18px; line-height: 1.45; }

        .talk-btn {
            display: inline-block; background: var(--green); color: #000;
            padding: 8px 22px; border-radius: 20px; font-size: 12px; font-weight: 700;
            transition: background 0.15s, transform 0.15s;
        }
        .agent-card:hover .talk-btn {
            background: var(--green-hover); transform: scale(1.05);
        }

        /* Status dot */
        .status-dot {
            position: absolute; top: 14px; right: 14px;
            width: 8px; height: 8px; background: var(--green);
            border-radius: 50%;
            box-shadow: 0 0 6px var(--green);
            animation: pulse-dot 2.5s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.8); }
        }

        /* ── BRIEFING — special card ── */
        .agent-card.briefing-card {
            background: linear-gradient(135deg, #001a2e, #0a0a16);
            border-color: #003366;
            --card-color: #00aaff;
        }
        :root[data-theme="light"] .agent-card.briefing-card {
            background: linear-gradient(135deg, #e0f2ff, #f0f8ff);
            border-color: #93c5fd;
        }
        .briefing-card .agent-name { color: #00aaff; }
        :root[data-theme="light"] .briefing-card .agent-name { color: #0369a1; }
        .briefing-card .agent-role { color: #4b5b74; }
        :root[data-theme="light"] .briefing-card .agent-role { color: #475569; }
        .briefing-card .talk-btn { background: #003366; color: #00aaff; border: 1px solid #0055aa; }
        :root[data-theme="light"] .briefing-card .talk-btn { background: #0369a1; color: #ffffff; border-color: #0284c7; }
        .briefing-card:hover .talk-btn { background: #004488; transform: scale(1.05); }
        :root[data-theme="light"] .briefing-card:hover .talk-btn { background: #0284c7; }
        .briefing-card .status-dot { background: #00aaff; box-shadow: 0 0 8px #00aaff; }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--muted);
            display: none;
        }
        .empty-state.visible { display: block; }
        .empty-state .big { font-size: 48px; margin-bottom: 12px; }
        .empty-state h3 { font-size: 16px; color: var(--text); margin-bottom: 6px; }
        .empty-state p { font-size: 13px; }

        /* ── MOBILE ── */
        @media (max-width: 640px) {
            .header { padding: 10px 16px; }
            .nav-links { gap: 6px; }
            .nav-link { font-size: 11px; padding: 4px 9px; }
            .hero { padding: 32px 16px 20px; }
            .hero h1 { font-size: 26px; }
            .categories-wrap { padding: 0 16px 40px; margin-top: 26px; }
            .category-section { margin-bottom: 32px; }
            .agents-grid { gap: 12px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .agent-card { padding: 20px 14px 16px; }
            .agent-card .avatar { width: 64px; height: 64px; font-size: 30px; }
            .agent-name { font-size: 13px; }
            .agent-role { font-size: 11px; }
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:36px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));"></a>
    <span class="badge">© PartYard/Setq.AI Rights reserved 2026</span>
    <div class="nav-links">
        <a href="/briefing" class="nav-link briefing">📊 Briefing</a>
        <a href="/intel" class="nav-link" style="color:#a855f7;border-color:#3b1a5f;background:#0f0a1e">🔗 Intel Bus</a>
        <a href="/agents/activity" class="nav-link" style="color:#76b900;border-color:#1e3300;background:#0d1a00">🤖 Activity</a>
        <a href="/discoveries" class="nav-link">🔬 Discoveries</a>
        <a href="/patents/library" class="nav-link">🏛️ Patents</a>
        <a href="/reports" class="nav-link">📋 Reports</a>
        <a href="/schedules" class="nav-link">🗓️ Schedule</a>
        <a href="/shares" class="nav-link" style="color:#60a5fa;border-color:#1e3a5f;background:#0a1a2e">🔗 Shared Agents</a>
        @if(Auth::user()->isAdmin())
            <a href="/admin/users" class="nav-link admin">⚙️ Admin</a>
        @endif
        <span class="user-name">{{ Auth::user()->name }}</span>
        <button type="button" class="theme-toggle" id="themeToggle" title="Toggle theme" aria-label="Toggle dark/light theme">
            <span class="theme-icon-light">🌙</span>
            <span class="theme-icon-dark">☀️</span>
        </button>
        <form method="POST" action="{{ route('logout') }}" class="logout-form">
            @csrf
            <button type="submit" class="logout-btn">Log out</button>
        </form>
    </div>
</header>

<div class="hero">
    <p class="hero-label">HP-Group · PartYard Marine · PartYard Military</p>
    <h1>Choose your <span>Agent</span></h1>
    <p>© PartYard/Setq.AI Rights reserved 2026 — {{ $agentCount ?? 27 }} specialised agents ready to help</p>

    <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" id="agentSearch" class="search-input" placeholder="Pesquisar agente (nome, função, tecnologia…)" autocomplete="off">
        <button type="button" class="search-clear" id="searchClear" aria-label="Clear search">✕</button>
    </div>
</div>

@php
// Each agent tagged with a category for grouping on the dashboard.
// Keep this stable — the search filter reads from the same structure.
$agents = [
    ['key' => 'briefing',    'category' => 'strategic',  'name' => 'Strategist Renato',      'emoji' => '📊', 'role' => 'Executive briefing — combines Quantum, ARIA, Sales and all agents into an action plan', 'color' => '#00aaff', 'special' => true],
    ['key' => 'orchestrator','category' => 'strategic',  'name' => 'All Agents',             'emoji' => '🌐', 'role' => 'Orchestrator — activates multiple agents in parallel',                                    'color' => '#76b900'],
    ['key' => 'thinking',    'category' => 'strategic',  'name' => 'Prof. Deep Thought',     'emoji' => '🧠', 'role' => 'Extended thinking — complex multi-step reasoning and deep analysis',                      'color' => '#a855f7'],
    ['key' => 'claude',      'category' => 'strategic',  'name' => 'Bruno AI',               'emoji' => '🧠', 'role' => 'Claude — advanced reasoning and complex analysis',                                        'color' => '#a855f7'],
    ['key' => 'nvidia',      'category' => 'strategic',  'name' => 'Carlos NVIDIA',          'emoji' => '⚡', 'role' => 'NVIDIA NeMo — maximum speed and efficiency',                                              'color' => '#76b900'],

    ['key' => 'sales',       'category' => 'commercial', 'name' => 'Marco Sales',            'emoji' => '💼', 'role' => 'Sales MTU · CAT · MAK · Jenbacher · SKF · Schottel',                                     'color' => '#3b82f6'],
    ['key' => 'support',     'category' => 'commercial', 'name' => 'Marcus Support',         'emoji' => '🔧', 'role' => 'Technical Support — engine and system fault diagnosis',                                   'color' => '#f59e0b'],
    ['key' => 'email',       'category' => 'commercial', 'name' => 'Daniel Email',           'emoji' => '📧', 'role' => 'Maritime email — shipowners, agents and vessels',                                         'color' => '#8b5cf6'],
    ['key' => 'crm',         'category' => 'commercial', 'name' => 'Marta CRM',              'emoji' => '🎯', 'role' => 'SAP B1 CRM — cria oportunidades a partir de emails, pipeline por vendedor',              'color' => '#e11d48'],
    ['key' => 'shipping',    'category' => 'commercial', 'name' => 'Logística/PartYard',     'emoji' => '🚚', 'role' => 'Transporte UPS 2026, catalogação de faturas, alfândega (Incoterms, TARIC, VIES, DAU/SAD, IVA intra-UE)', 'color' => '#8b5cf6'],
    ['key' => 'mildef',      'category' => 'commercial', 'name' => 'Cor. Rodrigues Defesa',  'emoji' => '🎖️', 'role' => 'Military procurement — worldwide defence suppliers excl. China/Russia, NATO/EU/USLI context', 'color' => '#6b3fa0'],

    ['key' => 'sap',         'category' => 'operations', 'name' => 'Richard SAP',            'emoji' => '📊', 'role' => 'SAP B1 — stock, invoices, pipeline CRM and ERP data',                                    'color' => '#06b6d4'],
    ['key' => 'capitao',     'category' => 'operations', 'name' => 'Captain Porto',          'emoji' => '⚓', 'role' => 'Port operations — port calls, documentation and maritime logistics',                      'color' => '#0ea5e9'],
    ['key' => 'document',    'category' => 'operations', 'name' => 'Commander Doc',          'emoji' => '📄', 'role' => 'Documents — analyses PDFs, contracts and technical certificates',                         'color' => '#94a3b8'],
    ['key' => 'qnap',        'category' => 'operations', 'name' => 'PartYard Archive',       'emoji' => '🗄️', 'role' => 'Document archive — search prices, codes, invoices, licences and contracts on QNAP',       'color' => '#f59e0b'],
    ['key' => 'finance',     'category' => 'operations', 'name' => 'Dr. Luís Finance',       'emoji' => '💰', 'role' => 'ROC · TOC · PhD Banking Management — Accounting, Audit and Taxation',                    'color' => '#10b981'],
    ['key' => 'acingov',     'category' => 'operations', 'name' => 'Dr. Ana Contracts',      'emoji' => '🏛️', 'role' => 'Public procurement — Acingov tenders ranked by relevance for PartYard',                  'color' => '#f59e0b'],
    ['key' => 'vessel',      'category' => 'operations', 'name' => 'Capitão Vasco',          'emoji' => '⚓', 'role' => 'Vessel search + naval repair — ship brokers, drydocks, IACS class, inland waterways',     'color' => '#0ea5e9'],

    ['key' => 'engineer',    'category' => 'rd',         'name' => 'Eng. Victor R&D',        'emoji' => '🔩', 'role' => 'R&D and Product Development — TRL plans, CAPEX, roadmap for new PartYard equipment',      'color' => '#f97316'],
    ['key' => 'patent',      'category' => 'rd',         'name' => 'Dr. Sofia IP',           'emoji' => '🏛️', 'role' => 'Intellectual Property — patent validation, prior art EPO/USPTO, patentability and FTO',   'color' => '#8b5cf6'],
    ['key' => 'quantum',     'category' => 'rd',         'name' => 'Prof. Quantum Leap',     'emoji' => '⚛️', 'role' => 'Quantum — arXiv papers + USPTO patents for PartYard',                                      'color' => '#22d3ee'],
    ['key' => 'energy',      'category' => 'rd',         'name' => 'Eng. Sofia Energy',      'emoji' => '⚡', 'role' => 'Maritime decarbonisation — Fuzzy TOPSIS, CII/EEXI, LNG/Biofuel/H2, Fleet Energy Mgmt',   'color' => '#10b981'],
    ['key' => 'research',    'category' => 'rd',         'name' => 'Marina Research',        'emoji' => '🔍', 'role' => 'Competitive intelligence — benchmarking, market analysis and site improvements',          'color' => '#f97316'],

    ['key' => 'aria',        'category' => 'security',   'name' => 'ARIA Security',          'emoji' => '🔐', 'role' => 'Cybersecurity — STRIDE, OWASP, daily site scanning',                                      'color' => '#ef4444'],
    ['key' => 'kyber',       'category' => 'security',   'name' => 'KYBER Encryption',       'emoji' => '🔒', 'role' => 'Post-quantum encryption — Kyber-1024 + AES-256-GCM, key generation and encrypted email',  'color' => '#76b900'],
    ['key' => 'computer',    'category' => 'security',   'name' => 'RoboDesk',               'emoji' => '🖥️', 'role' => 'Web automation — Computer Use API, browser control and desktop tasks',                    'color' => '#22c55e'],
    ['key' => 'batch',       'category' => 'security',   'name' => 'Max Batch',              'emoji' => '📦', 'role' => 'Batch processing — run multiple tasks in parallel with async queues',                     'color' => '#06b6d4'],
];

$categories = [
    'strategic'  => ['title' => 'Strategic & AI Core',         'subtitle' => 'Executive briefings, orchestration and deep reasoning', 'icon' => '🎯', 'color' => '#00aaff'],
    'commercial' => ['title' => 'Commercial & Logistics',      'subtitle' => 'Sales, support, email, CRM, shipping and defence',       'icon' => '💼', 'color' => '#3b82f6'],
    'operations' => ['title' => 'Operations & Finance',        'subtitle' => 'SAP, port ops, documents, accounting and procurement',   'icon' => '⚙️', 'color' => '#06b6d4'],
    'rd'         => ['title' => 'R&D · Engineering · IP',      'subtitle' => 'Product development, patents, quantum and research',      'icon' => '🔬', 'color' => '#f97316'],
    'security'   => ['title' => 'Security · Automation · Ops', 'subtitle' => 'Cybersecurity, encryption, web automation and batching',  'icon' => '🔐', 'color' => '#ef4444'],
];

// Group agents by category in order
$grouped = [];
foreach ($categories as $catKey => $catMeta) {
    $grouped[$catKey] = array_values(array_filter($agents, fn($a) => $a['category'] === $catKey));
}
@endphp

<div class="categories-wrap" id="categoriesWrap">

@foreach($categories as $catKey => $cat)
    <section class="category-section" data-category="{{ $catKey }}">
        <div class="category-header" style="--cat-color: {{ $cat['color'] }}">
            <div class="category-icon">{{ $cat['icon'] }}</div>
            <div>
                <div class="category-title">{{ $cat['title'] }}</div>
                <div class="category-subtitle">{{ $cat['subtitle'] }}</div>
            </div>
            <span class="category-count">{{ count($grouped[$catKey]) }} agent{{ count($grouped[$catKey]) === 1 ? '' : 's' }}</span>
        </div>

        <div class="agents-grid">
            @foreach($grouped[$catKey] as $agent)
                @php
                    $href    = isset($agent['special']) && $agent['key'] === 'briefing' ? '/briefing' : '/chat?agent=' . $agent['key'];
                    $imgPath = null;
                    foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
                        if (file_exists(public_path('images/agents/' . $agent['key'] . $ext))) {
                            $imgPath = '/images/agents/' . $agent['key'] . $ext;
                            break;
                        }
                    }
                    $isSpecial = isset($agent['special']) && $agent['special'];
                    // Search index: everything a user might type to find this agent
                    $searchIdx = mb_strtolower($agent['name'] . ' ' . $agent['role'] . ' ' . $agent['key'] . ' ' . $catKey);
                @endphp

                <a href="{{ $href }}"
                   class="agent-card {{ $isSpecial ? 'briefing-card' : '' }}"
                   data-search="{{ $searchIdx }}"
                   data-agent-key="{{ $agent['key'] }}"
                   style="--card-color: {{ $agent['color'] }}">
                    <div class="status-dot" style="{{ $isSpecial ? 'background:#00aaff;box-shadow:0 0 8px #00aaff' : 'background:' . $agent['color'] . ';box-shadow:0 0 6px ' . $agent['color'] }}"></div>
                    <div class="avatar">
                        @if($imgPath)
                            <img src="{{ $imgPath }}" alt="{{ $agent['name'] }}">
                        @else
                            {{ $agent['emoji'] }}
                        @endif
                    </div>
                    <div class="agent-name">{{ $agent['name'] }}</div>
                    <div class="agent-role">{{ $agent['role'] }}</div>
                    @if($isSpecial)
                        <span class="talk-btn">Generate Briefing →</span>
                    @else
                        <span class="talk-btn">Chat</span>
                    @endif
                </a>
            @endforeach
        </div>
    </section>
@endforeach

<div class="empty-state" id="emptyState">
    <div class="big">🔍</div>
    <h3>Nenhum agente encontrado</h3>
    <p>Tenta outras palavras-chave (ex: "fatura", "patent", "NSN", "UPS", "naval")</p>
</div>

</div>

<script>
// ── THEME TOGGLE ──────────────────────────────────────────
(function () {
    const root = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    if (!btn) return;

    // Default to dark if nothing set
    const current = root.getAttribute('data-theme') || 'dark';
    if (!root.hasAttribute('data-theme')) root.setAttribute('data-theme', current);

    btn.addEventListener('click', () => {
        const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('cy-theme', next); } catch (e) {}
    });
})();

// ── SEARCH FILTER ─────────────────────────────────────────
(function () {
    const input = document.getElementById('agentSearch');
    const clear = document.getElementById('searchClear');
    const empty = document.getElementById('emptyState');
    const sections = document.querySelectorAll('.category-section');
    if (!input) return;

    function norm(s) {
        return (s || '').toString().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, ''); // strip accents
    }

    function applyFilter(q) {
        const query = norm(q.trim());
        let totalVisible = 0;

        sections.forEach(section => {
            const cards = section.querySelectorAll('.agent-card');
            let visibleInSection = 0;
            cards.forEach(card => {
                const idx = norm(card.getAttribute('data-search') || '');
                const matches = !query || idx.includes(query);
                card.classList.toggle('hidden-by-search', !matches);
                if (matches) visibleInSection++;
            });
            section.classList.toggle('empty', visibleInSection === 0);
            totalVisible += visibleInSection;

            // Update category count on-the-fly if searching
            const countEl = section.querySelector('.category-count');
            if (countEl) {
                if (query) {
                    countEl.textContent = visibleInSection + ' of ' + cards.length;
                } else {
                    countEl.textContent = cards.length + (cards.length === 1 ? ' agent' : ' agents');
                }
            }
        });

        empty.classList.toggle('visible', totalVisible === 0);
        clear.classList.toggle('visible', !!query);
    }

    input.addEventListener('input', (e) => applyFilter(e.target.value));
    clear.addEventListener('click', () => {
        input.value = '';
        applyFilter('');
        input.focus();
    });

    // '/' key focuses search (power users)
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && document.activeElement !== input && !e.metaKey && !e.ctrlKey && !e.altKey) {
            e.preventDefault();
            input.focus();
            input.select();
        }
        if (e.key === 'Escape' && document.activeElement === input) {
            input.value = '';
            applyFilter('');
            input.blur();
        }
    });
})();
</script>

</body>
</html>
