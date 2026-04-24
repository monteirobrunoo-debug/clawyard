<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Agentes Partilhados — ClawYard</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root{
            --bg:#0a0a0f;--bg2:#111118;--bg3:#1a1a24;--border:#2a2a3a;
            --text:#e2e8f0;--muted:#64748b;--green:#76b900;--red:#ef4444;--blue:#3b82f6;
            --subtle:#94a3b8;
            --link:#60a5fa;
            --tag-bg:rgba(255,255,255,.05);
            --toggle-bg:rgba(255,255,255,.04);--toggle-border:rgba(255,255,255,.10);
            --overlay:rgba(0,0,0,.7);
            --hover-row:rgba(255,255,255,.02);
            --pill-bg:rgba(255,255,255,.06);
        }
        :root[data-theme="light"]{
            --bg:#f4f6fa;--bg2:#ffffff;--bg3:#f1f5f9;--border:#e2e8f0;
            --text:#0f172a;--muted:#475569;--green:#4d7a00;--red:#b91c1c;--blue:#1d4ed8;
            --subtle:#64748b;
            --link:#1d4ed8;
            --tag-bg:rgba(15,23,42,.05);
            --toggle-bg:rgba(15,23,42,.04);--toggle-border:rgba(15,23,42,.12);
            --overlay:rgba(15,23,42,.35);
            --hover-row:rgba(15,23,42,.03);
            --pill-bg:rgba(15,23,42,.06);
        }
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;transition:background .2s,color .2s}
        .theme-toggle{margin-left:8px;width:34px;height:34px;border-radius:9px;background:var(--toggle-bg);border:1px solid var(--toggle-border);color:var(--muted);cursor:pointer;font-size:14px;display:inline-flex;align-items:center;justify-content:center;padding:0;transition:.15s}
        .theme-toggle:hover{color:var(--text);border-color:var(--green)}

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
        .modal{background:var(--bg2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:520px;padding:28px;max-height:90vh;overflow-y:auto}
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
    <button type="button" class="theme-toggle" onclick="toggleClawTheme()" aria-label="Alternar modo claro/escuro" title="Alternar modo claro/escuro"><span id="themeIcon">🌙</span></button>
</nav>

<div class="main">
    <div class="page-header">
        <div class="page-title">🔗 Agentes Partilhados</div>
        <div class="page-sub">Cria links para clientes acederem a um agente específico sem conta ClawYard.</div>
    </div>

    {{-- ─── Super-user panel (admin-only) ────────────────────────────────
         User request (2026-04-24): "quero nomear na partilha de user no
         dashboard quem pode partilhar os processos ou ser super-user".
         A `super-user` here means: role=manager — the gate that unlocks
         every authenticated admin action (create/share, see all tenders,
         edit collaborators, trigger digests). One click flips it on/off.
         The panel is only rendered when the viewer is admin — regular
         users and managers never see it. --}}
    @if($teamUsers !== null)
        <div class="team-panel" style="margin-bottom:28px;background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px;">
                <div>
                    <div style="font-weight:700;font-size:15px;color:var(--text);">👥 Super-users da equipa</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:3px;line-height:1.5;">
                        Quem é <strong>Super-user</strong> pode: criar partilhas para clientes, ver todos os concursos e atribuí-los,
                        gerir o roster de colaboradores. Quem é <strong>User normal</strong> só vê os concursos que lhe foram atribuídos.
                        Clica no chip para alternar.
                    </div>
                </div>
                <div style="font-size:11px;color:var(--muted);white-space:nowrap;">
                    {{ $teamUsers->where('role','manager')->count() }} super-user(s) · {{ $teamUsers->where('role','user')->count() }} user(s)
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
                @foreach($teamUsers as $tu)
                    @php $isMgr = $tu->role === 'manager'; @endphp
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg3);">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $tu->name }}</div>
                            <div style="font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $tu->email }}</div>
                        </div>
                        <button type="button"
                                id="promote-chip-{{ $tu->id }}"
                                onclick="togglePromote({{ $tu->id }}, this)"
                                title="Clica para {{ $isMgr ? 'despromover a user normal' : 'promover a super-user' }}"
                                data-is-manager="{{ $isMgr ? '1' : '0' }}"
                                style="font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;cursor:pointer;white-space:nowrap;border:1px solid;
                                    {{ $isMgr
                                        ? 'background:rgba(118,185,0,.15);color:var(--green);border-color:rgba(118,185,0,.35);'
                                        : 'background:var(--pill-bg);color:var(--muted);border-color:var(--border);' }}
                                    transition:all .15s;">
                            {{ $isMgr ? '★ Super-user' : 'User normal' }}
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
        @php
            $singleAgent = $clientShares->count() === 1;
            // All shares in the same batch share one portal_token. If the
            // whole group has the same (non-null) portal_token, expose a
            // unified portal URL at the top of the card.
            $portalToken = $clientShares->pluck('portal_token')->unique()->filter()->first();
            $allSamePortal = $portalToken && $clientShares->every(fn($s) => $s->portal_token === $portalToken);
            $portalUrl   = $allSamePortal ? $clientShares->first()->getPortalUrl() : null;
        @endphp
        <div class="client-group-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <!-- Client group header -->
            <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02);flex-wrap:wrap">
                <span style="font-size:15px;font-weight:800;color:var(--text)">{{ $clientName }}</span>
                <span style="font-size:11px;color:var(--muted);background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:10px;padding:2px 8px">
                    {{ $clientShares->count() }} {{ $clientShares->count() === 1 ? 'agente' : 'agentes' }}
                </span>
                @if($portalUrl && !$singleAgent)
                    <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:11px;color:#a3e635;background:rgba(118,185,0,.08);border:1px solid rgba(118,185,0,.3);border-radius:10px;padding:3px 10px">🌐 Portal único</span>
                @endif
            </div>

            @if($portalUrl && !$singleAgent)
            <div style="padding:10px 20px;background:rgba(118,185,0,.05);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span style="font-size:10px;color:#a3e635;font-weight:700;letter-spacing:.5px;text-transform:uppercase">Link do portal cliente</span>
                <span style="font-family:monospace;font-size:11px;color:#cbd5e1;word-break:break-all;flex:1;min-width:180px">{{ $portalUrl }}</span>
                <button class="btn-action" onclick="copyUrl('{{ $portalUrl }}', this)">📋 Copiar</button>
                <button class="btn-action" onclick="window.open('{{ $portalUrl }}','_blank')">↗ Abrir</button>
            </div>
            @endif

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
                    {{-- Agent identity: real name first (otherwise every
                         card in a "Marine Agents" portal looks identical),
                         role underneath, and custom_title kept as a small
                         suffix tag when set so we don't lose the context. --}}
                    <div class="share-agent-name" style="font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span>{{ $meta['emoji'] ?? '🤖' }} {{ $meta['name'] }}</span>
                        @if($share->custom_title && $share->custom_title !== ($meta['name'] ?? ''))
                            <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--pill-bg);color:var(--muted);text-transform:uppercase;letter-spacing:.4px">{{ $share->custom_title }}</span>
                        @endif
                    </div>
                    @if(!empty($meta['role']))
                        <div style="font-size:11px;color:var(--subtle);margin-top:2px;line-height:1.4">{{ $meta['role'] }}</div>
                    @endif
                    <div class="share-url">{{ $share->getUrl() }}</div>
                    @php $recipients = $share->authorisedEmails(); @endphp
                    @if(count($recipients) > 0)
                        <div style="font-size:11px;color:var(--muted);margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <span style="color:var(--subtle)">📧 Destinatários:</span>
                            @if(count($recipients) === 1)
                                <span style="color:var(--text)">{{ $recipients[0] }}</span>
                            @else
                                <span style="color:var(--text)">{{ $recipients[0] }}</span>
                                <span title="{{ implode(', ', array_slice($recipients, 1)) }}" style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;background:rgba(118,185,0,.12);border:1px solid rgba(118,185,0,.3);color:#a3e635;cursor:help">+{{ count($recipients) - 1 }} {{ count($recipients) - 1 === 1 ? 'email' : 'emails' }}</span>
                            @endif
                        </div>
                    @endif
                    <div class="share-meta">
                        <span class="share-tag {{ $valid ? 'green' : 'red' }}">
                            {{ $valid ? ($share->is_active ? '● Activo' : '● Pausado') : '● Expirado' }}
                        </span>
                        @if($share->password_hash)
                            <span class="share-tag blue">🔒 Com password</span>
                        @endif
                        {{-- SAP access chip — click to toggle. User rule:
                             "por um pisco no user para aceder ao SAP".
                             The field was write-once at create time; now the
                             admin can flip it per-share from this dashboard
                             without recreating the link. The ✓/✗ marker makes
                             the state unambiguous at a glance. --}}
                        <button type="button"
                                id="sap-toggle-{{ $share->id }}"
                                onclick="toggleSap({{ $share->id }}, this)"
                                class="share-tag sap-toggle"
                                title="Clica para {{ $share->allow_sap_access ? 'BLOQUEAR' : 'AUTORIZAR' }} o acesso SAP deste utilizador"
                                style="cursor:pointer;border:none;font:inherit;{{ $share->allow_sap_access
                                    ? 'background:rgba(6,182,212,.12);color:#06b6d4'
                                    : 'background:rgba(239,68,68,.08);color:#f87171' }}">
                            📊 SAP {{ $share->allow_sap_access ? '✓ autorizado' : '✗ bloqueado' }}
                        </button>
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
                    <button class="btn-action" onclick="showAccessLog({{ $share->id }})">📜 Acessos</button>
                    <button class="btn-action" onclick="window.open('{{ $share->getUrl() }}','_blank')">↗ Abrir</button>
                    <button class="btn-action danger" onclick="revokeShare({{ $share->id }})" title="Revogar imediatamente (bloqueia todos os acessos futuros)">🛑 Revogar</button>
                    <button class="btn-action danger" onclick="deleteShare({{ $share->id }})" title="Apagar">🗑</button>
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
                        {{-- Always show the agent's real name so portal bundles
                             with a shared custom_title don't collapse into
                             N copies of the same label. custom_title becomes
                             a small suffix pill. --}}
                        <div style="font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <span>{{ $meta['emoji'] ?? '🤖' }} {{ $meta['name'] }}</span>
                            @if($share->custom_title && $share->custom_title !== ($meta['name'] ?? ''))
                                <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--pill-bg);color:var(--muted);text-transform:uppercase;letter-spacing:.4px">{{ $share->custom_title }}</span>
                            @endif
                        </div>
                        @if(!empty($meta['role']))
                            <div style="font-size:11px;color:var(--subtle);margin-top:2px;line-height:1.35">{{ $meta['role'] }}</div>
                        @endif
                        <div style="font-size:11px;color:var(--link);word-break:break-all;margin-top:2px">{{ $share->getUrl() }}</div>
                        @php $recipients = $share->authorisedEmails(); @endphp
                        @if(count($recipients) > 0)
                            <div style="font-size:10px;color:var(--muted);margin-top:3px;display:flex;align-items:center;gap:5px;flex-wrap:wrap">
                                <span style="color:var(--subtle)">📧</span>
                                @if(count($recipients) === 1)
                                    <span>{{ $recipients[0] }}</span>
                                @else
                                    <span>{{ $recipients[0] }}</span>
                                    <span title="{{ implode(', ', array_slice($recipients, 1)) }}" style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:999px;background:rgba(118,185,0,.12);border:1px solid rgba(118,185,0,.3);color:#a3e635;cursor:help">+{{ count($recipients) - 1 }}</span>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td style="padding:10px 12px;white-space:nowrap">
                        <span class="share-tag {{ $valid ? 'green' : 'red' }}">
                            {{ $valid ? ($share->is_active ? '● Activo' : '● Pausado') : '● Expirado' }}
                        </span>
                        {{-- Per-share SAP access toggle — same semantic as
                             the button in the single-share layout above. --}}
                        <button type="button"
                                id="sap-toggle-{{ $share->id }}"
                                onclick="toggleSap({{ $share->id }}, this)"
                                class="share-tag sap-toggle"
                                title="Clica para {{ $share->allow_sap_access ? 'BLOQUEAR' : 'AUTORIZAR' }} o acesso SAP deste utilizador"
                                style="cursor:pointer;border:none;font:inherit;margin-left:4px;{{ $share->allow_sap_access
                                    ? 'background:rgba(6,182,212,.12);color:#06b6d4'
                                    : 'background:rgba(239,68,68,.08);color:#f87171' }}">
                            📊 {{ $share->allow_sap_access ? '✓' : '✗' }}
                        </button>
                    </td>
                    <td style="padding:10px 12px;white-space:nowrap">
                        <span class="share-tag">{{ $share->usage_count }} msgs</span>
                        @if($share->last_used_at)
                            <span class="share-tag" style="margin-left:4px">{{ $share->last_used_at->diffForHumans() }}</span>
                        @endif
                    </td>
                    <td style="padding:10px 20px;text-align:right">
                        <div class="share-actions">
                            <button class="btn-action" onclick="copyUrl('{{ $share->getUrl() }}', this)" title="Copiar link">📋</button>
                            <button class="btn-action" onclick="toggleShare({{ $share->id }}, this)" title="Pausar/Activar">
                                {{ $share->is_active ? '⏸' : '▶' }}
                            </button>
                            <button class="btn-action" onclick="showAccessLog({{ $share->id }})" title="Acessos">📜</button>
                            <button class="btn-action" onclick="window.open('{{ $share->getUrl() }}','_blank')" title="Abrir">↗</button>
                            <button class="btn-action danger" onclick="revokeShare({{ $share->id }})" title="Revogar">🛑</button>
                            <button class="btn-action danger" onclick="deleteShare({{ $share->id }})" title="Apagar">🗑</button>
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

            {{-- Per-recipient delivery receipt. Filled in from the backend's
                 response (`recipients`, `emails_sent_count`, `email_sent`).
                 Lets the admin SEE whether every email actually landed: if
                 emails_sent_count < recipients.length, we flag the gap with a
                 red banner so they know to check the server logs / SMTP. --}}
            <div id="delivery-receipt" style="margin-top:12px;font-size:12px;line-height:1.55;display:none;">
                <div style="font-weight:700;color:var(--text);margin-bottom:4px;">📨 Emails enviados:</div>
                <ul id="delivery-list" style="list-style:none;padding:0;margin:0;"></ul>
                <div id="delivery-warn" style="display:none;margin-top:8px;padding:8px 10px;border-radius:6px;background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.35);color:var(--red);font-weight:600;"></div>
            </div>
        </div>

        <div id="create-form">
            <div class="form-row">
                <label class="form-label">Agente</label>
                <select id="f-agent" class="form-select" onchange="updateAgentRole()">
                    @foreach($agentMeta as $key => $m)
                    <option value="{{ $key }}" data-role="{{ $m['role'] ?? '' }}">{{ $m['emoji'] }} {{ $m['name'] }}</option>
                    @endforeach
                </select>
                <div id="agent-role-preview" style="margin-top:8px;padding:10px 14px;background:rgba(118,185,0,.06);border:1px solid rgba(118,185,0,.2);border-radius:8px;font-size:12px;color:#cbd5e1;line-height:1.5;display:none">
                    <div style="font-size:10px;color:#a3e635;font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin-bottom:4px">O que faz este agente</div>
                    <div id="agent-role-text"></div>
                </div>
            </div>

            <!-- SAP access toggle — posição de destaque, logo após escolha do agente -->
            <div class="form-row" style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 14px;margin-bottom:16px">
                <label style="display:flex;align-items:center;gap:12px;cursor:pointer">
                    <input type="checkbox" id="f-sap-access" style="width:20px;height:20px;accent-color:#76b900;cursor:pointer;flex-shrink:0">
                    <div>
                        <div style="font-size:13px;font-weight:700;color:#e2e8f0">
                            📊 Permitir acesso SAP B1 (Richard)
                        </div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                            Por defeito <strong style="color:#ef4444">bloqueado</strong> — stock, faturas e CRM ficam ocultos a utilizadores externos.
                        </div>
                    </div>
                </label>
            </div>

            <div class="form-row-2">
                <div class="form-row">
                    <label class="form-label">Nome do Cliente</label>
                    <input id="f-client" class="form-input" type="text" placeholder="ex: Armadores Silva Lda.">
                </div>
                <div class="form-row">
                    <label class="form-label">Email do Cliente <span style="color:#ef4444;font-weight:700">*</span></label>
                    <input id="f-email" class="form-input" type="email" placeholder="cliente@empresa.com" required>
                    <div class="form-hint">Obrigatório — é para este email que vai o código de acesso (OTP).</div>
                </div>
            </div>

            <div class="form-row">
                <label class="form-label">Emails adicionais <span style="opacity:.5">(opcional)</span></label>
                <textarea id="f-extra-emails" class="form-textarea" rows="2" placeholder="colega1@empresa.com, colega2@empresa.com"></textarea>
                <div class="form-hint">Separa por vírgula, ponto-e-vírgula ou nova linha. Cada pessoa recebe o mesmo link e pede o código de acesso ao próprio email.</div>
            </div>

            <div style="background:rgba(118,185,0,.06);border:1px solid rgba(118,185,0,.25);border-radius:10px;padding:12px 14px;margin:6px 0">
                <div style="font-size:12px;font-weight:700;color:#a3e635;margin-bottom:10px">🔒 Segurança do link</div>

                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
                    <input type="checkbox" id="f-require-otp" checked style="width:18px;height:18px;accent-color:#76b900">
                    <span style="font-size:12px">
                        <strong>Exigir código por email (OTP)</strong>
                        <span style="opacity:.7">— só quem tem a caixa de entrada acima entra</span>
                    </span>
                </label>

                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
                    <input type="checkbox" id="f-lock-device" checked style="width:18px;height:18px;accent-color:#76b900">
                    <span style="font-size:12px">
                        <strong>Fixar ao dispositivo</strong>
                        <span style="opacity:.7">— se o link for aberto noutro browser, pede OTP de novo</span>
                    </span>
                </label>

                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
                    <input type="checkbox" id="f-notify" checked style="width:18px;height:18px;accent-color:#76b900">
                    <span style="font-size:12px">
                        <strong>Notificar-me a cada acesso</strong>
                        <span style="opacity:.7">— email + WhatsApp (opcional) com IP + dispositivo</span>
                    </span>
                </label>

                <div class="form-row-2" style="margin-top:8px">
                    <div class="form-row">
                        <label class="form-label" style="font-size:11px">Email de notificação</label>
                        <input id="f-notify-email" class="form-input" type="email" placeholder="{{ auth()->user()->email }}">
                    </div>
                    <div class="form-row">
                        <label class="form-label" style="font-size:11px">WhatsApp (+351…)</label>
                        <input id="f-notify-wa" class="form-input" type="text" placeholder="+351 91x xxx xxx">
                    </div>
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
    updateAgentRole();
}

function updateAgentRole() {
    const sel  = document.getElementById('f-agent');
    const opt  = sel && sel.options[sel.selectedIndex];
    const role = opt ? (opt.dataset.role || '') : '';
    const box  = document.getElementById('agent-role-preview');
    const txt  = document.getElementById('agent-role-text');
    if (!box || !txt) return;
    if (role && role.trim() !== '') {
        txt.textContent = role;
        box.style.display = '';
    } else {
        box.style.display = 'none';
    }
}
function closeModal() {
    document.getElementById('modal').classList.remove('open');
    location.reload();
}

async function createShare() {
    const btn = document.getElementById('btn-create');
    btn.textContent = 'A criar...';
    btn.disabled = true;

    // Split the additional-emails textarea on comma / semicolon / whitespace
    // into a clean array. Empty → null so the backend keeps the column NULL
    // instead of storing an empty JSON array.
    const extraRaw = document.getElementById('f-extra-emails').value || '';
    const extraList = extraRaw
        .split(/[\s,;]+/)
        .map(s => s.trim())
        .filter(s => s.length > 0);

    const payload = {
        agent_key:         document.getElementById('f-agent').value,
        client_name:       document.getElementById('f-client').value.trim(),
        client_email:      document.getElementById('f-email').value.trim(),
        additional_emails: extraList.length ? extraList : null,
        custom_title:      document.getElementById('f-title').value.trim() || null,
        welcome_message:  document.getElementById('f-welcome').value.trim() || null,
        password:         document.getElementById('f-pass').value || null,
        expires_at:       document.getElementById('f-expires').value || null,
        show_branding:    true,
        allow_sap_access: document.getElementById('f-sap-access').checked,
        require_otp:      document.getElementById('f-require-otp').checked,
        lock_to_device:   document.getElementById('f-lock-device').checked,
        notify_on_access: document.getElementById('f-notify').checked,
        notify_email:     document.getElementById('f-notify-email').value.trim() || null,
        notify_whatsapp:  document.getElementById('f-notify-wa').value.trim() || null,
    };

    if (!payload.client_name) {
        alert('Introduz o nome do cliente.');
        btn.textContent = 'Criar Link';
        btn.disabled = false;
        return;
    }
    if (!payload.client_email) {
        alert('O email do cliente é obrigatório — é para onde vai o código de acesso.');
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

            // Per-recipient delivery receipt — renders nothing when
            // skip_email was true (batch flow) or when recipients is empty.
            // Otherwise shows ✓/✗ per authorised email so the admin can
            // verify at a glance that EVERY email went out, not just the
            // primary. This is what diagnoses the "só o primário recebeu"
            // class of complaints: a mismatch between recipients.length and
            // emails_sent_count shows up immediately here.
            const recipients = Array.isArray(data.recipients) ? data.recipients : [];
            const sentCount  = Number(data.emails_sent_count || 0);
            const skipped    = !!data.email_skipped;
            const receipt    = document.getElementById('delivery-receipt');
            const list       = document.getElementById('delivery-list');
            const warn       = document.getElementById('delivery-warn');
            list.innerHTML = '';
            warn.style.display = 'none';
            if (!skipped && recipients.length > 0) {
                receipt.style.display = 'block';
                // We don't have per-recipient success from the backend yet
                // (the loop aggregates to a count), so we mark all as ✓ when
                // sentCount === recipients.length, all as ⚠ when 0, and show
                // a caveat when partial.
                const allOk   = sentCount === recipients.length;
                const noneOk  = sentCount === 0;
                recipients.forEach(email => {
                    const li = document.createElement('li');
                    li.style.cssText = 'padding:3px 0;color:var(--text);font-family:ui-monospace,monospace;';
                    const icon = allOk ? '✅' : (noneOk ? '❌' : '⚠️');
                    const color = allOk ? 'var(--green)' : (noneOk ? 'var(--red)' : '#f59e0b');
                    li.innerHTML = '<span style="color:' + color + ';margin-right:6px;">' + icon + '</span>' + email;
                    list.appendChild(li);
                });
                if (!allOk) {
                    warn.style.display = 'block';
                    warn.textContent = noneOk
                        ? '⚠ Nenhum email foi entregue — verifica logs SMTP em Forge → Logs.'
                        : '⚠ ' + sentCount + ' de ' + recipients.length + ' emails entregues. Os que falharam estão nos logs do servidor — reenvia manualmente ou pede ao admin para verificar.';
                }
            }
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

/**
 * Flip SAP-access for a share. Two layouts render the same button
 * (single-share card + multi-agent table) — we detect which one by the
 * text already inside: the long one says "SAP ✓ autorizado" / "SAP ✗ bloqueado",
 * the compact one says just "📊 ✓" / "📊 ✗". Both update in place with a
 * short pulse so the admin sees the flip land.
 */
async function toggleSap(id, btn) {
    btn.disabled = true;
    const r = await fetch(`/admin/shares/${id}/toggle-sap`, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': CSRF }
    });
    btn.disabled = false;
    const data = await r.json();
    if (!data.ok) {
        alert('Falha ao alternar acesso SAP. Tenta de novo.');
        return;
    }
    const on = data.allow_sap_access;
    // Colour swap — identical mapping for both layouts.
    btn.style.background = on ? 'rgba(6,182,212,.12)' : 'rgba(239,68,68,.08)';
    btn.style.color      = on ? '#06b6d4'            : '#f87171';
    // Label swap — detect the compact variant by length.
    const isCompact = btn.textContent.trim().length <= 5;
    btn.textContent = isCompact
        ? ('📊 ' + (on ? '✓' : '✗'))
        : ('📊 SAP ' + (on ? '✓ autorizado' : '✗ bloqueado'));
    btn.title = 'Clica para ' + (on ? 'BLOQUEAR' : 'AUTORIZAR') + ' o acesso SAP deste utilizador';
    // Quick flash so the change is visible even when the label barely moves.
    btn.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.08)' }, { transform: 'scale(1)' }],
        { duration: 240, easing: 'ease-out' }
    );
}

/**
 * Promote / demote a user between `user` and `manager` role.
 *
 * Fires against `/admin/users/{id}/toggle-promote` which is admin-only on
 * the server side — the panel itself is only rendered for admins, so in
 * practice the two gates agree. The server returns {ok, role, is_manager,
 * label}; we just swap the chip colour + label in place.
 */
async function togglePromote(userId, btn) {
    btn.disabled = true;
    try {
        const r = await fetch(`/admin/users/${userId}/toggle-promote`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'Accept': 'application/json'
            }
        });
        const data = await r.json();
        if (!data.ok) {
            alert(data.error || 'Não foi possível alterar a role.');
            return;
        }
        const isMgr = !!data.is_manager;
        btn.dataset.isManager = isMgr ? '1' : '0';
        btn.textContent = isMgr ? '★ Super-user' : 'User normal';
        btn.title = 'Clica para ' + (isMgr ? 'despromover a user normal' : 'promover a super-user');
        // Green-highlighted chip for managers, neutral for regular users.
        btn.style.background   = isMgr ? 'rgba(118,185,0,.15)' : 'var(--pill-bg)';
        btn.style.color        = isMgr ? 'var(--green)'        : 'var(--muted)';
        btn.style.borderColor  = isMgr ? 'rgba(118,185,0,.35)' : 'var(--border)';
        btn.animate(
            [{ transform: 'scale(1)' }, { transform: 'scale(1.08)' }, { transform: 'scale(1)' }],
            { duration: 240, easing: 'ease-out' }
        );
    } catch (e) {
        alert('Erro de rede — tenta de novo.');
    } finally {
        btn.disabled = false;
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

async function revokeShare(id) {
    if (!confirm('🛑 REVOGAR este link?\n\nQualquer sessão aberta é terminada imediatamente e o link deixa de aceitar novos acessos. Não é reversível.')) return;
    const reason = prompt('(Opcional) Motivo para o registo:', '') ?? '';
    const r = await fetch(`/admin/shares/${id}/revoke`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ reason }),
    });
    if ((await r.json()).ok) {
        alert('Link revogado. Todas as sessões activas foram terminadas.');
        location.reload();
    } else {
        alert('Falha ao revogar. Tenta novamente.');
    }
}

async function showAccessLog(id) {
    const r = await fetch(`/admin/shares/${id}/log`, { headers: { 'X-CSRF-TOKEN': CSRF } });
    const data = await r.json();
    if (!data.logs || !data.logs.length) {
        alert('Sem acessos registados.');
        return;
    }
    const rows = data.logs.map(l => {
        const when = new Date(l.created_at).toLocaleString('pt-PT');
        const status = l.status === 'allowed' ? '✅' : '⛔';
        return `${status} ${when}  ·  ${l.event.padEnd(15)}  ·  ${l.email || '-'}  ·  ${l.ip || '-'} (${l.country || '?'})${l.note ? '  · ' + l.note : ''}`;
    }).join('\n');
    alert('📜 Últimos acessos:\n\n' + rows);
}

// ── Day/Night theme toggle (shared with /a/* and /p/* via localStorage) ─────
(function(){
    var KEY='cy-theme',saved=null;
    try{saved=localStorage.getItem(KEY);}catch(e){}
    var t=(saved==='light'?'light':'dark');
    document.documentElement.setAttribute('data-theme',t);
    var ic=document.getElementById('themeIcon');if(ic)ic.textContent=(t==='light'?'☀️':'🌙');
})();
function toggleClawTheme(){
    var cur=document.documentElement.getAttribute('data-theme')==='light'?'light':'dark';
    var next=cur==='light'?'dark':'light';
    document.documentElement.setAttribute('data-theme',next);
    var ic=document.getElementById('themeIcon');if(ic)ic.textContent=(next==='light'?'☀️':'🌙');
    try{localStorage.setItem('cy-theme',next);}catch(e){}
}
</script>
</body>
</html>
