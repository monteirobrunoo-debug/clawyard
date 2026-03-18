<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard Admin — Conversa #{{ $conversation->id }}</title>
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
        .logout-btn { font-size: 12px; color: #555; background: none; border: 1px solid #333; padding: 6px 14px; border-radius: 8px; cursor: pointer; }

        .main { padding: 32px; max-width: 900px; margin: 0 auto; }
        .back-btn { display: inline-flex; align-items: center; gap: 6px; color: #555; text-decoration: none; font-size: 13px; margin-bottom: 20px; }
        .back-btn:hover { color: #76b900; }
        .page-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
        .meta { font-size: 12px; color: #555; margin-bottom: 24px; display: flex; gap: 16px; flex-wrap: wrap; }
        .meta span { background: #1a1a1a; padding: 4px 10px; border-radius: 8px; }

        .messages { display: flex; flex-direction: column; gap: 16px; }
        .msg { padding: 16px 20px; border-radius: 14px; max-width: 85%; }
        .msg.user { background: #1a1a1a; border: 1px solid #2a2a2a; align-self: flex-end; }
        .msg.assistant { background: rgba(118,185,0,0.08); border: 1px solid rgba(118,185,0,0.2); align-self: flex-start; }
        .msg-header { font-size: 11px; color: #555; margin-bottom: 8px; display: flex; justify-content: space-between; }
        .msg-content { font-size: 14px; line-height: 1.6; white-space: pre-wrap; }
        .msg-agent { color: #76b900; font-weight: 600; }
    </style>
</head>
<body>

<header class="header">
    <span class="logo">🐾 ClawYard</span>
    <span class="badge">ADMIN</span>
    <nav class="nav">
        <a href="/admin/users">👥 Utilizadores</a>
        <a href="/admin/conversations" class="active">💬 Conversas</a>
        <a href="/dashboard">🏠 Dashboard</a>
    </nav>
    <div class="user-info">
        <span style="font-size:13px;color:#aaa">{{ Auth::user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-btn">Sair</button>
        </form>
    </div>
</header>

<div class="main">
    <a href="/admin/conversations" class="back-btn">← Voltar às Conversas</a>

    <h1 class="page-title">💬 Conversa #{{ $conversation->id }}</h1>
    <div class="meta">
        <span>Canal: {{ strtoupper($conversation->channel) }}</span>
        <span>Agente: {{ $conversation->agent }}</span>
        <span>{{ $conversation->messages->count() }} mensagens</span>
        @if($conversation->phone) <span>📞 {{ $conversation->phone }}</span> @endif
        @if($conversation->email) <span>✉️ {{ $conversation->email }}</span> @endif
        <span>{{ $conversation->created_at->format('d/m/Y H:i') }}</span>
    </div>

    <div class="messages">
        @foreach($conversation->messages as $msg)
        <div class="msg {{ $msg->role }}">
            <div class="msg-header">
                <span class="{{ $msg->role === 'assistant' ? 'msg-agent' : '' }}">
                    {{ $msg->role === 'user' ? '👤 Utilizador' : '🤖 ' . ($msg->agent ?? $conversation->agent) }}
                </span>
                <span>{{ $msg->created_at->format('H:i:s') }}</span>
            </div>
            <div class="msg-content">{{ $msg->content }}</div>
        </div>
        @endforeach
    </div>
</div>

</body>
</html>
