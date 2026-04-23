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

    {{-- Apply saved theme BEFORE first paint to avoid FOUC --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('cy-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── DARK (default) ── */
        :root {
            --green: #76b900;
            --green-hover: #8fd400;
            --bg: #0a0a0a;
            --bg2: #111;
            --bg3: #1a1a1a;
            --border: #222;
            --border2: #2a2a2a;
            --text: #e5e5e5;
            --text-strong: #ffffff;
            --muted: #555;
            --muted2: #444;
            --agent-color: #76b900;

            /* bubble + content colors */
            --bubble-user-bg: #1a2a0a;
            --bubble-user-border: #2a4a0a;
            --bubble-ai-bg: #111;
            --bubble-ai-border: #2a2a2a;
            --code-bg: #0a0a0a;
            --code-border: #2a2a2a;
            --link: #76b900;
        }

        /* ── LIGHT ── */
        :root[data-theme="light"] {
            --green: #5a9300;
            --green-hover: #6ead00;
            --bg: #f7f8fa;
            --bg2: #ffffff;
            --bg3: #f1f3f5;
            --border: #e5e7eb;
            --border2: #d1d5db;
            --text: #1f2937;
            --text-strong: #0f172a;
            --muted: #6b7280;
            --muted2: #9ca3af;
            --agent-color: #5a9300;

            --bubble-user-bg: #f1f8e4;
            --bubble-user-border: #c5e08a;
            --bubble-ai-bg: #ffffff;
            --bubble-ai-border: #e5e7eb;
            --code-bg: #f4f4f5;
            --code-border: #e5e7eb;
            --link: #1d6f00;
        }

        body, header, .main, #activity-panel, .chat-area, input, textarea, button { transition: background 0.2s, color 0.2s, border-color 0.2s; }

        /* Theme toggle icon swap — only one visible at a time */
        #theme-toggle .theme-icon-dark, #theme-toggle .theme-icon-light { display: none; line-height: 1; }
        :root[data-theme="light"] #theme-toggle .theme-icon-dark { display: inline; }
        :root[data-theme="dark"]  #theme-toggle .theme-icon-light,
        :root:not([data-theme])   #theme-toggle .theme-icon-light { display: inline; }
        #theme-toggle:hover { color: var(--text); border-color: var(--muted); transform: rotate(20deg); }

        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background:var(--bg); color:var(--text); height:100vh; display:flex; flex-direction:column; overflow:hidden; }

        /* ── HEADER ── */
        header { display:flex; align-items:center; gap:10px; padding:12px 20px; border-bottom:1px solid var(--border); background:var(--bg2); flex-shrink:0; }
        .logo { font-size:18px; font-weight:800; color:var(--green); letter-spacing:-0.5px; }
        .badge { font-size:10px; background:var(--green); color:#000; padding:2px 8px; border-radius:20px; font-weight:700; }
        .hdr-right { display:flex; align-items:center; gap:8px; }
        #agent-select { background:var(--bg3); border:1px solid var(--border2); color:var(--text); padding:5px 10px; border-radius:8px; font-size:12px; cursor:pointer; outline:none; }
        #agent-select:focus { border-color:var(--green); }
        #model-badge { font-size:11px; color:var(--muted); background:var(--bg3); padding:3px 10px; border-radius:20px; border:1px solid var(--border); }
        .back-btn { color:var(--muted); text-decoration:none; font-size:18px; padding:4px; }
        .back-btn:hover { color:var(--text); }

        /* ── MAIN LAYOUT ── */
        .main { flex:1; display:flex; flex-direction:row-reverse; overflow:hidden; }

        /* ── SIDEBAR — Agent Activity ── */
        #activity-panel {
            width:260px; min-width:260px; border-left:1px solid var(--border);
            background:var(--bg2); display:flex; flex-direction:column;
            transition: width 0.3s; overflow:hidden;
        }
        #activity-panel.collapsed { width:0; min-width:0; border-left:none; }
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

        /* Agent live status — compact 4-column grid */
        .agent-live { padding:8px; border-top:1px solid var(--border); flex-shrink:0; background:var(--bg); max-height:400px; overflow-y:auto; }
        .agent-live .lbl { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; padding:0 2px; }
        .agent-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:3px; }
        .ag-section-hdr { grid-column:1/-1; font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:#3a3a4a; padding:6px 2px 2px; border-top:1px solid #1a1a2a; margin-top:2px; }
        .ag-section-hdr:first-child { border-top:none; padding-top:0; margin-top:0; }
        .agent-grid-item {
            position:relative; display:flex; flex-direction:column; align-items:center;
            justify-content:center; gap:2px; padding:7px 2px 5px;
            border-radius:8px; cursor:pointer;
            background:var(--bg3); border:1.5px solid transparent;
            transition:border-color .12s, background .12s;
        }
        .agent-grid-item:hover { border-color:var(--border2); background:#1f1f1f; }
        .agent-grid-item.active { border-color:var(--agent-color); background:#0a1800; }
        .agent-grid-item .ag-icon { font-size:17px; line-height:1; display:flex; align-items:center; justify-content:center; }
        .agent-grid-item .ag-icon .ag-photo { width:28px; height:28px; border-radius:50%; object-fit:cover; border:1.5px solid #2a2a3a; }
        .agent-grid-item.active .ag-icon .ag-photo { border-color:var(--agent-color); }
        .agent-grid-item .ag-name { font-size:8.5px; color:var(--muted); text-align:center; max-width:56px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.2; }
        .agent-grid-item.active .ag-name { color:#ccc; }
        .agent-grid-item .ag-dot { position:absolute; top:3px; right:4px; width:5px; height:5px; border-radius:50%; background:var(--muted2); }
        .agent-grid-item.active .ag-dot { background:var(--green); box-shadow:0 0 3px var(--green); animation:pulse-dot 1.5s infinite; }
        /* Activity step per agent */
        .agent-grid-item .ag-status { font-size:7.5px; color:#555; text-align:center; max-width:56px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.2; display:none; }
        .agent-grid-item.active .ag-status { display:block; color:var(--green); }
        .agent-grid-item.working .ag-status { display:block; color:#f59e0b; animation:status-pulse 1.2s ease-in-out infinite; }
        .agent-grid-item.working .ag-dot { background:#f59e0b; box-shadow:0 0 4px #f59e0b; animation:pulse-dot 1s infinite; }
        @keyframes status-pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:0.4} }

        /* ── CHAT AREA ── */
        .chat-wrap { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        /* ── Persistent agent header bar ── */
        .agent-header-bar { display:flex; align-items:center; gap:10px; padding:8px 18px; border-bottom:1px solid var(--border); background:var(--bg3); flex-shrink:0; min-height:48px; }
        .agent-header-bar .ahb-avatar { width:34px; height:34px; border-radius:50%; overflow:hidden; flex-shrink:0; border:2px solid var(--agent-color); box-shadow:0 0 8px var(--agent-color)55; display:flex; align-items:center; justify-content:center; font-size:18px; background:var(--bg2); transition:border-color .3s, box-shadow .3s; }
        .agent-header-bar .ahb-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; display:block; }
        .agent-header-bar .ahb-info { flex:1; min-width:0; }
        .agent-header-bar .ahb-name { font-size:13px; font-weight:700; color:var(--text); line-height:1.2; }
        .agent-header-bar .ahb-desc { font-size:11px; color:var(--muted); line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
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
        .email-outlook-btn { background:#0078d4; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; }
        .email-outlook-btn:hover { background:#006cbe; }
        .email-status { font-size:11px; margin-left:auto; }
        .email-status.sent { color:var(--green); }
        .email-status.err { color:#ff4444; }
        /* ── TABLE CARD (Marco Sales) ── */
        .table-card { background:var(--bg); border:1px solid #1a2e00; border-left:3px solid #3b82f6; border-radius:12px; overflow:hidden; margin-top:4px; }
        .table-card-header { background:#0a1220; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #1a2e00; }
        .table-card-header span { font-size:12px; font-weight:700; color:#3b82f6; }
        .table-card-header small { font-size:11px; color:var(--muted); }
        .table-wrap { overflow-x:auto; max-height:320px; overflow-y:auto; }
        .table-card table { width:100%; border-collapse:collapse; font-size:12px; }
        .table-card th { background:#0d1a30; color:#7ab3f0; padding:7px 12px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; position:sticky; top:0; }
        .table-card td { padding:7px 12px; border-bottom:1px solid #111; color:#ccc; }
        .table-card tr:hover td { background:#0a1220; }
        .table-analysis { padding:10px 16px; font-size:12px; color:#aaa; border-top:1px solid #111; line-height:1.6; }
        .table-recommendation { padding:8px 16px 10px; font-size:12px; color:var(--green); font-weight:600; }
        .table-actions { padding:8px 16px; display:flex; gap:8px; background:#0a1220; border-top:1px solid #1a2e00; }
        .table-excel-btn { background:#217346; color:#fff; border:none; padding:7px 16px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; }
        .table-excel-btn:hover { background:#1a5c38; }
        .table-copy-btn { background:none; color:var(--muted); border:1px solid var(--border2); padding:7px 14px; border-radius:8px; font-size:12px; cursor:pointer; }

        /* ── KYBER CARDS ── */
        .kyber-card { background:var(--bg); border:1px solid #1a3300; border-left:3px solid #76b900; border-radius:12px; overflow:hidden; margin-top:4px; font-size:13px; }
        .kyber-card-header { background:#0a1a00; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #1a3300; }
        .kyber-card-header .kh-title { font-size:12px; font-weight:700; color:#76b900; }
        .kyber-card-header .kh-sub { font-size:11px; color:var(--muted); }
        .kyber-field { padding:8px 16px; border-bottom:1px solid #111; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .kyber-field label { font-size:10px; color:var(--muted); min-width:52px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; flex-shrink:0; }
        .kyber-field .kv { flex:1; font-family:'JetBrains Mono',monospace; font-size:10px; color:#aaa; word-break:break-all; max-height:44px; overflow:hidden; }
        .kyber-field .kv.secret { color:#ffaa00; }
        .kyber-key-copy { background:none; border:1px solid var(--border2); color:var(--muted); padding:4px 10px; border-radius:6px; font-size:11px; cursor:pointer; white-space:nowrap; flex-shrink:0; }
        .kyber-key-copy:hover { border-color:#76b900; color:#76b900; }
        .kyber-warning { margin:8px 16px; background:#1a1000; border:1px solid #ffaa0033; border-radius:8px; padding:10px 14px; font-size:12px; color:#ffaa00; line-height:1.5; }
        .kyber-actions { padding:10px 16px; display:flex; gap:8px; flex-wrap:wrap; background:#0a1a00; border-top:1px solid #1a3300; }
        .kyber-send-btn { background:#76b900; color:#000; border:none; padding:8px 18px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:5px; }
        .kyber-send-btn:hover { background:#8fd400; }
        .kyber-send-btn:disabled { background:#333; color:#666; cursor:not-allowed; }
        .kyber-store-btn { background:#001f3f; color:#76b900; border:1px solid #76b90033; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; }
        .kyber-store-btn:hover { border-color:#76b900; }
        .kyber-copy-btn2 { background:none; color:var(--muted); border:1px solid var(--border2); padding:8px 14px; border-radius:8px; font-size:12px; cursor:pointer; }
        .kyber-copy-btn2:hover { border-color:var(--muted); color:#aaa; }
        .kyber-status { padding:0 16px 10px; font-size:12px; }
        .kyber-status.ok { color:#76b900; }
        .kyber-status.err { color:#ff4444; }
        [contenteditable][data-placeholder]:empty:before { content:attr(data-placeholder); color:#555; pointer-events:none; }

        /* ── MOBILE: Kyber compose ── */
        @media (max-width: 640px) {
            .kyber-card { margin-left:0; margin-right:0; width:100%; max-width:100%; box-sizing:border-box; }
            .kyber-card-header { padding:10px 12px; flex-direction:column; align-items:flex-start; gap:2px; }
            .kyber-actions { padding:10px 12px; flex-direction:column; gap:8px; }
            .kyber-send-btn,
            .kyber-store-btn,
            .kyber-copy-btn2 { width:100%; justify-content:center; padding:12px 14px; font-size:14px; min-height:44px; }
            .kyber-field { padding:8px 12px; }
        }

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
        .pdf-export-btn { background:none; border:1px solid var(--border2); color:var(--muted); cursor:pointer; font-size:11px; font-weight:600; margin-left:6px; opacity:0; transition:opacity .2s,border-color .2s; padding:2px 8px; border-radius:5px; }
        .pdf-export-btn:hover { opacity:1 !important; border-color:#76b900; color:#76b900; }
        .message.ai:hover .pdf-export-btn { opacity:0.5; }

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

            /* ── AGENT HEADER BAR: compact on mobile ── */
            .agent-header-bar { padding: 6px 12px; min-height: 40px; }
            .agent-header-bar .ahb-desc { display: none; }

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
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/clawyard-logo.svg" alt="ClawYard" style="height:36px;filter:drop-shadow(0 0 4px rgba(118,185,0,0.3));"></a>
    <span class="badge">AI</span>
    <select id="agent-select">
        <option value="auto">🤖 Auto Route</option>
        <option value="orchestrator">🌐 All Agents</option>
        <option value="sales">💼 Marco Sales</option>
        <option value="support">🔧 Marcus Suporte</option>
        <option value="email">📧 Daniel Email</option>
        <option value="sap">📊 Richard SAP</option>
        <option value="crm">🎯 Marta CRM</option>
        <option value="document">📄 Comandante Doc</option>
        <option value="claude">🧠 Bruno AI</option>
        <option value="nvidia">⚡ Carlos NVIDIA</option>
        <option value="aria">🔐 ARIA Security</option>
        <option value="quantum">⚛️ Prof. Quantum Leap</option>
        <option value="finance">💰 Dr. Luís Financeiro</option>
        <option value="research">🔍 Marina Research</option>
        <option value="capitao">⚓ Capitão Porto</option>
        <option value="acingov">🏛️ Dra. Ana Contratos</option>
        <option value="engineer">🔩 Eng. Victor I&amp;D</option>
        <option value="patent">🏛️ Dra. Sofia IP</option>
        <option value="energy">⚡ Eng. Sofia Energia</option>
        <option value="kyber">🔒 KYBER Encryption</option>
        <option value="qnap">🗄️ Arquivo PartYard</option>
        <option value="thinking">🧠 Prof. Deep Thought</option>
        <option value="batch">📦 Max Batch</option>
        <option value="computer">🖥️ RoboDesk</option>
        <option value="vessel">⚓ Capitão Vasco</option>
        <option value="mildef">🎖️ Cor. Rodrigues Defesa</option>
        <option value="shipping">🚚 Logística/PartYard</option>
    </select>
    <button id="share-agent-btn" onclick="openShareModal()" title="Partilhar este agente com um cliente" style="background:var(--agent-color,#76b900);border:none;color:#000;font-size:12px;font-weight:800;padding:5px 14px;border-radius:8px;cursor:pointer;white-space:nowrap;transition:.15s;display:flex;align-items:center;gap:5px;flex-shrink:0;margin-left:auto;">🔗 Share</button>
    <div class="hdr-right" style="margin-left:8px;">
        <span id="model-badge">pronto</span>
        <a href="/discoveries" title="Descobertas" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">🔬 Descobertas</a>
        <a href="/patents/library" title="Biblioteca de Patentes" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">🏛️ Patentes</a>
        <a href="/reports" title="Relatórios" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">📋 Reports</a>
        <a href="/conversations" title="Histórico de Conversas" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">💬 Histórico</a>
        <a href="/briefing" title="Briefing Executivo Diário" style="background:#0d1a00;border:1px solid #1e3300;color:#76b900;padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;font-weight:700;">📊 Briefing</a>
        <a href="/schedules" title="Tarefas Agendadas" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">🗓️ Schedule</a>
        <a id="manage-shares-btn" href="/shares" title="Gerir links partilhados" style="background:var(--bg3);border:1px solid #1e3a5f;color:#60a5fa;padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;font-weight:600;">⚙️ Shares</a>
        <button onclick="clearHistory()" title="Nova conversa (limpar histórico)" style="background:#1a0a00;border:1px solid #ff6600;color:#ff8844;font-size:12px;font-weight:700;padding:5px 14px;border-radius:8px;cursor:pointer;white-space:nowrap;transition:all .15s;display:flex;align-items:center;gap:5px;" onmouseover="this.style.background='#2a1000';this.style.borderColor='#ff8844'" onmouseout="this.style.background='#1a0a00';this.style.borderColor='#ff6600'">🗑️ Nova conversa</button>
        @if(Auth::user()->isAdmin())
        <a href="/admin/users" title="Admin" style="background:var(--bg3);border:1px solid #ff4444;color:#ff6666;padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">⚙️ Admin</a>
        @endif
        <button id="theme-toggle" title="Alternar tema dark/light" aria-label="Toggle theme" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 10px;border-radius:8px;font-size:14px;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;width:32px;height:32px;">
            <span class="theme-icon-light">🌙</span>
            <span class="theme-icon-dark">☀️</span>
        </button>
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
            <div class="lbl">Agentes · clica para selecionar</div>
            <div class="agent-grid" id="agent-grid">

                <!-- ── GERAL ── -->
                <div class="ag-section-hdr">🤖 Geral</div>
                <div class="agent-grid-item" data-agent="auto"       title="Auto Route"><span class="ag-icon">🤖</span><span class="ag-name">Auto</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="claude"     title="Bruno AI"><span class="ag-icon"><img src="/images/agents/claude.png" class="ag-photo" alt="Bruno"></span><span class="ag-name">Bruno</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="nvidia"     title="Carlos NVIDIA"><span class="ag-icon"><img src="/images/agents/nvidia.png" class="ag-photo" alt="Carlos"></span><span class="ag-name">Carlos</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="thinking"   title="Prof. Deep Thought — Extended Thinking"><span class="ag-icon">🧠</span><span class="ag-name">Deep Thought</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── COMERCIAL ── -->
                <div class="ag-section-hdr">💼 Comercial</div>
                <div class="agent-grid-item" data-agent="sales"      title="Marco Sales"><span class="ag-icon"><img src="/images/agents/sales.png" class="ag-photo" alt="Marco"></span><span class="ag-name">Marco</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="support"    title="Marcus Support"><span class="ag-icon"><img src="/images/agents/support.png" class="ag-photo" alt="Marcus"></span><span class="ag-name">Marcus</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="email"      title="Daniel Email"><span class="ag-icon"><img src="/images/agents/email.png" class="ag-photo" alt="Daniel"></span><span class="ag-name">Daniel</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="crm"        title="Marta CRM — Criar Oportunidades SAP"><span class="ag-icon"><img src="/images/agents/crm.png" class="ag-photo" alt="Marta"></span><span class="ag-name">Marta CRM</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── SAP & DADOS ── -->
                <div class="ag-section-hdr">📊 SAP &amp; Dados</div>
                <div class="agent-grid-item" data-agent="sap"        title="Richard SAP"><span class="ag-icon"><img src="/images/agents/sap.png" class="ag-photo" alt="Richard"></span><span class="ag-name">Richard</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── OPERAÇÕES ── -->
                <div class="ag-section-hdr">📋 Operações</div>
                <div class="agent-grid-item" data-agent="document"   title="Commander Doc"><span class="ag-icon"><img src="/images/agents/document.png" class="ag-photo" alt="Doc"></span><span class="ag-name">Doc</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="finance"    title="Dr. Luís Finance"><span class="ag-icon"><img src="/images/agents/finance.png" class="ag-photo" alt="Luís"></span><span class="ag-name">Luís</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="acingov"    title="Dr. Ana Contracts"><span class="ag-icon"><img src="/images/agents/acingov.png" class="ag-photo" alt="Ana"></span><span class="ag-name">Ana</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="batch"      title="Max Batch — Bulk Processing"><span class="ag-icon">📦</span><span class="ag-name">Max Batch</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── LOGÍSTICA ── -->
                <div class="ag-section-hdr">🚚 Logística</div>
                <div class="agent-grid-item" data-agent="shipping"   title="Logística/PartYard — transporte, faturação, alfândega e pauta aduaneira"><span class="ag-icon">🚚</span><span class="ag-name">Logística</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── DEFESA & SEGURANÇA ── -->
                <div class="ag-section-hdr">🎖️ Defesa &amp; Segurança</div>
                <div class="agent-grid-item" data-agent="mildef"     title="Cor. Rodrigues — Military Procurement"><span class="ag-icon"><img src="/images/agents/mildef.png" class="ag-photo" alt="Rodrigues"></span><span class="ag-name">Rodrigues</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="aria"       title="ARIA Security"><span class="ag-icon"><img src="/images/agents/aria.png" class="ag-photo" alt="ARIA"></span><span class="ag-name">ARIA</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="kyber"      title="KYBER Encryption"><span class="ag-icon"><img src="/images/agents/kyber.png" class="ag-photo" alt="KYBER"></span><span class="ag-name">KYBER</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="computer"   title="RoboDesk — Web Automation"><span class="ag-icon">🖥️</span><span class="ag-name">RoboDesk</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── I&D / CIÊNCIA ── -->
                <div class="ag-section-hdr">🔬 I&amp;D / Ciência</div>
                <div class="agent-grid-item" data-agent="research"   title="Marina Research"><span class="ag-icon"><img src="/images/agents/research.png" class="ag-photo" alt="Marina"></span><span class="ag-name">Marina</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="quantum"    title="Prof. Quantum Leap"><span class="ag-icon"><img src="/images/agents/quantum.png" class="ag-photo" alt="Quantum"></span><span class="ag-name">Quantum</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="engineer"   title="Eng. Victor R&D"><span class="ag-icon"><img src="/images/agents/engineer.png" class="ag-photo" alt="Victor"></span><span class="ag-name">Victor</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="patent"     title="Dra. Sofia IP"><span class="ag-icon">🏛️</span><span class="ag-name">Sofia IP</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

                <!-- ── NAVAL & ENERGIA ── -->
                <div class="ag-section-hdr">🚢 Naval &amp; Energia</div>
                <div class="agent-grid-item" data-agent="capitao"    title="Capitão Porto"><span class="ag-icon"><img src="/images/agents/maritime.png" class="ag-photo" alt="Capitão"></span><span class="ag-name">Capitão</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="vessel"     title="Capitão Vasco — Ship Search &amp; Naval Services"><span class="ag-icon">⚓</span><span class="ag-name">Cap. Vasco</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="energy"     title="Eng. Sofia Energia"><span class="ag-icon">🌱</span><span class="ag-name">Sofia E.</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>
                <div class="agent-grid-item" data-agent="qnap"       title="PartYard Archive"><span class="ag-icon">🗄️</span><span class="ag-name">Archive</span><span class="ag-status">ready</span><span class="ag-dot"></span></div>

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
        <!-- Persistent agent header — always visible even when history is loaded -->
        <div class="agent-header-bar" id="agent-header-bar">
            <div class="ahb-avatar" id="ahb-avatar">🤖</div>
            <div class="ahb-info">
                <div class="ahb-name" id="ahb-name">ClawYard Auto</div>
                <div class="ahb-desc" id="ahb-desc">Routing inteligente — vai ao agente certo automaticamente</div>
            </div>
        </div>
        <div id="chat">
            <div class="empty-state" id="empty-state">
                <div class="empty-state-hero">
                    <div style="display:flex;flex-direction:column;align-items:center;gap:10px">
                        <div class="empty-state-avatar" id="empty-avatar">🤖</div>
                    </div>
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
            <button type="button" class="icon-btn" id="image-btn" title="Anexar ficheiros (PDF, imagem, Excel, Word, TXT, Email) — múltiplos permitidos" onclick="document.getElementById('image-input').click()" style="cursor:pointer">📎</button>
            <input type="file" id="image-input" accept="image/*,.pdf,.doc,.docx,.txt,.csv,.xlsx,.xls,.pptx,.md,.eml,.msg" multiple style="position:absolute;width:0;height:0;opacity:0;pointer-events:none">
            <button class="icon-btn" id="clear-btn" title="Limpar histórico desta conversa" onclick="clearHistory()">🗑️</button>
            <button type="button" id="finance-pdf-btn" onclick="createAgentPdf()" title="Gerar relatório PDF desta conversa" style="display:none;align-items:center;gap:5px;background:#10b981;border:none;color:#fff;font-size:11px;font-weight:700;padding:0 12px;border-radius:8px;cursor:pointer;white-space:nowrap;height:38px;flex-shrink:0;">📄 PDF</button>
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

// ── Persistent SESSION_ID per agent (survives page navigation) ──────────────
function getSessionId(agent) {
    const key = 'cyw_session_' + (agent || 'auto');
    let id = localStorage.getItem(key);
    if (!id) {
        id = 'cyw_' + Date.now() + '_' + Math.random().toString(36).substr(2,6);
        localStorage.setItem(key, id);
    }
    return id;
}
let selectedAgent = agentSelect.value || 'auto';
let SESSION_ID    = getSessionId(selectedAgent);

const AGENT_EMOJIS = {
    auto:'🤖', orchestrator:'🌐', sales:'💼', support:'🔧',
    email:'📧', sap:'📊', crm:'🎯', document:'📄', claude:'🧠', nvidia:'⚡',
    aria:'🔐', quantum:'⚛️', finance:'💰', research:'🔍',
    capitao:'⚓', acingov:'🏛️', engineer:'🔩', patent:'🏛️', energy:'⚡', kyber:'🔒', qnap:'🗄️',
    thinking:'🧠', batch:'📦', computer:'🖥️', vessel:'⚓', mildef:'🎖️',
    shipping:'🚚'
};

// Agents with a real photo (stored in /images/agents/{key}.png)
const AGENT_PHOTOS = {
    briefing:     '/images/agents/briefing.png',
    orchestrator: '/images/agents/orchestrator.png',
    sales:        '/images/agents/sales.png',
    support:      '/images/agents/support.png',
    email:        '/images/agents/email.png',
    sap:          '/images/agents/sap.png',
    crm:          '/images/agents/crm.png',
    document:     '/images/agents/document.png',
    claude:       '/images/agents/claude.png',
    nvidia:       '/images/agents/nvidia.png',
    aria:         '/images/agents/aria.png',
    quantum:      '/images/agents/quantum.png',
    finance:      '/images/agents/finance.png',
    research:     '/images/agents/research.png',
    capitao:      '/images/agents/maritime.png',
    acingov:      '/images/agents/acingov.png',
    engineer:     '/images/agents/engineer.png',
    kyber:        '/images/agents/kyber.png',
    mildef:       '/images/agents/mildef.png',
};

const AGENT_NAMES = {
    auto:'Auto', orchestrator:'All Agents', sales:'Marco Sales', support:'Marcus Suporte',
    email:'Daniel Email', sap:'Richard SAP', crm:'Marta CRM', document:'Comandante Doc', claude:'Bruno AI', nvidia:'Carlos NVIDIA',
    aria:'ARIA Security', quantum:'Prof. Quantum Leap', finance:'Dr. Luís Financeiro', research:'Marina Research',
    capitao:'Capitão Porto',
    acingov:'Dra. Ana Contratos',
    engineer:'Eng. Victor I&D',
    patent: 'Dra. Sofia IP',
    energy: 'Eng. Sofia Energia',
    kyber:    'KYBER Encryption',
    qnap:     'Arquivo PartYard',
    thinking: 'Prof. Deep Thought',
    batch:    'Max Batch',
    computer: 'RoboDesk',
    vessel:   'Capitão Vasco',
    mildef:   'Cor. Rodrigues Defesa',
    shipping: 'Logística/PartYard'
};

const AGENT_COLORS = {
    auto:'#76b900', orchestrator:'#76b900',
    sales:'#3b82f6', support:'#f59e0b', email:'#8b5cf6',
    sap:'#06b6d4', document:'#94a3b8', claude:'#a855f7',
    nvidia:'#76b900', aria:'#ef4444', quantum:'#22d3ee',
    finance:'#10b981', research:'#f97316',
    capitao:'#0ea5e9',
    acingov:'#f59e0b',
    engineer:'#f97316',
    patent:'#8b5cf6',
    energy:'#10b981',
    kyber:'#76b900',
    qnap: '#f59e0b',
    thinking:'#a855f7',
    batch:'#06b6d4',
    computer:'#22c55e',
    vessel:'#0ea5e9',
    mildef:'#6b3fa0',
    shipping:'#8b5cf6'
};

const AGENT_DESCRIPTIONS = {
    auto: 'Routing inteligente — vai ao agente certo automaticamente',
    orchestrator: 'Colaboração entre todos os agentes em simultâneo',
    sales: 'Comparação de preços, análise de fornecedores e códigos de fabricante — exporta Excel',
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
    engineer: 'I&D e desenvolvimento de produto — planos, TRL, CAPEX e roadmap para novos equipamentos PartYard',
    patent:   'Propriedade Intelectual — validação de patentes, prior art EPO/USPTO/WIPO, patenteabilidade e Freedom to Operate',
    energy:   'Descarbonização marítima — Fuzzy TOPSIS, CII/EEXI, LNG/Biofuel/H₂, Fleet Energy Management (PeerJ CS 3625)',
    kyber:    'Encriptação post-quantum de emails — CRYSTALS-Kyber 1024 + AES-256-GCM (NIST FIPS 203)',
    capitao:  'Operações portuárias, escalas, documentação e logística marítima',
    qnap:     'Arquivo documental PartYard — pesquisa preços, códigos, invoices, licenças e contratos',
    mildef:   'Procurement militar mundial (excl. China/Rússia) — radares, SAM, AAM, artilharia, munições, bombs — contexto NATO/EU/USLI',
    crm:      'Cria oportunidades SAP B1 a partir de emails recebidos — extrai campos automaticamente e grava no CRM',
    shipping: 'Logística/PartYard — cotações UPS/FedEx, faturação pro-forma/CMR/AWB, Incoterms 2020 e pauta aduaneira HS/CN/TARIC',
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
        '💼 Compara preços OEM vs aftermarket para pistões MTU Série 4000 — exporta Excel',
        '💼 Analisa este PDF de fornecedor e extrai referências, preços e lead times',
        '💼 Equivalências de filtros Caterpillar 3516 — OEM vs Fleetguard vs Mann',
        '💼 Compara 3 fornecedores de selos SKF SternTube — qualidade, preço e prazo',
        '💼 Códigos cruzados MAN B&W vs peças aftermarket — tabela comparativa',
        '💼 Análise de mercado: turbocompressores MAK M32 — fornecedores globais e preços',
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
        '📧 Cold outreach EN para shipping agent em Hamburgo — motor MTU Série 4000',
        '📧 Proposta comercial PT para armador em Lisboa — peças MAK M32 disponíveis',
        '📧 Follow-up urgente para cliente em Pireu — selos SKF em stock imediato',
        '📧 Apresentação PartYard Defense para NATO procurement em Bruxelas',
        '📧 Email de parceria para agente marítimo em Valência — exclusividade Schottel',
        '📧 Warranty claim ao fabricante MTU — defeito em peças série 2000',
    ],
    // ── Richard SAP ───────────────────────────────────────────────────────
    sap: [
        '📊 Pipeline CRM completo por vendedor — cotações de compra e venda abertas',
        '📊 Cotações de venda abertas — maiores oportunidades, por quem e quando fecham?',
        '📊 Cotações de compra em pipeline — fornecedores, valores e datas de fecho',
        '📊 Encomendas abertas NSPA — estado actual, valores e prazos',
        '📊 NSN 1290997479873 — stock disponível, fornecedor e quantidades encomendadas',
        '📊 Encomendas abertas RAYTHEON — OC pendentes e valores',
        '📊 OCEANPACT — ordens abertas e histórico recente de faturas',
        '📊 Facturas em atraso >30 dias — lista de clientes e valor total',
        '📊 Artigos com stock baixo — código NSN, quantidade actual vs mínimo',
        '📊 Vendas último mês — top 10 clientes por valor faturado',
    ],
    // ── Marta CRM ─────────────────────────────────────────────────────────
    crm: [
        '🎯 Cola aqui o email do cliente — vou criar a oportunidade no SAP',
        '🎯 NSPA enviou pedido de cotação para peças MTU — cria oportunidade Cotação de Compra',
        '🎯 Cria oportunidade OCEANPACT — Possível Venda, €80.000, fecho Junho 2026',
        '🎯 Actualiza oportunidade #1234 — passa para Follow Up Vendas, valor €65.000',
        '🎯 Email SASU VBAF recebido — inspecção de motores, prazo urgente 30 dias',
        '🎯 Cria prospecção para novo armador em Lisboa — reunião marcada para próxima semana',
        '🎯 Qual o pipeline de Cotações de Venda? Mostra por vendedor',
        '🎯 Quais as oportunidades em Follow Up há mais de 30 dias sem actualização?',
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
        '🔐 [CT-GMARL] Análise SIEM assíncrona: cola logs Windows Event XML para análise de ameaças em tempo contínuo',
        '🔐 [ZTNA] Arquitectura Zero-Trust para a infra PartYard — segmentação DMZ → Corporate → Secure Vault',
        '🔐 [NetForge_RL] Simula cadeia de ataque APT na infra PartYard: EternalBlue → LSASS → PassTheTicket → Lateral Movement',
        '🔐 [Surgical Defense] Analisa trade-off Scorched Earth vs Defesa Cirúrgica para os sistemas do HP-Group',
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
    // ── Eng. Victor I&D ────────────────────────────────────────────────────
    engineer: [
        '🔩 Analisa o briefing do Renato e propõe 3 novos produtos para desenvolver',
        '🛩️ Plano de desenvolvimento: kit de reparação certificado MIL-SPEC para AH-64 Apache',
        '🛢️ ARMITE: plano I&D para novo lubrificante bio-based MIL-PRF-32033 sustentável',
        '🎯 Roadmap completo: simulador de voo part-task trainer para C-130 — TRL, CAPEX, parceiros',
        '🔐 SETQ: plano de produto HSM (Hardware Security Module) para instalações NATO',
        '🚗 Retrofit kit para Leopard 2: sistema de gestão de potência com IA — viabilidade técnica',
    ],
    // ── Eng. Sofia Energia ───────────────────────────────────────────────
    energy: [
        '⚡ Fuzzy TOPSIS: qual o melhor combustível para um ferry de 120m em rotas de cabotagem PT?',
        '🌊 Análise CII/EEXI: navio bulk carrier com motor MAK 9M32C — opções de retrofit para 2026',
        '🔋 Comparação LNG vs Biofuel drop-in para frota de 5 rebocadores MTU — CAPEX e payback',
        '🌿 Plano de descarbonização para armador com 12 navios — metas IMO 2030 e EU ETS marítimo',
        '⚓ Retrofit propulsão eléctrica para ferry Setúbal-Tróia — viabilidade técnica e financiamento',
        '📊 Qual o impacto de mudar para biocombustível B30 nos motores CAT 3516 da frota PartYard?',
    ],
    // ── Dra. Sofia IP ────────────────────────────────────────────────────
    patent: [
        '🔍 Valida o projecto de lubrificante bio-based ARMITE — prior art EPO e patenteabilidade',
        '🏛️ Pesquisa prior art para simulador de voo com IA adaptativa — conflitos com patentes activas?',
        '✅ O sistema de diagnóstico remoto de motores navais da PartYard já foi patenteado por alguém?',
        '📋 Analisa todos os projectos do Eng. Victor e diz quais são patenteáveis imediatamente',
        '🔐 Freedom to Operate: kit de reparação MIL-SPEC para AH-64 — podemos fabricar sem infringir?',
        '💡 Estratégia IP completa para o HP-Group — onde depositar patentes primeiro (EP vs PCT vs PT)',
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
    // ── KYBER Encryption ──────────────────────────────────────────────────
    kyber: [
        '🔒 Gera um par de chaves Kyber-1024 para mim e explica como guardar o secret key',
        '🔒 Explica como enviar um email encriptado com Kyber-1024 passo a passo',
        '🔒 O que é o CRYSTALS-Kyber? Porque é resistente a computadores quânticos?',
        '🔒 Como funciona o esquema KEM + AES-256-GCM usado nos emails encriptados?',
        '🔒 Como instalar a extensão Kyber no Outlook para Mac e Windows?',
        '🔒 Qual a diferença entre Kyber-512, Kyber-768 e Kyber-1024? Qual usar?',
    ],
    // ── Arquivo PartYard ──────────────────────────────────────────────────
    qnap: [
        '🗄️ Que fornecedores da Collins Aerospace temos no arquivo e quais as condições de licença?',
        '🗄️ Mostra-me todas as invoices de 2023 e os valores totais por fornecedor',
        '🗄️ Pesquisa documentos sobre o programa NP2000 — preços e códigos de peças',
        '🗄️ Que condições de crédito temos com os nossos fornecedores? (net 30, net 60...)',
        '🗄️ Lista todos os contratos e declarações relacionados com o Min. Defesa Nacional',
        '🗄️ Analisa os ficheiros CONCURSOS Excel e resume as oportunidades',
    ],
    // ── Prof. Deep Thought ────────────────────────────────────────────────
    thinking: [
        '🧠 Qual a estratégia óptima para a PartYard dominar o mercado de peças navais na Europa Meridional nos próximos 5 anos?',
        '🧠 Analisa em profundidade os riscos geopolíticos que afectam o supply chain marítimo europeu em 2026',
        '🧠 Faz um raciocínio primeiro-princípios: porque é que os motores MTU dominam o mercado naval militar?',
        '🧠 Modela o impacto de uma recessão europeia de 18 meses no negócio da PartYard — 3 cenários',
        '🧠 Qual o argumento mais forte para a PartYard entrar no mercado de MRO aeronáutico militar?',
        '🧠 Decompõe o problema: como aumentar a margem bruta de 22% para 35% em 24 meses?',
    ],
    // ── Max Batch ─────────────────────────────────────────────────────────
    batch: [
        '📦 Processa esta lista de 50 referências MTU e gera descrições de produto em PT/EN/ES para cada uma',
        '📦 Analisa em paralelo estes 10 PDFs de fornecedores e extrai preços, prazos e condições',
        '📦 Gera 20 cold emails personalizados para armadores gregos — adapta por empresa e frota',
        '📦 Classifica e resume estes 30 concursos BASE.gov por relevância para a PartYard',
        '📦 Cria fichas técnicas para 15 peças CAT 3516 — formato SAP B1 pronto a importar',
        '📦 Traduz 25 documentos técnicos Schottel do alemão para português e inglês em paralelo',
    ],
    // ── RoboDesk ──────────────────────────────────────────────────────────
    computer: [
        '🖥️ Abre o SAP B1 no browser, entra em Compras → Ordens de Compra e tira screenshot da lista de OC abertas',
        '🖥️ Vai ao base.gov.pt, pesquisa contratos públicos com "motor naval" publicados esta semana e copia os resultados',
        '🖥️ Abre o Gmail, procura emails não lidos de clientes com "urgent" ou "NSPA" no assunto e lista-os',
        '🖥️ Entra no portal ACINGOV, faz login e descarrega os concursos abertos da área de defesa em PDF',
        '🖥️ Abre o Excel com a lista de preços, actualiza os valores MTU Série 4000 com os preços do site MTU e guarda',
        '🖥️ Preenche o formulário de registo no portal de fornecedores da NATO com os dados da PartYard',
    ],
    // ── Capitão Vasco ─────────────────────────────────────────────────────
    vessel: [
        '⚓ Procura navio fluvial automotor 2300+ DWT, máx 112m, máx €2M — Rhine/Danube flag',
        '⚓ Lista estaleiros em Portugal e Holanda capazes de colocar navio de 110m em dique seco',
        '⚓ Verifica no mercado actual navios com bow thruster e autopilot disponíveis abaixo de €1.5M',
        '⚓ Quais os contactos dos brokers neerlandeses especializados em motorvrachtschepen 110m?',
        '⚓ Analisa a oferta Mi Vida (ENI 08023148) — especificações, preço e gap de certificação',
        '⚓ Empresas de reparação naval no Reno/Main para overhaul de motor e renovação de casco',
    ],
    mildef: [
        '🎯 Lista todos os fabricantes mundiais (excl. China/Rússia) de mísseis SAM com alcance >70 km',
        '📡 Fabricantes de radares de defesa aérea tática NATO — com specs e contactos',
        '📧 Escreve emails RFI para todos os fabricantes de radares e SAM identificados',
        '✉️ Draft email de procurement para MBDA, Raytheon e Kongsberg — RFP mísseis SAM >70km',
        '📋 Ajuda-me a preencher o Anexo 1 e Anexo 2 para o Ukraine Support Loan Instrument EU',
        '📧 Cria emails em PT+EN para todos os fornecedores de bombas guiadas (SPICE, Paveway, JDAM)',
    ],
    shipping: [
        '🚚 Cota UPS: 12 kg 50×40×30 cm para Hamburgo — Express Saver vs Expedited',
        '📋 Emite fatura pro-forma + packing list para exportação de rolamentos para o Reino Unido',
        '🏛️ Código pautal para válvula de esfera DN50 PN40 aço inox — confirma TARIC',
        '🏛️ O que é o 8482.10.00? Dá-me o link TARIC e as medidas activas',
        '📑 Classifica: "turbina de motor A400M" — propõe capítulo, posição e candidatos CN',
        '💶 IVA intracomunitário B2B para Alemanha — tratamento correcto na fatura',
        '✈️ EUR.1 para exportação de peças aeronáuticas para Marrocos — procedimento',
        '📦 Envio de peças Collins Aerospace EUA→PT — direitos aduaneiros e IVA de importação',
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

// ── Persistent agent header bar (always visible above messages) ─────────────
function updateAgentHeader(agent) {
    const avatarEl = document.getElementById('ahb-avatar');
    const nameEl   = document.getElementById('ahb-name');
    const descEl   = document.getElementById('ahb-desc');
    if (!avatarEl) return;
    const emoji = AGENT_EMOJIS[agent] || '🤖';
    const name  = AGENT_NAMES[agent]  || 'ClawYard';
    const desc  = AGENT_DESCRIPTIONS[agent] || 'Escolhe um exemplo ou escreve a tua pergunta';
    const photo = AGENT_PHOTOS[agent];
    if (photo) {
        avatarEl.innerHTML = `<img src="${photo}" alt="${name}">`;
    } else {
        avatarEl.innerHTML = emoji;
    }
    if (nameEl) nameEl.textContent = name;
    if (descEl) descEl.textContent = desc;
}

function updateEmptyState(agent) {
    // Always update the persistent header bar (visible even when messages are shown)
    updateAgentHeader(agent);

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

    // SAP: show WebClient shortcut link
    const existingLink = document.getElementById('sap-webclient-link');
    if (existingLink) existingLink.remove();
    if (agent === 'sap') {
        const link = document.createElement('a');
        link.id = 'sap-webclient-link';
        link.href = 'https://sld.partyard.privatcloud.biz/webx/index.html';
        link.target = '_blank';
        link.innerHTML = '🔗 Abrir SAP WebClient →';
        link.style.cssText = 'display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:12px;font-weight:600;color:#06b6d4;text-decoration:none;border:1px solid rgba(6,182,212,.3);padding:6px 16px;border-radius:20px;transition:all .15s;';
        link.onmouseover = () => { link.style.background='rgba(6,182,212,.1)'; link.style.borderColor='rgba(6,182,212,.6)'; };
        link.onmouseout  = () => { link.style.background=''; link.style.borderColor='rgba(6,182,212,.3)'; };
        document.getElementById('empty-state')?.querySelector('.empty-state-hero')?.appendChild(link);
    }
}

let isRecording  = false;
let isStreaming   = false;
let recognition  = null;
let currentImg      = null;
let currentImgType  = 'image/jpeg'; // MIME type of attached image
let currentFile  = null;  // primary binary file (PDF/Excel/Word) — first selected
let currentFiles = [];    // all attached files (text + binary); enables multi-attach
let panelOpen    = true;
let actCount     = 0;

// ── Agent from URL — must run BEFORE init so chips/colors reflect the right agent
const urlAgent = new URLSearchParams(window.location.search).get('agent');
if (urlAgent && agentSelect.querySelector(`option[value="${urlAgent}"]`)) {
    agentSelect.value = urlAgent;
}

// ── Session from URL — restores a specific conversation when coming
// from "Continue where you left off" on the dashboard. We pin the client
// session id into localStorage BEFORE getSessionId() runs so the existing
// history-restore path picks it up instead of spawning a fresh session.
const urlSession = new URLSearchParams(window.location.search).get('session');
if (urlAgent && urlSession && /^[A-Za-z0-9_\-]+$/.test(urlSession)) {
    localStorage.setItem('cyw_session_' + urlAgent, urlSession);
}

// ── Prefill from URL (?q=...) — agent profile starter chips jump straight
// into a chat with the question loaded in the textarea. User still hits
// Enter to send, so they can tweak first.
const urlQuery = new URLSearchParams(window.location.search).get('q');
if (urlQuery) {
    // Run on next tick so the textarea (defined earlier in this script file
    // as `const input = document.getElementById('message-input')`) exists.
    setTimeout(() => {
        const ta = document.getElementById('message-input');
        if (ta) {
            ta.value = urlQuery;
            ta.style.height = 'auto';
            ta.style.height = Math.min(ta.scrollHeight, 150) + 'px';
            ta.focus();
        }
    }, 0);
}

// Init on page load (after URL agent is applied)
const initAgent = agentSelect.value || 'auto';
selectedAgent = initAgent;
SESSION_ID    = getSessionId(initAgent);
renderStarterChips(initAgent);
applyAgentColor(initAgent);
updateEmptyState(initAgent);
updateShareBtn();
document.getElementById('finance-pdf-btn').style.display = 'flex';
// Mark initial agent in grid
document.querySelectorAll('.agent-grid-item').forEach(el => {
    el.classList.toggle('active', el.dataset.agent === initAgent);
});

// Restore history on page load
restoreHistory(initAgent);

// Update when agent changes
agentSelect.addEventListener('change', () => {
    const agent = agentSelect.value;
    selectedAgent = agent;
    SESSION_ID    = getSessionId(agent);
    renderStarterChips(agent);
    applyAgentColor(agent);
    updateEmptyState(agent);
    updateShareBtn();
    // PDF button always visible
    document.getElementById('finance-pdf-btn').style.display = 'flex';
    document.querySelectorAll('.agent-grid-item').forEach(el => {
        el.classList.toggle('active', el.dataset.agent === agent);
        const statusEl = el.querySelector('.ag-status');
        if (statusEl && el.dataset.agent !== agent && !el.classList.contains('working')) statusEl.textContent = 'ready';
    });
    // Clear chat and restore history for new agent
    document.getElementById('chat').innerHTML = '';
    document.getElementById('chat').insertAdjacentHTML('beforeend',
        '<div class="empty-state" id="empty-state"><div class="empty-state-hero">' +
        '<div style="display:flex;flex-direction:column;align-items:center;gap:10px">' +
        '<div class="empty-state-avatar" id="empty-avatar">🤖</div>' +
        '' +
        '</div>' +
        '<h2 id="empty-title">ClawYard <span>AI</span></h2>' +
        '<p id="empty-desc"></p></div>' +
        '<div class="starter-chips" id="starter-chips"></div></div>');
    updateEmptyState(agent);
    renderStarterChips(agent);
    restoreHistory(agent);
});

// ── Toggle activity panel ──
document.getElementById('toggle-panel').addEventListener('click', () => {
    panelOpen = !panelOpen;
    actPanel.classList.toggle('collapsed', !panelOpen);
});

// ── Voice input (Web Speech API) ─────────────────────────────────────────────
// Improved UX:
//  - Continuous dictation (doesn't stop after 1 sentence)
//  - Interim results render live in the textarea so you see what you're saying
//  - Does NOT auto-send — user reviews and hits Enter (safer, fewer surprises)
//  - Remembers the last committed text so interim doesn't overwrite what you typed
//  - Language toggle on the button: pt-PT ↔ en-US (double-click to cycle)
//  - Toast notifications on errors instead of alert()
// ────────────────────────────────────────────────────────────────────────────────

let voiceLang         = localStorage.getItem('cy-voice-lang') || 'pt-PT';
let voiceBaseText     = '';          // text already committed before current dictation
let voiceFinalBuffer  = '';          // final results accumulated during this session

// Mini toast helper (used for voice + other transient notifications)
function showVoiceToast(text, type = 'info', ms = 3000) {
    let toast = document.getElementById('cy-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'cy-toast';
        toast.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:99999;opacity:0;transition:opacity .2s,transform .2s;pointer-events:none;max-width:90%;box-shadow:0 8px 30px rgba(0,0,0,.35);';
        document.body.appendChild(toast);
    }
    const colours = {
        info:   { bg: '#111827', fg: '#f3f4f6', border: '#374151' },
        error:  { bg: '#450a0a', fg: '#fecaca', border: '#dc2626' },
        ok:     { bg: '#052e16', fg: '#bbf7d0', border: '#16a34a' },
    }[type] || { bg: '#111827', fg: '#f3f4f6', border: '#374151' };
    toast.style.background   = colours.bg;
    toast.style.color        = colours.fg;
    toast.style.border       = '1px solid ' + colours.border;
    toast.textContent        = text;
    requestAnimationFrame(() => {
        toast.style.opacity   = '1';
        toast.style.transform = 'translateX(-50%) translateY(-6px)';
    });
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(-50%) translateY(0)';
    }, ms);
}

const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
if (SR) {
    recognition = new SR();
    recognition.continuous     = true;   // keep listening after pauses
    recognition.interimResults = true;   // stream partial transcripts
    recognition.lang           = voiceLang;
    recognition.maxAlternatives = 1;

    recognition.onresult = (e) => {
        let interim = '';
        for (let i = e.resultIndex; i < e.results.length; i++) {
            const r = e.results[i];
            if (r.isFinal) voiceFinalBuffer += r[0].transcript;
            else            interim += r[0].transcript;
        }
        // Build the textarea content = base + final + interim (visually marked)
        const separator = (voiceBaseText && !voiceBaseText.endsWith(' ')) ? ' ' : '';
        input.value = voiceBaseText + separator + voiceFinalBuffer + interim;
        // Auto-grow textarea
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 150) + 'px';
    };

    recognition.onerror = (e) => {
        const msg = {
            'not-allowed':    'Permissão do microfone negada. Abre as definições do browser e autoriza.',
            'no-speech':      'Não ouvi nada. Tenta falar mais perto do microfone.',
            'audio-capture':  'Sem microfone disponível no dispositivo.',
            'network':        'Erro de rede na transcrição de voz.',
            'aborted':        null, // user stopped — silent
        }[e.error] || ('Erro no reconhecimento de voz: ' + e.error);
        if (msg) showVoiceToast(msg, 'error', 4500);
        stopRecording();
    };
    recognition.onend = () => stopRecording();
}

function stopRecording() {
    const btn = document.getElementById('voice-btn');
    if (btn) btn.classList.remove('active', 'recording');
    isRecording = false;
    if (input.value.trim() && voiceFinalBuffer.trim()) {
        showVoiceToast('✓ Transcrito — revê e Enter para enviar', 'ok', 2500);
    }
}

document.getElementById('voice-btn').addEventListener('click', () => {
    if (!recognition) {
        showVoiceToast('Voz não suportada neste browser. Usa Chrome ou Edge no desktop.', 'error', 4500);
        return;
    }
    const btn = document.getElementById('voice-btn');
    if (isRecording) {
        recognition.stop();
        return;
    }
    // Start: lock in whatever the user already typed
    voiceBaseText    = input.value || '';
    voiceFinalBuffer = '';
    try {
        recognition.start();
        btn.classList.add('active', 'recording');
        isRecording = true;
        showVoiceToast('🎤 A ouvir em ' + voiceLang + ' — clica outra vez para parar', 'info', 2200);
    } catch (err) {
        showVoiceToast('Não consegui iniciar o microfone — recarrega a página e tenta outra vez.', 'error', 4500);
    }
});

// Double-click the voice button to toggle PT/EN
document.getElementById('voice-btn').addEventListener('dblclick', (e) => {
    e.preventDefault();
    if (!recognition) return;
    voiceLang = (voiceLang === 'pt-PT') ? 'en-US' : 'pt-PT';
    recognition.lang = voiceLang;
    localStorage.setItem('cy-voice-lang', voiceLang);
    document.getElementById('voice-btn').title = 'Voz (' + voiceLang + ') — duplo-clique alterna idioma';
    showVoiceToast('Idioma do microfone: ' + voiceLang, 'info', 1800);
});

// Reflect current lang in tooltip on load
(function () {
    const btn = document.getElementById('voice-btn');
    if (btn) btn.title = 'Voz (' + voiceLang + ') — duplo-clique alterna idioma';
})();

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
// NOTE: do NOT reset input.value here — resetting before reader.onload fires
// causes browsers to release the File reference before async read completes
// (PDF/large files silently fail). Value is reset inside clearImage() instead.
document.getElementById('image-input').addEventListener('change', fileInputChangeHandler);

document.getElementById('remove-image').addEventListener('click', clearImage);
function clearImage() {
    currentImg      = null;
    currentImgType  = 'image/jpeg';
    currentFile     = null;
    currentFiles    = [];
    document.getElementById('image-preview').style.display = 'none';
    document.getElementById('preview-img').style.display = 'none';
    document.getElementById('file-preview-info').style.display = 'none';
    document.getElementById('image-input').value = '';
}

function updateFilePreviewUI() {
    const previewImgEl  = document.getElementById('preview-img');
    const previewInfoEl = document.getElementById('file-preview-info');
    const wrapEl        = document.getElementById('image-preview');

    if (currentImg) {
        previewImgEl.style.display  = 'block';
        previewInfoEl.style.display = 'none';
        wrapEl.style.display        = 'flex';
        return;
    }
    if (!currentFiles.length) { wrapEl.style.display = 'none'; return; }

    previewImgEl.style.display = 'none';
    if (currentFiles.length === 1) {
        const f = currentFiles[0];
        document.getElementById('file-preview-icon').textContent = getFileIcon(f.name);
        document.getElementById('file-preview-name').textContent = f.name;
        document.getElementById('file-preview-size').textContent = f.size;
    } else {
        const icons = [...new Set(currentFiles.map(f => getFileIcon(f.name)))].join(' ');
        document.getElementById('file-preview-icon').textContent = icons;
        document.getElementById('file-preview-name').textContent = currentFiles.length + ' ficheiros';
        document.getElementById('file-preview-size').textContent = currentFiles.map(f => f.name).join(', ').substring(0, 60) + (currentFiles.map(f=>f.name).join(', ').length > 60 ? '…' : '');
    }
    previewInfoEl.style.display = 'flex';
    wrapEl.style.display        = 'flex';
}

// Max binary file size before warning (nginx default limit ~1MB; base64 = ×1.33)
const MAX_FILE_BYTES = 700 * 1024; // 700 KB → ~930 KB base64 → safe under 1MB nginx limit

function readOneFile(file) {
    return new Promise((resolve) => {
        const ext        = file.name.split('.').pop().toLowerCase();
        const readAsText = ['txt','csv','md','eml','msg'].includes(ext);
        const reader     = new FileReader();

        if (file.type.startsWith('image/')) {
            reader.onload = ev => resolve({
                name: file.name, type: file.type, ext,
                isImage: true,
                b64: ev.target.result.split(',')[1],
                imgSrc: ev.target.result,
                size: humanSize(file.size),
            });
            reader.readAsDataURL(file);
        } else {
            reader.onload = ev => resolve({
                name: file.name,
                type: file.type || 'application/octet-stream',
                ext,
                isImage: false,
                b64:  readAsText ? null : ev.target.result.split(',')[1],
                text: readAsText ? ev.target.result : null,
                size: humanSize(file.size),
            });
            if (readAsText) reader.readAsText(file);
            else            reader.readAsDataURL(file);
        }
    });
}

async function fileInputChangeHandler(e) {
    const files = Array.from(e.target.files);
    if (!files.length) return;

    // Note: large files are sent as-is; server will return error in chat bubble if too big

    // Read all files in parallel
    const read = await Promise.all(files.map(f => readOneFile(f)));

    // If any image selected, use first image (vision mode — single image only)
    const imgFile = read.find(f => f.isImage);
    if (imgFile) {
        currentImg     = imgFile.b64;
        currentImgType = imgFile.type;
        currentFile    = null;
        currentFiles   = read.filter(f => !f.isImage); // keep non-image files too
        document.getElementById('preview-img').src          = imgFile.imgSrc;
        document.getElementById('preview-img').style.display = 'block';
    } else {
        currentImg   = null;
        currentFiles = [...currentFiles, ...read]; // ACCUMULATE (don't replace)
        // First binary file becomes the primary attachment
        currentFile  = currentFiles.find(f => f.b64 !== null) || null;
    }

    updateFilePreviewUI();
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
        const droppedFiles = e.dataTransfer?.files;
        if (droppedFiles?.length) {
            fileInputChangeHandler({ target: { files: droppedFiles } });
        }
    });
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
    document.querySelectorAll('.agent-grid-item').forEach(el => {
        const isTarget = el.dataset.agent === agentName;
        if (isTarget) {
            el.classList.add('active', 'working');
            const statusEl = el.querySelector('.ag-status');
            if (statusEl) statusEl.textContent = 'working…';
        }
        // Don't touch other agents — they may be working too
    });
}

function setAgentStatus(agentName, text) {
    const el = document.querySelector(`.agent-grid-item[data-agent="${agentName}"]`);
    if (!el) return;
    const statusEl = el.querySelector('.ag-status');
    if (statusEl) statusEl.textContent = text;
}

function setAgentDone(agentName) {
    const el = document.querySelector(`.agent-grid-item[data-agent="${agentName}"]`);
    if (!el) return;
    el.classList.remove('working');
    const statusEl = el.querySelector('.ag-status');
    if (statusEl) statusEl.textContent = 'ready';
    // Keep .active if this is still the selected agent
}

// Sidebar agent click → switch agent
document.querySelectorAll('.agent-grid-item').forEach(el => {
    el.addEventListener('click', () => {
        const agent = el.dataset.agent;
        const target = agentSelect.querySelector(`option[value="${agent}"]`) ? agent : 'auto';
        if (agentSelect.value !== target) {
            agentSelect.value = target;
            agentSelect.dispatchEvent(new Event('change')); // triggers full switch logic
        }
    });
});

function clearAgentActive() {
    document.querySelectorAll('.agent-grid-item').forEach(el => {
        el.classList.remove('active', 'working');
        const statusEl = el.querySelector('.ag-status');
        if (statusEl) statusEl.textContent = 'ready';
    });
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

    const agentPhoto = role === 'ai' ? (AGENT_PHOTOS[agentName] || null) : null;

    const avatarHtml = agentPhoto
        ? `<div class="avatar" style="padding:0;overflow:hidden;border:1.5px solid var(--border2)"><img src="${agentPhoto}" alt="${name}" style="width:100%;height:100%;object-fit:cover;border-radius:50%"></div>`
        : `<div class="avatar">${role === 'user' ? emoji.charAt(0).toUpperCase() : emoji}</div>`;

    // Table card (Marco Sales)
    if (role === 'ai' && text.includes('__TABLE__')) {
        const tableMatch = text.match(/__TABLE__(\{[\s\S]*\})/);
        if (tableMatch) {
            try {
                const tableData  = JSON.parse(tableMatch[1]);
                const salesPhoto = AGENT_PHOTOS['sales'];
                const salesAvatar = salesPhoto
                    ? `<div class="avatar" style="padding:0;overflow:hidden;border:1.5px solid var(--border2)"><img src="${salesPhoto}" alt="Marco Sales" style="width:100%;height:100%;object-fit:cover;border-radius:50%"></div>`
                    : `<div class="avatar">${AGENT_EMOJIS['sales']}</div>`;
                const preText = text.split('__TABLE__')[0].trim();
                msg.innerHTML = `
                    ${salesAvatar}
                    <div class="msg-col" style="max-width:700px">
                        <div class="msg-meta">
                            <span class="agent-tag active">💼 Marco Sales</span>
                            <span>análise gerada</span>
                        </div>
                        ${preText ? `<div class="bubble">${markdownToHtml(preText)}</div>` : ''}
                        ${buildTableCard(tableData)}
                    </div>`;
                chat.appendChild(msg);
                chat.scrollTop = chat.scrollHeight;
                return msg;
            } catch(e) { /* fall through to normal render */ }
        }
    }

    // Kyber keys card
    if (role === 'ai' && text.startsWith('__KYBER_KEYS__')) {
        try {
            const kd = JSON.parse(text.replace('__KYBER_KEYS__', ''));
            msg.innerHTML = `<div class="avatar">🔒</div>
                <div class="msg-col" style="max-width:560px">
                    <div class="msg-meta"><span class="agent-tag active">🔒 KYBER Encryption</span><span>par de chaves gerado</span></div>
                    ${buildKyberKeysCard(kd)}
                </div>`;
            chat.appendChild(msg); chat.scrollTop = chat.scrollHeight; return msg;
        } catch(e) {}
    }

    // Kyber encrypted email card
    if (role === 'ai' && text.startsWith('__KYBER_EMAIL__')) {
        try {
            const kd = JSON.parse(text.replace('__KYBER_EMAIL__', ''));
            msg.innerHTML = `<div class="avatar">🔒</div>
                <div class="msg-col" style="max-width:560px">
                    <div class="msg-meta"><span class="agent-tag active">🔒 KYBER Encryption</span><span>email encriptado</span></div>
                    ${buildKyberEmailCard(kd)}
                </div>`;
            chat.appendChild(msg); chat.scrollTop = chat.scrollHeight; return msg;
        } catch(e) {}
    }

    // Kyber compose form card
    if (role === 'ai' && text.startsWith('__KYBER_COMPOSE__')) {
        try {
            const kd = JSON.parse(text.replace('__KYBER_COMPOSE__', ''));
            msg.innerHTML = `<div class="avatar">🔒</div>
                <div class="msg-col" style="max-width:560px">
                    <div class="msg-meta"><span class="agent-tag active">🔒 KYBER Encryption</span><span>compor email encriptado</span></div>
                    ${buildKyberComposeCard(kd)}
                </div>`;
            chat.appendChild(msg); chat.scrollTop = chat.scrollHeight; return msg;
        } catch(e) {}
    }

    // Email card
    if (role === 'ai' && text.startsWith('__EMAIL__')) {
        const emailData = JSON.parse(text.replace('__EMAIL__', ''));
        const emailPhoto = AGENT_PHOTOS['email'];
        const emailAvatar = emailPhoto
            ? `<div class="avatar" style="padding:0;overflow:hidden;border:1.5px solid var(--border2)"><img src="${emailPhoto}" alt="Daniel Email" style="width:100%;height:100%;object-fit:cover;border-radius:50%"></div>`
            : `<div class="avatar">${AGENT_EMOJIS['email']}</div>`;
        msg.innerHTML = `
            ${emailAvatar}
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
    const pdfBtn  = role === 'ai' ? `<button class="pdf-export-btn" onclick="exportMsgPDF(this)" title="Exportar como PDF">📄 PDF</button>` : '';
    msg.innerHTML = `
        ${avatarHtml}
        <div class="msg-col">
            <div class="msg-meta">
                <span>${role === 'user' ? '{{ Auth::user()->name }}' : name}</span>
                ${agentName ? `<span class="agent-tag ${role==='ai'?'active':''}">${AGENT_EMOJIS[agentName]||''} ${AGENT_NAMES[agentName]||agentName}</span>` : ''}
                ${saveBtn}${pdfBtn}
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
    // Switch to the agent that generated this suggestion
    if (agentName && agentSelect.querySelector(`option[value="${agentName}"]`)) {
        agentSelect.value = agentName;
        setAgentActive(agentName);
    }
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
function buildTableCard(data) {
    const id = 'tbl_' + Date.now();
    const headers = data.columns.map(c => `<th>${esc(c)}</th>`).join('');
    const rows = data.rows.map(r => `<tr>${r.map(c => `<td>${esc(String(c))}</td>`).join('')}</tr>`).join('');
    return `
    <div class="table-card" id="${id}">
        <div class="table-card-header">
            <span>📊 ${esc(data.title||'Análise Marco Sales')}</span>
            <small>${data.rows.length} itens</small>
        </div>
        <div class="table-wrap">
            <table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table>
        </div>
        ${data.analysis ? `<div class="table-analysis">🔍 ${esc(data.analysis)}</div>` : ''}
        ${data.recommendation ? `<div class="table-recommendation">✅ ${esc(data.recommendation)}</div>` : ''}
        <div class="table-actions">
            <button class="table-excel-btn" onclick="exportExcel('${id}')">📥 Exportar Excel</button>
            <button class="table-copy-btn" onclick="copyTable('${id}')">📋 Copiar CSV</button>
        </div>
    </div>`;
}

function exportExcel(id) {
    const card = document.getElementById(id);
    const title = card.querySelector('.table-card-header span')?.textContent?.replace('📊 ','') || 'marco_analise';
    const rows  = Array.from(card.querySelectorAll('table tr'));
    const csv   = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => '"' + c.textContent.replace(/"/g,'""') + '"').join(',')).join('\n');
    const bom   = '\uFEFF';
    const blob  = new Blob([bom + csv], {type:'text/csv;charset=utf-8;'});
    const url   = URL.createObjectURL(blob);
    const a     = document.createElement('a');
    a.href      = url;
    a.download  = title.replace(/[^a-zA-Z0-9_\-]/g,'_') + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

function copyTable(id) {
    const card = document.getElementById(id);
    const rows = Array.from(card.querySelectorAll('table tr'));
    const csv  = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => c.textContent).join('\t')).join('\n');
    navigator.clipboard.writeText(csv);
    const btn = card.querySelector('.table-copy-btn');
    btn.textContent = '✅ Copiado!';
    setTimeout(() => btn.textContent = '📋 Copiar CSV', 2000);
}

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
            <button class="email-outlook-btn" onclick="openInOutlook('${id}')" title="Abrir no Outlook">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4v16l10-2.5V6.5L7 4zm2 2.8l6 1.5v7.4l-6 1.5V6.8zM2 7v10l4 1V6L2 7z"/></svg>
                Outlook
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

// ═══════════════════════════════
//  KYBER CARDS
// ═══════════════════════════════

function buildKyberKeysCard(data) {
    const id  = 'kk_' + Date.now();
    const pk  = data.public_key  || '';
    const sk  = data.secret_key  || '';
    return `
    <div class="kyber-card" id="${id}">
        <div class="kyber-card-header">
            <span class="kh-title">🔒 Par de Chaves Kyber-1024 Gerado</span>
            <span class="kh-sub">NIST FIPS 203 · AES-256-GCM</span>
        </div>

        <div class="kyber-field">
            <label>Public Key</label>
            <span class="kv" id="${id}_pk" title="${esc(pk)}">${esc(pk.substring(0,56))}…</span>
            <button class="kyber-key-copy" onclick="kyberCopyKey('${id}_pk','${esc(pk)}',this)">📋 Copiar</button>
        </div>

        <div class="kyber-warning">
            ⚠️ <strong>Guarda o Secret Key agora — não será armazenado no servidor.</strong>
            Partilha-o com o destinatário via SMS ou WhatsApp após enviar o email.
        </div>
        <div class="kyber-field">
            <label style="color:#ffaa00">Secret Key</label>
            <span class="kv secret" id="${id}_sk" title="${esc(sk)}">${esc(sk.substring(0,56))}…</span>
            <button class="kyber-key-copy" onclick="kyberCopyKey('${id}_sk','${esc(sk)}',this)" style="border-color:#ffaa0055;color:#ffaa00;">📋 Copiar Secret Key</button>
        </div>

        <div class="kyber-actions">
            <button class="kyber-store-btn" onclick="kyberStorePublicKey('${id}','${esc(pk)}',this)">
                ☁️ Registar Chave Pública no Servidor
            </button>
        </div>
        <div class="kyber-status" id="${id}_status"></div>
    </div>`;
}

function buildKyberEmailCard(data) {
    const id      = 'ke_' + Date.now();
    const pkgStr  = JSON.stringify(data.package || {});
    const appUrl  = 'https://clawyard.partyard.eu';
    const hash    = btoa(pkgStr);
    const decryptUrl = appUrl + '/decrypt#' + hash;
    const htmlB64 = btoa(unescape(encodeURIComponent(data.html || '')));
    return `
    <div class="kyber-card" id="${id}">
        <div class="kyber-card-header">
            <span class="kh-title">🔒 Email Encriptado Pronto</span>
            <span class="kh-sub">Kyber-1024 + AES-256-GCM</span>
        </div>
        <div class="kyber-field">
            <label>Para</label>
            <span>${esc(data.to || '')}</span>
        </div>
        <div class="kyber-field">
            <label>Assunto</label>
            <span>[Encrypted] ${esc(data.subject || '')}</span>
        </div>
        <div class="kyber-field" style="background:#0a1800;">
            <label style="color:#76b900">Payload</label>
            <span style="color:#76b900;font-size:11px;">🔒 Mensagem encriptada com Kyber-1024 + AES-256-GCM</span>
        </div>
        <div class="kyber-actions">
            <button class="kyber-send-btn" id="${id}_sendbtn"
                onclick="kyberSendEmail('${id}','${esc(data.to)}','${esc(data.subject)}',this)"
                data-html="${htmlB64}"
                data-decrypt-url="${esc(decryptUrl)}">
                📤 Enviar via ClawYard
            </button>
            <button class="kyber-copy-btn2" onclick="kyberCopyDecryptLink('${hash}',this)">
                🔗 Copiar link /decrypt
            </button>
            <button class="kyber-copy-btn2" onclick="kyberCopyJson('${esc(pkgStr)}',this)">
                📋 Copiar JSON (Outlook)
            </button>
        </div>
        <div class="kyber-status" id="${id}_status"></div>
    </div>`;
}

function kyberCopyKey(elId, value, btn) {
    navigator.clipboard.writeText(value).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✅ Copiado!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

function kyberCopyFromEl(elId, btn, origLabel) {
    const value = document.getElementById(elId)?.textContent || '';
    navigator.clipboard.writeText(value).then(() => {
        const orig = origLabel || btn.textContent;
        btn.textContent = '✅ Copiado!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

async function kyberStorePublicKey(id, publicKey, btn) {
    btn.disabled = true;
    btn.textContent = '⏳ A registar...';
    const statusEl = document.getElementById(id + '_status');
    try {
        const r = await fetch('/api/keys/store', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ public_key: publicKey })
        });
        const d = await r.json();
        if (d.success) {
            btn.textContent = '✅ Chave registada!';
            statusEl.className = 'kyber-status ok';
            statusEl.textContent = '✅ Chave pública registada no servidor. Outros podem agora enviar-te emails encriptados.';
        } else {
            btn.disabled = false;
            btn.textContent = '☁️ Registar Chave Pública';
            statusEl.className = 'kyber-status err';
            statusEl.textContent = '❌ ' + (d.error || 'Erro ao registar');
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = '☁️ Registar Chave Pública';
        statusEl.className = 'kyber-status err';
        statusEl.textContent = '❌ Erro de ligação: ' + e.message;
    }
}

async function kyberSendEmail(id, to, subject, btn) {
    btn.disabled = true;
    btn.textContent = '⏳ A enviar...';
    const statusEl = document.getElementById(id + '_status');
    const htmlB64  = btn.dataset.html;
    let rawHtml;
    try { rawHtml = decodeURIComponent(escape(atob(htmlB64))); }
    catch(e) { rawHtml = atob(htmlB64); }

    try {
        const r = await fetch('/api/email/send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ to, subject, body: '', raw_html: rawHtml })
        });
        const d = await r.json();
        if (d.success) {
            btn.textContent = '✅ Email enviado!';
            statusEl.className = 'kyber-status ok';
            const decryptUrl = btn.dataset.decryptUrl || '';
            let statusHtml = '✅ Email encriptado (Kyber-1024) enviado para ' + to;
            if (decryptUrl) {
                statusHtml += `<br><br>
                <div style="background:#0a1800;border:1px solid #1a3a00;border-radius:6px;padding:10px 12px;margin-top:4px;">
                    <div style="font-size:11px;color:#76b900;font-weight:700;margin-bottom:6px;">🔗 Link de desencriptação — partilha com o destinatário via SMS/WhatsApp</div>
                    <div style="font-size:10px;color:#888;word-break:break-all;margin-bottom:8px;font-family:monospace;">${decryptUrl}</div>
                    <button onclick="navigator.clipboard.writeText('${decryptUrl.replace(/'/g,"\\'")}').then(()=>{this.textContent='✅ Copiado!';setTimeout(()=>this.textContent='📋 Copiar link',2000)})"
                        style="background:none;border:1px solid #76b90055;color:#76b900;padding:5px 14px;border-radius:6px;font-size:11px;cursor:pointer;">
                        📋 Copiar link
                    </button>
                </div>`;
            }
            statusEl.innerHTML = statusHtml;
        } else {
            btn.disabled = false;
            btn.textContent = '📤 Enviar via ClawYard';
            statusEl.className = 'kyber-status err';
            statusEl.textContent = '❌ ' + (d.error || 'Erro ao enviar');
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = '📤 Enviar via ClawYard';
        statusEl.className = 'kyber-status err';
        statusEl.textContent = '❌ Erro de ligação: ' + e.message;
    }
}

function kyberCopyDecryptLink(hash, btn) {
    const url = 'https://clawyard.partyard.eu/decrypt#' + hash;
    navigator.clipboard.writeText(url).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✅ Link copiado!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

function kyberCopyJson(json, btn) {
    navigator.clipboard.writeText(json).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✅ JSON copiado!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

function buildKyberComposeCard(data) {
    const id = 'kc_' + Date.now();
    const to = data.to || '';
    // MOBILE: iOS Safari returns empty innerText for <div contenteditable>
    // sporadically (autocorrect + magnifier race). Use <textarea> for 100%
    // reliability. Also make the card fluid to the viewport width.
    return `
    <div class="kyber-card" id="${id}" style="max-width:min(540px,100%);width:100%;box-sizing:border-box;">
        <div class="kyber-card-header">
            <span class="kh-title">🔒 Compor Email Encriptado</span>
            <span class="kh-sub">Kyber-1024 + AES-256-GCM · NIST FIPS 203</span>
        </div>
        <div class="email-field" style="margin-bottom:8px;">
            <label style="color:#aaa;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px;">Para</label>
            <input type="email" id="${id}_to" value="${esc(to)}" placeholder="destinatario@empresa.com"
                autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="email"
                style="width:100%;background:#1a1a2e;border:1px solid #333;border-radius:6px;padding:10px 12px;color:#e0e0e0;font-size:16px;outline:none;box-sizing:border-box;"
                onfocus="this.style.borderColor='#76b900'" onblur="this.style.borderColor='#333'">
        </div>
        <div class="email-field" style="margin-bottom:8px;">
            <label style="color:#aaa;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px;">Assunto</label>
            <input type="text" id="${id}_subject" placeholder="Assunto da mensagem"
                style="width:100%;background:#1a1a2e;border:1px solid #333;border-radius:6px;padding:10px 12px;color:#e0e0e0;font-size:16px;outline:none;box-sizing:border-box;"
                onfocus="this.style.borderColor='#76b900'" onblur="this.style.borderColor='#333'">
        </div>
        <div style="margin-bottom:12px;">
            <label style="color:#aaa;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px;">Mensagem</label>
            <textarea id="${id}_body" rows="6" placeholder="Escreve aqui a tua mensagem..."
                style="width:100%;min-height:120px;background:#1a1a2e;border:1px solid #333;border-radius:6px;padding:10px 12px;color:#e0e0e0;font-size:16px;outline:none;box-sizing:border-box;line-height:1.5;resize:vertical;font-family:inherit;"
                onfocus="this.style.borderColor='#76b900'" onblur="this.style.borderColor='#333'"></textarea>
        </div>
        <div style="margin-bottom:12px;">
            <label style="color:#aaa;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px;">Anexos <span style="color:#555;font-weight:400;text-transform:none">(opcional · máx 5 ficheiros · 20 MB cada)</span></label>
            <label style="display:inline-flex;align-items:center;gap:6px;background:#1a1a2e;border:1px dashed #444;border-radius:6px;padding:8px 14px;cursor:pointer;font-size:12px;color:#888;transition:border-color .15s;"
                onmouseover="this.style.borderColor='#76b900';this.style.color='#76b900'" onmouseout="this.style.borderColor='#444';this.style.color='#888'">
                📎 Seleccionar ficheiros
                <input type="file" id="${id}_files" multiple accept="*/*" style="display:none"
                    onchange="kyberUpdateFileList('${id}')">
            </label>
            <div id="${id}_filelist" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;"></div>
        </div>
        <div style="background:#0a1800;border:1px solid #1a3a00;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:11px;color:#76b900;">
            🔒 Mensagem e anexos encriptados com Kyber-1024 antes do envio. Só o destinatário com o secret key poderá ler.
        </div>
        <div class="kyber-actions">
            <button class="kyber-send-btn" id="${id}_sendbtn" onclick="kyberSendCompose('${id}',this)">
                🔒 Encriptar &amp; Enviar
            </button>
        </div>
        <div class="kyber-status" id="${id}_status"></div>
    </div>`;
}

function kyberUpdateFileList(id) {
    const input   = document.getElementById(id + '_files');
    const listEl  = document.getElementById(id + '_filelist');
    listEl.innerHTML = '';
    Array.from(input.files).forEach((f, i) => {
        const tag = document.createElement('span');
        tag.style.cssText = 'background:#1a1a2e;border:1px solid #333;border-radius:4px;padding:3px 8px;font-size:11px;color:#aaa;display:inline-flex;align-items:center;gap:4px;';
        tag.innerHTML = `📎 ${esc(f.name)} <button onclick="kyberRemoveFile('${id}',${i},this.parentElement)" style="background:none;border:none;color:#ff4444;cursor:pointer;font-size:12px;padding:0 2px;">✕</button>`;
        listEl.appendChild(tag);
    });
}

function kyberRemoveFile(id, idx, tagEl) {
    const input = document.getElementById(id + '_files');
    const dt = new DataTransfer();
    Array.from(input.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
    input.files = dt.files;
    kyberUpdateFileList(id);
}

async function kyberSendCompose(id, btn) {
    // MOBILE: read from the textarea (switched away from contenteditable
    // because iOS Safari returned empty innerText sporadically). Fallback
    // to innerText for backwards compatibility with any stale card.
    const bodyEl  = document.getElementById(id + '_body');
    const to      = document.getElementById(id + '_to')?.value.trim() || '';
    const subject = document.getElementById(id + '_subject')?.value.trim() || '';
    const body    = (bodyEl?.value ?? bodyEl?.innerText ?? '').trim();
    const statusEl = document.getElementById(id + '_status');

    // Blur the current field so the virtual keyboard doesn't steal focus
    // from the button tap on iOS (common cause of "nothing happens").
    if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
    }

    if (!to)      { statusEl.className = 'kyber-status err'; statusEl.textContent = '❌ Preenche o destinatário.'; return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
        statusEl.className = 'kyber-status err'; statusEl.textContent = '❌ Email do destinatário inválido.'; return;
    }
    if (!subject) { statusEl.className = 'kyber-status err'; statusEl.textContent = '❌ Preenche o assunto.'; return; }
    if (!body)    { statusEl.className = 'kyber-status err'; statusEl.textContent = '❌ Escreve a mensagem.'; return; }

    btn.disabled = true;
    btn.textContent = '⏳ A encriptar e enviar...';
    statusEl.className = '';
    statusEl.textContent = '';

    // Use FormData to support file uploads
    const fd = new FormData();
    fd.append('to', to);
    fd.append('subject', subject);
    fd.append('body', body);
    fd.append('generate_key', '1');
    const files = document.getElementById(id + '_files')?.files || [];
    Array.from(files).forEach(f => fd.append('attachments[]', f));

    try {
        const r = await fetch('/api/email/send', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: fd,
            credentials: 'same-origin',   // ensure Safari iOS sends the session cookie
        });
        const rawText = await r.text();
        console.log('[KyberCompose] HTTP', r.status, rawText.substring(0, 500));
        let d;
        try { d = JSON.parse(rawText); }
        catch(parseErr) {
            const card = document.getElementById(id);
            card.innerHTML = `<div style="padding:16px;color:#ff4444;font-size:13px;">❌ Resposta inválida do servidor (HTTP ${r.status}).<br><pre style="font-size:10px;margin-top:8px;color:#aaa;white-space:pre-wrap;word-break:break-all">${rawText.substring(0,300)}</pre></div>`;
            return;
        }
        if (d.success) {
            // Replace entire card with result view
            const card = document.getElementById(id);
            const sk   = d.secret_key || '';
            const url  = d.decrypt_url || '';
            // Store values in hidden spans so onclick can read them safely
            const skId  = id + '_sk_val';
            const urlId = id + '_url_val';
            card.innerHTML = `
                <div class="kyber-card-header">
                    <span class="kh-title">✅ Email Encriptado Enviado</span>
                    <span class="kh-sub">Kyber-1024 + AES-256-GCM · para ${esc(to)}</span>
                </div>
                <span id="${skId}" style="display:none">${esc(sk)}</span>
                <span id="${urlId}" style="display:none">${esc(url)}</span>
                ${sk ? `
                <div style="padding:14px 16px;border-bottom:1px solid #2a1a00;background:#120d00;">
                    <div style="font-size:11px;color:#ffaa00;font-weight:700;margin-bottom:8px;">⚠️ Secret Key — partilha com o destinatário via SMS/WhatsApp</div>
                    <div style="font-family:monospace;font-size:10px;color:#ccc;word-break:break-all;background:#0a0800;border:1px solid #3a2a00;border-radius:4px;padding:8px;margin-bottom:8px;max-height:60px;overflow:hidden;">${esc(sk.substring(0,120))}…</div>
                    <button onclick="kyberCopyFromEl('${skId}',this,'📋 Copiar Secret Key')"
                        style="background:none;border:1px solid #ffaa0066;color:#ffaa00;padding:6px 16px;border-radius:6px;font-size:12px;cursor:pointer;font-weight:600;">
                        📋 Copiar Secret Key
                    </button>
                </div>` : ''}
                ${url ? `
                <div style="padding:14px 16px;background:#050f00;">
                    <div style="font-size:11px;color:#76b900;font-weight:700;margin-bottom:8px;">🔗 Link de desencriptação — envia ao destinatário junto com o Secret Key</div>
                    <div style="font-family:monospace;font-size:10px;color:#888;word-break:break-all;background:#030a00;border:1px solid #1a3a00;border-radius:4px;padding:8px;margin-bottom:8px;">${esc(url)}</div>
                    <button onclick="kyberCopyFromEl('${urlId}',this,'📋 Copiar link /decrypt')"
                        style="background:none;border:1px solid #76b90066;color:#76b900;padding:6px 16px;border-radius:6px;font-size:12px;cursor:pointer;font-weight:600;">
                        📋 Copiar link /decrypt
                    </button>
                </div>` : ''}
                ${!sk && !url ? '<div style="padding:14px 16px;color:#76b900;font-size:13px;">✅ Email enviado com sucesso.</div>' : ''}
            `;
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            const card = document.getElementById(id);
            card.innerHTML = `<div style="padding:16px;">
                <div style="color:#ff4444;font-size:13px;font-weight:600;margin-bottom:8px;">❌ Erro ao enviar</div>
                <div style="color:#aaa;font-size:12px;">${esc(d.error || 'Erro desconhecido')}</div>
                <button onclick="location.reload()" style="margin-top:12px;background:none;border:1px solid #555;color:#aaa;padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;">Tentar de novo</button>
            </div>`;
        }
    } catch(e) {
        console.error('[KyberCompose] catch:', e);
        const card = document.getElementById(id);
        if (card) {
            card.innerHTML = `<div style="padding:16px;">
                <div style="color:#ff4444;font-size:13px;font-weight:600;margin-bottom:8px;">❌ Erro de ligação</div>
                <div style="color:#aaa;font-size:12px;">${esc(e.message)}</div>
            </div>`;
        }
    }
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

    window.location.href = mailto;
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
// ── Gerar PDF da conversa atual ───────────────────────────────────────────
async function createAgentPdf() {
    const btn       = document.getElementById('finance-pdf-btn');
    const agent     = agentSelect.value || 'auto';
    const agentName = AGENT_NAMES[agent] || 'ClawYard';

    // Collect all AI messages in the current conversation
    const bubbles = document.querySelectorAll('.message.ai .bubble');
    if (!bubbles.length) {
        alert('Sem respostas do ' + agentName + ' para gerar relatório.');
        return;
    }

    const fullText = Array.from(bubbles).map(b => b.innerText || b.textContent).join('\n\n---\n\n');
    const date     = new Date().toLocaleDateString('pt-PT', { day:'2-digit', month:'long', year:'numeric' });
    const title    = agentName + ' — Relatório ' + date;

    const origHTML = btn.innerHTML;
    btn.innerHTML  = '⏳';
    btn.disabled   = true;

    try {
        const res = await fetch('/api/reports', {
            method:  'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': CSRF },
            body:    JSON.stringify({ title, type: agent, content: fullText }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.success && data.report?.id) {
            btn.innerHTML = '✅';
            logActivity('📄', 'Relatório PDF criado — ' + agentName, 'done');
            setTimeout(() => window.open('/reports/' + data.report.id + '/pdf', '_blank'), 300);
        } else {
            throw new Error('save failed');
        }
    } catch(e) {
        btn.innerHTML = '❌';
        console.error('Agent PDF error:', e);
    } finally {
        setTimeout(() => { btn.innerHTML = origHTML; btn.disabled = false; }, 2500);
    }
}

function exportMsgPDF(btn) {
    const msgCol   = btn.closest('.msg-col');
    const bubble   = msgCol.querySelector('.bubble');
    const metaSpan = msgCol.querySelector('.msg-meta span');
    const agentTag = msgCol.querySelector('.agent-tag');
    const agentLabel = agentTag ? agentTag.textContent.trim() : 'ClawYard';
    const date     = new Date().toLocaleString('pt-PT', {day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const html     = bubble.innerHTML;

    const win = window.open('', '_blank', 'width=860,height=900');
    win.document.write(`<!DOCTYPE html>
<html lang="pt"><head>
<meta charset="UTF-8">
<title>${agentLabel} — ${date}</title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; margin: 40px; color: #1a1a1a; font-size: 13.5px; line-height: 1.65; }
  h1 { font-size: 16px; color: #1a1a1a; margin-bottom: 4px; border-bottom: 2px solid #76b900; padding-bottom: 8px; }
  .meta { font-size: 11px; color: #666; margin-bottom: 20px; }
  strong { font-weight: 700; }
  em { color: #555; }
  code { background: #f4f4f4; border: 1px solid #ddd; border-radius: 3px; padding: 1px 5px; font-family: 'Courier New', monospace; font-size: 12px; }
  pre { background: #f6f6f6; border: 1px solid #ddd; border-radius: 6px; padding: 12px; overflow-x: auto; }
  pre code { background: none; border: none; padding: 0; }
  table { border-collapse: collapse; width: 100%; font-size: 12px; margin: 10px 0; }
  th { background: #f0f0f0; font-weight: 700; padding: 7px 10px; border: 1px solid #ccc; text-align: left; }
  td { padding: 6px 10px; border: 1px solid #ddd; }
  tr:nth-child(even) td { background: #fafafa; }
  h2 { font-size: 14px; margin: 14px 0 5px; color: #333; }
  h3 { font-size: 13px; margin: 10px 0 4px; color: #555; }
  ul, ol { padding-left: 20px; }
  li { margin: 2px 0; }
  hr { border: none; border-top: 1px solid #ddd; margin: 12px 0; }
  .footer { margin-top: 32px; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
  @media print { body { margin: 20px; } .no-print { display: none; } }
</style>
</head><body>
<h1>${agentLabel}</h1>
<div class="meta">Gerado em ${date} · ClawYard / HP-Group</div>
${html}
<div class="footer">ClawYard AI Platform · HP-Group · Documento gerado automaticamente</div>
<div class="no-print" style="margin-top:20px;">
  <button onclick="window.print()" style="background:#76b900;color:#000;border:none;padding:10px 24px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">🖨️ Imprimir / Guardar PDF</button>
  <button onclick="window.close()" style="background:#eee;color:#333;border:none;padding:10px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-left:8px;">✕ Fechar</button>
</div>
</body></html>`);
    win.document.close();
    win.focus();
}

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
    const hasAttachment = !!(currentImg || currentFile || currentFiles.length);
    if (!text && !hasAttachment) return;
    if (sendBtn.disabled) return;

    // Default prompt when only a file is attached (no text typed)
    if (!text && hasAttachment) {
        const ext = currentFile?.ext || '';
        if (['pdf'].includes(ext))                              text = 'Analisa este documento PDF.';
        else if (['xlsx','xls','csv'].includes(ext))           text = 'Analisa este ficheiro Excel/CSV.';
        else if (['doc','docx'].includes(ext))                  text = 'Analisa este documento Word.';
        else if (currentImg)                                    text = 'O que vês nesta imagem?';
        else if (currentFiles.length > 1)                      text = `Analisa estes ${currentFiles.length} ficheiros.`;
        else                                                    text = 'Analisa este ficheiro.';
    }

    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;
    isStreaming       = true;

    const selectedAgent = agentSelect.value;
    document.getElementById('empty-state')?.remove();

    // ── User message ──
    addMessage('user', text);

    // ── Activity log ──
    logActivity('📨', 'Mensagem recebida: "' + text.substring(0,50) + (text.length>50?'…':'') + '"', 'done');
    const stepRAG   = logActivity('📚', 'A consultar base de conhecimento (RAG)…');
    const agentEmoji = AGENT_EMOJIS[selectedAgent] || '🤖';
    const stepAgent = logActivity(agentEmoji, (AGENT_NAMES[selectedAgent]||selectedAgent) + ' a processar…');
    setAgentActive(selectedAgent);
    modelBadge.textContent = '⏳ ' + (AGENT_NAMES[selectedAgent]||selectedAgent);

    const typing = addTyping(selectedAgent);

    const payload = { message: text, agent: selectedAgent, session_id: SESSION_ID };

    if (currentImg) {
        payload.image      = currentImg;
        payload.image_type = currentImgType;
        logActivity('🖼️', 'Imagem incluída (multimodal)', 'done');
    }

    // Embed all text files (TXT, CSV, MD, EML) into the message body
    const textFiles   = currentFiles.filter(f => f.text !== null);
    const binaryFiles = currentFiles.filter(f => f.b64  !== null);

    if (textFiles.length) {
        textFiles.forEach(f => {
            payload.message += `\n\n---\n**Ficheiro: ${f.name}**\n\`\`\`\n${f.text.substring(0, 12000)}\n\`\`\``;
        });
        logActivity('📎', `${textFiles.length} ficheiro(s) de texto incluídos`, 'done');
    }

    // Binary files (PDF/Excel/Word): use FormData to send ALL files when no image present
    let requestBody, requestHeaders;

    if (!currentImg && binaryFiles.length > 0) {
        // Use FormData to send ALL binary files
        const fd = new FormData();
        fd.append('message',    payload.message);
        fd.append('agent',      selectedAgent);
        fd.append('session_id', SESSION_ID);
        // Append each binary file
        binaryFiles.forEach(f => {
            const bytes = atob(f.b64);
            const arr   = new Uint8Array(bytes.length);
            for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
            fd.append('files[]', new Blob([arr], { type: f.type }), f.name);
        });
        requestBody    = fd;
        requestHeaders = { 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': CSRF };
        logActivity('📎', `${binaryFiles.length} ficheiro(s) incluídos`, 'done');
    } else {
        // JSON for text-only or image (payload.image already set above if present)
        requestBody    = JSON.stringify(payload);
        requestHeaders = { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': CSRF };
    }

    clearImage();

    resolveStep(stepRAG);

    // State accumulated across SSE events
    let metaData     = null;   // from the first 'meta' event
    let accumulated  = '';     // full reply text built chunk by chunk
    let streamMsg    = null;   // the AI message DOM element
    let streamBubble = null;   // the bubble div inside that message
    let agentKey     = selectedAgent; // resolved agent (may change after meta)

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
            sendBtn.disabled = false; isStreaming = false; clearAgentActive(); modelBadge.textContent = 'pronto'; input.focus();
            return;
        }

        const reader  = res.body.getReader();
        const decoder = new TextDecoder();
        let   lineBuf = '';

        // Process one SSE line
        function handleLine(line) {
            line = line.trim();
            // Heartbeat comment lines carry agent status: ": heartbeat searching docs"
            if (line.startsWith(': heartbeat')) {
                const status = line.slice(11).trim();
                if (status && agentKey) setAgentStatus(agentKey, status);
                return;
            }
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

                agentKey = evt.agent || selectedAgent;

                // Apply agent color and mark correct grid item active
                applyAgentColor(agentKey);
                if (agentKey !== selectedAgent) setAgentActive(agentKey);

                // Log agent actions
                if (evt.agent_log) {
                    evt.agent_log.forEach(l => logActivity(l.icon, l.text, 'done'));
                }

                // Show agent name only — never the raw model identifier
                modelBadge.textContent = AGENT_NAMES[agentKey] || AGENT_NAMES[evt.agent] || 'PartYard AI';

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

            // ── Smart suggestions event (arrives after response is complete) ──
            if (evt.type === 'suggestions') {
                if (evt.suggestions && evt.suggestions.length) {
                    addSuggestions(evt.suggestions, evt.agent || agentKey);
                }
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

                // ── Kyber action payloads — render card immediately, don't show raw JSON ──
                if (accumulated.startsWith('__KYBER_KEYS__')) {
                    try {
                        const kd = JSON.parse(accumulated.replace('__KYBER_KEYS__', ''));
                        const msgCol = streamMsg.querySelector('.msg-col');
                        msgCol.innerHTML = `
                            <div class="msg-meta">
                                <span class="agent-tag active">🔒 KYBER Encryption</span>
                                <span>par de chaves gerado</span>
                            </div>
                            ${buildKyberKeysCard(kd)}`;
                        streamMsg.querySelector('.avatar').textContent = '🔒';
                    } catch(e) {
                        // JSON still arriving — show spinner while waiting
                        streamBubble.innerHTML = '<span style="color:var(--green)">🔒 A gerar chaves Kyber-1024…</span><span class="stream-cursor">▌</span>';
                    }
                    chat.scrollTop = chat.scrollHeight;
                    return;
                }
                if (accumulated.startsWith('__KYBER_EMAIL__')) {
                    try {
                        const kd = JSON.parse(accumulated.replace('__KYBER_EMAIL__', ''));
                        const msgCol = streamMsg.querySelector('.msg-col');
                        msgCol.innerHTML = `
                            <div class="msg-meta">
                                <span class="agent-tag active">🔒 KYBER Encryption</span>
                                <span>email encriptado</span>
                            </div>
                            ${buildKyberEmailCard(kd)}`;
                        streamMsg.querySelector('.avatar').textContent = '🔒';
                    } catch(e) {
                        streamBubble.innerHTML = '<span style="color:var(--green)">🔒 A encriptar email…</span><span class="stream-cursor">▌</span>';
                    }
                    chat.scrollTop = chat.scrollHeight;
                    return;
                }
                if (accumulated.includes('__KYBER_COMPOSE__')) {
                    try {
                        const kd = JSON.parse(accumulated.substring(accumulated.indexOf('__KYBER_COMPOSE__') + '__KYBER_COMPOSE__'.length) || '{}');
                        const msgCol = streamMsg.querySelector('.msg-col');
                        msgCol.innerHTML = `
                            <div class="msg-meta">
                                <span class="agent-tag active">🔒 KYBER Encryption</span>
                                <span>compor email encriptado</span>
                            </div>
                            ${buildKyberComposeCard(kd)}`;
                        streamMsg.querySelector('.avatar').textContent = '🔒';
                    } catch(e) {
                        streamBubble.innerHTML = '<span style="color:var(--green)">🔒 A preparar formulário…</span><span class="stream-cursor">▌</span>';
                    }
                    chat.scrollTop = chat.scrollHeight;
                    return;
                }

                // Strip hidden DISCOVERIES_JSON block before rendering
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
            if (done) {
                // Stream closed by server without [DONE] (timeout / Cloudflare cut)
                // Clean up any leftover typing indicator or empty bubble
                if (typing.parentNode) typing.remove();
                if (streamMsg && streamBubble && !accumulated.trim()) {
                    // Empty response — replace bubble with timeout error
                    streamBubble.innerHTML = '<span style="color:#ff6b6b;font-size:12px">⏱️ O agente SAP B1 está a demorar mais que o esperado. Tenta novamente — os dados SAP podem estar a carregar.</span>';
                }
                break;
            }

            lineBuf += decoder.decode(value, { stream: true });

            let nlPos;
            while ((nlPos = lineBuf.indexOf('\n')) !== -1) {
                const line = lineBuf.slice(0, nlPos);
                lineBuf    = lineBuf.slice(nlPos + 1);

                if (line.trim() === 'data: [DONE]') {
                    // Streaming complete — finalise the message
                    const agentKey = metaData?.agent || selectedAgent;

                    if (streamMsg && streamBubble) {
                        // ── Kyber key-pair card ──────────────────────────────
                        if (accumulated.startsWith('__KYBER_KEYS__')) {
                            try {
                                const kd = JSON.parse(accumulated.replace('__KYBER_KEYS__', ''));
                                const msgCol = streamMsg.querySelector('.msg-col');
                                msgCol.innerHTML = `
                                    <div class="msg-meta">
                                        <span class="agent-tag active">🔒 KYBER Encryption</span>
                                        <span>par de chaves gerado</span>
                                    </div>
                                    ${buildKyberKeysCard(kd)}`;
                                streamMsg.querySelector('.avatar').textContent = '🔒';
                            } catch(e) {
                                streamBubble.innerHTML = renderMarkdown('Erro ao gerar chaves: ' + e.message);
                            }
                        // ── Kyber encrypted email card ───────────────────────
                        } else if (accumulated.startsWith('__KYBER_EMAIL__')) {
                            try {
                                const kd = JSON.parse(accumulated.replace('__KYBER_EMAIL__', ''));
                                const msgCol = streamMsg.querySelector('.msg-col');
                                msgCol.innerHTML = `
                                    <div class="msg-meta">
                                        <span class="agent-tag active">🔒 KYBER Encryption</span>
                                        <span>email encriptado</span>
                                    </div>
                                    ${buildKyberEmailCard(kd)}`;
                                streamMsg.querySelector('.avatar').textContent = '🔒';
                            } catch(e) {
                                streamBubble.innerHTML = renderMarkdown('Erro ao encriptar: ' + e.message);
                            }
                        // ── Kyber compose form card ──────────────────────────
                        } else if (accumulated.includes('__KYBER_COMPOSE__')) {
                            try {
                                const kd = JSON.parse(accumulated.substring(accumulated.indexOf('__KYBER_COMPOSE__') + '__KYBER_COMPOSE__'.length) || '{}');
                                const msgCol = streamMsg.querySelector('.msg-col');
                                msgCol.innerHTML = `
                                    <div class="msg-meta">
                                        <span class="agent-tag active">🔒 KYBER Encryption</span>
                                        <span>compor email encriptado</span>
                                    </div>
                                    ${buildKyberComposeCard(kd)}`;
                                streamMsg.querySelector('.avatar').textContent = '🔒';
                            } catch(e) {
                                streamBubble.innerHTML = renderMarkdown('Erro: ' + e.message);
                            }
                        // ── Daniel Email card ────────────────────────────────
                        } else if (accumulated.startsWith('__EMAIL__')) {
                            try {
                                const emailData = JSON.parse(accumulated.replace('__EMAIL__', ''));
                                const msgCol = streamMsg.querySelector('.msg-col');
                                msgCol.innerHTML = `
                                    <div class="msg-meta">
                                        <span class="agent-tag active">📧 Daniel Email</span>
                                        <span>email gerado</span>
                                    </div>
                                    ${buildEmailCard(emailData)}`;
                                streamMsg.querySelector('.avatar').textContent = AGENT_EMOJIS['email'];
                            } catch (e) {
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

                    // Suggestions arrive via their own SSE event (type:'suggestions')
                    // after the response is complete — handled in handleLine above.

                    logActivity('✅', 'Resposta pronta', 'done');
                    setAgentDone(agentKey);
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
        isStreaming       = false;
        clearAgentActive();
        modelBadge.textContent = 'pronto';
        input.focus();
    }
}

// ═══════════════════════════════════════════════════════
//  HISTORY RESTORE
// ═══════════════════════════════════════════════════════
async function restoreHistory(agent) {
    const sid = getSessionId(agent);
    try {
        const r    = await fetch(`/api/history/${sid}`);
        const data = await r.json();
        const allMsgs = data.messages || [];
        if (!allMsgs.length) return;

        // Only restore the last 5 messages — keeps the chat clean on re-entry
        const msgs = allMsgs.slice(-5);

        // Remove empty state
        document.getElementById('empty-state')?.remove();

        // Show a subtle "older messages exist" hint if history was trimmed
        if (allMsgs.length > 5) {
            const hint = document.createElement('div');
            hint.style.cssText = 'text-align:center;padding:8px 0 4px;font-size:11px;color:#444;';
            hint.innerHTML = `<a href="/conversations" style="color:#555;text-decoration:none;border:1px solid #222;padding:3px 12px;border-radius:20px;font-size:11px;" title="Ver conversa completa">↑ ${allMsgs.length - 5} mensagens anteriores — <span style="color:#76b900">ver histórico completo</span></a>`;
            document.getElementById('chat').appendChild(hint);
        }

        for (const m of msgs) {
            const role     = m.role === 'user' ? 'user' : 'ai';
            const agentKey = m.agent || agent;
            addMessage(role, m.content, agentKey);
        }

        // Log restoration
        const step = logActivity('📂', 'Histórico restaurado — ' + msgs.length + ' mensagens');
        setTimeout(() => resolveStep(step), 1500);
    } catch(e) {
        // silently ignore — no history or network error
    }
}

// Clear current agent's history
async function clearHistory() {
    if (isStreaming) return;
    const sid = SESSION_ID;
    if (!confirm('Limpar o histórico desta conversa?')) return;
    try {
        await fetch(`/api/history/${sid}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json' }
        });
    } catch(e) { /* ignore */ }
    // Clear chat area
    const agent = agentSelect.value;
    document.getElementById('chat').innerHTML = '';
    document.getElementById('chat').insertAdjacentHTML('beforeend',
        '<div class="empty-state" id="empty-state"><div class="empty-state-hero">' +
        '<div style="display:flex;flex-direction:column;align-items:center;gap:10px">' +
        '<div class="empty-state-avatar" id="empty-avatar">🤖</div>' +
        '</div>' +
        '<h2 id="empty-title">ClawYard <span>AI</span></h2>' +
        '<p id="empty-desc"></p></div>' +
        '<div class="starter-chips" id="starter-chips"></div></div>');
    updateEmptyState(agent);
    renderStarterChips(agent);
    // Clear activity log — reset to initial state
    if (activityLog) {
        activityLog.innerHTML =
            '<div class="activity-step done"><span class="step-icon">✅</span><span class="step-text">Nova conversa iniciada.</span></div>';
    }
}

// Warn before leaving if agent is streaming
window.addEventListener('beforeunload', (e) => {
    if (isStreaming) {
        e.preventDefault();
        e.returnValue = 'O agente ainda está a processar. Tens a certeza que queres sair?';
        return e.returnValue;
    }
});

// ═══════════════════════════════════════════════════════
//  SHARE AGENT MODAL
// ═══════════════════════════════════════════════════════
function openShareModal() {
    const agent = agentSelect.value || 'auto';
    document.getElementById('share-modal').style.display = 'flex';
    document.getElementById('share-success-box').style.display = 'none';
    document.getElementById('share-form-body').style.display = '';
    document.getElementById('share-submit-btn').style.display = '';
    document.getElementById('share-submit-btn').textContent = 'Criar Link';
    document.getElementById('share-submit-btn').disabled = false;
    // Reset fields
    ['share-client','share-email','share-title','share-welcome','share-pass','share-expires']
        .forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    // Populate agent checkboxes
    const list = document.getElementById('share-agent-list');
    if (list) {
        list.innerHTML = '';
        Object.keys(AGENT_NAMES).forEach(key => {
            const name  = AGENT_NAMES[key] || key;
            const photo = AGENT_PHOTOS[key] || null;
            const emoji = AGENT_EMOJIS[key] || '🤖';
            const checked = key === agent ? 'checked' : '';
            const avatar = photo
                ? `<img src="${photo}" alt="${name}" style="width:22px;height:22px;border-radius:5px;object-fit:cover;flex-shrink:0">`
                : `<span style="font-size:16px;line-height:1;flex-shrink:0">${emoji}</span>`;
            list.innerHTML += `
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:5px 6px;border-radius:6px;transition:.12s;user-select:none;min-width:0;overflow:hidden"
                       title="${name.replace(/"/g,'&quot;')}"
                       onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background=''">
                    <input type="checkbox" value="${key}" ${checked}
                        style="accent-color:#76b900;width:14px;height:14px;cursor:pointer;flex-shrink:0">
                    ${avatar}
                    <span style="font-size:12px;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1">${name}</span>
                </label>`;
        });
    }
}
function closeShareModal() {
    document.getElementById('share-modal').style.display = 'none';
}
async function submitShareModal() {
    const btn = document.getElementById('share-submit-btn');
    const client = document.getElementById('share-client').value.trim();
    const clientEmail = document.getElementById('share-email').value.trim();
    if (!client) { alert('Introduz o nome do cliente.'); return; }
    if (!clientEmail) {
        alert('⚠️ Email do cliente é obrigatório.\n\nÉ para esse email que vai o código de acesso (OTP). Sem email, o cliente não consegue entrar.');
        return;
    }

    const checked = Array.from(document.querySelectorAll('#share-agent-list input[type="checkbox"]:checked')).map(c => c.value);
    if (!checked.length) { alert('Seleciona pelo menos um agente.'); return; }

    btn.textContent = 'A criar…'; btn.disabled = true;

    // Generate a single portal_token so multiple agents selected in this
    // batch all land under /p/{portalToken} — one URL to send, one OTP to
    // enter, and the visitor sees every agent in a ClawYard landing page.
    const portalToken = Array.from({length:24}, () =>
        'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
            .charAt(Math.floor(Math.random() * 62))
    ).join('');

    // Additional recipients: split on comma/semicolon/whitespace, dedupe
    // against the primary, null out if empty so the JSON column stays NULL
    // for single-recipient shares.
    const extraRaw = (document.getElementById('share-extra-emails') || {}).value || '';
    const extraList = extraRaw
        .split(/[\s,;]+/)
        .map(s => s.trim().toLowerCase())
        .filter(s => s.length > 0 && s !== clientEmail.toLowerCase());

    // BUNDLED EMAIL STRATEGY:
    // When more than one agent is selected we create all shares with
    // skip_email=true and then fire a single POST to /admin/shares/portal-email
    // at the end. That endpoint sends ONE email per recipient listing every
    // agent (with real photos/avatars) so the client gets a single clean
    // message instead of N separate emails. For a single-agent selection we
    // keep the legacy per-share email path — there's nothing to bundle.
    const isBundle = checked.length > 1;
    const common = {
        client_name:       client,
        client_email:      clientEmail,
        additional_emails: extraList.length ? extraList : null,
        custom_title:      document.getElementById('share-title').value.trim() || null,
        welcome_message:  document.getElementById('share-welcome').value.trim() || null,
        password:         document.getElementById('share-pass').value || null,
        expires_at:       document.getElementById('share-expires').value || null,
        show_branding:    true,
        allow_sap_access: document.getElementById('share-sap-access').checked,
        // Security defaults: every link gets OTP + device lock + notifications
        // unless the creator explicitly opts out in /shares. The chat-side
        // modal keeps them ON — simpler UX.
        require_otp:      true,
        lock_to_device:   true,
        notify_on_access: true,
        // Groups this batch under one client portal.
        portal_token:     portalToken,
        // Suppress the per-share email when we're going to send a bundled
        // portal email right after all shares exist.
        skip_email:       isBundle,
    };

    try {
        const results = [];
        let portalUrl = null;
        for (const agentKey of checked) {
            const r    = await fetch('/admin/shares', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify({ ...common, agent_key: agentKey }) });
            const data = await r.json();
            if (data.ok) {
                results.push({ agent: AGENT_NAMES[agentKey] || agentKey, url: data.url });
                if (data.portal_url) portalUrl = data.portal_url;
            } else {
                alert('Erro ao criar link para ' + (AGENT_NAMES[agentKey] || agentKey) + ': ' + JSON.stringify(data));
            }
        }

        // After every share in the bundle was created, trigger the single
        // portal email. We do this OUT of the per-share loop so the recipient
        // receives exactly one message listing all agents.
        if (isBundle && results.length > 0) {
            try {
                await fetch('/admin/shares/portal-email', {
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
                    body:JSON.stringify({
                        portal_token: portalToken,
                        password:     common.password,
                    }),
                });
            } catch (e) {
                console.warn('Portal bundled email failed — individual shares still exist', e);
            }
        }
        if (results.length) {
            const display = document.getElementById('share-url-display');
            // If multiple agents share a portal, feature the portal URL
            // prominently (one link to send). Individual agent URLs are
            // listed below for reference / fallback.
            if (results.length > 1 && portalUrl) {
                display.innerHTML =
                    `<div style="padding:10px 12px;background:rgba(118,185,0,.08);border:1px solid rgba(118,185,0,.3);border-radius:8px;margin-bottom:10px">
                        <div style="font-size:10px;color:#a3e635;font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin-bottom:4px">🌐 Portal do Cliente — envia só este link</div>
                        <div style="word-break:break-all;font-family:monospace;font-size:12px;color:#e2e8f0">${portalUrl}</div>
                    </div>
                    <div style="font-size:10px;color:#666;margin-bottom:4px">Links individuais dos agentes (opcional):</div>` +
                    results.map(r =>
                        `<div style="margin-bottom:4px;font-size:11px;opacity:.75"><span style="opacity:.7">${r.agent}:</span> <span style="word-break:break-all">${r.url}</span></div>`
                    ).join('');
                window._shareUrl  = portalUrl;         // clipboard copy uses portal URL
                window._shareUrls = [portalUrl];
            } else {
                display.innerHTML = results.map(r =>
                    `<div style="margin-bottom:6px"><span style="font-size:10px;opacity:.7">${r.agent}</span><br>${r.url}</div>`
                ).join('');
                window._shareUrls = results.map(r => r.url);
                window._shareUrl  = results[0].url;
            }
            document.getElementById('share-success-box').style.display = 'block';
            document.getElementById('share-form-body').style.display = 'none';
            document.getElementById('share-submit-btn').style.display = 'none';
        } else {
            btn.textContent = 'Criar Link'; btn.disabled = false;
        }
    } catch(e) { alert('Erro de rede.'); btn.textContent = 'Criar Link'; btn.disabled = false; }
}
function copyShareUrl() {
    const urls = (window._shareUrls || [window._shareUrl]).filter(Boolean);
    navigator.clipboard.writeText(urls.join('\n'));
    const btn = document.getElementById('copy-share-url');
    btn.textContent = '✅ Copiado!';
    setTimeout(() => btn.textContent = '📋 Copiar Link', 2000);
}

// Keep header share button color in sync with current agent
agentSelect.addEventListener('change', updateShareBtn);
function updateShareBtn() {
    const btn = document.getElementById('share-agent-btn');
    if (btn) btn.style.background = getComputedStyle(document.documentElement).getPropertyValue('--agent-color').trim() || '#76b900';
    // Also update any legacy in-chat share buttons if present
    const manage = document.getElementById('manage-shares-btn');
    if (manage) manage.style.display = 'block';
}
updateShareBtn();

// ── THEME TOGGLE (dark/light) ─────────────────────────────
(function () {
    const root = document.documentElement;
    const btn  = document.getElementById('theme-toggle');
    if (!btn) return;

    // Default to dark if nothing set yet
    if (!root.hasAttribute('data-theme')) root.setAttribute('data-theme', 'dark');

    btn.addEventListener('click', () => {
        const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('cy-theme', next); } catch (e) {}
        // Re-colour agent share button (which uses --agent-color) after theme swap
        try { updateShareBtn(); } catch (e) {}
    });
})();
</script>

<!-- ── SHARE AGENT MODAL ── -->
<div id="share-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;flex-direction:column"
     onclick="if(event.target===this)closeShareModal()">
    <div style="background:#111118;border:1px solid #2a2a3a;border-radius:16px;width:100%;max-width:460px;padding:28px;position:relative">
        <button onclick="closeShareModal()" style="position:absolute;top:16px;right:16px;background:none;border:none;color:#64748b;font-size:18px;cursor:pointer">✕</button>
        <div style="font-size:17px;font-weight:800;margin-bottom:16px">🔗 Share Agent</div>
        <div style="margin-bottom:14px">
            <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px">Agentes a partilhar</label>
            <div id="share-agent-list" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px;max-height:200px;overflow-y:auto;overflow-x:hidden;border:1px solid #2a2a3a;border-radius:8px;padding:8px;background:#1a1a24">
                <!-- filled by JS from AGENT_NAMES + AGENT_PHOTOS -->
            </div>
        </div>

        <!-- Success -->
        <div id="share-success-box" style="display:none;background:rgba(118,185,0,.1);border:1px solid rgba(118,185,0,.3);border-radius:10px;padding:16px;margin-bottom:16px">
            <div style="font-size:12px;color:#76b900;font-weight:700;margin-bottom:8px">✅ Link created!</div>
            <div id="share-url-display" style="font-size:12px;color:#76b900;word-break:break-all;margin-bottom:10px"></div>
            <button id="copy-share-url" onclick="copyShareUrl()" style="background:rgba(118,185,0,.2);border:1px solid rgba(118,185,0,.4);color:#76b900;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600">📋 Copy Link</button>
        </div>

        <!-- Form -->
        <div id="share-form-body">
            <div style="margin-bottom:14px">
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Client Name *</label>
                <input id="share-client" type="text" placeholder="e.g. Armadores Silva Ltd."
                    style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:14px;outline:none">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div>
                    <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Email <span style="color:#ef4444">*</span></label>
                    <input id="share-email" type="email" placeholder="client@company.com" required
                        style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:14px;outline:none">
                    <div style="font-size:10px;color:#64748b;margin-top:4px">Obrigatório — é para aqui que vai o código de acesso.</div>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Password</label>
                    <input id="share-pass" type="password" placeholder="Optional"
                        style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:14px;outline:none">
                </div>
            </div>
            <div style="margin-bottom:14px">
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Additional emails <span style="color:#64748b;text-transform:none;letter-spacing:0;font-weight:500">(opcional)</span></label>
                <textarea id="share-extra-emails" rows="2" placeholder="colega1@empresa.com, colega2@empresa.com"
                    style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:13px;outline:none;resize:vertical;font-family:inherit"></textarea>
                <div style="font-size:10px;color:#64748b;margin-top:4px">Separa por vírgula, ponto-e-vírgula ou nova linha. Cada pessoa recebe o mesmo link e pede o código ao próprio email.</div>
            </div>
            <div style="margin-bottom:14px">
                <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Custom Title</label>
                <input id="share-title" type="text" placeholder="e.g. Security Assistant — Silva Ltd."
                    style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:14px;outline:none">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div>
                    <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Welcome Message</label>
                    <input id="share-welcome" type="text" placeholder="Hello! How can I help?"
                        style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:14px;outline:none">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Expires at</label>
                    <input id="share-expires" type="datetime-local"
                        style="width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:10px 14px;border-radius:8px;font-size:13px;outline:none">
                </div>
            </div>
        </div>

        <!-- SAP access toggle -->
        <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:11px 14px;margin-bottom:14px">
            <label style="display:flex;align-items:center;gap:11px;cursor:pointer">
                <input type="checkbox" id="share-sap-access" style="width:20px;height:20px;accent-color:#76b900;cursor:pointer;flex-shrink:0">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#e2e8f0">📊 Permitir acesso SAP B1 (Richard)</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px">Por defeito <strong style="color:#ef4444">bloqueado</strong> — stock, faturas e CRM ficam ocultos a utilizadores externos.</div>
                </div>
            </label>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
            <button onclick="closeShareModal()" style="background:none;border:1px solid #2a2a3a;color:#64748b;padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px">Cancel</button>
            <button id="share-submit-btn" onclick="submitShareModal()" style="background:var(--agent-color,#76b900);color:#000;font-weight:700;padding:8px 20px;border:none;border-radius:8px;cursor:pointer;font-size:13px">Criar Link</button>
        </div>
    </div>
</div>

@include('partials.keyboard-shortcuts')

</body>
</html>
