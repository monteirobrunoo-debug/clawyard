<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $share->custom_title ?: $meta['name'] }}</title>
    <!-- Structured token rendering (TABLE/CHART/PPT) — partilhado com welcome.blade -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js" defer></script>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root{
            --agent-color: {{ $meta['color'] }};
            /* Night (default) tokens */
            --bg:#0a0a0f;--bg2:#111118;--bg3:#1a1a24;--bg4:#0f0f18;
            --border:#2a2a3a;--text:#e2e8f0;--muted:#64748b;
            --text-soft:#94a3b8;
            --code-bg:rgba(255,255,255,.08);
            --pre-bg:rgba(0,0,0,.4);
            --toggle-bg:rgba(255,255,255,.04);
            --toggle-border:rgba(255,255,255,.10);
        }
        /* Day mode — override tokens. Keeps --agent-color untouched so
           each agent's accent stays on-brand in both themes. */
        :root[data-theme="light"]{
            --bg:#f4f6fa;
            --bg2:#ffffff;
            --bg3:#f1f5f9;
            --bg4:#eef2f7;
            --border:#e2e8f0;
            --text:#0f172a;
            --muted:#64748b;
            --text-soft:#475569;
            --code-bg:rgba(15,23,42,.06);
            --pre-bg:rgba(15,23,42,.04);
            --toggle-bg:rgba(15,23,42,.04);
            --toggle-border:rgba(15,23,42,.12);
        }
        html,body{height:100%;overflow:hidden}
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;display:flex;flex-direction:column;transition:background .2s,color .2s}

        /* Header theme toggle */
        .theme-toggle{
            width:34px;height:34px;border-radius:9px;
            background:var(--toggle-bg);border:1px solid var(--toggle-border);
            color:var(--muted);cursor:pointer;font-size:14px;line-height:1;
            display:inline-flex;align-items:center;justify-content:center;
            padding:0;transition:.15s;flex-shrink:0;
        }
        .theme-toggle:hover{color:var(--text);border-color:var(--agent-color)}

        /* HEADER */
        .header{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 20px;height:54px;display:flex;align-items:center;gap:12px;flex-shrink:0}
        .agent-avatar{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;background:rgba(0,0,0,.3);border:1px solid var(--agent-color)44;flex-shrink:0}
        .agent-info{flex:1;min-width:0}
        .agent-name{font-size:14px;font-weight:700;color:var(--text)}
        .agent-status{font-size:11px;color:var(--agent-color);display:flex;align-items:center;gap:4px}
        .status-dot{width:6px;height:6px;border-radius:50%;background:var(--agent-color);animation:pulse 2s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
        @if($share->show_branding)
        .branding{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px}
        .branding a{color:var(--muted);text-decoration:none}
        .branding a:hover{color:var(--text)}
        @endif

        /* CHAT AREA */
        .chat-wrap{flex:1;overflow:hidden;display:flex;flex-direction:column}
        .messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:16px;scroll-behavior:smooth}
        .messages::-webkit-scrollbar{width:4px}
        .messages::-webkit-scrollbar-track{background:transparent}
        .messages::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}

        /* MESSAGES */
        .message{display:flex;gap:10px;align-items:flex-start;max-width:800px}
        .message.user{flex-direction:row-reverse;margin-left:auto}
        .avatar{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;font-weight:700}
        .message.user .avatar{background:var(--agent-color)22;border:1px solid var(--agent-color)44;color:var(--agent-color)}
        .message.assistant .avatar{background:rgba(255,255,255,.04);border:1px solid var(--border);font-size:16px}
        .bubble{padding:12px 16px;border-radius:12px;font-size:14px;line-height:1.6;max-width:680px}
        .message.user .bubble{background:var(--agent-color)18;border:1px solid var(--agent-color)30;border-radius:12px 4px 12px 12px;color:var(--text)}
        .message.assistant .bubble{background:var(--bg2);border:1px solid var(--border);border-radius:4px 12px 12px 12px;color:var(--text)}
        .bubble p{margin-bottom:.5em}.bubble p:last-child{margin-bottom:0}
        .bubble strong{color:var(--text);font-weight:700}
        .bubble code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-size:12px;font-family:monospace}
        .bubble pre{background:rgba(0,0,0,.4);border:1px solid var(--border);border-radius:8px;padding:12px;margin:8px 0;overflow-x:auto}
        .bubble pre code{background:none;padding:0;font-size:12px}
        .bubble ul,
        .bubble ol{padding-left:20px;margin:.4em 0}
        .bubble li{margin-bottom:.25em}
        .bubble h1,.bubble h2,.bubble h3{margin:.6em 0 .3em;line-height:1.3}
        .bubble h1{font-size:17px}.bubble h2{font-size:15px}.bubble h3{font-size:14px}
        .bubble hr{border:none;border-top:1px solid var(--border);margin:.8em 0}
        .bubble a{color:var(--agent-color);text-decoration:none}
        .bubble a:hover{text-decoration:underline}

        /* TYPING */
        .typing .bubble{padding:14px 18px}
        .typing-dots{display:flex;gap:4px}
        .typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:bounce .8s infinite}
        .typing-dots span:nth-child(2){animation-delay:.15s}
        .typing-dots span:nth-child(3){animation-delay:.3s}
        @keyframes bounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-5px)}}

        /* WELCOME */
        .welcome{text-align:center;padding:32px 20px 16px;max-width:560px;margin:0 auto}
        .welcome-avatar{font-size:52px;margin-bottom:12px;filter:drop-shadow(0 0 20px var(--agent-color))}
        .welcome-name{font-size:22px;font-weight:800;color:var(--agent-color);margin-bottom:8px}
        .welcome-msg{font-size:14px;color:var(--muted);line-height:1.6;margin-bottom:18px}

        /* STARTER CHIPS */
        .starter-chips{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;max-width:560px;margin:16px auto 0}
        .chip{background:var(--bg3);border:1px solid var(--border);border-radius:20px;padding:7px 14px;font-size:12px;color:var(--muted);cursor:pointer;transition:all .15s;text-align:left;line-height:1.4}
        .chip:hover{border-color:var(--agent-color);color:var(--text);background:color-mix(in srgb,var(--agent-color) 8%,var(--bg3))}
        @media(max-width:640px){.chip{font-size:11px;padding:6px 11px}}

        /* INPUT */
        .input-area{background:var(--bg2);border-top:1px solid var(--border);padding:16px 20px;flex-shrink:0}
        .input-row{display:flex;gap:8px;align-items:flex-end;max-width:800px;margin:0 auto}
        .input-box{flex:1;background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:10px 14px;color:var(--text);font-size:14px;resize:none;outline:none;max-height:140px;min-height:44px;line-height:1.5;transition:border-color .15s;font-family:inherit}
        .input-box:focus{border-color:var(--agent-color)}
        .attach-btn{width:40px;height:40px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);font-size:17px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.15s;margin-bottom:2px}
        .attach-btn:hover{border-color:var(--agent-color);color:var(--text)}
        .send-btn{width:44px;height:44px;background:var(--agent-color);border:none;border-radius:10px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.15s}
        .send-btn svg{width:18px;height:18px;flex-shrink:0}
        .send-btn:hover{filter:brightness(1.1)}
        .send-btn:disabled{opacity:.4;cursor:not-allowed}
        /* 2026-05-18: Stop button — interrompe streaming activo. */
        .stop-btn{width:44px;height:44px;background:#dc2626;border:none;border-radius:10px;cursor:pointer;flex-shrink:0;display:none;align-items:center;justify-content:center;transition:.15s;color:#fff}
        .stop-btn svg{width:16px;height:16px}
        .stop-btn:hover{background:#b91c1c}
        .stop-btn.visible{display:flex}
        .input-hint{text-align:center;font-size:11px;color:var(--muted);margin-top:8px;max-width:800px;margin:8px auto 0}
        /* File preview */
        .file-preview-bar{display:none;align-items:center;gap:8px;padding:6px 10px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;font-size:13px;color:var(--muted);margin-bottom:8px;max-width:800px;margin-left:auto;margin-right:auto}
        .file-preview-bar img{max-height:40px;border-radius:4px}
        .file-preview-bar button{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;margin-left:auto;padding:0 4px}
        .file-preview-bar button:hover{color:var(--text)}

        /* Markdown tables rendered inside the bubble */
        .bubble table{border-collapse:collapse;width:100%;margin:8px 0;font-size:12.5px}
        .bubble th{background:var(--bg3);font-weight:700;padding:6px 9px;border:1px solid var(--border);text-align:left;white-space:nowrap}
        .bubble td{padding:5px 9px;border:1px solid var(--border)}
        .bubble tr:nth-child(even) td{background:color-mix(in srgb,var(--bg3) 50%,transparent)}

        /* Export toolbar (PDF / Excel) — shown under each AI bubble */
        .msg-actions{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
        .msg-actions button{background:var(--bg3);border:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:.15s}
        .msg-actions button:hover{border-color:var(--agent-color);color:var(--text)}
        .msg-actions .excel-btn:hover{background:#217346;color:#fff;border-color:#217346}

        /* ── Daniel Email card (Outlook / Copy / Edit) ───────────────────
           Rendered when the Email agent streams an `__EMAIL__` + JSON
           payload. Theme-aware via CSS vars; accent uses --agent-color so
           it stays on-brand for Daniel (purple) across light/dark mode. */
        .email-card{background:var(--bg2);border:1px solid var(--border);border-left:3px solid var(--agent-color);border-radius:12px;overflow:hidden;margin:2px 0;max-width:680px}
        .email-card-header{background:color-mix(in srgb,var(--agent-color) 10%,var(--bg3));padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
        .email-card-header .ectl{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .email-card-header span{font-size:12px;font-weight:700;color:var(--agent-color)}
        .email-card-header small{font-size:11px;color:var(--muted)}
        .email-field{padding:8px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
        .email-field label{font-size:10px;color:var(--muted);min-width:52px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
        .email-field input{flex:1;background:transparent;border:none;color:var(--text);font-size:13px;outline:none;font-family:inherit}
        .email-field input:focus{color:var(--agent-color)}
        .email-body-area{padding:12px 14px;font-size:13px;color:var(--text);line-height:1.65;white-space:pre-wrap;max-height:240px;overflow-y:auto;border-bottom:1px solid var(--border);outline:none}
        .email-body-area:focus{background:color-mix(in srgb,var(--agent-color) 4%,var(--bg2))}
        .email-actions{padding:10px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:color-mix(in srgb,var(--agent-color) 6%,var(--bg3))}
        .email-outlook-btn{background:#0078d4;color:#fff;border:none;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:.15s}
        .email-outlook-btn:hover{background:#006cbe}
        .email-outlook-btn svg{flex-shrink:0}
        .email-copy-btn{background:transparent;color:var(--muted);border:1px solid var(--border);padding:8px 14px;border-radius:8px;font-size:12px;cursor:pointer;transition:.15s}
        .email-copy-btn:hover{border-color:var(--agent-color);color:var(--text)}
        .email-edit-btn{background:transparent;color:var(--muted);border:1px solid var(--border);padding:8px 12px;border-radius:8px;font-size:12px;cursor:pointer;transition:.15s}
        .email-edit-btn:hover{border-color:var(--agent-color);color:var(--text)}
        .email-status{font-size:11px;margin-left:auto;color:var(--muted)}
        .email-status.sent{color:#22c55e}
        .email-status.err{color:#ef4444}
        @media(max-width:640px){
            .email-body-area{max-height:180px;font-size:12.5px}
            .email-field input{font-size:12.5px}
        }

        /* MARKDOWN render */
        @media(max-width:640px){
            .header{padding:0 12px}
            .messages{padding:12px}
            .input-area{padding:12px}
            .bubble{font-size:13px}
            .msg-actions button{font-size:10px;padding:3px 8px}
        }

        /* Structured token cards (TABLE/CHART/PPT) — 2026-05-17 visual polish.
           Usa --agent-color para destaque (passada via inline style do PHP). */
        .table-card{
            --tc-accent: var(--agent-color, #76b900);
            background: var(--bg2);
            border: 1px solid color-mix(in srgb, var(--tc-accent) 18%, transparent);
            border-left: 4px solid var(--tc-accent);
            border-radius: 14px;
            margin: 12px 0;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0,0,0,0.18), 0 1px 0 rgba(255,255,255,0.03) inset;
            animation: tc-fade-in 0.32s ease-out;
        }
        @keyframes tc-fade-in { from { opacity:0; transform: translateY(6px); } to { opacity:1; transform: translateY(0); } }
        .table-card-header{
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 18px;
            background: linear-gradient(90deg, color-mix(in srgb, var(--tc-accent) 14%, transparent) 0%, transparent 70%);
            border-bottom: 1px solid color-mix(in srgb, var(--tc-accent) 18%, transparent);
            font-size: 13px;
            font-weight: 700;
            color: var(--tc-accent);
            gap: 12px;
        }
        .table-card-header small{
            opacity: .7;
            font-size: 11px;
            font-weight: 500;
            color: var(--text-soft);
            background: rgba(255,255,255,0.04);
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
        }
        .table-wrap{ overflow-x: auto; max-height: 440px; overflow-y: auto; scroll-behavior: smooth; }
        .table-wrap table{ width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .table-wrap th, .table-wrap td{
            padding: 9px 14px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: var(--text);
            vertical-align: top;
            line-height: 1.45;
        }
        .table-wrap th{
            background: rgba(0,0,0,0.35);
            color: color-mix(in srgb, var(--tc-accent) 75%, #ffffff);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            position: sticky;
            top: 0;
            z-index: 2;
            border-bottom: 1px solid color-mix(in srgb, var(--tc-accent) 22%, #000);
        }
        .table-wrap tbody tr:nth-child(even) td { background: rgba(255,255,255,0.018); }
        .table-wrap tbody tr:hover td {
            background: color-mix(in srgb, var(--tc-accent) 8%, transparent);
            transition: background 0.12s ease;
        }
        .table-analysis{
            padding: 11px 18px;
            background: color-mix(in srgb, var(--tc-accent) 6%, transparent);
            color: var(--text-soft);
            font-size: 12.5px;
            line-height: 1.6;
            border-top: 1px solid rgba(255,255,255,0.04);
        }
        .table-recommendation{
            padding: 10px 18px 12px;
            background: rgba(244,195,97,.06);
            color: #f4c361;
            font-size: 12.5px;
            line-height: 1.55;
            border-top: 1px solid rgba(255,255,255,0.04);
            font-weight: 600;
        }
        .table-actions{
            display: flex;
            gap: 8px;
            padding: 10px 16px;
            background: rgba(0,0,0,0.22);
            border-top: 1px solid rgba(255,255,255,0.04);
            flex-wrap: wrap;
        }
        .table-excel-btn, .table-copy-btn{
            background: var(--tc-accent);
            border: 1px solid var(--tc-accent);
            color: #fff;
            padding: 7px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: transform 0.1s, filter 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .table-excel-btn:hover, .table-copy-btn:hover { filter: brightness(1.15); transform: translateY(-1px); }
        /* Light theme overrides */
        :root[data-theme="light"] .table-card { background: #ffffff; box-shadow: 0 4px 14px rgba(0,0,0,0.06); }
        :root[data-theme="light"] .table-card td { color: #374151; border-bottom-color: rgba(0,0,0,0.06); }
        :root[data-theme="light"] .table-card th { background: rgba(0,0,0,0.04); color: var(--tc-accent); }
        :root[data-theme="light"] .table-card tbody tr:nth-child(even) td { background: rgba(0,0,0,0.025); }
        :root[data-theme="light"] .table-actions { background: rgba(0,0,0,0.03); }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="agent-avatar" style="{{ ($meta['photo'] ?? null) ? 'padding:0;overflow:hidden' : '' }}">
        @if($meta['photo'] ?? null)
            <img src="{{ $meta['photo'] }}" alt="{{ $meta['name'] }}" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
        @else
            {{ $meta['emoji'] }}
        @endif
    </div>
    <div class="agent-info">
        {{-- Header ALWAYS shows the real agent name. custom_title is the
             portal-level label (same across every share in a batch) so
             using it here hides *which* agent the client is actually
             talking to. We render it as a small context line above. --}}
        @if($share->custom_title && $share->custom_title !== ($meta['name'] ?? ''))
            <div style="font-size:10px;color:#94a3b8;opacity:.7;text-transform:uppercase;letter-spacing:.6px;margin-bottom:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:460px">{{ $share->custom_title }}</div>
        @endif
        <div class="agent-name">{{ $meta['name'] ?? $share->agent_key }}</div>
        @if(!empty($meta['role']))
            <div style="font-size:11px;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:460px" title="{{ $meta['role'] }}">{{ $meta['role'] }}</div>
        @else
            <div class="agent-status"><span class="status-dot"></span> Online</div>
        @endif
    </div>
    @if($share->show_branding)
    <div class="branding">© PartYard/Setq.AI Rights reserved 2026</div>
    @endif
    <button type="button"
            class="theme-toggle"
            id="themeToggle"
            onclick="toggleClawTheme()"
            aria-label="Alternar modo claro/escuro"
            title="Alternar modo claro/escuro">
        <span id="themeIcon">🌙</span>
    </button>
</div>

<!-- CHAT -->
<div class="chat-wrap">
    <div class="messages" id="messages">
        <div class="welcome" id="welcome">
            <div class="welcome-avatar" style="{{ ($meta['photo'] ?? null) ? 'font-size:0' : '' }}">
                @if($meta['photo'] ?? null)
                    <img src="{{ $meta['photo'] }}" alt="{{ $meta['name'] }}" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--agent-color);box-shadow:0 0 24px color-mix(in srgb,var(--agent-color) 33%,transparent)">
                @else
                    {{ $meta['emoji'] }}
                @endif
            </div>
            {{-- Primary = real agent name. custom_title (portal label) is
                 rendered as a small subtitle so the client still sees the
                 portal context but knows exactly which agent this is. --}}
            <div class="welcome-name">{{ $meta['name'] ?? $share->agent_key }}</div>
            @if(!empty($meta['role']))
            <div class="welcome-role" style="font-size:13px;color:var(--agent-color);font-weight:600;margin-top:4px;margin-bottom:10px;opacity:.9">
                {{ $meta['role'] }}
            </div>
            @endif
            @if($share->custom_title && $share->custom_title !== ($meta['name'] ?? ''))
            <div style="font-size:11px;color:#94a3b8;opacity:.65;margin-bottom:10px">{{ $share->custom_title }}</div>
            @endif
            <div class="welcome-msg">
                @if($share->welcome_message)
                    {{ $share->welcome_message }}
                @else
                    Olá! Sou o <strong>{{ $meta['name'] }}</strong>. Como posso ajudar?
                @endif
            </div>
            <div class="starter-chips" id="starter-chips"></div>
        </div>
    </div>

    <div class="input-area">
        <!-- File preview bar -->
        <div class="file-preview-bar" id="file-preview-bar">
            <img id="fp-img" src="" alt="" style="display:none">
            <span id="fp-icon" style="font-size:20px"></span>
            <span id="fp-name" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
            <span id="fp-size" style="font-size:11px;color:#475569"></span>
            <button onclick="clearAttachment()" title="Remover ficheiro">✕</button>
        </div>
        <div class="input-row">
            <button type="button" class="attach-btn" title="Anexar ficheiros — múltiplos permitidos (PDF, imagem, Excel, Word, TXT, Email)" onclick="document.getElementById('file-input').click()">📎</button>
            <input type="file" id="file-input" accept="image/*,.pdf,.doc,.docx,.txt,.csv,.xlsx,.xls,.pptx,.md,.eml,.msg" multiple style="position:absolute;width:0;height:0;opacity:0;pointer-events:none">
            <textarea
                id="input"
                class="input-box"
                placeholder="Escreve a tua mensagem…"
                rows="1"
                onkeydown="handleKey(event)"
                oninput="autoResize(this)"
            ></textarea>
            <button class="send-btn" id="send-btn" onclick="sendMessage()">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
            {{-- 2026-05-18: botão Stop — pedido directo do operador
                 "possibilidade de clicar em stop num botão para
                 interromper o chat". Visível apenas durante streaming. --}}
            <button class="stop-btn" id="stop-btn" onclick="stopStreaming()" title="Parar resposta">
                <svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
            </button>
        </div>
        @if($share->show_branding)
        <div class="input-hint">© PartYard/Setq.AI Rights reserved 2026</div>
        @endif
    </div>
</div>

<script>
const TOKEN      = '{{ $share->token }}';
const CSRF       = document.querySelector('meta[name="csrf-token"]').content;
// 2026-05-18 — SECURITY: o session_id usado para namespacing do
// histórico é AGORA derivado server-side de um cookie HttpOnly
// (`share_chat_{id}`, 365d). Aplicação directa do pedido do operador:
//   "quando se copia os links para outro browser o sistema está a
//    inserir as conversas de outros users"
// O servidor passou a IGNORAR qualquer session_id que o cliente envie
// — o cookie HttpOnly é a identidade do browser e é impossível de
// forjar a partir de JS. Por compatibilidade enviamos um marker no
// body/query, mas o backend descarta-o.
const SESSION_ID = 'cookie-bound';
const AGENT_EMOJI = '{{ $meta['emoji'] }}';
// Public session id minted by show() — sent back as a header on every stream
// call so the server can look up the authorised OTP session regardless of
// how cookies happen to flow between web and api middleware groups.
const SHARE_SID  = '{{ $share_sid ?? '' }}';
const AGENT_PHOTO = '{{ $meta['photo'] ?? '' }}';
const AGENT_COLOR = '{{ $meta['color'] }}';
const AGENT_KEY   = '{{ $share->agent_key }}';
let history = [];
let isStreaming = false;
// 2026-05-18: AbortController para o botão Stop — referência guardada
// para que o handler stopStreaming() consiga cancelar o fetch SSE em
// curso. Limpa para null sempre que o stream termina (normal ou abort).
let streamAbortCtrl = null;

// ── File attachment state ──────────────────────────────────────────────────
let attachImg     = null;
let attachImgType = 'image/jpeg';
let attachFiles   = [];   // array: { name, type, ext, b64, text, size }

const FILE_ICONS = {'pdf':'📄','doc':'📝','docx':'📝','txt':'📃','csv':'📊','xlsx':'📊','xls':'📊','pptx':'📑','md':'📃','eml':'📧','msg':'📧'};
function getFileIcon(n){ const e=n.split('.').pop().toLowerCase(); return FILE_ICONS[e]||'📎'; }
function humanSize(b){ if(b<1024) return b+' B'; if(b<1048576) return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(1)+' MB'; }

function readOneFileShare(file) {
    return new Promise(resolve => {
        const ext    = file.name.split('.').pop().toLowerCase();
        const asText = ['txt','csv','md','eml','msg'].includes(ext);
        const reader = new FileReader();
        if (file.type.startsWith('image/')) {
            reader.onload = ev => resolve({ name: file.name, type: file.type, ext, isImage: true,
                b64: ev.target.result.split(',')[1], imgSrc: ev.target.result, size: humanSize(file.size) });
            reader.readAsDataURL(file);
        } else {
            reader.onload = ev => resolve({ name: file.name, type: file.type||'application/octet-stream', ext, isImage: false,
                b64: asText ? null : ev.target.result.split(',')[1],
                text: asText ? ev.target.result : null, size: humanSize(file.size) });
            if (asText) reader.readAsText(file); else reader.readAsDataURL(file);
        }
    });
}

function updateAttachPreview() {
    const bar = document.getElementById('file-preview-bar');
    if (attachImg) {
        document.getElementById('fp-img').style.display = 'block';
        document.getElementById('fp-icon').textContent  = '';
        document.getElementById('fp-name').textContent  = 'Imagem';
        document.getElementById('fp-size').textContent  = '';
        bar.style.display = 'flex'; return;
    }
    if (!attachFiles.length) { bar.style.display = 'none'; return; }
    document.getElementById('fp-img').style.display = 'none';
    if (attachFiles.length === 1) {
        document.getElementById('fp-icon').textContent = getFileIcon(attachFiles[0].name);
        document.getElementById('fp-name').textContent = attachFiles[0].name;
        document.getElementById('fp-size').textContent = attachFiles[0].size;
    } else {
        document.getElementById('fp-icon').textContent = '📎';
        document.getElementById('fp-name').textContent = attachFiles.length + ' ficheiros';
        document.getElementById('fp-size').textContent = attachFiles.map(f=>f.name).join(', ').substring(0,50)+'…';
    }
    bar.style.display = 'flex';
}

document.getElementById('file-input').addEventListener('change', async function(e){
    const files = Array.from(e.target.files);
    if (!files.length) return;
    // Large files sent as-is; error shown in chat bubble if server rejects
    const read = await Promise.all(files.map(f => readOneFileShare(f)));
    const imgFile = read.find(f => f.isImage);
    if (imgFile) {
        attachImg = imgFile.b64; attachImgType = imgFile.type;
        document.getElementById('fp-img').src = imgFile.imgSrc;
        attachFiles = read.filter(f => !f.isImage);
    } else {
        attachImg   = null;
        attachFiles = [...attachFiles, ...read]; // accumulate
    }
    updateAttachPreview();
});

function clearAttachment() {
    attachImg = null; attachImgType = 'image/jpeg'; attachFiles = [];
    document.getElementById('file-preview-bar').style.display = 'none';
    document.getElementById('fp-img').style.display = 'none';
    document.getElementById('file-input').value = '';
}

// ── Starter chips per agent ───────────────────────────────────────────────
// Resolved server-side via AgentChipsService::forAgent() so we get the
// correct cascade: curated chips → AgentCatalog::starters() → auto. This
// avoids the empty/mismatched-prompts bug when a share is for an agent
// that AgentChipsService doesn't explicitly define (e.g. mildef, crm).
const AGENT_CHIPS = @json(\App\Services\AgentChipsService::forAgent($share->agent_key));

function renderStarterChips() {
    const chips = AGENT_CHIPS || [];
    const container = document.getElementById('starter-chips');
    if (!container || !chips.length) return;
    container.innerHTML = chips.map(c =>
        `<button class="chip" onclick="useChip(this)">${c}</button>`
    ).join('');
}

function useChip(btn) {
    const input = document.getElementById('input');
    input.value = btn.textContent;
    autoResize(input);
    input.focus();
    // Remove chips after selection
    document.getElementById('starter-chips')?.remove();
}

// ── Auto-resize textarea ──
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 140) + 'px';
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

// ── Send message ──
async function sendMessage() {
    const input = document.getElementById('input');
    let text    = input.value.trim();
    if ((!text && !attachImg && !attachFile) || isStreaming) return;
    if (!text) text = attachImg ? 'O que vês nesta imagem?' : (attachFile?.name || '?');

    input.value = '';
    autoResize(input);
    document.getElementById('welcome')?.remove();
    document.getElementById('starter-chips')?.remove();
    document.getElementById('send-btn').disabled = true;
    document.getElementById('stop-btn').classList.add('visible');
    isStreaming = true;
    streamAbortCtrl = new AbortController();

    addMessage('user', text);
    history.push({ role: 'user', content: text });

    // Build payload — FormData for binary files, JSON for text-only
    let fetchBody, fetchHeaders;
    const textFiles   = attachFiles.filter(f => f.text !== null);
    const binaryFiles = attachFiles.filter(f => f.b64  !== null);
    const hasBinary   = !!(attachImg || binaryFiles.length);

    if (hasBinary) {
        const fd = new FormData();
        fd.append('message',    text);
        fd.append('session_id', SESSION_ID);
        history.slice(-20).forEach((m, i) => {
            fd.append(`history[${i}][role]`,    m.role);
            fd.append(`history[${i}][content]`, typeof m.content === 'string' ? m.content : JSON.stringify(m.content));
        });
        // Embed text files into message
        if (textFiles.length) {
            let extra = fd.get('message') || text;
            textFiles.forEach(f => { extra += `\n\n---\n**Ficheiro: ${f.name}**\n\`\`\`\n${f.text.substring(0,10000)}\n\`\`\``; });
            fd.set('message', extra);
        }
        if (attachImg) {
            const bytes = atob(attachImg); const arr = new Uint8Array(bytes.length);
            for (let i=0;i<bytes.length;i++) arr[i]=bytes.charCodeAt(i);
            fd.append('image_blob', new Blob([arr], {type:attachImgType}), 'image');
            fd.append('image_type', attachImgType);
        } else {
            const f = binaryFiles[0];
            const bytes = atob(f.b64); const arr = new Uint8Array(bytes.length);
            for (let i=0;i<bytes.length;i++) arr[i]=bytes.charCodeAt(i);
            fd.append('file_upload', new Blob([arr], {type:f.type}), f.name);
            fd.append('file_name', f.name); fd.append('file_type', f.type);
            // Extra binary files noted in message
            binaryFiles.slice(1).forEach(fb => fd.set('message', (fd.get('message')||'')+`\n[Ficheiro adicional: ${fb.name}]`));
        }
        fetchBody    = fd;
        fetchHeaders = { 'Accept': 'text/event-stream' };
        clearAttachment();
    } else {
        const payload = { message: text, history: history.slice(-20), session_id: SESSION_ID };
        textFiles.forEach(f => {
            payload.message += `\n\n---\n**Ficheiro: ${f.name}**\n\`\`\`\n${f.text.substring(0,12000)}\n\`\`\``;
        });
        clearAttachment();
        fetchBody    = JSON.stringify(payload);
        fetchHeaders = { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' };
    }

    const typingEl = addTyping();

    try {
        // Attach the per-session id so the backend can re-validate the OTP
        // session on every stream call (the cookie approach was unreliable
        // across the web→api middleware split).
        if (SHARE_SID) fetchHeaders['X-Share-SID'] = SHARE_SID;

        const resp = await fetch(`/api/a/${TOKEN}/stream`, {
            method: 'POST',
            headers: fetchHeaders,
            body:    fetchBody,
            // 2026-05-18: 'include' (era 'same-origin') para garantir
            // que o cookie HttpOnly `share_chat_{id}` viaja na chamada
            // mesmo em embeds/subdomain — sem ele, o servidor cria um
            // novo session per request e o histórico não persiste.
            credentials: 'include',
            // 2026-05-18: signal para suportar abort via botão Stop.
            signal: streamAbortCtrl?.signal,
        });

        // Handle HTTP errors (413 Too Large, 422 Unprocessable, 500, etc.)
        if (!resp.ok) {
            typingEl?.remove();
            let errMsg = `Erro ${resp.status}`;
            let reauth = false;
            try {
                const j = await resp.json();
                errMsg = j.error || errMsg;
                reauth = !!j.reauth;
            } catch(_){}
            addMessage('assistant', `❌ ${errMsg}`);
            document.getElementById('send-btn').disabled = false;
            document.getElementById('stop-btn').classList.remove('visible');
            isStreaming = false;
            streamAbortCtrl = null;
            // Sessão expirou / revogada → reenvia o visitante para a OTP gate.
            if (reauth) setTimeout(() => { window.location.href = '/a/' + TOKEN; }, 1500);
            return;
        }

        typingEl.remove();
        const bubble = addAssistantBubble();

        let full = '';
        const reader = resp.body.getReader();
        const decoder = new TextDecoder();
        let buf = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                const raw = line.slice(6).trim();
                if (raw === '[DONE]') break;
                if (raw.startsWith(':')) continue;
                try {
                    const evt = JSON.parse(raw);
                    if (evt.chunk) {
                        full += evt.chunk;
                        // While streaming, once we see the __EMAIL__ marker stop
                        // rendering as markdown — the rest of the stream is JSON
                        // that will be parsed into a proper email card on final.
                        if (full.startsWith('__EMAIL__')) {
                            bubble.innerHTML = '<div style="color:var(--muted);font-size:12px">📧 A preparar email…</div>';
                        } else if (full.includes('__TABLE__') || full.includes('__CHART__') || full.includes('__PPT__')) {
                            // While the structured token is still streaming the
                            // JSON may be incomplete — show a discreet placeholder
                            // and avoid rendering broken markdown. Final pass
                            // below will swap in the real table/chart/ppt card.
                            bubble.innerHTML = '<div style="color:var(--muted);font-size:12px">📊 A preparar tabela/gráfico…</div>';
                        } else {
                            bubble.innerHTML = renderMarkdown(full);
                        }
                        scrollBottom();
                    }
                    if (evt.error) {
                        bubble.innerHTML = '<span style="color:#ef4444">❌ ' + evt.error + '</span>';
                    }
                    // 2026-05-18: evento "critique" — server enviou o resultado
                    // da auto-validação após o stream terminar. Anexa um badge
                    // expansível no fim da bubble com o veredicto + issues.
                    if (evt.type === 'critique' && evt.payload) {
                        try { renderCritiqueBadge(bubble, evt.payload); } catch (_) {}
                    }
                } catch(e) {}
            }
        }

        if (full) {
            // Daniel Email: if the agent streamed an __EMAIL__ JSON payload,
            // render a dedicated card with Outlook / Copy / Edit buttons
            // instead of markdown (parity with the main welcome.blade.php UX).
            if (full.startsWith('__EMAIL__')) {
                try {
                    const emailData = JSON.parse(full.replace('__EMAIL__', ''));
                    bubble.innerHTML = buildEmailCard(emailData);
                    // Store a human-readable version in history so subsequent
                    // turns don't see the raw __EMAIL__ marker (would confuse
                    // the agent into echoing its own JSON back).
                    const summary = `Email gerado — Para: ${emailData.to||''} · Assunto: ${emailData.subject||''}`;
                    history.push({ role: 'assistant', content: summary });
                } catch (e) {
                    history.push({ role: 'assistant', content: full });
                    bubble.innerHTML = renderMarkdown(full.replace('__EMAIL__', ''));
                    addMsgActions(bubble);
                }
            } else {
                history.push({ role: 'assistant', content: full });
                // Re-render final HTML so pipe-tables become real <table>
                // elements AND so any structured __TABLE__ / __CHART__ / __PPT__
                // tokens get materialised into proper cards (parity with the
                // main ClawYard view). Charts are instantiated after the DOM
                // node is live.
                const rendered = renderAgentOutput(full);
                bubble.innerHTML = rendered.html;
                (rendered.chartsToInit || []).forEach(c => {
                    try { renderChart(c.data, c.canvasId); } catch (_) {}
                });
                addMsgActions(bubble);
            }
        }

    } catch(e) {
        typingEl?.remove();
        if (e.name === 'AbortError') {
            // Stop foi clicado — não mostramos erro, só marca a bubble
            // como interrompida (se já existir conteúdo parcial).
            addMessage('assistant', '⏹️ Resposta interrompida.');
        } else {
            addMessage('assistant', '❌ Erro de ligação: ' + (e.message || 'Tenta novamente.'));
        }
    }

    document.getElementById('send-btn').disabled = false;
    document.getElementById('stop-btn').classList.remove('visible');
    isStreaming = false;
    streamAbortCtrl = null;
}

// 2026-05-18: handler do botão Stop. Aborta o fetch SSE em curso via
// AbortController — o catch acima trata o AbortError silenciosamente.
function stopStreaming() {
    if (streamAbortCtrl) {
        try { streamAbortCtrl.abort(); } catch (e) {}
    }
}

// 2026-05-18: badge de auto-crítica. Mostra resultado da second-pass de
// validação contra hallucinations (ver app/Services/AgentSelfCritique).
// payload: {verdict, confidence, issues[{severity,category,text}]}
function renderCritiqueBadge(bubble, payload) {
    const verdict = payload.verdict || 'ok';
    const issues  = Array.isArray(payload.issues) ? payload.issues : [];
    const conf    = typeof payload.confidence === 'number' ? payload.confidence : 1;

    // Mapping veredicto → cor + ícone + label
    const styles = {
        ok:    { color: '#16a34a', bg: '#dcfce7', icon: '✓', label: 'Auto-validado' },
        minor: { color: '#ca8a04', bg: '#fef9c3', icon: '⚠', label: 'Validado com ressalvas' },
        major: { color: '#dc2626', bg: '#fee2e2', icon: '⚠', label: 'Atenção — issues importantes' },
        block: { color: '#7f1d1d', bg: '#fecaca', icon: '⛔', label: 'Revisão humana recomendada' },
    };
    const s = styles[verdict] || styles.ok;

    const badge = document.createElement('div');
    badge.style.cssText = `margin-top:10px;padding:6px 10px;border-radius:6px;background:${s.bg};color:${s.color};font-size:11px;display:inline-flex;align-items:center;gap:6px;cursor:${issues.length?'pointer':'default'};user-select:none;`;
    badge.innerHTML = `<span style="font-weight:700">${s.icon} ${s.label}</span>`
                    + (issues.length ? `<span style="opacity:.75">· ${issues.length} ${issues.length===1?'nota':'notas'}</span>` : '')
                    + `<span style="opacity:.6;font-size:10px">· conf ${Math.round(conf*100)}%</span>`;

    if (issues.length) {
        const details = document.createElement('div');
        details.style.cssText = 'display:none;margin-top:8px;padding:8px 10px;background:rgba(0,0,0,.04);border-radius:6px;font-size:11px;color:var(--text);line-height:1.5;';
        details.innerHTML = issues.map(it => {
            const sev = (it.severity||'low').toUpperCase();
            const cat = (it.category||'-').toUpperCase();
            return `<div style="margin:4px 0"><strong style="color:${s.color}">${sev}</strong> · ${cat} — ${escapeHtml(it.text||'')}</div>`;
        }).join('');
        badge.addEventListener('click', () => {
            details.style.display = details.style.display === 'none' ? 'block' : 'none';
        });

        const wrap = document.createElement('div');
        wrap.appendChild(badge);
        wrap.appendChild(details);
        bubble.appendChild(wrap);
    } else {
        bubble.appendChild(badge);
    }
}

function makeAgentAvatar() {
    const avatar = document.createElement('div');
    avatar.className = 'avatar';
    if (AGENT_PHOTO) {
        avatar.style.cssText = 'padding:0;overflow:hidden;border:1.5px solid var(--border)';
        avatar.innerHTML = `<img src="${AGENT_PHOTO}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
    } else {
        avatar.textContent = AGENT_EMOJI;
    }
    return avatar;
}

function addMessage(role, text) {
    const msgs = document.getElementById('messages');
    const div  = document.createElement('div');
    div.className = 'message ' + role;

    const avatar = role === 'user' ? (() => {
        const a = document.createElement('div');
        a.className = 'avatar';
        a.textContent = 'You';
        return a;
    })() : makeAgentAvatar();

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.innerHTML = role === 'user' ? escapeHtml(text) : renderMarkdown(text);

    div.appendChild(avatar);
    div.appendChild(bubble);
    msgs.appendChild(div);
    scrollBottom();
    return bubble;
}

function addAssistantBubble() {
    const msgs = document.getElementById('messages');
    const div  = document.createElement('div');
    div.className = 'message assistant';

    const bubble = document.createElement('div');
    bubble.className = 'bubble';

    div.appendChild(makeAgentAvatar());
    div.appendChild(bubble);
    msgs.appendChild(div);
    scrollBottom();
    return bubble;
}

function addTyping() {
    const msgs = document.getElementById('messages');
    const div  = document.createElement('div');
    div.className = 'message assistant typing';

    const avatar = makeAgentAvatar();

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div>';

    div.appendChild(avatar);
    div.appendChild(bubble);
    msgs.appendChild(div);
    scrollBottom();
    return div;
}

function scrollBottom() {
    const msgs = document.getElementById('messages');
    msgs.scrollTop = msgs.scrollHeight;
}

function escapeHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

// Render chips on load
renderStarterChips();

// ───────────────────────────────────────────────────────────────────────
// STRUCTURED TOKENS (TABLE/CHART/PPT) — paridade com welcome.blade
// Sem isto, agentes como Cor. Rodrigues, Dr.ª Ana RH, Ana Marketing
// emitem __TABLE__{...} que aparece como JSON cru em links partilhados
// (DLoren Wfit, etc).
// ───────────────────────────────────────────────────────────────────────
let _chartCardCounter = 0;
let _tableCardCounter = 0;

function escTok(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]);
}

function sanitizeLlmJson(s) {
    // Step 1 — substituições globais
    s = s
        .replace(/[“”„‟″❝❞]/g, '"')
        .replace(/[‘’‚‛′❛❜]/g, '\'')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/,(\s*[}\]])/g, '$1');

    // Step 2 — escape de control chars dentro de strings JSON.
    // Fix 2026-05-17: o LLM pode emitir newlines literais dentro de
    // valores compridos (descrições, analysis, recommendation) que
    // JSON.parse rejeita. Sem este pass, o token __TABLE__ ficava em
    // raw no chat da DLoren Wfit (share #103).
    let out = '', inString = false, escape = false;
    for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (escape) { out += ch; escape = false; continue; }
        if (ch === '\\') { out += ch; escape = true; continue; }
        if (ch === '"')  { out += ch; inString = !inString; continue; }
        if (inString) {
            const code = ch.charCodeAt(0);
            if (ch === '\n') { out += '\\n'; continue; }
            if (ch === '\r') { out += '\\r'; continue; }
            if (ch === '\t') { out += '\\t'; continue; }
            if (code < 0x20) { out += '\\u' + code.toString(16).padStart(4,'0'); continue; }
        }
        out += ch;
    }
    return out;
}

function parseStructuredBlocks(text) {
    const TOKENS = ['__TABLE__', '__CHART__', '__PPT__'];
    const blocks = [];
    let cursor = 0;
    while (cursor < text.length) {
        let earliest = -1, earliestToken = null;
        for (const tok of TOKENS) {
            const i = text.indexOf(tok, cursor);
            if (i !== -1 && (earliest === -1 || i < earliest)) { earliest = i; earliestToken = tok; }
        }
        if (earliest === -1) {
            const rest = text.slice(cursor);
            if (rest.trim()) blocks.push({ type: 'text', content: rest });
            break;
        }
        if (earliest > cursor) {
            const pre = text.slice(cursor, earliest);
            if (pre.trim()) blocks.push({ type: 'text', content: pre });
        }
        let i = earliest + earliestToken.length;
        while (i < text.length && text[i] !== '{') i++;
        if (i >= text.length) break;
        let depth = 0, inString = false, escape = false;
        const start = i;
        for (; i < text.length; i++) {
            const ch = text[i];
            if (escape) { escape = false; continue; }
            if (ch === '\\') { escape = true; continue; }
            if (ch === '"' || ch === '“' || ch === '”') { inString = !inString; continue; }
            if (inString) continue;
            if (ch === '{') depth++;
            else if (ch === '}') { depth--; if (depth === 0) { i++; break; } }
        }
        if (depth !== 0) { blocks.push({ type: 'text', content: text.slice(earliest) }); break; }
        const json = text.slice(start, i);
        let data = null;
        try { data = JSON.parse(json); }
        catch (e1) {
            try { data = JSON.parse(sanitizeLlmJson(json)); }
            catch (e2) {
                blocks.push({ type: 'text', content: text.slice(earliest, i) });
                cursor = i;
                continue;
            }
        }
        const valid =
            (earliestToken === '__TABLE__' && Array.isArray(data.columns) && Array.isArray(data.rows)) ||
            (earliestToken === '__CHART__' && Array.isArray(data.labels)  && Array.isArray(data.datasets)) ||
            (earliestToken === '__PPT__'   && Array.isArray(data.slides));
        if (valid) {
            if (earliestToken === '__TABLE__') blocks.push({ type: 'table', data });
            else if (earliestToken === '__CHART__') {
                blocks.push({ type: 'chart', data, canvasId: 'chart_' + Date.now() + '_' + (++_chartCardCounter) });
            } else blocks.push({ type: 'ppt', data });
        } else {
            blocks.push({ type: 'text', content: text.slice(earliest, i) });
        }
        cursor = i;
    }
    return blocks;
}

function buildTableCard(data) {
    const id = 'tbl_' + Date.now() + '_' + (++_tableCardCounter);
    const headers = data.columns.map(c => `<th>${escTok(c)}</th>`).join('');
    const rows = data.rows.map(r => `<tr>${r.map(c => `<td>${escTok(String(c))}</td>`).join('')}</tr>`).join('');
    return `
    <div class="table-card" id="${id}">
        <div class="table-card-header">
            <span>📊 ${escTok(data.title || 'Tabela')}</span>
            <small>${data.rows.length} itens</small>
        </div>
        <div class="table-wrap"><table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></div>
        ${data.analysis ? `<div class="table-analysis">🔍 ${escTok(data.analysis)}</div>` : ''}
        ${data.recommendation ? `<div class="table-recommendation">✅ ${escTok(data.recommendation)}</div>` : ''}
        <div class="table-actions">
            <button class="table-excel-btn" onclick="exportXlsx('${id}')">📥 Excel</button>
            <button class="table-excel-btn" onclick="exportCsv('${id}')" style="background:#475569;border-color:#475569">📄 CSV</button>
            <button class="table-excel-btn" onclick="exportTablePdf('${id}')" style="background:#a83232;border-color:#a83232">📑 PDF</button>
            <button class="table-copy-btn" onclick="copyTable('${id}')">📋 Copiar</button>
        </div>
    </div>`;
}

function buildChartCard(data, canvasId) {
    return `
    <div class="table-card">
        <div class="table-card-header">
            <span>📊 ${escTok(data.title || 'Gráfico')}</span>
            <small>${data.labels.length} pontos · ${data.type || 'bar'}</small>
        </div>
        <div style="padding:14px;background:var(--bg3)"><canvas id="${canvasId}" width="700" height="380"></canvas></div>
        ${data.analysis ? `<div class="table-analysis">🔍 ${escTok(data.analysis)}</div>` : ''}
        <div class="table-actions">
            <button class="table-excel-btn" onclick="exportChartPng('${canvasId}','${(data.title||'chart').replace(/'/g,'\\\'')}')">⬇ PNG</button>
        </div>
    </div>`;
}

function renderChart(data, canvasId) {
    if (typeof Chart === 'undefined') return setTimeout(() => renderChart(data, canvasId), 400);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const defaultColors = ['#76b900','#3b82f6','#ec4899','#f59e0b','#a855f7','#10b981','#ef4444','#06b6d4'];
    const datasets = data.datasets.map((d, idx) => ({
        label: d.label || `Série ${idx+1}`,
        data: d.data,
        backgroundColor: d.color || defaultColors[idx % defaultColors.length],
        borderColor: d.color || defaultColors[idx % defaultColors.length],
        borderWidth: 2, tension: 0.3,
    }));
    try {
        new Chart(canvas.getContext('2d'), {
            type: data.type || 'bar',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#e6e6e6' } } },
                scales: (['pie','doughnut','radar'].includes(data.type)) ? {} : {
                    x: { ticks: { color: '#9ca3af' }, grid: { color: '#2a2f36' } },
                    y: { ticks: { color: '#9ca3af' }, grid: { color: '#2a2f36' } },
                },
            },
        });
    } catch (e) { console.error('Chart render failed:', e); }
}

function buildPptCard(pd) {
    const slides = pd.slides || [];
    return `
    <div class="table-card">
        <div class="table-card-header">
            <span>📊 ${escTok(pd.title || 'PowerPoint')}</span>
            <small>${slides.length} slide${slides.length===1?'':'s'}</small>
        </div>
        <div style="padding:14px;color:var(--text);font-size:13px;line-height:1.6">
            ${pd.author ? `<div style="opacity:.65;margin-bottom:8px">Autor: ${escTok(pd.author)}</div>` : ''}
            <ol style="margin:0;padding-left:20px">${slides.map(s => `<li>${escTok(s.title || '(sem título)')}</li>`).join('')}</ol>
        </div>
        <div class="table-actions">
            <button class="table-excel-btn" onclick='exportPpt(${JSON.stringify(pd).replace(/'/g,"\\'")})' style="background:#c2410c;border-color:#c2410c">📊 Download .pptx</button>
        </div>
    </div>`;
}

// Exporters
function exportXlsx(id) {
    if (typeof XLSX === 'undefined') return exportCsv(id);
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','').trim() || 'tabela';
    const rows = Array.from(card.querySelectorAll('table tr')).map(tr =>
        Array.from(tr.querySelectorAll('th,td')).map(c => {
            const v = c.textContent.trim();
            if (/^-?\d+([.,]\d+)?$/.test(v)) return parseFloat(v.replace(',', '.'));
            return v;
        })
    );
    if (!rows.length) return;
    const ws = XLSX.utils.aoa_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, title.replace(/[^a-zA-Z0-9 _-]/g,'').slice(0,31) || 'Tabela');
    XLSX.writeFile(wb, title.replace(/[^a-zA-Z0-9_\-]/g,'_').slice(0,80) + '.xlsx');
}
function exportCsv(id) {
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','').trim() || 'tabela';
    const rows = Array.from(card.querySelectorAll('table tr'));
    const csv = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => '"' + c.textContent.replace(/"/g,'""') + '"').join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = title.replace(/[^a-zA-Z0-9_\-]/g,'_') + '.csv'; a.click();
}
function exportTablePdf(id) {
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','').trim() || 'tabela';
    const tableHtml = card.querySelector('table').outerHTML;
    const w = window.open('', '_blank');
    w.document.write(`<!DOCTYPE html><html><head><title>${escTok(title)}</title><style>body{font-family:system-ui;padding:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f4f4f4}</style></head><body><h2>${escTok(title)}</h2>${tableHtml}<script>window.onload=()=>setTimeout(()=>window.print(),300);<\/script></body></html>`);
    w.document.close();
}
function copyTable(id) {
    const rows = Array.from(document.getElementById(id).querySelectorAll('table tr'));
    const tsv = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => c.textContent).join('\t')).join('\n');
    navigator.clipboard.writeText(tsv);
}
function exportChartPng(canvasId, title) {
    const c = document.getElementById(canvasId); if (!c) return;
    const a = document.createElement('a');
    a.href = c.toDataURL('image/png', 1.0);
    a.download = title.replace(/[^a-zA-Z0-9_\-]/g,'_') + '.png';
    a.click();
}
function exportPpt(pd) {
    if (typeof PptxGenJS === 'undefined') { alert('PptxGenJS a carregar — tenta novamente'); return; }
    const pptx = new PptxGenJS();
    pptx.author = pd.author || 'ClawYard';
    pptx.title = pd.title || 'ClawYard Deck';
    pptx.layout = 'LAYOUT_WIDE';
    const title = pptx.addSlide();
    title.background = { color: '0F1115' };
    title.addText(pd.title || 'Deck', { x:0.5, y:2.5, w:12, h:1.2, fontSize:36, bold:true, color:'FFFFFF', align:'center' });
    if (pd.author) title.addText('por ' + pd.author, { x:0.5, y:4, w:12, h:0.6, fontSize:18, color:'EC4899', align:'center' });
    (pd.slides || []).forEach((s, idx) => {
        const slide = pptx.addSlide();
        slide.addText(s.title || `Slide ${idx+1}`, { x:0.5, y:0.3, w:12, h:0.8, fontSize:28, bold:true, color:'76B900' });
        if (Array.isArray(s.bullets)) {
            slide.addText(s.bullets.map(b => ({ text:b, options:{bullet:true} })), { x:0.5, y:1.5, w:12, h:5, fontSize:16, color:'222222' });
        } else if (s.body) {
            slide.addText(s.body, { x:0.5, y:1.5, w:12, h:5.5, fontSize:14, color:'222222' });
        }
    });
    pptx.writeFile({ fileName: (pd.title || 'deck').replace(/[^a-zA-Z0-9_\-]/g,'_').slice(0,60) + '.pptx' });
}

/**
 * Render output do agente: se tiver tokens TABLE/CHART/PPT, gera cards
 * mixed com markdown. Charts inicializados após DOM live.
 * Retorna { html, chartsToInit: [{data, canvasId}] }
 */
function renderAgentOutput(text) {
    if (!text.includes('__TABLE__') && !text.includes('__CHART__') && !text.includes('__PPT__')) {
        return { html: renderMarkdown(text), chartsToInit: [] };
    }
    const blocks = parseStructuredBlocks(text);
    if (!blocks.some(b => b.type !== 'text')) {
        return { html: renderMarkdown(text), chartsToInit: [] };
    }
    const chartsToInit = [];
    const html = blocks.map(b => {
        if (b.type === 'text') return `<div>${renderMarkdown(b.content)}</div>`;
        if (b.type === 'table') return buildTableCard(b.data);
        if (b.type === 'chart') { chartsToInit.push({ data: b.data, canvasId: b.canvasId }); return buildChartCard(b.data, b.canvasId); }
        if (b.type === 'ppt') return buildPptCard(b.data);
        return '';
    }).join('');
    return { html, chartsToInit };
}

// ── Simple markdown renderer ──
function renderMarkdown(md) {
    // Step 1 — pull markdown pipe-tables out BEFORE escaping so we can build
    // real <table> HTML. Finance/Sales/SAP agents answer with tables and the
    // shared view used to render them as plain text.
    const tablePlaceholders = [];
    md = md.replace(/(^|\n)((?:\|[^\n]*\|\s*\n)+)/g, (full, lead, block) => {
        const lines = block.trim().split('\n').map(l => l.trim()).filter(Boolean);
        if (lines.length < 2) return full; // need header + separator minimum
        const sep = lines[1];
        if (!/^\|?\s*:?-{2,}/.test(sep.replace(/\s/g,''))) return full; // not a table
        const splitRow = r => r.replace(/^\||\|$/g,'').split('|').map(c => c.trim());
        const head = splitRow(lines[0]);
        const body = lines.slice(2).map(splitRow);
        const th = head.map(c => `<th>${escMd(c)}</th>`).join('');
        const rows = body.map(r => '<tr>' + r.map(c => `<td>${escMd(c)}</td>`).join('') + '</tr>').join('');
        const html = `<table><thead><tr>${th}</tr></thead><tbody>${rows}</tbody></table>`;
        tablePlaceholders.push(html);
        return lead + `@@TABLE_${tablePlaceholders.length - 1}@@`;
    });

    let html = md
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        // Code blocks
        .replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
        // Inline code
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        // Bold
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        // Italic
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        // HR
        .replace(/^---$/gm, '<hr>')
        // H3
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        // H2
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        // H1
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        // Bullet lists
        .replace(/^[-•] (.+)$/gm, '<li>$1</li>')
        // Numbered lists
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        // Links
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
        // Newlines → paragraphs
        .split(/\n{2,}/).map(p => {
            if (p.startsWith('<h') || p.startsWith('<pre') || p.startsWith('<hr')) return p;
            if (/^@@TABLE_\d+@@$/.test(p.trim())) return p.trim();
            if (p.includes('<li>')) return '<ul>' + p + '</ul>';
            return '<p>' + p.replace(/\n/g,'<br>') + '</p>';
        }).join('');

    // Step 2 — put the real <table> blocks back in place.
    html = html.replace(/@@TABLE_(\d+)@@/g, (_, i) => tablePlaceholders[+i] || '');

    return html;
}
function escMd(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Per-message export toolbar (PDF + Excel) ─────────────────────────────
// Mirrors welcome.blade.php so external clients (e.g. Luís Finance recipients)
// can export analyses without needing access to the main dashboard.
function addMsgActions(bubble) {
    if (!bubble || bubble.parentElement?.querySelector('.msg-actions')) return;
    const hasTable = !!bubble.querySelector('table');
    const wrap = document.createElement('div');
    wrap.className = 'msg-actions';
    wrap.innerHTML = `
        <button type="button" class="pdf-btn"   title="Exportar esta resposta em PDF">📄 PDF</button>
        ${hasTable ? '<button type="button" class="excel-btn" title="Exportar tabela para Excel (CSV)">📥 Excel</button>' : ''}
    `;
    wrap.querySelector('.pdf-btn').addEventListener('click', () => exportBubblePDF(bubble));
    if (hasTable) wrap.querySelector('.excel-btn').addEventListener('click', () => exportBubbleExcel(bubble));
    bubble.insertAdjacentElement('afterend', wrap);
}

function exportBubblePDF(bubble) {
    const agentLabel = @json($meta['name'] ?? $share->agent_key);
    const date = new Date().toLocaleString('pt-PT', {day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const win = window.open('', '_blank', 'width=860,height=900');
    win.document.write(`<!DOCTYPE html>
<html lang="pt"><head>
<meta charset="UTF-8"><title>${agentLabel} — ${date}</title>
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;margin:40px;color:#1a1a1a;font-size:13.5px;line-height:1.65}
  h1{font-size:16px;color:#1a1a1a;margin-bottom:4px;border-bottom:2px solid ${AGENT_COLOR};padding-bottom:8px}
  .meta{font-size:11px;color:#666;margin-bottom:20px}
  table{border-collapse:collapse;width:100%;font-size:12px;margin:10px 0}
  th{background:#f0f0f0;font-weight:700;padding:7px 10px;border:1px solid #ccc;text-align:left}
  td{padding:6px 10px;border:1px solid #ddd}
  tr:nth-child(even) td{background:#fafafa}
  h2{font-size:14px;margin:14px 0 5px;color:#333}
  h3{font-size:13px;margin:10px 0 4px;color:#555}
  ul,ol{padding-left:20px} li{margin:2px 0}
  pre{background:#f6f6f6;border:1px solid #ddd;border-radius:6px;padding:12px;overflow-x:auto}
  hr{border:none;border-top:1px solid #ddd;margin:12px 0}
  .footer{margin-top:32px;font-size:10px;color:#999;border-top:1px solid #eee;padding-top:8px}
  @media print{body{margin:20px}.no-print{display:none}}
</style>
</head><body>
<h1>${agentLabel}</h1>
<div class="meta">Gerado em ${date} · ClawYard</div>
${bubble.innerHTML}
<div class="footer">ClawYard AI Platform · Documento gerado automaticamente</div>
<div class="no-print" style="margin-top:20px">
  <button onclick="window.print()" style="background:${AGENT_COLOR};color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer">🖨️ Imprimir / Guardar PDF</button>
  <button onclick="window.close()" style="background:#eee;color:#333;border:none;padding:10px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-left:8px">✕ Fechar</button>
</div>
</body></html>`);
    win.document.close();
    win.focus();
}

function exportBubbleExcel(bubble) {
    const tables = bubble.querySelectorAll('table');
    if (!tables.length) return;
    // Concatenate all tables in this bubble into one CSV (separated by blank line)
    const sheets = Array.from(tables).map(tbl => {
        return Array.from(tbl.querySelectorAll('tr')).map(tr =>
            Array.from(tr.querySelectorAll('th,td'))
                 .map(c => '"' + (c.innerText||c.textContent||'').replace(/"/g,'""').trim() + '"')
                 .join(',')
        ).join('\n');
    });
    const bom = '\uFEFF';
    const csv = bom + sheets.join('\n\n');
    const agentSlug = @json($share->agent_key);
    const stamp = new Date().toISOString().slice(0,10);
    const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = `${agentSlug}_${stamp}.csv`;
    document.body.appendChild(a); a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Daniel Email card ─────────────────────────────────────────────────────
// Mirrors welcome.blade.php so external portal clients using the Email agent
// get the same "compose → open in Outlook" experience. Direct /api/email/send
// is NOT exposed here (needs internal auth) — the Outlook button uses a
// mailto: link so the user finishes the send from their own client.
function buildEmailCard(data) {
    const id = 'em_' + Date.now();
    const langMap = { pt:'🇵🇹 PT', es:'🇪🇸 ES', en:'🇬🇧 EN' };
    const lang = langMap[data.language] || langMap.pt;
    const tpl  = data.template ? (escapeHtml(data.template) + ' · ') : '';
    return `
    <div class="email-card" id="${id}">
        <div class="email-card-header">
            <div class="ectl">
                <span>✉️ Email Draft</span>
                <small>${tpl}${lang}</small>
            </div>
        </div>
        <div class="email-field">
            <label>Para</label>
            <input type="email" id="${id}_to" value="${escapeAttr(data.to||'')}" placeholder="destinatario@empresa.com">
        </div>
        <div class="email-field">
            <label>CC</label>
            <input type="email" id="${id}_cc" value="${escapeAttr(data.cc||'')}" placeholder="cc@empresa.com">
        </div>
        <div class="email-field">
            <label>Assunto</label>
            <input type="text" id="${id}_subject" value="${escapeAttr(data.subject||'')}">
        </div>
        <div class="email-body-area" id="${id}_body" contenteditable="true">${escapeHtml(data.body||'')}</div>
        <div class="email-actions">
            <button type="button" class="email-outlook-btn" onclick="openInOutlook('${id}')" title="Abrir no Outlook / cliente de email">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4v16l10-2.5V6.5L7 4zm2 2.8l6 1.5v7.4l-6 1.5V6.8zM2 7v10l4 1V6L2 7z"/></svg>
                Outlook
            </button>
            <button type="button" class="email-copy-btn" onclick="copyEmail('${id}')">📋 Copiar</button>
            <button type="button" class="email-edit-btn" onclick="editEmail('${id}')" title="Editar corpo do email">✏️</button>
            <span class="email-status" id="${id}_status"></span>
        </div>
    </div>`;
}

// Attribute-safe escape — escapeHtml() uses <br> for newlines which would
// corrupt input values. This variant keeps the value intact for attrs.
function escapeAttr(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openInOutlook(id) {
    const to      = document.getElementById(id+'_to')?.value.trim() || '';
    const cc      = document.getElementById(id+'_cc')?.value.trim() || '';
    const subject = document.getElementById(id+'_subject')?.value.trim() || '';
    const body    = document.getElementById(id+'_body')?.innerText.trim() || '';

    let mailto = 'mailto:' + encodeURIComponent(to);
    const parts = [];
    if (cc)      parts.push('cc='      + encodeURIComponent(cc));
    if (subject) parts.push('subject=' + encodeURIComponent(subject));
    if (body)    parts.push('body='    + encodeURIComponent(body));
    if (parts.length) mailto += '?' + parts.join('&');

    // mailto: is the most portable — Outlook desktop, Outlook Web (if set as
    // default handler), Apple Mail, Gmail all hook this scheme on macOS/Win.
    window.location.href = mailto;

    const statusEl = document.getElementById(id+'_status');
    if (statusEl) {
        statusEl.textContent = '📮 A abrir no cliente de email…';
        statusEl.className = 'email-status sent';
        setTimeout(() => { statusEl.textContent = ''; statusEl.className = 'email-status'; }, 3000);
    }
}

function copyEmail(id) {
    const subject = document.getElementById(id+'_subject')?.value || '';
    const body    = document.getElementById(id+'_body')?.innerText || '';
    const to      = document.getElementById(id+'_to')?.value || '';
    const text    = (to ? 'Para: '+to+'\n' : '') + 'Assunto: '+subject+'\n\n'+body;
    navigator.clipboard.writeText(text);
    const btn = document.querySelector(`#${id} .email-copy-btn`);
    if (btn) {
        const old = btn.textContent;
        btn.textContent = '✅ Copiado!';
        setTimeout(() => { btn.textContent = old; }, 2000);
    }
}

function editEmail(id) {
    const bodyEl = document.getElementById(id+'_body');
    if (!bodyEl) return;
    bodyEl.focus();
    const range = document.createRange();
    range.selectNodeContents(bodyEl);
    range.collapse(false);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}

// ── Day/Night theme toggle ─────────────────────────────────────────────────
// Any recipient can flip the UI between modes — the choice is remembered in
// localStorage (scoped "cy-theme") so it persists across /a/{token},
// /p/{portal_token}, and the OTP challenge page.
(function initClawTheme(){
    var KEY = 'cy-theme';
    var saved = null;
    try { saved = localStorage.getItem(KEY); } catch (e) {}
    applyClawTheme(saved === 'light' ? 'light' : 'dark');
})();

function applyClawTheme(t){
    document.documentElement.setAttribute('data-theme', t);
    var ic = document.getElementById('themeIcon');
    if (ic) ic.textContent = (t === 'light' ? '☀️' : '🌙');
}

function toggleClawTheme(){
    var cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    var next = cur === 'light' ? 'dark' : 'light';
    applyClawTheme(next);
    try { localStorage.setItem('cy-theme', next); } catch (e) {}
}

// 2026-05-18: ao carregar o link, hidrata conversa anterior do servidor.
// O servidor lê a Conversation/Message em BD para o (share_id, session_id)
// e devolve até 50 mensagens. Pedido directo do operador:
//   "cliente Dloren Wfit já consegue gravar as conversas, guarda
//    vários anos" — agora SIM, persistência permanente em BD.
(async function loadShareHistoryOnce() {
    try {
        // session_id deliberadamente OMITIDO da query — o servidor usa
        // o cookie HttpOnly `share_chat_{id}` para identificar o browser.
        // Enviar credentials: 'include' garante que o cookie viaja.
        const url = '/api/a/' + encodeURIComponent(TOKEN) + '/history';
        const headers = { 'Accept': 'application/json' };
        if (SHARE_SID) headers['X-Share-SID'] = SHARE_SID;
        const res = await fetch(url, { headers, credentials: 'include' });
        if (!res.ok) return;   // 401 reauth ou 404 — silenciar
        const data = await res.json();
        const msgs = data.messages || [];
        if (!msgs.length) return;
        // Limpa welcome message se existir e mostra histórico
        const welcome = document.getElementById('welcome-msg');
        if (welcome) welcome.remove();
        // Reset history JS array para alinhar com server
        history = msgs.slice(-20);
        // Mostra hint de continuidade
        const hint = document.createElement('div');
        hint.style.cssText = 'text-align:center;padding:8px 0;font-size:11px;color:var(--muted);border-bottom:1px dashed var(--border);margin-bottom:8px;';
        hint.textContent = '↑ ' + msgs.length + ' mensagens da tua conversa anterior';
        const msgsEl = document.getElementById('messages');
        if (msgsEl) msgsEl.appendChild(hint);
        // Render cada mensagem
        msgs.forEach(m => {
            const role = m.role === 'user' ? 'user' : 'assistant';
            addMessage(role, String(m.content || ''));
        });
    } catch (e) {
        // sem rede ou erro — não bloqueia o uso normal
    }
})();
</script>
</body>
</html>
