{{--
    /admin/agent-access — matrix of every active user × every agent.

    Each cell is a clickable chip. PATCH to toggleAgentAccess flips the
    bit in users.allowed_agents and we patch the chip in-place.

    Header row exposes presets (vendor_spares, engineering, …) per
    user — one click applies a curated bundle instead of toggling 20+
    cells one by one.

    Admin always passes the gate (User::canUseAgent), so for admin
    rows the whole row is rendered as "always on" and not toggleable.

    Implementation note: the matrix can grow wide (10 users × 28
    agents). Sticky first column + horizontal scroll keeps the layout
    readable on a 1280px screen.
--}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard Admin — Acessos a Agentes</title>
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
        .nav a {
            font-size: 13px; color: #555; text-decoration: none;
            padding: 6px 14px; border-radius: 8px; transition: all 0.2s;
        }
        .nav a:hover, .nav a.active { color: #e5e5e5; background: #1a1a1a; }
        .user-info { margin-left: auto; display: flex; align-items: center; gap: 12px; }

        .container { padding: 24px 32px; max-width: 1600px; margin: 0 auto; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        .subtitle { font-size: 13px; color: #888; margin-bottom: 20px; }

        .legend { font-size: 12px; color: #888; margin-bottom: 14px; display: flex; gap: 18px; }
        .legend .chip { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; }
        .legend .chip.on  { background: #103820; color: #4ade80; border: 1px solid #2a6b3e; }
        .legend .chip.off { background: #2a1717; color: #f87171; border: 1px solid #5b2222; line-height:1; }
        .legend .chip.always { background: #2c2316; color: #fbbf24; border: 1px solid #6b4f1c; }

        .flash {
            padding: 10px 14px; border-radius: 8px; margin-bottom: 16px;
            font-size: 13px; background: #103820; color: #86efac; border: 1px solid #2a6b3e;
        }
        .flash.error { background: #2a1717; color: #fca5a5; border-color: #5b2222; }

        .matrix-wrap { overflow-x: auto; border: 1px solid #1e1e1e; border-radius: 10px; background: #0e0e0e; }
        table.matrix { border-collapse: separate; border-spacing: 0; width: max-content; }
        table.matrix th, table.matrix td {
            padding: 10px 8px; font-size: 12px; vertical-align: middle;
            border-bottom: 1px solid #1a1a1a; border-right: 1px solid #161616;
            white-space: nowrap;
        }
        table.matrix thead th {
            position: sticky; top: 0; background: #111; z-index: 2;
            font-weight: 600; color: #ccc;
        }
        /* First column sticks left so the user names stay visible while
           the operator scrolls horizontally across agent columns. */
        table.matrix th:first-child, table.matrix td:first-child {
            position: sticky; left: 0; background: #0e0e0e; z-index: 1;
            min-width: 240px; max-width: 240px;
        }
        table.matrix thead th:first-child { z-index: 3; background: #111; }
        table.matrix tbody tr:hover td { background: #131313; }

        .user-cell { display: flex; flex-direction: column; gap: 2px; }
        .user-cell .name { font-weight: 600; color: #e5e5e5; }
        .user-cell .email { color: #888; font-size: 11px; }
        .user-cell .role { font-size: 10px; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.5px; }

        .agent-col { writing-mode: vertical-rl; transform: rotate(180deg); padding: 14px 4px; min-width: 30px; max-width: 30px; }
        .agent-col-head {
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            font-size: 11px; color: #ccc;
        }

        .cell-chip {
            display: inline-block; min-width: 28px; padding: 4px 6px;
            border-radius: 5px; cursor: pointer; user-select: none;
            font-weight: 700; font-size: 12px; text-align: center;
            transition: transform 0.1s, background 0.15s;
        }
        .cell-chip:hover { transform: scale(1.1); }
        .cell-chip.on    { background: #103820; color: #4ade80; border: 1px solid #2a6b3e; }
        .cell-chip.off   { background: #2a1717; color: #f87171; border: 1px solid #5b2222; }
        .cell-chip.always{ background: #2c2316; color: #fbbf24; border: 1px solid #6b4f1c; cursor: not-allowed; }

        .preset-cell { padding: 6px; }
        .preset-cell select {
            background: #1a1a1a; color: #ccc; border: 1px solid #333;
            border-radius: 6px; padding: 4px 8px; font-size: 11px;
            min-width: 130px; cursor: pointer;
        }
        .preset-cell select:hover { background: #222; }
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
        <a href="/admin/agent-access" class="active">🎭 Agentes × Users</a>
        <a href="/admin/conversations">💬 Conversas</a>
        <a href="/dashboard">🏠 Dashboard</a>
    </nav>
    <div class="user-info">
        <span style="font-size: 13px; color: #aaa;">{{ Auth::user()->name }}</span>
    </div>
</header>

<div class="container">
    <h1>Acessos a Agentes</h1>
    <p class="subtitle">
        Clica numa célula para alternar o acesso de um utilizador a um agente.
        <strong>Admin</strong> tem sempre acesso a todos.
        Aplica um <em>preset</em> à direita para configurar várias permissões de uma vez.
    </p>

    <div class="legend">
        <span><span class="chip on">✓</span> com acesso</span>
        <span><span class="chip off">✗</span> bloqueado</span>
        <span><span class="chip always">★</span> admin (sempre on)</span>
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
                    @foreach($agents as $a)
                        <th class="agent-col" title="{{ $a['name'] }} — {{ $a['role'] }}">
                            <div class="agent-col-head">
                                <span>{{ $a['emoji'] ?? '·' }}</span>
                                <span>{{ \Illuminate\Support\Str::limit($a['name'], 18) }}</span>
                            </div>
                        </th>
                    @endforeach
                    <th style="min-width: 170px;">Preset</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $u)
                    <tr data-user="{{ $u->id }}">
                        <td>
                            <div class="user-cell">
                                <span class="name">{{ $u->name }}</span>
                                <span class="email">{{ $u->email }}</span>
                                <span class="role">{{ $u->role }}</span>
                            </div>
                        </td>
                        @foreach($agents as $a)
                            @php
                                $key = $a['key'];
                                $isAdmin = $u->role === 'admin';
                                $allowed = $isAdmin ? true : $u->canUseAgent($key);
                            @endphp
                            <td>
                                <span class="cell-chip {{ $isAdmin ? 'always' : ($allowed ? 'on' : 'off') }}"
                                      data-key="{{ $key }}"
                                      data-name="{{ $a['name'] }}"
                                      @if(!$isAdmin) onclick="toggleCell(this, {{ $u->id }})" @endif
                                      title="{{ $isAdmin ? 'Admin — acesso total' : $a['name'] }}">
                                    {{ $isAdmin ? '★' : ($allowed ? '✓' : '✗') }}
                                </span>
                            </td>
                        @endforeach
                        <td class="preset-cell">
                            @if($u->role !== 'admin')
                                <form method="POST"
                                      action=""
                                      data-user="{{ $u->id }}"
                                      onsubmit="return false">
                                    @csrf
                                    <select onchange="applyPreset(this, {{ $u->id }})">
                                        <option value="">Aplicar preset…</option>
                                        @foreach($presets as $p)
                                            <option value="{{ $p }}">{{ $p }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @else
                                <span style="color: #555; font-size: 11px;">— admin —</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p style="margin-top: 14px; font-size: 12px; color: #666;">
        ℹ Toggle alterna a permissão. <strong>Primeira</strong> alteração de um utilizador
        sem restrição materializa a whitelist como "tudo menos isto"; quando cobrir
        de novo todos os agentes, volta automaticamente a NULL (sem restrição).
        Cada acção é registada em <code>user_admin_events</code>.
    </p>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

async function toggleCell(chip, userId) {
    const key = chip.dataset.key;
    chip.style.opacity = '0.5';
    try {
        const r = await fetch(`/admin/users/${userId}/agents/${key}`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        });
        const data = await r.json();
        if (!data.ok) {
            alert(data.error || 'Erro');
            return;
        }
        // Update this cell. The toggle endpoint also affects no other
        // cells in the matrix — but the row's whitelist mode might
        // have flipped from NULL → array, which only manifests when
        // the user clicks ANOTHER cell. So we patch this one only.
        chip.classList.toggle('on',  data.now_allowed);
        chip.classList.toggle('off', !data.now_allowed);
        chip.textContent = data.now_allowed ? '✓' : '✗';
    } catch (e) {
        alert('Erro de rede.');
    } finally {
        chip.style.opacity = '1';
    }
}

async function applyPreset(selectEl, userId) {
    const preset = selectEl.value;
    if (!preset) return;
    if (!confirm(`Aplicar preset "${preset}" — substitui as permissões actuais. Continuar?`)) {
        selectEl.value = '';
        return;
    }
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = `/admin/users/${userId}/agents/preset/${preset}`;
    f.innerHTML = `<input type="hidden" name="_token" value="${csrf}">`;
    document.body.appendChild(f);
    f.submit();
}
</script>

</body>
</html>
