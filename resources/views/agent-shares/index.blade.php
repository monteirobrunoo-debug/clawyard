<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Agentes Partilhados — ClawYard</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#0a0a0f;--bg2:#111118;--bg3:#1a1a24;--border:#2a2a3a;--text:#e2e8f0;--muted:#64748b;--green:#76b900;--red:#ef4444;--blue:#3b82f6}
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh}

        /* NAV */
        .nav{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 24px;height:56px;display:flex;align-items:center;gap:16px}
        .nav-logo{font-size:18px;font-weight:800;color:var(--green);text-decoration:none}
        .nav-back{font-size:13px;color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--border);border-radius:8px;transition:.15s}
        .nav-back:hover{color:var(--text);border-color:var(--text)}
        .nav-spacer{flex:1}
        .btn-new{background:var(--green);color:#000;font-weight:700;font-size:13px;padding:8px 18px;border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:6px;transition:.15s}
        .btn-new:hover{filter:brightness(1.1)}

        /* MAIN */
        .main{max-width:960px;margin:0 auto;padding:32px 24px}
        .page-header{margin-bottom:28px}
        .page-title{font-size:24px;font-weight:800;color:var(--text)}
        .page-sub{font-size:13px;color:var(--muted);margin-top:4px}

        /* STATS */
        .stats{display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap}
        .stat{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px 20px;flex:1;min-width:120px}
        .stat-val{font-size:22px;font-weight:800;color:var(--text)}
        .stat-lbl{font-size:11px;color:var(--muted);margin-top:2px}

        /* SHARE CARDS */
        .shares-grid{display:flex;flex-direction:column;gap:12px}
        .share-card{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:16px;transition:.15s}
        .share-card:hover{border-color:#3a3a4a}
        .share-agent-badge{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
        .share-info{flex:1;min-width:0}
        .share-client{font-size:14px;font-weight:700;color:var(--text)}
        .share-agent-name{font-size:12px;color:var(--muted);margin-top:2px}
        .share-url{font-size:11px;color:#60a5fa;margin-top:4px;word-break:break-all}
        .share-meta{display:flex;gap:12px;margin-top:6px;flex-wrap:wrap}
        .share-tag{font-size:10px;padding:2px 8px;border-radius:12px;background:rgba(255,255,255,.05);color:var(--muted)}
        .share-tag.green{background:rgba(118,185,0,.15);color:var(--green)}
        .share-tag.red{background:rgba(239,68,68,.12);color:var(--red)}
        .share-tag.blue{background:rgba(59,130,246,.12);color:var(--blue)}
        .share-actions{display:flex;gap:8px;flex-shrink:0}
        .btn-action{background:none;border:1px solid var(--border);color:var(--muted);font-size:12px;padding:6px 12px;border-radius:8px;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:4px}
        .btn-action:hover{color:var(--text);border-color:var(--text)}
        .btn-action.danger:hover{color:var(--red);border-color:var(--red)}

        /* EMPTY */
        .empty{text-align:center;padding:60px 20px;color:var(--muted)}
        .empty-icon{font-size:48px;margin-bottom:12px}
        .empty-title{font-size:16px;font-weight:600;color:var(--text);margin-bottom:6px}

        /* MODAL */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
        .modal-overlay.open{display:flex}
        .modal{background:var(--bg2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:520px;padding:28px}
        .modal-title{font-size:18px;font-weight:800;margin-bottom:20px}
        .form-row{margin-bottom:16px}
        .form-label{font-size:12px;font-weight:600;color:var(--muted);margin-bottom:6px;display:block;text-transform:uppercase;letter-spacing:.5px}
        .form-input,.form-select,.form-textarea{width:100%;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:10px 14px;border-radius:8px;font-size:14px;outline:none;transition:.15s}
        .form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--green)}
        .form-textarea{resize:vertical;min-height:80px}
        .form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .form-hint{font-size:11px;color:var(--muted);margin-top:4px}
        .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px}
        .btn-cancel{background:none;border:1px solid var(--border);color:var(--muted);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px}
        .btn-submit{background:var(--green);color:#000;font-weight:700;padding:8px 20px;border:none;border-radius:8px;cursor:pointer;font-size:13px}
        .btn-submit:hover{filter:brightness(1.1)}

        /* SUCCESS */
        .success-box{background:rgba(118,185,0,.1);border:1px solid rgba(118,185,0,.3);border-radius:10px;padding:16px;margin-top:16px;display:none}
        .success-box.show{display:block}
        .success-url{font-size:13px;color:var(--green);word-break:break-all;margin-bottom:10px}
        .btn-copy{background:rgba(118,185,0,.2);border:1px solid rgba(118,185,0,.4);color:var(--green);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600}
    </style>
</head>
<body>
<nav class="nav">
    <a href="/dashboard" class="nav-logo">⚡ ClawYard</a>
    <a href="/dashboard" class="nav-back">← Dashboard</a>
    <div class="nav-spacer"></div>
    <button class="btn-new" onclick="openModal()">+ Novo Agente Partilhado</button>
</nav>

<div class="main">
    <div class="page-header">
        <div class="page-title">🔗 Agentes Partilhados</div>
        <div class="page-sub">Cria links para clientes acederem a um agente específico sem conta ClawYard.</div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-val">{{ $shares->count() }}</div>
            <div class="stat-lbl">Total criados</div>
        </div>
        <div class="stat">
            <div class="stat-val">{{ $shares->where('is_active', true)->count() }}</div>
            <div class="stat-lbl">Activos</div>
        </div>
        <div class="stat">
            <div class="stat-val">{{ $shares->sum('usage_count') }}</div>
            <div class="stat-lbl">Mensagens enviadas</div>
        </div>
        <div class="stat">
            <div class="stat-val">{{ $shares->whereNotNull('expires_at')->where('expires_at', '>', now())->count() + $shares->whereNull('expires_at')->count() }}</div>
            <div class="stat-lbl">Não expirados</div>
        </div>
    </div>

    <div class="shares-grid">
        @forelse($shares->groupBy('client_name') as $clientName => $clientShares)
        @php $singleAgent = $clientShares->count() === 1; @endphp
        <div class="client-group-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <!-- Client group header -->
            <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02)">
                <span style="font-size:15px;font-weight:800;color:var(--text)">{{ $clientName }}</span>
                <span style="font-size:11px;color:var(--muted);background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:10px;padding:2px 8px">
                    {{ $clientShares->count() }} {{ $clientShares->count() === 1 ? 'agente' : 'agentes' }}
                </span>
            </div>

            @if($singleAgent)
            {{-- Single agent: full card layout --}}
            @php
                $share = $clientShares->first();
                $meta  = $agentMeta[$share->agent_key] ?? ['name' => $share->agent_key, 'emoji' => '🤖', 'color' => '#76b900', 'photo' => null];
                $valid = $share->isValid();
            @endphp
            <div class="share-card" id="share-{{ $share->id }}" style="border:none;border-radius:0;padding:14px 20px">
                <div class="share-agent-badge" style="background:{{ $meta['color'] }}22;border:1px solid {{ $meta['color'] }}44;overflow:hidden;padding:0">
                    @if(!empty($meta['photo']))
                        <img src="{{ $meta['photo'] }}" alt="{{ $meta['name'] }}" style="width:100%;height:100%;object-fit:cover;border-radius:10px;display:block">
                    @else
                        <span style="font-size:22px">{{ $meta['emoji'] }}</span>
                    @endif
                </div>
                <div class="share-info">
                    <div class="share-agent-name" style="font-size:13px;font-weight:700;color:var(--text)">{{ $share->custom_title ?: $meta['name'] }}</div>
                    <div class="share-url">{{ $share->getUrl() }}</div>
                    <div class="share-meta">
                        <span class="share-tag {{ $valid ? 'green' : 'red' }}">
                            {{ $valid ? ($share->is_active ? '● Activo' : '● Pausado') : '● Expirado' }}
                        </span>
                        @if($share->password_hash)
                            <span class="share-tag blue">🔒 Com password</span>
                        @endif
                        @if($share->expires_at)
                            <span class="share-tag">⏱ Expira {{ $share->expires_at->format('d/m/Y') }}</span>
                        @else
                            <span class="share-tag">∞ Sem expiração</span>
                        @endif
                        <span class="share-tag">{{ $share->usage_count }} mensagens</span>
                        @if($share->last_used_at)
                            <span class="share-tag">Usado {{ $share->last_used_at->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
                <div class="share-actions">
                    <button class="btn-action" onclick="copyUrl('{{ $share->getUrl() }}', this)">📋 Copiar</button>
                    <button class="btn-action" onclick="toggleShare({{ $share->id }}, this)">
                        {{ $share->is_active ? '⏸ Pausar' : '▶ Activar' }}
                    </button>
                    <button class="btn-action" onclick="window.open('{{ $share->getUrl() }}','_blank')">↗ Abrir</button>
                    <button class="btn-action danger" onclick="deleteShare({{ $share->id }})">🗑</button>
                </div>
            </div>

            @else
            {{-- Multiple agents: compact table layout --}}
            <table style="width:100%;border-collapse:collapse">
                @foreach($clientShares as $share)
                @php
                    $meta  = $agentMeta[$share->agent_key] ?? ['name' => $share->agent_key, 'emoji' => '🤖', 'color' => '#76b900', 'photo' => null];
                    $valid = $share->isValid();
                @endphp
                <tr id="share-{{ $share->id }}" style="border-top:1px solid var(--border);transition:.15s" onmouseover="this.style.background='rgba(255,255,255,.02)'" onmouseout="this.style.background=''">
                    <td style="padding:10px 20px;width:44px">
                        <div style="width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:{{ $meta['color'] }}22;border:1px solid {{ $meta['color'] }}44;overflow:hidden;padding:0;flex-shrink:0">
                            @if(!empty($meta['photo']))
                                <img src="{{ $meta['photo'] }}" alt="{{ $meta['name'] }}" style="width:100%;height:100%;object-fit:cover;border-radius:7px;display:block">
                            @else
                                <span style="font-size:16px">{{ $meta['emoji'] }}</span>
                            @endif
                        </div>
                    </td>
                    <td style="padding:10px 12px;min-width:140px">
                        <div style="font-size:13px;font-weight:700;color:var(--text)">{{ $share->custom_title ?: $meta['name'] }}</div>
                        <div style="font-size:11px;color:#60a5fa;word-break:break-all;margin-top:2px">{{ $share->getUrl() }}</div>
                    </td>
                    <td style="padding:10px 12px;white-space:nowrap">
                        <span class="share-tag {{ $valid ? 'green' : 'red' }}">
                            {{ $valid ? ($share->is_active ? '● Activo' : '● Pausado') : '● Expirado' }}
                        </span>
                    </td>
                    <td style="padding:10px 12px;white-space:nowrap">
                        <span class="share-tag">{{ $share->usage_count }} msgs</span>
                        @if($share->last_used_at)
                            <span class="share-tag" style="margin-left:4px">{{ $share->last_used_at->diffForHumans() }}</span>
                        @endif
                    </td>
                    <td style="padding:10px 20px;text-align:right">
                        <div class="share-actions">
                            <button class="btn-action" onclick="copyUrl('{{ $share->getUrl() }}', this)">📋</button>
                            <button class="btn-action" onclick="toggleShare({{ $share->id }}, this)">
                                {{ $share->is_active ? '⏸' : '▶' }}
                            </button>
                            <button class="btn-action" onclick="window.open('{{ $share->getUrl() }}','_blank')">↗</button>
                            <button class="btn-action danger" onclick="deleteShare({{ $share->id }})">🗑</button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </table>
            @endif
        </div>
        @empty
        <div class="empty">
            <div class="empty-icon">🔗</div>
            <div class="empty-title">Nenhum agente partilhado ainda</div>
            <div style="font-size:13px;margin-top:6px">Clica em "Novo Agente Partilhado" para criar o primeiro link de cliente.</div>
        </div>
        @endforelse
    </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-title">🔗 Novo Agente Partilhado</div>

        <div id="success-box" class="success-box">
            <div style="font-size:12px;color:#76b900;font-weight:700;margin-bottom:8px">✅ Link criado com sucesso!</div>
            <div class="success-url" id="success-url"></div>
            <button class="btn-copy" onclick="copySuccessUrl()">📋 Copiar Link</button>
        </div>

        <div id="create-form">
            <div class="form-row">
                <label class="form-label">Agente</label>
                <select id="f-agent" class="form-select">
                    @foreach($agentMeta as $key => $m)
                    <option value="{{ $key }}">{{ $m['emoji'] }} {{ $m['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-row-2">
                <div class="form-row">
                    <label class="form-label">Nome do Cliente</label>
                    <input id="f-client" class="form-input" type="text" placeholder="ex: Armadores Silva Lda.">
                </div>
                <div class="form-row">
                    <label class="form-label">Email do Cliente <span style="opacity:.5">(opcional)</span></label>
                    <input id="f-email" class="form-input" type="email" placeholder="cliente@empresa.com">
                </div>
            </div>

            <div class="form-row">
                <label class="form-label">Título personalizado <span style="opacity:.5">(opcional)</span></label>
                <input id="f-title" class="form-input" type="text" placeholder="ex: Assistente de Segurança — Silva Lda.">
                <div class="form-hint">Se em branco usa o nome padrão do agente</div>
            </div>

            <div class="form-row">
                <label class="form-label">Mensagem de boas-vindas <span style="opacity:.5">(opcional)</span></label>
                <textarea id="f-welcome" class="form-textarea" placeholder="ex: Olá! Sou o vosso assistente de segurança. Como posso ajudar?"></textarea>
            </div>

            <div class="form-row-2">
                <div class="form-row">
                    <label class="form-label">Password <span style="opacity:.5">(opcional)</span></label>
                    <input id="f-pass" class="form-input" type="password" placeholder="Deixar em branco = livre">
                </div>
                <div class="form-row">
                    <label class="form-label">Expira em <span style="opacity:.5">(opcional)</span></label>
                    <input id="f-expires" class="form-input" type="datetime-local">
                </div>
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancelar</button>
            <button class="btn-submit" id="btn-create" onclick="createShare()">Criar Link</button>
        </div>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function openModal() {
    document.getElementById('modal').classList.add('open');
    document.getElementById('success-box').classList.remove('show');
    document.getElementById('create-form').style.display = '';
    document.getElementById('btn-create').style.display = '';
}
function closeModal() {
    document.getElementById('modal').classList.remove('open');
    location.reload();
}

async function createShare() {
    const btn = document.getElementById('btn-create');
    btn.textContent = 'A criar...';
    btn.disabled = true;

    const payload = {
        agent_key:       document.getElementById('f-agent').value,
        client_name:     document.getElementById('f-client').value.trim(),
        client_email:    document.getElementById('f-email').value.trim() || null,
        custom_title:    document.getElementById('f-title').value.trim() || null,
        welcome_message: document.getElementById('f-welcome').value.trim() || null,
        password:        document.getElementById('f-pass').value || null,
        expires_at:      document.getElementById('f-expires').value || null,
        show_branding:   true,
    };

    if (!payload.client_name) {
        alert('Introduz o nome do cliente.');
        btn.textContent = 'Criar Link';
        btn.disabled = false;
        return;
    }

    try {
        const r = await fetch('/admin/shares', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify(payload),
        });
        const data = await r.json();

        if (data.ok) {
            document.getElementById('success-url').textContent = data.url;
            document.getElementById('success-box').classList.add('show');
            document.getElementById('create-form').style.display = 'none';
            btn.style.display = 'none';
            window._newShareUrl = data.url;
        } else {
            alert('Erro ao criar: ' + JSON.stringify(data));
            btn.textContent = 'Criar Link';
            btn.disabled = false;
        }
    } catch(e) {
        alert('Erro de rede.');
        btn.textContent = 'Criar Link';
        btn.disabled = false;
    }
}

function copySuccessUrl() {
    navigator.clipboard.writeText(window._newShareUrl || '');
    event.target.textContent = '✅ Copiado!';
    setTimeout(() => event.target.textContent = '📋 Copiar Link', 2000);
}

function copyUrl(url, btn) {
    navigator.clipboard.writeText(url);
    const orig = btn.textContent;
    btn.textContent = '✅ Copiado!';
    setTimeout(() => btn.textContent = orig, 2000);
}

async function toggleShare(id, btn) {
    const r = await fetch(`/admin/shares/${id}/toggle`, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': CSRF }
    });
    const data = await r.json();
    if (data.ok) {
        btn.textContent = data.is_active ? '⏸ Pausar' : '▶ Activar';
        const card = document.getElementById('share-' + id);
        const tag = card.querySelector('.share-tag.green, .share-tag.red');
        if (tag) {
            tag.className = 'share-tag ' + (data.is_active ? 'green' : 'red');
            tag.textContent = data.is_active ? '● Activo' : '● Pausado';
        }
    }
}

async function deleteShare(id) {
    if (!confirm('Eliminar este link? O cliente deixará de ter acesso.')) return;
    const r = await fetch(`/admin/shares/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CSRF }
    });
    if ((await r.json()).ok) {
        document.getElementById('share-' + id)?.remove();
    }
}
</script>
</body>
</html>
