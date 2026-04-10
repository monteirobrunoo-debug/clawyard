<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard — Agentes</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green: #76b900;
            --bg: #0a0a0a;
            --bg2: #111;
            --bg3: #1a1a1a;
            --border: #1e1e1e;
            --border2: #2a2a2a;
            --text: #e5e5e5;
            --muted: #555;
        }

        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

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
        .nav-link:hover { color: var(--text); border-color: #444; }
        .nav-link.admin { color: #ff6666; border-color: #ff4444; }
        .nav-link.admin:hover { background: #1a0000; }
        .nav-link.briefing { color: var(--green); border-color: #1e3300; background: #0d1a00; font-weight: 700; }
        .nav-link.briefing:hover { background: #132400; }
        .user-name { font-size: 13px; color: #888; font-weight: 500; }
        .logout-form { display: inline; }
        .logout-btn {
            font-size: 12px; color: var(--muted); background: none; border: 1px solid var(--border2);
            padding: 5px 12px; border-radius: 8px; cursor: pointer; transition: all 0.15s;
        }
        .logout-btn:hover { color: var(--text); border-color: #444; }

        /* ── HERO ── */
        .hero { text-align: center; padding: 52px 32px 36px; }
        .hero-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 2px; margin-bottom: 14px; }
        .hero h1 { font-size: 38px; font-weight: 800; color: var(--text); margin-bottom: 10px; letter-spacing: -1px; }
        .hero h1 span { color: var(--green); }
        .hero p { font-size: 14px; color: var(--muted); max-width: 480px; margin: 0 auto; line-height: 1.6; }

        /* ── SECTION ── */
        .section-title {
            text-align: center; font-size: 10px; color: #2a2a2a;
            text-transform: uppercase; letter-spacing: 2px; margin: 0 32px 24px;
        }

        /* ── GRID ── */
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 16px; padding: 0 32px 60px;
            max-width: 1280px; margin: 0 auto;
        }

        /* ── CARD ── */
        .agent-card {
            background: var(--bg2); border: 1px solid var(--border); border-radius: 18px;
            padding: 28px 20px 22px; text-align: center; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
            text-decoration: none; display: block; position: relative; overflow: hidden;
        }
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

        .agent-name { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
        .agent-role { font-size: 12px; color: #666; margin-bottom: 18px; line-height: 1.45; }

        .talk-btn {
            display: inline-block; background: var(--green); color: #000;
            padding: 8px 22px; border-radius: 20px; font-size: 12px; font-weight: 700;
            transition: background 0.15s, transform 0.15s;
        }
        .agent-card:hover .talk-btn {
            background: #8fd400; transform: scale(1.05);
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
        .briefing-card .agent-name { color: #00aaff; }
        .briefing-card .talk-btn { background: #003366; color: #00aaff; border: 1px solid #0055aa; }
        .briefing-card:hover .talk-btn { background: #004488; transform: scale(1.05); }
        .briefing-card .status-dot { background: #00aaff; box-shadow: 0 0 8px #00aaff; }

        /* ── MOBILE ── */
        @media (max-width: 640px) {
            .header { padding: 10px 16px; }
            .nav-links { gap: 6px; }
            .nav-link { font-size: 11px; padding: 4px 9px; }
            .hero { padding: 32px 16px 24px; }
            .hero h1 { font-size: 26px; }
            .agents-grid { gap: 12px; padding: 0 16px 40px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .agent-card { padding: 20px 14px 16px; }
            .agent-card .avatar { width: 64px; height: 64px; font-size: 30px; }
            .agent-name { font-size: 13px; }
            .agent-role { font-size: 11px; }
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/setq-logo.svg" alt="SETQ.AI" style="height:32px;filter:drop-shadow(0 0 1px rgba(255,255,255,0.1));"></a>
    <span class="badge">NVIDIA NeMo</span>
    <div class="nav-links">
        <a href="/briefing" class="nav-link briefing">📊 Briefing</a>
        <a href="/agents/activity" class="nav-link" style="color:#76b900;border-color:#1e3300;background:#0d1a00">🤖 Actividade</a>
        <a href="/discoveries" class="nav-link">🔬 Descobertas</a>
        <a href="/patents/library" class="nav-link">🏛️ Patentes</a>
        <a href="/reports" class="nav-link">📋 Relatórios</a>
        <a href="/schedules" class="nav-link">🗓️ Schedule</a>
        @if(Auth::user()->isAdmin())
            <a href="/admin/users" class="nav-link admin">⚙️ Admin</a>
        @endif
        <span class="user-name">{{ Auth::user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}" class="logout-form">
            @csrf
            <button type="submit" class="logout-btn">Sair</button>
        </form>
    </div>
</header>

<div class="hero">
    <p class="hero-label">HP-Group · PartYard Marine · PartYard Military</p>
    <h1>Escolha o seu <span>Agente</span></h1>
    <p>Powered by NVIDIA NeMo + Claude — 14 agentes especializados prontos a ajudar</p>
</div>

<p class="section-title">Agentes Disponíveis</p>

<div class="agents-grid">

    @php
    $agents = [
        ['key' => 'briefing',   'name' => 'Estratega Renato',       'emoji' => '📊', 'role' => 'Briefing executivo — reúne Quantum, ARIA, Sales e todos os agentes num plano de acção', 'color' => '#00aaff',  'special' => true],
        ['key' => 'orchestrator','name' => 'Todos os Agentes',      'emoji' => '🌐', 'role' => 'Orquestrador — activa múltiplos agentes em paralelo',                                   'color' => '#76b900'],
        ['key' => 'sales',       'name' => 'Marco Sales',           'emoji' => '💼', 'role' => 'Vendas MTU · CAT · MAK · Jenbacher · SKF · Schottel',                                  'color' => '#3b82f6'],
        ['key' => 'support',     'name' => 'Marcus Suporte',        'emoji' => '🔧', 'role' => 'Suporte Técnico — avarias de motores e sistemas',                                       'color' => '#f59e0b'],
        ['key' => 'email',       'name' => 'Daniel Email',          'emoji' => '📧', 'role' => 'Email marítimo — armadores, agentes e navios',                                          'color' => '#8b5cf6'],
        ['key' => 'sap',         'name' => 'Richard SAP',           'emoji' => '📊', 'role' => 'SAP B1 — stock, facturas e ERP',                                                        'color' => '#06b6d4'],
        ['key' => 'document',    'name' => 'Comandante Doc',        'emoji' => '📄', 'role' => 'Documentos — analisa PDFs, contratos e certificados técnicos',                          'color' => '#94a3b8'],
        ['key' => 'capitao',     'name' => 'Capitão Porto',         'emoji' => '⚓', 'role' => 'Operações portuárias — escalas, documentação e logística marítima',              'color' => '#0ea5e9'],
        ['key' => 'claude',      'name' => 'Bruno AI',              'emoji' => '🧠', 'role' => 'Claude — raciocínio avançado e análise complexa',                                       'color' => '#a855f7'],
        ['key' => 'nvidia',      'name' => 'Carlos NVIDIA',         'emoji' => '⚡', 'role' => 'NVIDIA NeMo — velocidade e eficiência máxima',                                          'color' => '#76b900'],
        ['key' => 'aria',        'name' => 'ARIA Security',         'emoji' => '🔐', 'role' => 'Cibersegurança — STRIDE, OWASP, scan diário dos sites',                                 'color' => '#ef4444'],
        ['key' => 'quantum',     'name' => 'Prof. Quantum Leap',    'emoji' => '⚛️', 'role' => 'Quantum — papers arXiv + patentes USPTO para PartYard',                                 'color' => '#22d3ee'],
        ['key' => 'finance',     'name' => 'Dr. Luís Financeiro',   'emoji' => '💰', 'role' => 'ROC · TOC · PhD Gestão Bancária — Contabilidade, Auditoria e Fiscalidade',              'color' => '#10b981'],
        ['key' => 'research',    'name' => 'Marina Research',       'emoji' => '🔍', 'role' => 'Inteligência competitiva — benchmarking, mercado e melhorias do site',                  'color' => '#f97316'],
        ['key' => 'acingov',     'name' => 'Dra. Ana Contratos',    'emoji' => '🏛️', 'role' => 'Contratação pública — concursos Acingov classificados por relevância para PartYard',       'color' => '#f59e0b'],
        ['key' => 'engineer',    'name' => 'Eng. Victor I&D',       'emoji' => '🔩', 'role' => 'I&D e Desenvolvimento de Produto — planos TRL, CAPEX, roadmap para novos equipamentos PartYard', 'color' => '#f97316'],
        ['key' => 'patent',      'name' => 'Dra. Sofia IP',         'emoji' => '🏛️', 'role' => 'Propriedade Intelectual — validação de patentes, prior art EPO/USPTO, patenteabilidade e FTO', 'color' => '#8b5cf6'],
        ['key' => 'energy',      'name' => 'Eng. Sofia Energia',    'emoji' => '⚡', 'role' => 'Descarbonização marítima — Fuzzy TOPSIS, CII/EEXI, LNG/Biofuel/H2, Fleet Energy Management', 'color' => '#10b981'],
        ['key' => 'kyber',       'name' => 'KYBER Encryption',      'emoji' => '🔒', 'role' => 'Encriptação post-quantum — Kyber-1024 + AES-256-GCM, geração de chaves e emails encriptados',    'color' => '#76b900'],
    ];
    @endphp

    @foreach($agents as $agent)
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
    @endphp

    <a href="{{ $href }}"
       class="agent-card {{ $isSpecial ? 'briefing-card' : '' }}"
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
        @if($agent['key'] === 'sap')
            <span class="talk-btn">Falar</span>
        @elseif($isSpecial)
            <span class="talk-btn">Gerar Briefing →</span>
        @else
            <span class="talk-btn">Falar</span>
        @endif
    </a>
    @if($agent['key'] === 'sap')
    <div style="display:flex;margin-top:-8px;margin-bottom:4px;border:1px solid rgba(6,182,212,.15);border-top:none;border-radius:0 0 12px 12px;overflow:hidden;">
        <a href="/sap/documents"
           style="flex:1;text-align:center;font-size:11px;font-weight:600;color:#06b6d4;text-decoration:none;padding:5px 0;background:rgba(6,182,212,.07);border-right:1px solid rgba(6,182,212,.15);transition:background .15s;"
           onmouseover="this.style.background='rgba(6,182,212,.14)'"
           onmouseout="this.style.background='rgba(6,182,212,.07)'"
           onclick="event.stopPropagation()">
            🗂️ Documentos
        </a>
        <a href="https://sld.partyard.privatcloud.biz/webx/index.html" target="_blank"
           style="flex:1;text-align:center;font-size:11px;font-weight:600;color:#06b6d4;text-decoration:none;padding:5px 0;background:rgba(6,182,212,.07);transition:background .15s;"
           onmouseover="this.style.background='rgba(6,182,212,.14)'"
           onmouseout="this.style.background='rgba(6,182,212,.07)'"
           onclick="event.stopPropagation()">
            🔗 SAP WebClient
        </a>
    </div>
    @endif
    @endforeach

</div>

</body>
</html>
