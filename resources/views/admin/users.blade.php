<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard Admin — Utilizadores</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0a0a0a; color: #e5e5e5; font-family: system-ui, sans-serif; min-height: 100vh; }

        .header {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 32px; border-bottom: 1px solid #1e1e1e; background: #111;
        }
        .logo { font-size: 20px; font-weight: 800; color: #76b900; }
        .badge { font-size: 11px; background: #ff4444; color: #fff; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .nav { display: flex; gap: 8px; margin-left: 16px; }
        .nav a {
            font-size: 13px; color: #555; text-decoration: none;
            padding: 6px 14px; border-radius: 8px; transition: all 0.2s;
        }
        .nav a:hover, .nav a.active { color: #e5e5e5; background: #1a1a1a; }
        .user-info { margin-left: auto; display: flex; align-items: center; gap: 12px; }
        .user-name { font-size: 13px; color: #aaa; }
        .logout-btn {
            font-size: 12px; color: #555; background: none; border: 1px solid #333;
            padding: 6px 14px; border-radius: 8px; cursor: pointer;
        }
        .logout-btn:hover { color: #e5e5e5; border-color: #555; }

        .main { padding: 32px; max-width: 1200px; margin: 0 auto; }

        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-title { font-size: 24px; font-weight: 700; }

        .alert-success {
            background: rgba(118,185,0,0.1); border: 1px solid rgba(118,185,0,0.3);
            color: #76b900; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px;
        }
        .alert-error {
            background: rgba(255,68,68,0.1); border: 1px solid rgba(255,68,68,0.3);
            color: #ff6666; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px;
        }

        .filters {
            display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .filters input, .filters select {
            background: #1a1a1a; border: 1px solid #2a2a2a; color: #e5e5e5;
            padding: 10px 14px; border-radius: 10px; font-size: 13px; outline: none;
        }
        .filters input:focus, .filters select:focus { border-color: #76b900; }
        .filters button {
            background: #76b900; color: #000; border: none; padding: 10px 20px;
            border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;
        }

        .table-card {
            background: #111; border: 1px solid #1e1e1e; border-radius: 16px; overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #0f0f0f; }
        th { padding: 14px 16px; text-align: left; font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 14px 16px; font-size: 13px; border-top: 1px solid #1a1a1a; }
        tr:hover td { background: #131313; }

        .role-badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
        }
        .role-admin   { background: rgba(255,68,68,0.2); color: #ff6666; }
        .role-manager { background: rgba(255,200,0,0.2); color: #ffd700; }
        .role-user    { background: rgba(118,185,0,0.2); color: #76b900; }
        .role-guest   { background: rgba(100,100,100,0.2); color: #888; }

        .status-active   { color: #76b900; font-size: 12px; }
        .status-inactive { color: #ff4444; font-size: 12px; }

        .action-btn {
            font-size: 11px; padding: 5px 12px; border-radius: 6px; cursor: pointer;
            border: 1px solid #2a2a2a; background: #1a1a1a; color: #aaa; transition: all 0.2s;
            text-decoration: none; display: inline-block;
        }
        .action-btn:hover { border-color: #76b900; color: #76b900; }
        .action-btn.danger:hover { border-color: #ff4444; color: #ff4444; }
        .action-btn.toggle-on { border-color: #ff4444; color: #ff4444; }
        .action-btn.toggle-off { border-color: #76b900; color: #76b900; }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8);
            z-index: 100; align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #111; border: 1px solid #2a2a2a; border-radius: 20px;
            padding: 32px; width: 100%; max-width: 460px;
        }
        .modal h2 { font-size: 18px; font-weight: 700; margin-bottom: 24px; color: #e5e5e5; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 11px; color: #666; margin-bottom: 6px; font-weight: 600; text-transform: uppercase; }
        .form-group input, .form-group select {
            width: 100%; background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 10px; padding: 11px 14px; color: #e5e5e5; font-size: 14px; outline: none;
        }
        .form-group input:focus, .form-group select:focus { border-color: #76b900; }
        .modal-actions { display: flex; gap: 12px; margin-top: 24px; }
        .btn-primary {
            flex: 1; background: #76b900; color: #000; border: none; padding: 12px;
            border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer;
        }
        .btn-secondary {
            flex: 1; background: none; color: #aaa; border: 1px solid #2a2a2a;
            padding: 12px; border-radius: 10px; font-size: 14px; cursor: pointer;
        }

        .pagination { display: flex; gap: 8px; margin-top: 20px; justify-content: center; }
        .pagination a, .pagination span {
            padding: 8px 14px; border-radius: 8px; font-size: 13px;
            background: #111; border: 1px solid #1e1e1e; color: #aaa; text-decoration: none;
        }
        .pagination .active { background: #76b900; color: #000; border-color: #76b900; font-weight: 700; }

        .stats-bar {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: #111; border: 1px solid #1e1e1e; border-radius: 12px; padding: 16px 20px;
        }
        .stat-card .label { font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 28px; font-weight: 800; color: #76b900; margin-top: 4px; }

        /* ── LIGHT THEME OVERRIDES ─────────────────────────────── */
        html[data-theme="light"] body { background:#f8fafc; color:#1f2937; }
        html[data-theme="light"] .header { background:#ffffff; border-bottom-color:#e5e7eb; }
        html[data-theme="light"] .nav a { color:#6b7280; }
        html[data-theme="light"] .nav a:hover, html[data-theme="light"] .nav a.active { color:#1f2937; background:#f1f5f9; }
        html[data-theme="light"] .user-name { color:#374151; }
        html[data-theme="light"] .logout-btn { color:#6b7280; border-color:#e5e7eb; }
        html[data-theme="light"] .logout-btn:hover { color:#1f2937; border-color:#9ca3af; }
        html[data-theme="light"] .filters input, html[data-theme="light"] .filters select {
            background:#ffffff; border-color:#d1d5db; color:#1f2937;
        }
        html[data-theme="light"] .table-card { background:#ffffff; border-color:#e5e7eb; }
        html[data-theme="light"] thead { background:#f8fafc; }
        html[data-theme="light"] th { color:#6b7280; }
        html[data-theme="light"] td { border-top-color:#f1f5f9; }
        html[data-theme="light"] tr:hover td { background:#f8fafc; }
        html[data-theme="light"] .action-btn { background:#f8fafc; border-color:#e5e7eb; color:#374151; }
        html[data-theme="light"] .action-btn:hover { border-color:#76b900; color:#059669; }
        html[data-theme="light"] .modal-overlay { background:rgba(15,23,42,.5); }
        html[data-theme="light"] .modal { background:#ffffff; border-color:#e5e7eb; }
        html[data-theme="light"] .modal h2 { color:#1f2937; }
        html[data-theme="light"] .form-group label { color:#6b7280; }
        html[data-theme="light"] .form-group input, html[data-theme="light"] .form-group select {
            background:#f8fafc; border-color:#d1d5db; color:#1f2937;
        }
        html[data-theme="light"] .btn-secondary { color:#6b7280; border-color:#e5e7eb; }
        html[data-theme="light"] .pagination a, html[data-theme="light"] .pagination span {
            background:#ffffff; border-color:#e5e7eb; color:#374151;
        }
        html[data-theme="light"] .stat-card { background:#ffffff; border-color:#e5e7eb; }
        html[data-theme="light"] .stat-card .label { color:#6b7280; }
    </style>
</head>
<body>

<header class="header">
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:36px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));"></a>
    <span class="badge">ADMIN</span>
    <nav class="nav">
        <a href="/admin/users" class="active">👥 Utilizadores</a>
        <a href="/admin/agent-access">🎭 Agentes × Users</a>
        <a href="/admin/conversations">💬 Conversas</a>
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

    @if(session('success'))
        <div class="alert-success">✅ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert-error">❌ {{ $errors->first() }}</div>
    @endif

    <!-- Stats bar -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="label">Total Users</div>
            <div class="value">{{ $users->total() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Admins</div>
            <div class="value">{{ \App\Models\User::where('role','admin')->count() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Ativos</div>
            <div class="value">{{ \App\Models\User::where('is_active',true)->count() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Bloqueados</div>
            <div class="value" style="color:#ff4444">{{ \App\Models\User::where('is_active',false)->count() }}</div>
        </div>
    </div>

    <div class="page-header">
        <h1 class="page-title">👥 Utilizadores</h1>
        <button class="action-btn" style="background:#76b900;color:#000;border-color:#76b900;padding:10px 20px;font-size:13px;font-weight:700" onclick="document.getElementById('create-modal').classList.add('open')">
            + Novo Utilizador
        </button>
    </div>

    <!-- Filters -->
    <form method="GET" action="/admin/users">
        <div class="filters">
            <input type="text" name="search" placeholder="🔍 Pesquisar nome ou email..." value="{{ request('search') }}" style="flex:1;min-width:200px">
            <select name="role">
                <option value="">Todos os roles</option>
                <option value="admin"   {{ request('role') === 'admin'   ? 'selected' : '' }}>🔴 Admin</option>
                <option value="manager" {{ request('role') === 'manager' ? 'selected' : '' }}>🟡 Manager</option>
                <option value="user"    {{ request('role') === 'user'    ? 'selected' : '' }}>🟢 User</option>
                <option value="guest"   {{ request('role') === 'guest'   ? 'selected' : '' }}>⚪ Guest</option>
            </select>
            <button type="submit">Filtrar</button>
        </div>
    </form>

    <!-- Table -->
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Conversas</th>
                    <th>Ultimo Login</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td style="font-weight:600">{{ $user->name }}</td>
                    <td style="color:#888">{{ $user->email }}</td>
                    <td>
                        <span class="role-badge role-{{ $user->role }}">{{ strtoupper($user->role) }}</span>
                    </td>
                    <td>
                        @if($user->is_active)
                            <span class="status-active">✅ Ativo</span>
                        @else
                            <span class="status-inactive">🔴 Bloqueado</span>
                        @endif
                    </td>
                    <td style="color:#555">{{ $user->conversations_count }}</td>
                    <td style="color:#555;font-size:12px">
                        {{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Nunca' }}
                    </td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap">
                        <button class="action-btn"
                            onclick="editUser({{ $user->id }}, '{{ $user->name }}', '{{ $user->email }}', '{{ $user->role }}', {{ $user->is_active ? 1 : 0 }})">
                            Editar
                        </button>
                        @if($user->id !== Auth::id())
                        <form method="POST" action="/admin/users/{{ $user->id }}/toggle" style="display:inline">
                            @csrf @method('PATCH')
                            <button type="submit" class="action-btn {{ $user->is_active ? 'toggle-on' : 'toggle-off' }}">
                                {{ $user->is_active ? 'Bloquear' : 'Ativar' }}
                            </button>
                        </form>
                        <form method="POST" action="/admin/users/{{ $user->id }}" style="display:inline"
                            onsubmit="return confirm('Apagar {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="action-btn danger">Apagar</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="pagination">
        {{ $users->appends(request()->query())->links() }}
    </div>

</div>

<!-- Create Modal -->
<div class="modal-overlay" id="create-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <h2>+ Novo Utilizador</h2>
        <form method="POST" action="/admin/users">
            @csrf
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="name" required placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="email@empresa.com">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required minlength="8" placeholder="Min. 8 caracteres">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" required>
                    <option value="user">🟢 User</option>
                    <option value="manager">🟡 Manager</option>
                    <option value="guest">⚪ Guest</option>
                    <option value="admin">🔴 Admin</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="document.getElementById('create-modal').classList.remove('open')">Cancelar</button>
                <button type="submit" class="btn-primary">Criar</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="edit-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <h2>✏️ Editar Utilizador</h2>
        <form method="POST" id="edit-form" action="">
            @csrf @method('PATCH')
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="name" id="edit-name" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" id="edit-email" required>
            </div>
            <div class="form-group">
                <label>Nova Password (opcional)</label>
                <input type="password" name="password" placeholder="Deixar vazio para nao alterar">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="edit-role">
                    <option value="user">🟢 User</option>
                    <option value="manager">🟡 Manager</option>
                    <option value="guest">⚪ Guest</option>
                    <option value="admin">🔴 Admin</option>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="is_active" id="edit-active" value="1" style="width:auto">
                <label style="margin:0;text-transform:none;font-size:13px;color:#aaa">Conta Ativa</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="document.getElementById('edit-modal').classList.remove('open')">Cancelar</button>
                <button type="submit" class="btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(id, name, email, role, isActive) {
    document.getElementById('edit-form').action = '/admin/users/' + id;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-email').value = email;
    document.getElementById('edit-role').value = role;
    document.getElementById('edit-active').checked = isActive === 1;
    document.getElementById('edit-modal').classList.add('open');
}
</script>

@include('partials.theme-button')
</body>
</html>
