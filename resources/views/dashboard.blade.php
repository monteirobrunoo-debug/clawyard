<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard — Agents</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #0a0a0a; color: #e5e5e5; font-family: system-ui, sans-serif; min-height: 100vh; }

        .header {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 32px; border-bottom: 1px solid #1e1e1e; background: #111;
        }
        .logo { font-size: 22px; font-weight: 800; color: #76b900; }
        .badge { font-size: 11px; background: #76b900; color: #000; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .user-info { margin-left: auto; display: flex; align-items: center; gap: 16px; }
        .user-name { font-size: 13px; color: #aaa; }
        .logout-btn {
            font-size: 12px; color: #555; background: none; border: 1px solid #333;
            padding: 6px 14px; border-radius: 8px; cursor: pointer; transition: all 0.2s;
        }
        .logout-btn:hover { color: #e5e5e5; border-color: #555; }

        .hero { text-align: center; padding: 48px 32px 32px; }
        .hero h1 { font-size: 36px; font-weight: 800; color: #76b900; margin-bottom: 8px; }
        .hero p { font-size: 15px; color: #555; }

        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px; padding: 0 32px 48px;
            max-width: 1200px; margin: 0 auto;
        }

        .agent-card {
            background: #111; border: 1px solid #1e1e1e; border-radius: 20px;
            padding: 28px 20px 20px; text-align: center; cursor: pointer;
            transition: all 0.2s; text-decoration: none; display: block; position: relative;
        }

        .agent-card:hover {
            border-color: #76b900; transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(118, 185, 0, 0.15);
        }

        .agent-card .avatar {
            width: 80px; height: 80px; border-radius: 50%; margin: 0 auto 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; background: #1a1a1a; border: 2px solid #2a2a2a; overflow: hidden;
        }

        .agent-card .avatar img { width: 100%; height: 100%; object-fit: cover; }

        .agent-card .agent-name { font-size: 15px; font-weight: 700; color: #e5e5e5; margin-bottom: 6px; }
        .agent-card .agent-role { font-size: 12px; color: #555; margin-bottom: 16px; line-height: 1.4; }

        .talk-btn {
            display: inline-block; background: #76b900; color: #000;
            padding: 8px 20px; border-radius: 20px; font-size: 12px; font-weight: 700;
        }

        .agent-card:hover .talk-btn { background: #8fd400; }

        .status-dot {
            position: absolute; top: 16px; right: 16px;
            width: 8px; height: 8px; background: #76b900;
            border-radius: 50%; animation: blink 2s infinite;
        }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .section-title {
            text-align: center; font-size: 11px; color: #333;
            text-transform: uppercase; letter-spacing: 2px; margin: 0 32px 24px;
        }
    </style>
</head>
<body>

<header class="header">
    <span class="logo">🐾 ClawYard</span>
    <span class="badge">NVIDIA NeMo</span>
    <div class="user-info">
        <a href="/discoveries" style="font-size:12px;color:#aaa;text-decoration:none;border:1px solid #333;padding:5px 12px;border-radius:8px;">🔬 Descobertas</a>
        <a href="/reports" style="font-size:12px;color:#aaa;text-decoration:none;border:1px solid #333;padding:5px 12px;border-radius:8px;">📋 Relatórios</a>
        <a href="/schedules" style="font-size:12px;color:#aaa;text-decoration:none;border:1px solid #333;padding:5px 12px;border-radius:8px;">🗓️ Schedule</a>
        @if(Auth::user()->isAdmin())
            <a href="/admin/users" style="font-size:12px;color:#ff6666;text-decoration:none;border:1px solid #ff4444;padding:5px 12px;border-radius:8px;">⚙️ Admin</a>
        @endif
        <span class="user-name">{{ Auth::user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-btn">Sair</button>
        </form>
    </div>
</header>

<div class="hero">
    <h1>Escolha o seu Agente</h1>
    <p>Powered by NVIDIA NeMo + Claude — 11 agentes especializados prontos a ajudar</p>
</div>

<p class="section-title">Agentes Disponiveis</p>

<div class="agents-grid">

    @php
    $agents = [
        ['key' => 'orchestrator', 'name' => 'Todos os Agentes',        'emoji' => '🌐', 'role' => 'Orquestrador — activa multiplos agentes em paralelo'],
        ['key' => 'sales',        'name' => 'Marco Sales',             'emoji' => '💼', 'role' => 'Vendas MTU · CAT · MAK · Jenbacher · SKF · Schottel'],
        ['key' => 'support',      'name' => 'Marcus Suporte',             'emoji' => '🔧', 'role' => 'Suporte Tecnico — avarias de motores e sistemas'],
        ['key' => 'email',        'name' => 'Daniel Email',            'emoji' => '📧', 'role' => 'Email maritimo — armadores, agentes e navios'],
        ['key' => 'sap',          'name' => 'Richard SAP',             'emoji' => '📊', 'role' => 'SAP B1 — stock, facturas e ERP'],
        ['key' => 'document',     'name' => 'Comandante Doc',          'emoji' => '📄', 'role' => 'Documentos — analisa PDFs e contratos tecnicos'],
        ['key' => 'maritime',     'name' => 'Capitao Porto',           'emoji' => '🚢', 'role' => 'Maritimo — portos europeus e concorrentes'],
        ['key' => 'claude',       'name' => 'Bruno AI',                'emoji' => '🧠', 'role' => 'Claude — raciocinio avancado e analise complexa'],
        ['key' => 'nvidia',       'name' => 'Carlos NVIDIA',           'emoji' => '⚡', 'role' => 'NVIDIA NeMo — velocidade e eficiencia maxima'],
        ['key' => 'aria',         'name' => 'ARIA Security',           'emoji' => '🔐', 'role' => 'Ciberseguranca — STRIDE, OWASP, scan diario dos sites'],
        ['key' => 'quantum',      'name' => 'Prof. Quantum Leap',      'emoji' => '⚛️', 'role' => 'Quantum — analise diaria de papers arXiv sobre quantum'],
    ];
    @endphp

    {{-- Briefing Executivo — card especial que vai para /briefing --}}
    <a href="/briefing" class="agent-card" style="border-color:#002244;background:linear-gradient(135deg,#001a2a,#0a0a0a)">
        <div class="status-dot" style="background:#00aaff;box-shadow:0 0 6px #00aaff"></div>
        <div class="avatar" style="background:#001a2a;border:2px solid #002244;overflow:hidden">
            <img src="/images/agents/briefing.png" alt="Renato" style="width:100%;height:100%;object-fit:cover">
        </div>
        <div class="agent-name" style="color:#00aaff">Estratega Renato</div>
        <div class="agent-role">Briefing executivo — reúne Quantum, ARIA, Sales e todos os agentes num plano de acção com PDF</div>
        <div class="talk-btn" style="background:#002244;color:#00aaff;border:1px solid #004488">Gerar Briefing →</div>
    </a>

    @foreach($agents as $agent)
    <a href="/chat?agent={{ $agent['key'] }}" class="agent-card">
        <div class="status-dot"></div>
        <div class="avatar">
            @php
                $imgPath = null;
                foreach (['.png', '.jpg', '.jpeg', '.webp'] as $ext) {
                    if (file_exists(public_path('images/agents/' . $agent['key'] . $ext))) {
                        $imgPath = '/images/agents/' . $agent['key'] . $ext;
                        break;
                    }
                }
            @endphp
            @if($imgPath)
                <img src="{{ $imgPath }}" alt="{{ $agent['name'] }}">
            @else
                {{ $agent['emoji'] }}
            @endif
        </div>
        <div class="agent-name">{{ $agent['name'] }}</div>
        <div class="agent-role">{{ $agent['role'] }}</div>
        <span class="talk-btn">Falar</span>
    </a>
    @endforeach

</div>

</body>
</html>
