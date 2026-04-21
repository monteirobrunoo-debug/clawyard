<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard Admin — Conversas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0a0a0a; color: #e5e5e5; font-family: system-ui, sans-serif; min-height: 100vh; }
        .header { display: flex; align-items: center; gap: 12px; padding: 16px 32px; border-bottom: 1px solid #1e1e1e; background: #111; }
        .logo { font-size: 20px; font-weight: 800; color: #76b900; }
        .badge { font-size: 11px; background: #ff4444; color: #fff; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .nav { display: flex; gap: 8px; margin-left: 16px; }
        .nav a { font-size: 13px; color: #555; text-decoration: none; padding: 6px 14px; border-radius: 8px; }
        .nav a:hover, .nav a.active { color: #e5e5e5; background: #1a1a1a; }
        .user-info { margin-left: auto; display: flex; align-items: center; gap: 12px; }
        .user-name { font-size: 13px; color: #aaa; }
        .logout-btn { font-size: 12px; color: #555; background: none; border: 1px solid #333; padding: 6px 14px; border-radius: 8px; cursor: pointer; }

        .main { padding: 32px; max-width: 1200px; margin: 0 auto; }
        .page-title { font-size: 24px; font-weight: 700; margin-bottom: 24px; }

        .table-card { background: #111; border: 1px solid #1e1e1e; border-radius: 16px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #0f0f0f; }
        th { padding: 14px 16px; text-align: left; font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 14px 16px; font-size: 13px; border-top: 1px solid #1a1a1a; }
        tr:hover td { background: #131313; }

        .channel-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .channel-web       { background: rgba(118,185,0,0.2); color: #76b900; }
        .channel-whatsapp  { background: rgba(37,211,102,0.2); color: #25d366; }
        .channel-email     { background: rgba(66,133,244,0.2); color: #4285f4; }

        .view-btn { font-size: 11px; padding: 5px 12px; border-radius: 6px; cursor: pointer; border: 1px solid #2a2a2a; background: #1a1a1a; color: #aaa; text-decoration: none; }
        .view-btn:hover { border-color: #76b900; color: #76b900; }

        /* ── LIGHT THEME OVERRIDES ─────────────────────────────── */
        html[data-theme="light"] body { background:#f8fafc; color:#1f2937; }
        html[data-theme="light"] .header { background:#ffffff; border-bottom-color:#e5e7eb; }
        html[data-theme="light"] .nav a { color:#6b7280; }
        html[data-theme="light"] .nav a:hover, html[data-theme="light"] .nav a.active { color:#1f2937; background:#f1f5f9; }
        html[data-theme="light"] .user-name { color:#374151; }
        html[data-theme="light"] .logout-btn { color:#6b7280; border-color:#e5e7eb; }
        html[data-theme="light"] .table-card { background:#ffffff; border-color:#e5e7eb; }
        html[data-theme="light"] thead { background:#f8fafc; }
        html[data-theme="light"] th { color:#6b7280; }
        html[data-theme="light"] td { border-top-color:#f1f5f9; color:#374151; }
        html[data-theme="light"] tr:hover td { background:#f8fafc; }
        html[data-theme="light"] .view-btn { background:#f8fafc; border-color:#e5e7eb; color:#374151; }
    </style>
</head>
<body>

<header class="header">
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:36px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));"></a>
    <span class="badge">ADMIN</span>
    <nav class="nav">
        <a href="/admin/users">👥 Utilizadores</a>
        <a href="/admin/conversations" class="active">💬 Conversas</a>
        <a href="/dashboard">🏠 Dashboard</a>
    </nav>
    <div class="user-info">
        <span class="user-name">{{ Auth::user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-btn">Sair</button>
        </form>
        <button id="cyThemeBtn" class="cy-theme-btn" type="button" aria-label="Toggle theme">☀️</button>
    </div>
</header>

<div class="main">
    <h1 class="page-title">💬 Todas as Conversas</h1>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Canal</th>
                    <th>Agente</th>
                    <th>Mensagens</th>
                    <th>Telefone / Email</th>
                    <th>Data</th>
                    <th>Ver</th>
                </tr>
            </thead>
            <tbody>
                @foreach($conversations as $conv)
                <tr>
                    <td style="color:#555;font-size:11px">#{{ $conv->id }}</td>
                    <td><span class="channel-badge channel-{{ $conv->channel }}">{{ strtoupper($conv->channel) }}</span></td>
                    <td style="font-weight:600">{{ $conv->agent }}</td>
                    <td style="color:#76b900">{{ $conv->messages->count() }}</td>
                    <td style="color:#555;font-size:12px">{{ $conv->phone ?: $conv->email ?: $conv->session_id }}</td>
                    <td style="color:#555;font-size:12px">{{ $conv->created_at->diffForHumans() }}</td>
                    <td><a href="/admin/conversations/{{ $conv->id }}" class="view-btn">Ver</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top:20px">{{ $conversations->links() }}</div>
</div>

@include('partials.theme-button')
</body>
</html>
