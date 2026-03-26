<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — AI Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green: #76b900;
            --green-hover: #8fd400;
            --bg: #0a0a0a;
            --bg2: #111;
            --bg3: #1a1a1a;
            --border: #222;
            --border2: #2a2a2a;
            --text: #e5e5e5;
            --muted: #555;
            --muted2: #444;
            --agent-color: #76b900;
        }

        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background:var(--bg); color:var(--text); height:100vh; display:flex; flex-direction:column; overflow:hidden; }

        /* ── HEADER ── */
        header { display:flex; align-items:center; gap:10px; padding:12px 20px; border-bottom:1px solid var(--border); background:var(--bg2); flex-shrink:0; }
        .logo { font-size:18px; font-weight:800; color:var(--green); letter-spacing:-0.5px; }
        .badge { font-size:10px; background:var(--green); color:#000; padding:2px 8px; border-radius:20px; font-weight:700; }
        .hdr-right { margin-left:auto; display:flex; align-items:center; gap:8px; }
        #agent-select { background:var(--bg3); border:1px solid var(--border2); color:var(--text); padding:5px 10px; border-radius:8px; font-size:12px; cursor:pointer; outline:none; }
        #agent-select:focus { border-color:var(--green); }
        #model-badge { font-size:11px; color:var(--muted); background:var(--bg3); padding:3px 10px; border-radius:20px; border:1px solid var(--border); }
        .back-btn { color:var(--muted); text-decoration:none; font-size:18px; padding:4px; }
        .back-btn:hover { color:var(--text); }

        /* ── MAIN LAYOUT ── */
        .main { flex:1; display:flex; overflow:hidden; }

        /* ── SIDEBAR — Agent Activity ── */
        #activity-panel {
            width:260px; min-width:260px; border-right:1px solid var(--border);
            background:var(--bg2); display:flex; flex-direction:column;
            transition: width 0.3s; overflow:hidden;
        }
        #activity-panel.collapsed { width:0; min-width:0; border-right:none; }
        .activity-header { padding:12px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
        .activity-header span { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }
        #activity-log { flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:6px; }
        .activity-step { display:flex; align-items:flex-start; gap:8px; padding:8px 10px; border-radius:8px; font-size:12px; line-height:1.5; color:#aaa; background:var(--bg3); border:1px solid var(--border); animation: fadeIn 0.3s ease; }
        .activity-step.active { color:var(--green); border-color:var(--green); background:#0f1f00; }
        .activity-step.done { color:#666; }
        .activity-step .step-icon { font-size:14px; flex-shrink:0; margin-top:1px; }
        .activity-step .step-text { flex:1; }
        .step-spinner { width:12px; height:12px; border:2px solid var(--border2); border-top-color:var(--green); border-radius:50%; animation:spin 0.8s linear infinite; flex-shrink:0; margin-top:3px; }
        @keyframes spin { to { transform:rotate(360deg); } }
        @keyframes fadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:translateY(0); } }

        /* Agent live status */
        .agent-live { padding:12px 16px; border-top:1px solid var(--border); background:var(--bg); flex-shrink:0; }
        .agent-live .lbl { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; }
        .agent-cards-mini { display:flex; flex-direction:column; gap:4px; }
        .agent-mini { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:6px; font-size:12px; color:var(--muted); }
        .agent-mini.active { background:var(--bg3); color:var(--text); }
        .agent-mini .dot-status { width:7px; height:7px; border-radius:50%; background:var(--muted2); flex-shrink:0; }
        .agent-mini.active .dot-status { background:var(--green); box-shadow:0 0 4px var(--green); animation:pulse-dot 1.5s infinite; }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:0.4} }

        /* ── CHAT AREA ── */
        .chat-wrap { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        #chat { flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:12px; scroll-behavior:smooth; }
        #chat::-webkit-scrollbar { width:4px; }
        #chat::-webkit-scrollbar-track { background:transparent; }
        #chat::-webkit-scrollbar-thumb { background:var(--border2); border-radius:4px; }

        /* ── MESSAGES ── */
        .message { display:flex; gap:10px; max-width:780px; width:100%; animation:fadeIn 0.2s ease; }
        .message.user { align-self:flex-end; flex-direction:row-reverse; }

        .avatar { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; flex-shrink:0; }
        .message.user .avatar { background:var(--green); color:#000; }
        .message.ai .avatar { background:var(--bg3); color:var(--green); border:1px solid var(--border2); font-size:16px; }

        .msg-col { display:flex; flex-direction:column; gap:4px; max-width:calc(100% - 42px); }
        .msg-meta { font-size:10px; color:var(--muted); display:flex; align-items:center; gap:6px; }
        .msg-meta .agent-tag { background:var(--bg3); border:1px solid var(--border2); padding:1px 7px; border-radius:10px; color:#888; }
        .msg-meta .agent-tag.active { border-color:var(--green); color:var(--green); }

        .bubble { padding:11px 15px; border-radius:14px; font-size:13.5px; line-height:1.65; white-space:pre-wrap; word-break:break-word; }
        .message.user .bubble { background:var(--green); color:#000; border-bottom-right-radius:4px; font-weight:500; }
        .message.ai .bubble { background:var(--bg3); color:var(--text); border-bottom-left-radius:4px; border:1px solid var(--border2); }

        /* Agent color left border on AI bubbles */
        .message.ai .bubble { border-left: 3px solid var(--agent-color); }

        /* Markdown-like styling inside bubble */
        .bubble strong { font-weight:700; }
        .bubble em { font-style:italic; color:#aaa; }
        .bubble code { background:#0f0f0f; border:1px solid var(--border2); border-radius:4px; padding:1px 5px; font-family:'JetBrains Mono',monospace; font-size:12px; color:#76b900; }
        .bubble h2 { font-size:14px; font-weight:700; color:var(--green); margin:8px 0 4px; }
        .bubble h3 { font-size:13px; font-weight:700; color:#aaa; margin:6px 0 3px; }
        .bubble ul, .bubble ol { padding-left:18px; margin:4px 0; }
        .bubble li { margin:2px 0; }
        .bubble hr { border:none; border-top:1px solid var(--border2); margin:10px 0; }

        /* Code blocks (fenced ```) */
        .bubble pre.code-block { background:#0d0d0d; border:1px solid var(--border2); border-radius:8px; overflow:hidden; margin:8px 0; }
        .bubble pre.code-block .code-lang { display:block; padding:4px 12px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--muted); background:#111; border-bottom:1px solid var(--border2); }
        .bubble pre.code-block code { display:block; padding:12px; font-size:12px; line-height:1.6; overflow-x:auto; color:#e5e5e5; background:transparent; border:none; border-radius:0; }

        /* Tables */
        .bubble .table-wrap { overflow-x:auto; margin:8px 0; }
        .bubble .md-table { border-collapse:collapse; width:100%; font-size:12.5px; }
        .bubble .md-table th { background:#111; color:var(--green); font-weight:700; padding:7px 12px; border:1px solid var(--border2); text-align:left; white-space:nowrap; }
        .bubble .md-table td { padding:6px 12px; border:1px solid var(--border2); color:#ccc; }
        .bubble .md-table tr:nth-child(even) td { background:#0f0f0f; }

        /* Streaming cursor */
        .stream-cursor { display:inline-block; color:var(--agent-color); font-weight:700; animation:blink-cur 0.7s steps(1) infinite; margin-left:1px; }
        @keyframes blink-cur { 0%,100%{opacity:1} 50%{opacity:0} }

        /* Typing indicator */
        .typing .bubble { padding:14px; }
        .dot { width:7px; height:7px; background:var(--muted); border-radius:50%; animation:bounce 1.2s infinite; display:inline-block; }
        .dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
        @keyframes bounce { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-5px)} }

        /* ── SUGGESTIONS ── */
        .suggestions { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
        .sugg-btn { background:none; border:1px solid var(--border2); color:#888; padding:5px 12px; border-radius:20px; font-size:12px; cursor:pointer; transition:all 0.15s; white-space:nowrap; }
        .sugg-btn:hover { border-color:var(--green); color:var(--green); background:#0f1f00; }

        /* ── ACTION APPROVAL CARD ── */
        .action-card { background:var(--bg2); border:1px solid #ffaa00; border-radius:12px; padding:14px 16px; margin-top:4px; }
        .action-card-header { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
        .action-card-header .icon { font-size:18px; }
        .action-card-header h4 { font-size:13px; font-weight:700; color:#ffaa00; }
        .action-card-body { font-size:12.5px; color:#aaa; line-height:1.6; margin-bottom:12px; }
        .action-btns { display:flex; gap:8px; }
        .action-approve { background:var(--green); color:#000; border:none; padding:7px 18px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        .action-approve:hover { background:var(--green-hover); }
        .action-reject { background:none; color:var(--muted); border:1px solid var(--border2); padding:7px 18px; border-radius:8px; font-size:12px; cursor:pointer; }
        .action-reject:hover { border-color:#ff4444; color:#ff4444; }
        .action-status { font-size:11px; margin-top:6px; }
        .action-status.approved { color:var(--green); }
        .action-status.rejected { color:#ff4444; }

        /* ── EMAIL CARD ── */
        .email-card { background:var(--bg); border:1px solid #1a3300; border-left:3px solid var(--green); border-radius:12px; overflow:hidden; margin-top:4px; }
        .email-card-header { background:#0a1a00; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #1a3300; }
        .email-card-header .ectl { display:flex; align-items:center; gap:8px; }
        .email-card-header span { font-size:12px; font-weight:700; color:var(--green); }
        .email-card-header small { font-size:11px; color:var(--muted); }
        .email-field { padding:8px 16px; border-bottom:1px solid #111; display:flex; align-items:center; gap:10px; }
        .email-field label { font-size:10px; color:var(--muted); min-width:52px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .email-field input { flex:1; background:transparent; border:none; color:var(--text); font-size:13px; outline:none; }
        .email-field input:focus { color:var(--green); }
        .email-body-area { padding:14px 16px; font-size:13px; color:#ccc; line-height:1.7; white-space:pre-wrap; max-height:240px; overflow-y:auto; border-bottom:1px solid #111; outline:none; }
        .email-body-area:focus { background:#050f00; }
        .email-actions { padding:10px 16px; display:flex; gap:8px; align-items:center; background:#0a1a00; }
        .email-send-btn { background:var(--green); color:#000; border:none; padding:8px 20px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; }
        .email-send-btn:hover { background:var(--green-hover); }
        .email-send-btn:disabled { background:#333; color:#666; cursor:not-allowed; }
        .email-copy-btn { background:none; color:var(--muted); border:1px solid var(--border2); padding:8px 16px; border-radius:8px; font-size:12px; cursor:pointer; }
        .email-copy-btn:hover { border-color:var(--muted); color:#aaa; }
        .email-edit-btn { background:none; color:var(--muted); border:1px solid var(--border2); padding:8px 14px; border-radius:8px; font-size:12px; cursor:pointer; }
        .email-status { font-size:11px; margin-left:auto; }
        .email-status.sent { color:var(--green); }
        .email-status.err { color:#ff4444; }

        /* ── IMAGE PREVIEW ── */
        #image-preview { display:none; padding:8px 20px 0; position:relative; }
        #image-preview img { height:72px; border-radius:8px; border:1px solid var(--border2); }
        #remove-image { position:absolute; top:4px; left:82px; background:#ff4444; color:#fff; border:none; border-radius:50%; width:18px; height:18px; cursor:pointer; font-size:11px; display:flex; align-items:center; justify-content:center; }

        /* ── DRAG & DROP OVERLAY ── */
        #drop-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; flex-direction:column; gap:16px; pointer-events:none; }
        #drop-overlay.active { display:flex; }
        #drop-overlay .drop-box { border:3px dashed var(--agent-color,#76b900); border-radius:24px; padding:60px 80px; text-align:center; animation:drop-pulse 1s ease-in-out infinite alternate; }
        #drop-overlay .drop-icon { font-size:64px; display:block; margin-bottom:12px; }
        #drop-overlay .drop-text { font-size:22px; font-weight:700; color:var(--agent-color,#76b900); }
        #drop-overlay .drop-sub  { font-size:13px; color:#888; margin-top:6px; }
        @keyframes drop-pulse { from{box-shadow:0 0 0 0 color-mix(in srgb,var(--agent-color,#76b900) 30%,transparent)} to{box-shadow:0 0 40px 4px color-mix(in srgb,var(--agent-color,#76b900) 20%,transparent)} }

        /* ── INPUT AREA ── */
        #input-area { padding:14px 20px; border-top:1px solid var(--border); background:var(--bg2); display:flex; gap:10px; align-items:flex-end; flex-shrink:0; }
        .icon-btn { width:44px; height:44px; background:var(--bg3); border:1px solid var(--border2); border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s; flex-shrink:0; font-size:17px; }
        .icon-btn:hover { border-color:var(--green); }
        .icon-btn.active { background:var(--green); border-color:var(--green); }
        .icon-btn.recording { animation:pulse-rec 1s infinite; }
        @keyframes pulse-rec { 0%,100%{box-shadow:0 0 0 0 rgba(118,185,0,.4)} 50%{box-shadow:0 0 0 6px rgba(118,185,0,0)} }
        #message-input { flex:1; background:var(--bg3); border:1px solid var(--border2); border-radius:12px; padding:11px 15px; color:var(--text); font-size:13.5px; resize:none; outline:none; min-height:44px; max-height:150px; font-family:inherit; line-height:1.5; transition:border-color 0.2s; }
        #message-input:focus { border-color:var(--green); }
        #message-input::placeholder { color:#333; }
        #send-btn { width:44px; height:44px; background:var(--green); border:none; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.2s; flex-shrink:0; }
        #send-btn:hover { background:var(--green-hover); transform:scale(1.05); }
        #send-btn:active { transform:scale(0.93); }
        #send-btn:disabled { background:#222; cursor:not-allowed; transform:none; }
        #send-btn svg { width:18px; height:18px; }

        /* Toggle sidebar btn */
        #toggle-panel { background:none; border:none; color:var(--muted); cursor:pointer; font-size:14px; padding:4px; }
        #toggle-panel:hover { color:var(--text); }

        /* ── EMPTY STATE ── */
        .empty-state { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; padding:20px; }
        .empty-state-hero { display:flex; flex-direction:column; align-items:center; gap:8px; }
        .empty-state-avatar { font-size:52px; line-height:1; filter:drop-shadow(0 0 20px var(--agent-color)); transition:filter 0.4s; }
        .empty-state h2 { font-size:28px; color:var(--text); font-weight:800; letter-spacing:-0.5px; }
        .empty-state h2 span { color:var(--agent-color); }
        .empty-state p { font-size:13px; color:var(--muted); text-align:center; max-width:400px; line-height:1.5; }
        .starter-chips { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; max-width:540px; }
        .starter-chip { background:var(--bg3); border:1px solid var(--border2); color:#888; padding:7px 14px; border-radius:20px; font-size:12px; cursor:pointer; transition:all 0.15s; }
        .starter-chip:hover { border-color:var(--agent-color); color:var(--agent-color); background:#0f0f0f; }

        /* Save report button */
        .save-report-btn { background:none; border:none; cursor:pointer; font-size:13px; margin-left:6px; opacity:0.4; transition:opacity .2s; padding:0; }
        .save-report-btn:hover { opacity:1; }
        .save-report-btn.saved { opacity:1; }

        /* ══════════════════════════════════════
           MOBILE — max-width: 768px
           Focus: agent select visible, input usable
           ══════════════════════════════════════ */
        @media (max-width: 768px) {

            /* Fix iOS/Android viewport height (browser bar issue) */
            body { height: 100dvh; height: -webkit-fill-available; }

            /* ── HEADER: compacto numa linha ── */
            header {
                padding: 8px 12px;
                gap: 8px;
                flex-wrap: nowrap;
                min-height: 52px;
            }

            /* Esconde badge "AI" e botão toggle panel */
            .badge { display: none; }
            #toggle-panel { display: none; }
            #model-badge { display: none; }

            /* Logo mais pequeno */
            .logo { font-size: 15px; }

            /* Select de agente: ocupa o espaço disponível, fonte legível */
            #agent-select {
                flex: 1;
                min-width: 0;
                font-size: 13px;
                padding: 7px 8px;
                max-width: 100%;
            }

            /* Esconde todos os links de navegação no header */
            .hdr-right {
                display: none;
            }

            /* ── SIDEBAR: escondida automaticamente em mobile ── */
            #activity-panel {
                width: 0 !important;
                min-width: 0 !important;
                border-right: none !important;
                overflow: hidden !important;
            }

            /* ── CHAT: padding mais pequeno para ganhar espaço ── */
            #chat {
                padding: 12px;
                gap: 10px;
            }

            /* Mensagens: largura total em mobile */
            .message {
                max-width: 100%;
            }

            /* Avatar mais pequeno */
            .avatar { width: 26px; height: 26px; font-size: 11px; }

            /* Bubble: fonte legível, sem overflow */
            .bubble {
                font-size: 14px;
                padding: 10px 13px;
                max-width: calc(100vw - 80px);
            }

            /* ── INPUT AREA: uma linha limpa ── */
            #input-area {
                padding: 8px 10px;
                gap: 7px;
                flex-shrink: 0; /* nunca encolhe — fica sempre visível */
            }

            /* Botões de voz e anexo: mais pequenos */
            .icon-btn {
                width: 38px;
                height: 38px;
                font-size: 15px;
                border-radius: 8px;
                flex-shrink: 0;
            }

            /* Textarea: altura mínima menor para não tomar demasiado espaço */
            #message-input {
                font-size: 16px; /* 16px evita zoom automático no iOS */
                min-height: 38px;
                max-height: 110px;
                padding: 9px 12px;
                border-radius: 10px;
            }

            /* Botão enviar */
            #send-btn {
                width: 38px;
                height: 38px;
                border-radius: 8px;
                flex-shrink: 0;
            }
            #send-btn svg { width: 16px; height: 16px; }

            /* ── EMPTY STATE: mais compacto ── */
            .empty-state h2 { font-size: 22px; }
            .empty-state p { font-size: 12px; text-align: center; padding: 0 16px; }
            .starter-chips { padding: 0 8px; gap: 6px; }
            .starter-chip { font-size: 12px; padding: 6px 12px; }

            /* ── EMAIL CARD: scroll horizontal evitado ── */
            .email-body-area { max-height: 160px; font-size: 13px; }

            /* ── TABELA dentro de bubble: scroll horizontal ── */
            .bubble table { display: block; overflow-x: auto; white-space: nowrap; }
        }
    </style>
</head>
<body>

<!-- ── HEADER ── -->
<header>
    <a href="/dashboard" class="back-btn" title="Voltar ao dashboard">←</a>
    <span class="logo">🐾 ClawYard</span>
    <span class="badge">AI</span>
    <select id="agent-select">
        <option value="auto">🤖 Auto Route</option>
        <option value="orchestrator">🌐 All Agents</option>
        <option value="sales">💼 Marco Sales</option>
        <option value="support">🔧 Marcus Suporte</option>
        <option value="email">📧 Daniel Email</option>
        <option value="sap">📊 Richard SAP</option>
        <option value="document">📄 Comandante Doc</option>
        <option value="claude">🧠 Bruno AI</option>
        <option value="nvidia">⚡ Carlos NVIDIA</option>
        <option value="aria">🔐 ARIA Security</option>
        <option value="quantum">⚛️ Prof. Quantum Leap</option>
        <option value="finance">💰 Dr. Luís Financeiro</option>
        <option value="research">🔍 Marina Research</option>
        <option value="capitao">⚓ Capitão Porto</option>
        <option value="acingov">🏛️ Dra. Ana Contratos</option>
    </select>
    <div class="hdr-right">
        <span id="model-badge">pronto</span>
        <a href="/discoveries" title="Descobertas & Patentes" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">🔬 Descobertas</a>
        <a href="/reports" title="Relatórios" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">📋 Reports</a>
        <a href="/conversations" title="Histórico de Conversas" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">💬 Histórico</a>
        <a href="/briefing" title="Briefing Executivo Diário" style="background:#0d1a00;border:1px solid #1e3300;color:#76b900;padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;font-weight:700;">📊 Briefing</a>
        <a href="/schedules" title="Tarefas Agendadas" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">🗓️ Schedule</a>
        @if(Auth::user()->isAdmin())
        <a href="/admin/users" title="Admin" style="background:var(--bg3);border:1px solid #ff4444;color:#ff6666;padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">⚙️ Admin</a>
        @endif
        <button id="toggle-panel" title="Toggle activity panel">⚡</button>
    </div>
</header>

<!-- ── MAIN ── -->
<div class="main">

    <!-- ── ACTIVITY SIDEBAR ── -->
    <div id="activity-panel">
        <div class="activity-header">
            <span>⚡ Actividade</span>
            <span id="activity-count" style="font-size:11px;color:var(--green)"></span>
        </div>
        <div id="activity-log">
            <div class="activity-step done">
                <span class="step-icon">✅</span>
                <span class="step-text">Sistema iniciado. RAG carregado.</span>
            </div>
            <div class="activity-step done">
                <span class="step-icon">📚</span>
                <span class="step-text">Base de conhecimento: portos, concorrentes, serviços PartYard.</span>
            </div>
        </div>
        <div class="agent-live">
            <div class="lbl">Agentes Disponíveis</div>
            <div class="agent-cards-mini" id="agent-status-list">
                <div class="agent-mini" data-agent="sales"><div class="dot-status"></div><span>💼 Marco Sales</span></div>
                <div class="agent-mini" data-agent="support"><div class="dot-status"></div><span>🔧 Marcus Suporte</span></div>
                <div class="agent-mini" data-agent="email"><div class="dot-status"></div><span>📧 Daniel Email</span></div>
                <div class="agent-mini" data-agent="sap"><div class="dot-status"></div><span>📊 Richard SAP</span></div>
                <div class="agent-mini" data-agent="document"><div class="dot-status"></div><span>📄 Comandante Doc</span></div>
                <div class="agent-mini" data-agent="claude"><div class="dot-status"></div><span>🧠 Bruno AI</span></div>
                <div class="agent-mini" data-agent="nvidia"><div class="dot-status"></div><span>⚡ Carlos NVIDIA</span></div>
                <div class="agent-mini" data-agent="aria"><div class="dot-status"></div><span>🔐 ARIA Security</span></div>
                <div class="agent-mini" data-agent="quantum"><div class="dot-status"></div><span>⚛️ Prof. Quantum Leap</span></div>
                <div class="agent-mini" data-agent="finance"><div class="dot-status"></div><span>💰 Dr. Luís Financeiro</span></div>
                <div class="agent-mini" data-agent="research"><div class="dot-status"></div><span>🔍 Marina Research</span></div>
            </div>
        </div>
    </div>

    <!-- ── DRAG & DROP OVERLAY ── -->
    <div id="drop-overlay">
        <div class="drop-box">
            <span class="drop-icon">📎</span>
            <div class="drop-text">Larga aqui para anexar</div>
            <div class="drop-sub">PDF · Excel · Word · Imagem · TXT · CSV</div>
        </div>
    </div>

    <!-- ── CHAT AREA ── -->
    <div class="chat-wrap">
        <div id="chat">
            <div class="empty-state" id="empty-state">
                <div class="empty-state-hero">
                    <div class="empty-state-avatar" id="empty-avatar">🤖</div>
                    <h2 id="empty-title">ClawYard <span>AI</span></h2>
                    <p id="empty-desc">Routing inteligente — vai ao agente certo automaticamente</p>
                </div>
                <div class="starter-chips" id="starter-chips"></div>
            </div>
        </div>

        <!-- Image preview -->
        <div id="image-preview">
            <img id="preview-img" src="" alt="preview" style="display:none">
            <div id="file-preview-info" style="display:none;align-items:center;gap:8px;padding:6px 10px;background:#1a1a1a;border-radius:8px;font-size:13px;color:#ccc">
                <span id="file-preview-icon" style="font-size:20px"></span>
                <span id="file-preview-name" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                <span id="file-preview-size" style="color:#666;font-size:11px"></span>
            </div>
            <button id="remove-image">✕</button>
        </div>

        <!-- ── INPUT ── -->
        <div id="input-area">
            <button class="icon-btn" id="voice-btn" title="Voz (pt-PT)">🎤</button>
            <label for="image-input" class="icon-btn" id="image-btn" title="Anexar ficheiro (imagem, PDF, Word, Excel, TXT)" style="cursor:pointer;display:flex;align-items:center;justify-content:center">📎</label>
            <input type="file" id="image-input" accept="image/*,.pdf,.doc,.docx,.txt,.csv,.xlsx,.xls,.pptx,.md" style="display:none">
            <textarea
                id="message-input"
                placeholder="Pergunta ao ClawYard… (Enter enviar · Shift+Enter nova linha)"
                rows="1"
            ></textarea>
            <button id="send-btn" title="Enviar">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════
   CLAWYARD CHAT ENGINE v2
   Features: Agent Activity, Suggestions, Autonomous Actions, Email Send
   ═══════════════════════════════════════════════ */

const chat        = document.getElementById('chat');
const input       = document.getElementById('message-input');
const sendBtn     = document.getElementById('send-btn');
const modelBadge  = document.getElementById('model-badge');
const agentSelect = document.getElementById('agent-select');
const activityLog = document.getElementById('activity-log');
const actPanel    = document.getElementById('activity-panel');

const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const SESSION_ID = 'cyw_' + Date.now() + '_' + Math.random().toString(36).substr(2,6);

const AGENT_EMOJIS = {
    auto:'🤖', orchestrator:'🌐', sales:'💼', support:'🔧',
    email:'📧', sap:'📊', document:'📄', claude:'🧠', nvidia:'⚡',
    aria:'🔐', quantum:'⚛️', finance:'💰', research:'🔍',
    capitao:'⚓', acingov:'🏛️'
};

// Agents with a real photo (stored in /images/agents/{key}.png)
const AGENT_PHOTOS = {
    acingov: '/images/agents/acingov.png',
};

const AGENT_NAMES = {
    auto:'Auto', orchestrator:'All Agents', sales:'Marco Sales', support:'Marcus Suporte',
    email:'Daniel Email', sap:'Richard SAP', document:'Comandante Doc', claude:'Bruno AI', nvidia:'Carlos NVIDIA',
    aria:'ARIA Security', quantum:'Prof. Quantum Leap', finance:'Dr. Luís Financeiro', research:'Marina Research',
    capitao:'Capitão Porto',
    acingov:'Dra. Ana Contratos'
};

const AGENT_COLORS = {
    auto:'#76b900', orchestrator:'#76b900',
    sales:'#3b82f6', support:'#f59e0b', email:'#8b5cf6',
    sap:'#06b6d4', document:'#94a3b8', claude:'#a855f7',
    nvidia:'#76b900', aria:'#ef4444', quantum:'#22d3ee',
    finance:'#10b981', research:'#f97316',
    capitao:'#0ea5e9',
    acingov:'#f59e0b'
};

const AGENT_DESCRIPTIONS = {
    auto: 'Routing inteligente — vai ao agente certo automaticamente',
    orchestrator: 'Colaboração entre todos os agentes em simultâneo',
    sales: 'Cotações, peças, disponibilidade e propostas comerciais',
    support: 'Diagnóstico técnico, avarias, manutenção e reparação',
    email: 'Emails profissionais em PT/EN/ES prontos a enviar',
    sap: 'Acesso directo ao ERP SAP B1 — stock, faturas, encomendas',
    document: 'Análise de documentos, contratos e certificados técnicos',
    claude: 'Análise estratégica, pesquisa avançada e consultoria',
    nvidia: 'Geração de texto, marketing e copywriting com NVIDIA NeMo',
    aria: 'Cibersegurança, STRIDE, OWASP e monitorização de sites',
    quantum: 'Digest científico diário: papers arXiv + patentes USPTO',
    finance: 'Contabilidade, fiscalidade, SAP financeiro e análise ROI',
    research: 'Pesquisa de mercado, concorrência e análise de websites',
    capitao: 'Operações portuárias, escalas, documentação e logística marítima',
    acingov: 'SAM.gov · base.gov.pt · Vortal · UNIDO · UNGM — contratos públicos para PartYard',
};

const AGENT_CHIPS = {
    // ── Auto Router ──────────────────────────────────────────────────────────
    auto: [
        '🚢 Analisa concorrentes no porto de Sines e lista oportunidades',
        '📧 Escreve cold email para armador grego — peças MTU Série 4000',
        '🔧 Motor CAT 3516B com consumo excessivo de óleo — diagnóstico',
        '📊 Relatório de vendas Q1 2026: MTU vs Caterpillar vs MAK',
        '⚛️ Há patentes novas relevantes para a PartYard esta semana?',
        '🔐 Faz scan OWASP completo ao partyard.eu e lista vulnerabilidades',
    ],
    // ── Orchestrator (todos os agentes em simultâneo) ──────────────────────
    orchestrator: [
        '🌐 Analisa mercado MTU em Roterdão → gera email + cotação SAP + threat model',
        '🌐 Motor MAK M32 avariado → diagnóstico técnico + proposta comercial + email ao armador',
        '🌐 Novo cliente em Algeciras → análise de crédito + cotação + cold outreach multilingue',
        '🌐 Digest completo PartYard: papers arXiv + patentes + concorrência + relatório financeiro',
        '🌐 Auditoria 360° ao partyard.eu: SEO + segurança + UX + proposta de melhoria',
        '🌐 Expansão para mercado grego: pesquisa + contactos + email + plano financeiro',
    ],
    // ── Marco Sales ───────────────────────────────────────────────────────
    sales: [
        '💼 Cotação urgente: pistões + camisas MTU Série 4000 para navio em Sines',
        '💼 Proposta completa para revisão MAK M32 — cliente novo em Barcelona',
        '💼 Disponibilidade de selos SKF SternTube — navio em dique em Lisboa',
        '💼 Peças Schottel SRP-X para rebocador — prazo e preço CIF Algeciras',
        '💼 Kit de válvulas Jenbacher J620 — cliente em Hamburgo precisa urgente',
        '💼 Turbocompressor Caterpillar 3516 remanufacturado — opções e garantia',
    ],
    // ── Marcus Suporte ─────────────────────────────────────────────────────
    support: [
        '🔧 MTU 12V4000M90 — alarme F0203 HT coolant temp alta em carga máxima',
        '🔧 CAT 3516B — consumo excessivo de óleo após revisão aos 8.000h',
        '🔧 MAK M25 — vibração anormal no cilindro 3 a 600 RPM, causa provável?',
        '🔧 Schottel SRP-X — vedante de proa com fuga, procedimento de substituição',
        '🔧 SKF SternTube — folga axial 0.45mm fora de spec, valores admissíveis?',
        '🔧 Jenbacher J320 — falha de ignição intermitente cilindro 6, diagnóstico',
    ],
    // ── Daniel Email ──────────────────────────────────────────────────────
    email: [
        '📧 Cold outreach EN para shipping agent em Hamburgo — stock MTU disponível',
        '📧 Proposta comercial ES para armador em Algeciras — peças MAK M32',
        '📧 Follow-up de cotação para cliente em Roterdão — Caterpillar C32',
        '📧 Email urgente PT/EN para agente em Pireu — selos SKF em stock imediato',
        '📧 Apresentação PartYard Defense para agente naval em Lisboa — PDF anexo',
        '📧 Email de parceria para agente marítimo em Valência — exclusividade Schottel',
    ],
    // ── Richard SAP ───────────────────────────────────────────────────────
    sap: [
        '📊 Stock actual de peças MTU Série 2000 — listar por referência e quantidade',
        '📊 Estado da encomenda #PY-2025-0847 — última actualização de entrega',
        '📊 Clientes com facturas em atraso >30 dias — valor total e lista de contacto',
        '📊 Vendas por marca Q1 2026: MTU vs Caterpillar vs MAK vs Schottel',
        '📊 Cria cotação SAP B1 para Navios do Tejo Lda — condições NET 30',
        '📊 Kits de pistões CAT 3516 em stock — quantidades + localização warehouse',
    ],
    // ── Comandante Doc ─────────────────────────────────────────────────────
    document: [
        '📄 Extrai todos os intervalos de manutenção deste manual MTU (PDF anexo)',
        '📄 Analisa contrato de fornecimento e lista cláusulas de risco (Word anexo)',
        '📄 Verifica validade e conformidade deste certificado ISO/DNV (PDF anexo)',
        '📄 Compara duas propostas técnicas Excel e recomenda a mais vantajosa',
        '📄 Resume este relatório de inspeção de navio em 5 pontos executivos',
        '📄 Traduz manual técnico Caterpillar do inglês para português (PDF anexo)',
    ],
    // ── Bruno AI ──────────────────────────────────────────────────────────
    claude: [
        '🧠 Estratégia de expansão para o mercado grego — análise SWOT completa',
        '🧠 Riscos de entrar no mercado de peças para navios militares — análise detalhada',
        '🧠 Benchmark PartYard vs Wilhelmsen vs Wärtsilä Parts — modelo de negócio',
        '🧠 Plano de negócio para abrir escritório em Roterdão — 3 anos, P&L incluído',
        '🧠 Megatendências da indústria naval até 2030 e oportunidades para a PartYard',
        '🧠 Como posicionar a PartYard Defense face à concorrência NATO e americana?',
    ],
    // ── Carlos NVIDIA ─────────────────────────────────────────────────────
    nvidia: [
        '⚡ Gera 10 subject lines de alta conversão para cold email a armadores gregos',
        '⚡ Cria descrição de produto SEO-optimizada para peças MTU Série 4000',
        '⚡ Optimiza este texto de proposta comercial para maior taxa de fecho',
        '⚡ Gera FAQ técnico completo sobre manutenção preventiva de motores MAK',
        '⚡ Cria post LinkedIn viral sobre inovação de peças Schottel da PartYard',
        '⚡ Traduz e adapta catálogo técnico Caterpillar para mercado espanhol',
    ],
    // ── ARIA Security ─────────────────────────────────────────────────────
    aria: [
        '🔐 Scan STRIDE completo ao partyard.eu — threat model e recomendações',
        '🔐 Análise OWASP Top 10 ao hp-group.org — vulnerabilidades e prioridades',
        '🔐 Testa o ClawYard contra SQL Injection, XSS e CSRF — relatório detalhado',
        '🔐 Verifica certificados SSL/TLS e headers HTTP dos sites do grupo H&P',
        '🔐 Gera threat model completo para a API REST do ClawYard — MITRE ATT&CK',
        '🔐 Plano de cibersegurança para empresa marítima — GDPR + NIS2 compliance',
    ],
    // ── Prof. Quantum Leap ────────────────────────────────────────────────
    quantum: [
        '⚛️ Digest científico de hoje: papers arXiv + patentes USPTO relevantes para PartYard',
        '🏛️ Top 7 patentes novas que a PartYard pode licenciar ou explorar este mês',
        '⚛️ Papers recentes sobre manutenção preditiva com IA para motores marítimos',
        '🏛️ Patentes de propulsão Schottel nos últimos 90 dias — análise de oportunidades',
        '⚛️ Quantum computing aplicado a optimização de rotas marítimas — estado da arte',
        '🏛️ Análise de patentes de turbinas MTU — o que os concorrentes estão a patentear?',
    ],
    // ── Dr. Luís Financeiro ───────────────────────────────────────────────
    finance: [
        '💰 Rentabilidade por linha de produto: marine vs defense vs aftermarket — Q1 2026',
        '💰 Benefícios fiscais RFAI + SIFIDE disponíveis para a PartYard em 2026',
        '💰 Análise de rácios financeiros: liquidez, endividamento e EBITDA da empresa',
        '💰 Estrutura de carta de crédito documentário para importação de peças MTU',
        '💰 Impacto fiscal de abrir subsidiária em Noruega vs Brasil vs Grécia',
        '💰 Análise de cash flow Q2 2026 — riscos de tesouraria e medidas preventivas',
    ],
    // ── Marina Research ───────────────────────────────────────────────────
    research: [
        '🔍 Auditoria completa ao partyard.eu — SEO, velocidade, UX e 10 melhorias urgentes',
        '🔍 Benchmark PartYard vs Wärtsilä Parts vs Wilhelmsen — preços e posicionamento',
        '🔍 Top 5 concorrentes MTU na Europa — análise de forças, fraquezas e market share',
        '🔍 Estratégia de palavras-chave SEO para PartYard em PT/EN/ES/GR',
        '🔍 Análise de presença digital de armadores gregos — oportunidades de contacto',
        '🔍 Estratégia de entrada no mercado escandinavo — canais, parceiros e timing',
    ],
    // ── Dra. Ana Contratos (SAM.gov + base.gov.pt + Vortal + UNIDO + UNGM) ─
    acingov: [
        '🏛️ Relatório completo últimos 5 dias: SAM.gov + base.gov.pt + Vortal + UNIDO + UNGM',
        '🏛️ SAM.gov: contratos US Navy, DoD e Coast Guard abertos para PartYard Military',
        '🏛️ Concursos navais e motores marítimos abertos agora — todos os 5 portais',
        '🏛️ Oportunidades UNIDO e UNGM para PartYard Military e SETQ esta semana',
        '🏛️ Qual o contrato com prazo mais urgente nos 5 portais? Ranking por deadline',
        '🏛️ SAM.gov NAICS 336611 e 334511 — ship building e defense navigation abertos',
    ],
    // ── Capitão Porto ─────────────────────────────────────────────────────
    capitao: [
        '⚓ Navio em Sines com motor MTU avariado — procedimento urgente de entrega de peças a bordo',
        '⚓ Plano de escala completo para cargueiro em Setúbal — documentação APSS e timeline',
        '⚓ Como desalfandegar Ship Spares isentos de IVA num porto português — passo a passo',
        '⚓ Calcular laytime e demurrage para bulk carrier com 48h de atraso no Terminal de Sines',
        '⚓ Documentação completa para exportação de peças via sea freight para Pireu (Grécia)',
        '⚓ Inspeção Port State Control (Paris MOU) amanhã — checklist de preparação para o Chief',
    ],
};

function applyAgentColor(agent) {
    const color = AGENT_COLORS[agent] || '#76b900';
    document.documentElement.style.setProperty('--agent-color', color);
}

function renderStarterChips(agent) {
    const chips = AGENT_CHIPS[agent] || AGENT_CHIPS['auto'];
    const container = document.getElementById('starter-chips');
    if (!container) return;
    container.innerHTML = chips.map(c =>
        `<div class="starter-chip" onclick="startChat(this)">${c}</div>`
    ).join('');
}

function updateEmptyState(agent) {
    const emptyState = document.getElementById('empty-state');
    if (!emptyState) return;
    const avatarEl = document.getElementById('empty-avatar');
    const titleEl  = document.getElementById('empty-title');
    const descEl   = document.getElementById('empty-desc');
    const emoji = AGENT_EMOJIS[agent] || '🤖';
    const name  = AGENT_NAMES[agent]  || 'ClawYard';
    const desc  = AGENT_DESCRIPTIONS[agent] || 'Escolhe um exemplo ou escreve a tua pergunta';
    if (avatarEl) {
        const photo = AGENT_PHOTOS[agent];
        if (photo) {
            avatarEl.innerHTML = `<img src="${photo}" alt="${name}" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--agent-color);box-shadow:0 0 24px var(--agent-color)55;">`;
        } else {
            avatarEl.innerHTML = emoji;
        }
    }
    if (titleEl)  titleEl.innerHTML = `<span>${name}</span>`;
    if (descEl)   descEl.textContent = desc;
}

let isRecording  = false;
let recognition  = null;
let currentImg   = null;
let currentFile  = null; // { name, type, b64, text } for non-image files
let panelOpen    = true;
let actCount     = 0;

// ── Agent from URL — must run BEFORE init so chips/colors reflect the right agent
const urlAgent = new URLSearchParams(window.location.search).get('agent');
if (urlAgent && agentSelect.querySelector(`option[value="${urlAgent}"]`)) {
    agentSelect.value = urlAgent;
}

// Init on page load (after URL agent is applied)
renderStarterChips(agentSelect.value || 'auto');
applyAgentColor(agentSelect.value || 'auto');
updateEmptyState(agentSelect.value || 'auto');

// Update when agent changes
agentSelect.addEventListener('change', () => {
    const agent = agentSelect.value;
    renderStarterChips(agent);
    applyAgentColor(agent);
    updateEmptyState(agent);
});

// ── Toggle activity panel ──
document.getElementById('toggle-panel').addEventListener('click', () => {
    panelOpen = !panelOpen;
    actPanel.classList.toggle('collapsed', !panelOpen);
});

// ── Voice ──
if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SR();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = 'pt-PT';
    recognition.onresult = (e) => {
        input.value = e.results[0][0].transcript;
        stopRecording();
        sendMessage();
    };
    recognition.onerror = stopRecording;
    recognition.onend   = stopRecording;
}

function stopRecording() {
    const btn = document.getElementById('voice-btn');
    btn.classList.remove('active','recording');
    isRecording = false;
}

document.getElementById('voice-btn').addEventListener('click', () => {
    if (!recognition) { alert('Voz não suportada neste browser'); return; }
    const btn = document.getElementById('voice-btn');
    if (isRecording) {
        recognition.stop();
    } else {
        recognition.start();
        btn.classList.add('active','recording');
        isRecording = true;
    }
});

// ── File / Image attachment ──
const FILE_ICONS = {
    'pdf':'📄', 'doc':'📝', 'docx':'📝', 'txt':'📃', 'csv':'📊',
    'xlsx':'📊', 'xls':'📊', 'pptx':'📑', 'md':'📃'
};
function getFileIcon(name) {
    const ext = name.split('.').pop().toLowerCase();
    return FILE_ICONS[ext] || '📎';
}
function humanSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1048576).toFixed(1) + ' MB';
}

// 📎 label → browser opens file picker natively via <label for="image-input">
// No JS click() needed — label click is handled by the browser directly.
document.getElementById('image-input').addEventListener('change', function(e) {
    fileInputChangeHandler(e);
    // Reset so re-selecting the same file always fires 'change' again
    e.target.value = '';
});

document.getElementById('remove-image').addEventListener('click', clearImage);
function clearImage() {
    currentImg  = null;
    currentFile = null;
    document.getElementById('image-preview').style.display = 'none';
    document.getElementById('preview-img').style.display = 'none';
    document.getElementById('file-preview-info').style.display = 'none';
    document.getElementById('image-input').value = '';
}

function fileInputChangeHandler(e) {
    const file = e.target.files[0];
    if (!file) return;

    const isImage = file.type.startsWith('image/');
    const reader  = new FileReader();

    if (isImage) {
        reader.onload = (ev) => {
            currentImg  = ev.target.result.split(',')[1];
            currentFile = null;
            document.getElementById('preview-img').src = ev.target.result;
            document.getElementById('preview-img').style.display = 'block';
            document.getElementById('file-preview-info').style.display = 'none';
            document.getElementById('image-preview').style.display = 'flex';
        };
        reader.readAsDataURL(file);
    } else {
        const ext = file.name.split('.').pop().toLowerCase();
        const readAsText = ['txt','csv','md'].includes(ext);

        if (!readAsText && file.size > 15 * 1024 * 1024) {
            alert(`Ficheiro muito grande (${humanSize(file.size)}). Máximo recomendado: 15 MB.`);
            return;
        }

        reader.onload = (ev) => {
            currentImg  = null;
            currentFile = {
                name:  file.name,
                type:  file.type || 'application/octet-stream',
                ext:   ext,
                b64:   readAsText ? null : ev.target.result.split(',')[1],
                text:  readAsText ? ev.target.result : null,
                size:  humanSize(file.size),
            };
            document.getElementById('preview-img').style.display = 'none';
            document.getElementById('file-preview-icon').textContent = getFileIcon(file.name);
            document.getElementById('file-preview-name').textContent = file.name;
            document.getElementById('file-preview-size').textContent = humanSize(file.size);
            document.getElementById('file-preview-info').style.display = 'flex';
            document.getElementById('image-preview').style.display = 'flex';
        };
        if (readAsText) reader.readAsText(file);
        else            reader.readAsDataURL(file);
    }
}

// ── Drag & Drop file attach (works for ALL agents including Luis) ──────────
(function () {
    const overlay   = document.getElementById('drop-overlay');
    let dragCounter = 0; // track nested dragenter/dragleave

    // Show overlay when dragging a file over the window
    document.addEventListener('dragenter', (e) => {
        if (!e.dataTransfer?.types?.includes('Files')) return;
        e.preventDefault();
        dragCounter++;
        overlay.classList.add('active');
    });
    document.addEventListener('dragleave', (e) => {
        dragCounter--;
        if (dragCounter <= 0) { dragCounter = 0; overlay.classList.remove('active'); }
    });
    document.addEventListener('dragover', (e) => {
        if (!e.dataTransfer?.types?.includes('Files')) return;
        e.preventDefault(); // allow drop
    });
    document.addEventListener('drop', (e) => {
        e.preventDefault();
        dragCounter = 0;
        overlay.classList.remove('active');
        const file = e.dataTransfer?.files?.[0];
        if (file) processDroppedFile(file);
    });

    function processDroppedFile(file) {
        // Reuse the same handler as the file input
        const fakeEvent = { target: { files: [file] } };
        fileInputChangeHandler(fakeEvent);
    }
})();

// ── Input resize ──
input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 150) + 'px';
});
sendBtn.addEventListener('click', sendMessage);

// ── Starter chips ──
function startChat(el) {
    // Strip leading emoji + space, keep the actual question
    input.value = el.textContent.replace(/^[\p{Emoji}\s]+/u, '').trim();
    if (!input.value) input.value = el.textContent.trim();
    document.getElementById('empty-state')?.remove();
    sendMessage();
}

// ═══════════════════════════════
//  ACTIVITY LOG
// ═══════════════════════════════
function logActivity(icon, text, state = 'active') {
    actCount++;
    document.getElementById('activity-count').textContent = actCount + ' acções';

    const step = document.createElement('div');
    step.className = 'activity-step ' + state;
    step.innerHTML = `
        <span class="step-icon">${icon}</span>
        <span class="step-text">${esc(text)}</span>
        ${state === 'active' ? '<div class="step-spinner"></div>' : ''}
    `;
    activityLog.appendChild(step);
    activityLog.scrollTop = activityLog.scrollHeight;
    return step;
}

function resolveStep(step, icon = '✅') {
    step.classList.remove('active');
    step.classList.add('done');
    const spinner = step.querySelector('.step-spinner');
    if (spinner) spinner.remove();
    step.querySelector('.step-icon').textContent = icon;
}

function setAgentActive(agentName) {
    document.querySelectorAll('.agent-mini').forEach(el => {
        el.classList.toggle('active', el.dataset.agent === agentName);
    });
}

function clearAgentActive() {
    document.querySelectorAll('.agent-mini').forEach(el => el.classList.remove('active'));
}

// ═══════════════════════════════
//  MESSAGE RENDERING
// ═══════════════════════════════
function addMessage(role, text, agentName = '') {
    document.getElementById('empty-state')?.remove();

    const msg  = document.createElement('div');
    msg.className = `message ${role}`;

    const emoji = role === 'user'
        ? '{{ substr(Auth::user()->name, 0, 2) }}'
        : (AGENT_EMOJIS[agentName] || '🤖');

    const name = role === 'user'
        ? '{{ Auth::user()->name }}'
        : (AGENT_NAMES[agentName] || 'ClawYard');

    // Email card
    if (role === 'ai' && text.startsWith('__EMAIL__')) {
        const emailData = JSON.parse(text.replace('__EMAIL__', ''));
        msg.innerHTML = `
            <div class="avatar">${AGENT_EMOJIS['email']}</div>
            <div class="msg-col" style="max-width:560px">
                <div class="msg-meta">
                    <span class="agent-tag active">📧 Daniel Email</span>
                    <span>email gerado</span>
                </div>
                ${buildEmailCard(emailData)}
            </div>`;
        chat.appendChild(msg);
        chat.scrollTop = chat.scrollHeight;
        return msg;
    }

    const saveBtn = role === 'ai' ? `<button class="save-report-btn" onclick="saveAsReport(this,'${agentName}')" title="Guardar como relatório">💾</button>` : '';
    msg.innerHTML = `
        <div class="avatar">${role === 'user' ? emoji.charAt(0).toUpperCase() : emoji}</div>
        <div class="msg-col">
            <div class="msg-meta">
                <span>${role === 'user' ? '{{ Auth::user()->name }}' : name}</span>
                ${agentName ? `<span class="agent-tag ${role==='ai'?'active':''}">${AGENT_EMOJIS[agentName]||''} ${AGENT_NAMES[agentName]||agentName}</span>` : ''}
                ${saveBtn}
            </div>
            <div class="bubble">${role === 'user' ? esc(text) : renderMarkdown(text)}</div>
        </div>`;

    chat.appendChild(msg);
    chat.scrollTop = chat.scrollHeight;
    return msg;
}

function addTyping(agentName = '') {
    const msg = document.createElement('div');
    msg.className = 'message ai typing';
    msg.innerHTML = `
        <div class="avatar">${AGENT_EMOJIS[agentName] || '🤖'}</div>
        <div class="msg-col">
            <div class="msg-meta"><span>${AGENT_NAMES[agentName] || 'ClawYard'}</span></div>
            <div class="bubble" style="display:flex;gap:4px;align-items:center">
                <div class="dot"></div><div class="dot"></div><div class="dot"></div>
            </div>
        </div>`;
    chat.appendChild(msg);
    chat.scrollTop = chat.scrollHeight;
    return msg;
}

function addSuggestions(suggestions, agentName) {
    if (!suggestions || !suggestions.length) return;
    const row = document.createElement('div');
    row.className = 'message ai';
    row.style.paddingLeft = '42px';
    row.innerHTML = `
        <div class="suggestions" style="max-width:700px">
            ${suggestions.map(s => `<button class="sugg-btn" onclick="useSuggestion(this,'${agentName}')">${esc(s)}</button>`).join('')}
        </div>`;
    chat.appendChild(row);
    chat.scrollTop = chat.scrollHeight;
}

function useSuggestion(btn, agentName) {
    const text = btn.textContent;
    // Remove emoji prefix for input
    input.value = text.replace(/^[\p{Emoji}\s]+/u, '').trim() || text;
    // Auto-select the matching agent
    if (text.includes('email') || text.includes('Email')) agentSelect.value = 'email';
    btn.closest('.message').remove();
    sendMessage();
}

function addActionApproval(action) {
    const id = 'action_' + Date.now();
    const msg = document.createElement('div');
    msg.className = 'message ai';
    msg.style.paddingLeft = '42px';
    msg.innerHTML = `
        <div class="action-card" id="${id}">
            <div class="action-card-header">
                <span class="icon">${action.icon || '⚡'}</span>
                <h4>${esc(action.title)}</h4>
            </div>
            <div class="action-card-body">${esc(action.description)}</div>
            <div class="action-btns">
                <button class="action-approve" onclick="approveAction('${id}', '${esc(action.prompt || '')}', '${action.agent || 'auto'}')">
                    ✅ Autorizar
                </button>
                <button class="action-reject" onclick="rejectAction('${id}')">
                    ✕ Recusar
                </button>
            </div>
            <div class="action-status" id="${id}_status"></div>
        </div>`;
    chat.appendChild(msg);
    chat.scrollTop = chat.scrollHeight;
}

function approveAction(id, prompt, agent) {
    document.getElementById(id + '_status').textContent = '⏳ A executar...';
    document.getElementById(id + '_status').className = 'action-status approved';
    document.querySelector(`#${id} .action-btns`).style.display = 'none';
    // Switch agent and send
    if (agent && agentSelect.querySelector(`option[value="${agent}"]`)) agentSelect.value = agent;
    input.value = decodeURIComponent(prompt);
    sendMessage();
}

function rejectAction(id) {
    const s = document.getElementById(id + '_status');
    s.textContent = '✕ Acção recusada';
    s.className = 'action-status rejected';
    document.querySelector(`#${id} .action-btns`).style.display = 'none';
}

// ═══════════════════════════════
//  EMAIL CARD
// ═══════════════════════════════
function buildEmailCard(data) {
    const id = 'em_' + Date.now();
    const lang = data.language === 'pt' ? '🇵🇹 PT' : data.language === 'es' ? '🇪🇸 ES' : '🇬🇧 EN';
    return `
    <div class="email-card" id="${id}">
        <div class="email-card-header">
            <div class="ectl">
                <span>✉️ Email Draft</span>
                <small>${data.template || ''} · ${lang}</small>
            </div>
        </div>
        <div class="email-field">
            <label>Para</label>
            <input type="email" id="${id}_to" value="${esc(data.to||'')}" placeholder="destinatario@empresa.com">
        </div>
        <div class="email-field">
            <label>CC</label>
            <input type="email" id="${id}_cc" placeholder="cc@empresa.com">
        </div>
        <div class="email-field">
            <label>Assunto</label>
            <input type="text" id="${id}_subject" value="${esc(data.subject||'')}">
        </div>
        <div class="email-body-area" id="${id}_body" contenteditable="true">${esc(data.body||'')}</div>
        <div class="email-actions">
            <button class="email-send-btn" id="${id}_sendbtn" onclick="sendEmail('${id}')">
                ✈️ Enviar
            </button>
            <button class="email-copy-btn" onclick="copyEmail('${id}')">📋 Copiar</button>
            <button class="email-edit-btn" onclick="editEmail('${id}')">✏️</button>
            <span class="email-status" id="${id}_status"></span>
        </div>
    </div>`;
}

async function sendEmail(id) {
    const to      = document.getElementById(id+'_to').value.trim();
    const cc      = document.getElementById(id+'_cc').value.trim();
    const subject = document.getElementById(id+'_subject').value.trim();
    const body    = document.getElementById(id+'_body').innerText.trim();
    const statusEl = document.getElementById(id+'_status');
    const btn      = document.getElementById(id+'_sendbtn');

    if (!to) { statusEl.textContent='⚠️ Insira email do destinatário'; statusEl.className='email-status err'; return; }
    if (!subject) { statusEl.textContent='⚠️ Insira assunto'; statusEl.className='email-status err'; return; }

    btn.disabled = true;
    btn.textContent = '⏳ A enviar...';

    const step = logActivity('📤', 'A enviar email para ' + to + '…');

    try {
        const res = await fetch('/api/email/send', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ to, cc, subject, body }),
        });
        const data = await res.json();
        if (data.success) {
            statusEl.textContent = '✅ Enviado para ' + to;
            statusEl.className = 'email-status sent';
            btn.textContent = '✅ Enviado';
            resolveStep(step, '✅');
            logActivity('✅', 'Email enviado com sucesso para ' + to, 'done');
        } else {
            statusEl.textContent = '❌ ' + data.error;
            statusEl.className = 'email-status err';
            btn.disabled = false;
            btn.textContent = '✈️ Enviar';
            resolveStep(step, '❌');
        }
    } catch (e) {
        statusEl.textContent = '❌ Erro de ligação';
        statusEl.className = 'email-status err';
        btn.disabled = false;
        btn.textContent = '✈️ Enviar';
        resolveStep(step, '❌');
    }
}

function copyEmail(id) {
    const subject = document.getElementById(id+'_subject').value;
    const body    = document.getElementById(id+'_body').innerText;
    navigator.clipboard.writeText('Assunto: '+subject+'\n\n'+body);
    const btn = document.querySelector(`#${id} .email-copy-btn`);
    btn.textContent = '✅ Copiado!';
    setTimeout(() => btn.textContent = '📋 Copiar', 2000);
}

function editEmail(id) {
    const bodyEl = document.getElementById(id+'_body');
    bodyEl.focus();
    const range = document.createRange();
    range.selectNodeContents(bodyEl);
    range.collapse(false);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}

// ═══════════════════════════════
//  MARKDOWN RENDERER
// ═══════════════════════════════
function renderMarkdown(text) {
    if (typeof text !== 'string') return '';

    // 1. Protect fenced code blocks from esc()
    const codeBlocks = [];
    text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, (_, lang, code) => {
        const safeCode = code.trim()
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const langBadge = lang ? `<span class="code-lang">${lang}</span>` : '';
        const idx = codeBlocks.length;
        codeBlocks.push(`<pre class="code-block">${langBadge}<code>${safeCode}</code></pre>`);
        return `\x00CB${idx}\x00`;
    });

    // 2. Protect inline code
    const inlineCodes = [];
    text = text.replace(/`([^`\n]+)`/g, (_, code) => {
        const safeCode = code.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const idx = inlineCodes.length;
        inlineCodes.push(`<code>${safeCode}</code>`);
        return `\x00IC${idx}\x00`;
    });

    // 3. Escape HTML
    let html = esc(text);

    // 4. Restore inline codes
    inlineCodes.forEach((code, i) => { html = html.replace(`\x00IC${i}\x00`, code); });

    // 5. Parse markdown tables (lines with | delimiters)
    html = html.replace(/((?:\|[^\n]+\|\n?){2,})/g, (block) => {
        const rows = block.trim().split('\n');
        if (rows.length < 2) return block;
        const isSep = (r) => /^\|[\s\-:|]+\|$/.test(r.trim());
        let out = '<div class="table-wrap"><table class="md-table">';
        let tbody = false;
        rows.forEach((row, i) => {
            if (isSep(row)) { if (!tbody) { out += '<tbody>'; tbody = true; } return; }
            const cells = row.trim().split('|').slice(1, -1);
            if (i === 0) {
                out += '<thead><tr>' + cells.map(c => `<th>${c.trim()}</th>`).join('') + '</tr></thead>';
            } else {
                if (!tbody) { out += '<tbody>'; tbody = true; }
                out += '<tr>' + cells.map(c => `<td>${c.trim()}</td>`).join('') + '</tr>';
            }
        });
        if (tbody) out += '</tbody>';
        out += '</table></div>';
        return out;
    });

    // 6. Headings, bold, italic, hr, lists
    html = html
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/^---$/gm, '<hr>')
        .replace(/^[-•] (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>(\n|$))+/g, m => `<ul>${m}</ul>`);

    // 7. Newlines to <br>
    html = html.replace(/\n/g, '<br>');

    // 8. Restore code blocks
    codeBlocks.forEach((block, i) => { html = html.replace(`\x00CB${i}\x00`, block); });

    return html;
}

function esc(text) {
    if (typeof text !== 'string') return '';
    return text
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

// ═══════════════════════════════
//  SAVE AS REPORT
// ═══════════════════════════════
async function saveAsReport(btn, agentName) {
    const bubble = btn.closest('.msg-col').querySelector('.bubble');
    const text   = bubble.innerText || bubble.textContent;
    const type   = ['aria','quantum','market'].includes(agentName) ? agentName : 'custom';
    const date   = new Date().toLocaleDateString('pt-PT', {day:'2-digit',month:'long',year:'numeric'});
    const title  = (AGENT_NAMES[agentName]||agentName) + ' — ' + date;

    btn.textContent = '⏳';
    try {
        const res = await fetch('/api/reports', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ title, type, content: text }),
        });
        if (!res.ok) {
            const errText = await res.text();
            console.error('Save report failed:', res.status, errText);
            alert('Erro ao guardar relatório: HTTP ' + res.status + '\n' + errText.substring(0,200));
            btn.textContent = '❌';
            setTimeout(() => { btn.textContent = '💾'; btn.classList.remove('saved'); }, 3000);
            return;
        }
        const data = await res.json();
        if (data.success) {
            btn.textContent = '✅';
            btn.classList.add('saved');
            btn.title = 'Guardado! Ver em /reports';
            logActivity('💾', 'Relatório guardado: ' + title, 'done');
        } else {
            console.error('Save report returned success:false', data);
            btn.textContent = '❌';
            setTimeout(() => { btn.textContent = '💾'; btn.classList.remove('saved'); }, 2000);
        }
    } catch(e) {
        console.error('Save report exception:', e);
        alert('Erro de rede ao guardar relatório: ' + e.message);
        btn.textContent = '❌';
        setTimeout(() => { btn.textContent = '💾'; }, 2000);
    }
}

// ── TTS (desactivado) ──
function speak(text) { /* TTS disabled */ }

// ═══════════════════════════════
//  SEND MESSAGE  (SSE streaming)
// ═══════════════════════════════
async function sendMessage() {
    let text = input.value.trim();

    // Allow send with no text if a file/image is attached
    const hasAttachment = !!(currentImg || currentFile);
    if (!text && !hasAttachment) return;
    if (sendBtn.disabled) return;

    // Default prompt when only a file is attached (no text typed)
    if (!text && hasAttachment) {
        const ext = currentFile?.ext || '';
        if (['pdf'].includes(ext))                              text = 'Analisa este documento PDF.';
        else if (['xlsx','xls','csv'].includes(ext))           text = 'Analisa este ficheiro Excel/CSV.';
        else if (['doc','docx'].includes(ext))                  text = 'Analisa este documento Word.';
        else if (currentImg)                                    text = 'O que vês nesta imagem?';
        else                                                    text = 'Analisa este ficheiro.';
    }

    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;

    const selectedAgent = agentSelect.value;
    document.getElementById('empty-state')?.remove();

    // ── User message ──
    addMessage('user', text);

    // ── Activity log ──
    logActivity('📨', 'Mensagem recebida: "' + text.substring(0,50) + (text.length>50?'…':'') + '"', 'done');
    const stepRAG   = logActivity('📚', 'A consultar base de conhecimento (RAG)…');
    const stepAgent = logActivity('🤖', 'A encaminhar para agente ' + (AGENT_NAMES[selectedAgent]||selectedAgent) + '…');
    setAgentActive(selectedAgent);
    modelBadge.textContent = '⏳ ' + (AGENT_NAMES[selectedAgent]||selectedAgent);

    const typing = addTyping(selectedAgent);

    const payload = { message: text, agent: selectedAgent, session_id: SESSION_ID };

    if (currentImg) {
        payload.image = currentImg;
        clearImage();
        logActivity('🖼️', 'Imagem incluída (multimodal)', 'done');
    } else if (currentFile) {
        const f = currentFile;
        if (f.text !== null) {
            // Plain text: embed in message body
            payload.message += `\n\n---\n**Ficheiro anexado: ${f.name}**\n\`\`\`\n${f.text.substring(0, 15000)}\n\`\`\``;
        } else if (f.b64) {
            // Binary file (PDF, Excel, Word…): base64 JSON
            payload.file_b64  = f.b64;
            payload.file_type = f.type || ('application/' + f.ext);
            payload.file_name = f.name;
        }
        clearImage();
        logActivity('📎', `Ficheiro incluído: ${f.name} (${f.size})`, 'done');
    }

    const requestBody    = JSON.stringify(payload);
    const requestHeaders = {
        'Content-Type': 'application/json',
        'Accept':       'text/event-stream',
        'X-CSRF-TOKEN': CSRF,
    };

    resolveStep(stepRAG);

    // State accumulated across SSE events
    let metaData     = null;   // from the first 'meta' event
    let accumulated  = '';     // full reply text built chunk by chunk
    let streamMsg    = null;   // the AI message DOM element
    let streamBubble = null;   // the bubble div inside that message

    try {
        const res = await fetch('/api/chat', {
            method:  'POST',
            headers: requestHeaders,
            body:    requestBody,
        });

        if (!res.ok) {
            const raw = await res.text();
            typing.remove();
            let errMsg;
            if (res.status === 413) {
                errMsg = '❌ Ficheiro demasiado grande para o servidor. Tenta um ficheiro mais pequeno (< 1 MB) ou pede ao admin para aumentar o limite do Nginx (client_max_body_size 50M).';
            } else if (res.status === 422) {
                errMsg = '❌ Dados inválidos: ' + (raw.replace(/<[^>]+>/g,'').trim().substring(0,200) || 'Verifica o tamanho do ficheiro.');
            } else {
                const snippet = raw.replace(/<[^>]+>/g,'').trim().substring(0,200);
                errMsg = `❌ Erro HTTP ${res.status}: ${snippet || 'Sem detalhe'}`;
            }
            addMessage('ai', errMsg);
            logActivity('❌', `HTTP ${res.status}`, 'done');
            sendBtn.disabled = false; clearAgentActive(); modelBadge.textContent = 'pronto'; input.focus();
            return;
        }

        const reader  = res.body.getReader();
        const decoder = new TextDecoder();
        let   lineBuf = '';

        // Process one SSE line
        function handleLine(line) {
            line = line.trim();
            if (!line.startsWith('data: ')) return;
            const raw = line.slice(6);

            if (raw === '[DONE]') return; // handled after loop

            let evt;
            try { evt = JSON.parse(raw); } catch { return; }

            // ── Meta event (first event) ──
            if (evt.type === 'meta') {
                metaData = evt;
                typing.remove();
                resolveStep(stepAgent);

                const agentKey = evt.agent || selectedAgent;

                // Apply agent color
                applyAgentColor(agentKey);

                // Log agent actions
                if (evt.agent_log) {
                    evt.agent_log.forEach(l => logActivity(l.icon, l.text, 'done'));
                }

                modelBadge.textContent = evt.model || evt.agents?.join(', ') || agentKey;

                // Create the streaming message bubble (empty, will fill with chunks)
                // Email responses are streamed as plain text then parsed at [DONE]
                const msgEl = document.createElement('div');
                msgEl.className = 'message ai';

                const agentEmoji = AGENT_EMOJIS[agentKey] || '🤖';
                const agentLabel = AGENT_NAMES[agentKey]  || 'ClawYard';

                msgEl.innerHTML = `
                    <div class="avatar">${agentEmoji}</div>
                    <div class="msg-col">
                        <div class="msg-meta">
                            <span>${agentLabel}</span>
                            <span class="agent-tag active">${agentEmoji} ${agentLabel}</span>
                        </div>
                        <div class="bubble stream-bubble"></div>
                    </div>`;

                chat.appendChild(msgEl);
                chat.scrollTop = chat.scrollHeight;

                streamMsg    = msgEl;
                streamBubble = msgEl.querySelector('.stream-bubble');
                return;
            }

            // ── Error event ──
            if (evt.error) {
                if (typing.parentNode) typing.remove();
                addMessage('ai', '❌ Erro: ' + evt.error);
                logActivity('❌', 'Erro: ' + evt.error, 'done');
                return;
            }

            // ── Chunk event ──
            if (evt.chunk !== undefined && streamBubble) {
                accumulated += evt.chunk;
                // Strip hidden DISCOVERIES_JSON block before rendering
                // (QuantumAgent sends it as an HTML comment; strip it so the
                //  user never sees partial JSON during streaming)
                const displayText = accumulated.replace(
                    /<!--\s*DISCOVERIES_JSON[\s\S]*?(DISCOVERIES_JSON\s*-->|$)/g, ''
                );
                streamBubble.innerHTML = renderMarkdown(displayText) + '<span class="stream-cursor">▌</span>';
                chat.scrollTop = chat.scrollHeight;
            }
        }

        // Read the stream
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            lineBuf += decoder.decode(value, { stream: true });

            let nlPos;
            while ((nlPos = lineBuf.indexOf('\n')) !== -1) {
                const line = lineBuf.slice(0, nlPos);
                lineBuf    = lineBuf.slice(nlPos + 1);

                if (line.trim() === 'data: [DONE]') {
                    // Streaming complete — finalise the message
                    const agentKey = metaData?.agent || selectedAgent;

                    if (streamMsg && streamBubble) {
                        // Check if it's an email response (accumulated after DONE)
                        if (accumulated.startsWith('__EMAIL__')) {
                            try {
                                const emailData = JSON.parse(accumulated.replace('__EMAIL__', ''));
                                // Replace the streaming bubble with a proper email card
                                const msgCol = streamMsg.querySelector('.msg-col');
                                msgCol.innerHTML = `
                                    <div class="msg-meta">
                                        <span class="agent-tag active">📧 Daniel Email</span>
                                        <span>email gerado</span>
                                    </div>
                                    ${buildEmailCard(emailData)}`;
                                streamMsg.querySelector('.avatar').textContent = AGENT_EMOJIS['email'];
                            } catch (e) {
                                // Fallback: display raw text
                                streamBubble.innerHTML = renderMarkdown(accumulated.replace('__EMAIL__', ''));
                            }
                        } else {
                            // Final render + add save button (strip hidden QuantumAgent JSON block)
                            const finalDisplay = accumulated.replace(
                                /<!--\s*DISCOVERIES_JSON[\s\S]*?(DISCOVERIES_JSON\s*-->|$)/g, ''
                            ).trim();
                            streamBubble.innerHTML = renderMarkdown(finalDisplay);
                            const meta = streamMsg.querySelector('.msg-meta');
                            const saveBtn = document.createElement('button');
                            saveBtn.className = 'save-report-btn';
                            saveBtn.title     = 'Guardar como relatório';
                            saveBtn.textContent = '💾';
                            saveBtn.onclick   = function() { saveAsReport(this, agentKey); };
                            meta.appendChild(saveBtn);
                        }
                        chat.scrollTop = chat.scrollHeight;
                    }

                    // Suggestions
                    if (metaData?.suggestions) {
                        addSuggestions(metaData.suggestions, agentKey);
                    }

                    // Autonomous action proposals
                    if (accumulated && !accumulated.startsWith('__EMAIL__')) {
                        const replyLower = accumulated.toLowerCase();

                        const salesAgents = ['sales','email','auto','orchestrator','maritime'];
                        if (salesAgents.includes(agentKey) && (replyLower.includes('concorrente') || replyLower.includes('competitor'))) {
                            setTimeout(() => addActionApproval({
                                icon: '🔍',
                                title: 'Análise de concorrentes detectada',
                                description: 'Posso pesquisar automaticamente os concorrentes mencionados na base de dados de portos e enviar-lhe um email com o relatório completo.',
                                agent: 'email',
                                prompt: encodeURIComponent('Escreve um email com análise dos concorrentes nos portos europeus para enviar ao CEO'),
                            }), 800);
                        }

                        const noEmailAgents = ['aria','quantum','nvidia','claude','document'];
                        if ((replyLower.includes('proposta') || replyLower.includes('proposal')) && !noEmailAgents.includes(agentKey)) {
                            setTimeout(() => addActionApproval({
                                icon: '📧',
                                title: 'Transformar em email profissional?',
                                description: 'O agente gerou uma proposta. Queres que o Daniel Email a transforme num email profissional pronto a enviar?',
                                agent: 'email',
                                prompt: encodeURIComponent('Transforma em email profissional: ' + accumulated.substring(0,300)),
                            }), 800);
                        }
                    }

                    logActivity('✅', 'Resposta pronta', 'done');
                    continue;
                }

                handleLine(line);
            }
        }

    } catch (err) {
        if (typing.parentNode) typing.remove();
        const errMsg = err?.message || String(err);
        addMessage('ai', '❌ Erro: ' + errMsg);
        logActivity('❌', 'Erro: ' + errMsg, 'done');
        console.error('sendMessage error:', err);
    } finally {
        sendBtn.disabled = false;
        clearAgentActive();
        modelBadge.textContent = 'pronto';
        input.focus();
    }
}
</script>

</body>
</html>
