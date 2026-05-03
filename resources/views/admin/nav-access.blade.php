{{--
    /admin/nav-access — matrix of every active user × every nav section.

    Each cell is a clickable chip. PATCH to toggleNavAccess flips the
    bit in users.allowed_nav. Admin rows are always-on and non-toggleable.

    "Default" badge indicates the cell would be visible by role default
    when allowed_nav is NULL. Clicking it locks the row to an explicit
    whitelist — same UX as the agent-access matrix.
--}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard Admin — Visibilidade da Navegação</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0a0a0a; color: #e5e5e5; font-family: system-ui, sans-serif; min-height: 100vh; }

        .header {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 32px; border-bottom: 1px solid #1e1e1e; background: #111;
        }
        .badge { font-size: 11px; background: #ff4444; color: #fff; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .nav { display: flex; gap: 8px; margin-left: 16px; }
        .nav a { font-size: 13px; color: #555; text-decoration: none; padding: 6px 14px; border-radius: 8px; transition: all 0.2s; }
        .nav a:hover, .nav a.active { color: #e5e5e5; background: #1a1a1a; }
        .user-info { margin-left: auto; display: flex; align-items: center; gap: 12px; }

        .container { padding: 24px 32px; max-width: 1600px; margin: 0 auto; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        .subtitle { font-size: 13px; color: #888; margin-bottom: 20px; }

        .legend { font-size: 12px; color: #888; margin-bottom: 14px; display: flex; gap: 18px; flex-wrap: wrap; }
        .chip { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; }
        .chip.on     { background: #103820; color: #4ade80; border: 1px solid #2a6b3e; }
        .chip.off    { background: #2a1717; color: #f87171; border: 1px solid #5b2222; }
        .chip.always { background: #2c2316; color: #fbbf24; border: 1px solid #6b4f1c; }
        .chip.default-on  { background: #0f2a3a; color: #7dd3fc; border: 1px solid #1e4f6b; }

        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: #103820; color: #86efac; border: 1px solid #2a6b3e; }
        .flash.error { background: #2a1717; color: #fca5a5; border-color: #5b2222; }

        .matrix-wrap { overflow-x: auto; border: 1px solid #1e1e1e; border-radius: 10px; background: #0e0e0e; }
        table.matrix { border-collapse: separate; border-spacing: 0; width: max-content; }
        table.matrix th, table.matrix td {
            padding: 10px 8px; font-size: 12px; vertical-align: middle;
            border-bottom: 1px solid #1a1a1a; border-right: 1px solid #161616;
            white-space: nowrap;
        }
        table.matrix thead th { position: sticky; top: 0; background: #111; z-index: 2; font-weight: 600; color: #ccc; }
        table.matrix th:first-child, table.matrix td:first-child {
            position: sticky; left: 0; background: #0e0e0e; z-index: 1;
            min-width: 220px; max-width: 220px;
        }
        table.matrix thead th:first-child { z-index: 3; background: #111; }
        table.matrix tbody tr:hover td { background: #131313; }

        /* Section headers — horizontal text (only 15, fits fine) */
        .section-col { min-width: 80px; max-width: 80px; text-align: center; }
        .section-col-head { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 2px; font-size: 11px; color: #ccc; }
        .section-col-head .sec-emoji { font-size: 15px; }
        .section-col-head .sec-label { font-size: 10px; color: #999; writing-mode: vertical-rl; transform: rotate(180deg); }

        .user-cell { display: flex; flex-direction: column; gap: 2px; }
        .user-cell .name  { font-weight: 600; color: #e5e5e5; }
        .user-cell .email { color: #888; font-size: 11px; }
        .user-cell .role  { font-size: 10px; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.5px; }

        .cell-chip {
            display: inline-block; min-width: 28px; padding: 4px 6px;
            border-radius: 5px; cursor: pointer; user-select: none;
            font-weight: 700; font-size: 12px; text-align: center;
            transition: transform 0.1s, background 0.15s;
        }
        .cell-chip:hover { transform: scale(1.1); }
        .cell-chip.on      { background: #103820; color: #4ade80; border: 1px solid #2a6b3e; }
        .cell-chip.off     { background: #2a1717; color: #f87171; border: 1px solid #5b2222; }
        .cell-chip.always  { background: #2c2316; color: #fbbf24; border: 1px solid #6b4f1c; cursor: not-allowed; }
        /* "on by role default" — blue tint to distinguish from explicit whitelist */
        .cell-chip.default-on { background: #0f2a3a; color: #7dd3fc; border: 1px solid #1e4f6b; }

        .reset-btn {
            font-size: 11px; color: #555; background: transparent;
            border: 1px solid #2a2a2a; padding: 3px 10px; border-radius: 5px;
            cursor: pointer; transition: all 0.15s; margin-top: 4px;
        }
        .reset-btn:hover { color: #e5e5e5; border-color: #76b900; }
    </style>
</head>
<body>

<header class="header">
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;">
        <img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:36px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));">
    </a>
    <span class="badge">ADMIN</span>
    <nav class="nav">
        <a href="/admin/users">👥 Utilizadores</a>
        <a href="/admin/agent-access">🎭 Agentes × Users</a>
        <a href="/admin/nav-access" class="active">🗺️ Nav × Users</a>
        <a href="/admin/conversations">💬 Conversas</a>
        <a href="{{ route('admin.panel') }}">⚙️ Painel</a>
        <a href="/dashboard">🏠 Dashboard</a>
    </nav>
    <div class="user-info">
        <span style="font-size: 13px; color: #aaa;">{{ Auth::user()->name }}</span>
    </div>
</header>

<div class="container">
    <h1>Visibilidade da Navegação</h1>
    <p class="subtitle">
        Clica numa célula para mostrar/ocultar uma secção do nav para esse utilizador.
        <strong>Admin</strong> vê sempre tudo. <strong>Reset</strong> volta aos defaults do role.
    </p>

    <div class="legend">
        <span><span class="chip on">✓</span> visível (whitelist explícita)</span>
        <span><span class="chip default-on">✓</span> visível (default do role)</span>
        <span><span class="chip off">✗</span> oculto</span>
        <span><span class="chip always">★</span> admin (sempre visível)</span>
    </div>

    @if(session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <div class="matrix-wrap">
        <table class="matrix">
            <thead>
                <tr>
                    <th>Utilizador</th>
                    @foreach($sections as $key => $sec)
                        <th class="section-col" title="{{ $sec['label'] }}">
                            <div class="section-col-head">
                                <span class="sec-emoji">{{ $sec['emoji'] }}</span>
                                <span class="sec-label">{{ $sec['label'] }}</span>
                            </div>
                        </th>
                    @endforeach
                    <th style="min-width: 100px;">Acções</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $u)
                    <tr data-user="{{ $u->id }}">
                        <td>
                            <div class="user-cell">
                                <span class="name">{{ $u->name }}</span>
                                <span class="email">{{ $u->email }}</span>
                                <span class="role">{{ $u->role }}
                                    @if($u->allowed_nav === null)
                                        · <span style="color:#7dd3fc;font-size:10px;">role default</span>
                                    @elseif(empty($u->allowed_nav))
                                        · <span style="color:#f87171;font-size:10px;">bloqueado</span>
                                    @else
                                        · <span style="color:#4ade80;font-size:10px;">whitelist ({{ count($u->allowed_nav) }})</span>
                                    @endif
                                </span>
                            </div>
                        </td>
                        @foreach($sections as $key => $sec)
                            @php
                                $isAdmin   = $u->role === 'admin';
                                $visible   = $u->canSeeNav($key);
                                $isDefault = $u->allowed_nav === null;  // showing role-based default
                                $chipClass = $isAdmin
                                    ? 'always'
                                    : ($visible ? ($isDefault ? 'default-on' : 'on') : 'off');
                                $chipText  = $isAdmin ? '★' : ($visible ? '✓' : '✗');
                            @endphp
                            <td style="text-align:center;">
                                <span class="cell-chip {{ $chipClass }}"
                                      data-key="{{ $key }}"
                                      @if(!$isAdmin) onclick="toggleCell(this, {{ $u->id }})" @endif
                                      title="{{ $isAdmin ? 'Admin — sempre visível' : $sec['label'] }}">
                                    {{ $chipText }}
                                </span>
                            </td>
                        @endforeach
                        <td style="text-align:center;">
                            @if($u->role !== 'admin')
                                <button class="reset-btn" onclick="resetNav({{ $u->id }}, this)"
                                        title="Voltar aos defaults do role">↺ Reset</button>
                            @else
                                <span style="color:#555;font-size:11px;">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p style="margin-top:14px; font-size:12px; color:#666;">
        ℹ Azul = visível pelo default do role (allowed_nav=null).
        Verde = whitelist explícita. Vermelho = bloqueado.
        <strong>Reset</strong> apaga a whitelist e volta ao comportamento por role.
        Cada acção é registada em <code>user_admin_events</code>.
    </p>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

async function toggleCell(chip, userId) {
    const key = chip.dataset.key;
    chip.style.opacity = '0.5';
    try {
        const r = await fetch(`/admin/users/${userId}/nav/${key}`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        });
        const data = await r.json();
        if (!data.ok) { alert(data.error || 'Erro'); return; }

        // Remove all state classes and apply new one
        chip.classList.remove('on', 'off', 'default-on');
        chip.classList.add(data.now_visible ? 'on' : 'off');
        chip.textContent = data.now_visible ? '✓' : '✗';

        // Update the mode badge in the user-cell
        const row = chip.closest('tr');
        const modeBadge = row?.querySelector('.role span:last-child');
        if (modeBadge && data.mode) {
            if (data.mode === 'default') {
                modeBadge.textContent = 'role default';
                modeBadge.style.color = '#7dd3fc';
            } else if (data.mode === 'blocked') {
                modeBadge.textContent = 'bloqueado';
                modeBadge.style.color = '#f87171';
            } else {
                // Update count from response
                const nav = data.allowed_nav;
                const cnt = Array.isArray(nav) ? nav.length : '?';
                modeBadge.textContent = `whitelist (${cnt})`;
                modeBadge.style.color = '#4ade80';
            }
        }
    } catch (e) {
        alert('Erro de rede.');
    } finally {
        chip.style.opacity = '1';
    }
}

async function resetNav(userId, btn) {
    if (!confirm('Repor os defaults do role para este utilizador?')) return;
    btn.disabled = true;
    try {
        const r = await fetch(`/admin/users/${userId}/nav/reset`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        });
        // Reload to reflect new state cleanly
        window.location.reload();
    } catch (e) {
        alert('Erro de rede.');
        btn.disabled = false;
    }
}
</script>

</body>
</html>
