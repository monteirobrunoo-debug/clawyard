<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — AI Chat</title>
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
        }

        body { font-family: system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--text); height:100vh; display:flex; flex-direction:column; overflow:hidden; }

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

        /* Markdown-like styling inside bubble */
        .bubble strong { font-weight:700; }
        .bubble em { font-style:italic; color:#aaa; }
        .bubble code { background:#0f0f0f; border:1px solid var(--border2); border-radius:4px; padding:1px 5px; font-family:monospace; font-size:12px; color:#76b900; }
        .bubble h2 { font-size:14px; font-weight:700; color:var(--green); margin:8px 0 4px; }
        .bubble h3 { font-size:13px; font-weight:700; color:#aaa; margin:6px 0 3px; }
        .bubble ul, .bubble ol { padding-left:18px; margin:4px 0; }
        .bubble li { margin:2px 0; }
        .bubble hr { border:none; border-top:1px solid var(--border2); margin:10px 0; }

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
        #send-btn:hover { background:var(--green-hover); }
        #send-btn:disabled { background:#222; cursor:not-allowed; }
        #send-btn svg { width:18px; height:18px; }

        /* Toggle sidebar btn */
        #toggle-panel { background:none; border:none; color:var(--muted); cursor:pointer; font-size:14px; padding:4px; }
        #toggle-panel:hover { color:var(--text); }

        /* ── EMPTY STATE ── */
        .empty-state { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; }
        .empty-state h2 { font-size:32px; color:var(--green); font-weight:800; }
        .empty-state p { font-size:13px; color:var(--muted); }
        .starter-chips { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; max-width:520px; }
        .starter-chip { background:var(--bg3); border:1px solid var(--border2); color:#888; padding:7px 14px; border-radius:20px; font-size:12px; cursor:pointer; transition:all 0.15s; }
        .starter-chip:hover { border-color:var(--green); color:var(--green); background:#0f1f00; }

        /* Save report button */
        .save-report-btn { background:none; border:none; cursor:pointer; font-size:13px; margin-left:6px; opacity:0.4; transition:opacity .2s; padding:0; }
        .save-report-btn:hover { opacity:1; }
        .save-report-btn.saved { opacity:1; }
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
        <option value="sales">💼 Marco — Sales</option>
        <option value="support">🔧 Marcus — Support</option>
        <option value="email">📧 Daniel — Email</option>
        <option value="sap">📊 Ricardo — SAP</option>
        <option value="document">📄 Sofia — Document</option>
        <option value="claude">🧠 Iris — Claude</option>
        <option value="nvidia">⚡ Nemo — NVIDIA</option>
        <option value="aria">🔐 ARIA — Security</option>
        <option value="quantum">⚛️ Prof. Quantum Leap</option>
    </select>
    <div class="hdr-right">
        <span id="model-badge">pronto</span>
        <a href="/discoveries" title="Descobertas & Patentes" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">🔬 Descobertas</a>
        <a href="/reports" title="Relatórios" style="background:var(--bg3);border:1px solid var(--border2);color:var(--muted);padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px;">📋 Reports</a>
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
                <div class="agent-mini" data-agent="sales"><div class="dot-status"></div><span>💼 Marco — Sales</span></div>
                <div class="agent-mini" data-agent="support"><div class="dot-status"></div><span>🔧 Marcus — Support</span></div>
                <div class="agent-mini" data-agent="email"><div class="dot-status"></div><span>📧 Daniel — Email</span></div>
                <div class="agent-mini" data-agent="sap"><div class="dot-status"></div><span>📊 Ricardo — SAP</span></div>
                <div class="agent-mini" data-agent="document"><div class="dot-status"></div><span>📄 Sofia — Document</span></div>
                <div class="agent-mini" data-agent="claude"><div class="dot-status"></div><span>🧠 Iris — Claude</span></div>
                <div class="agent-mini" data-agent="nvidia"><div class="dot-status"></div><span>⚡ Nemo — NVIDIA</span></div>
                <div class="agent-mini" data-agent="aria"><div class="dot-status"></div><span>🔐 ARIA — Security</span></div>
                <div class="agent-mini" data-agent="quantum"><div class="dot-status"></div><span>⚛️ Prof. Quantum Leap</span></div>
            </div>
        </div>
    </div>

    <!-- ── CHAT AREA ── -->
    <div class="chat-wrap">
        <div id="chat">
            <div class="empty-state" id="empty-state">
                <h2>ClawYard AI</h2>
                <p>Powered by NVIDIA NeMo + Claude · Portos, Peças, Emails, Análise</p>
                <div class="starter-chips" id="starter-chips"></div>
            </div>
        </div>

        <!-- Image preview -->
        <div id="image-preview">
            <img id="preview-img" src="" alt="preview">
            <button id="remove-image">✕</button>
        </div>

        <!-- ── INPUT ── -->
        <div id="input-area">
            <button class="icon-btn" id="voice-btn" title="Voz (pt-PT)">🎤</button>
            <button class="icon-btn" id="image-btn" title="Imagem">📎</button>
            <input type="file" id="image-input" accept="image/*" style="display:none">
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
    aria:'🔐', quantum:'⚛️'
};

const AGENT_NAMES = {
    auto:'Auto', orchestrator:'All Agents', sales:'Marco', support:'Marcus',
    email:'Daniel', sap:'Ricardo', document:'Sofia', claude:'Iris', nvidia:'Nemo',
    aria:'ARIA Security', quantum:'Prof. Quantum Leap'
};

const AGENT_CHIPS = {
    auto: [
        '🚢 Analisa concorrentes no porto de Sines',
        '📧 Escreve email para armador em Pireu',
        '🔧 Motor MTU com problema de pressão de óleo',
        '📊 Quais os portos europeus com mais crescimento?',
        '⚛️ O que são qubits e como funcionam?',
        '🔐 Faz scan de segurança ao partyard.eu',
    ],
    orchestrator: [
        '🌐 Analisa o mercado de peças MTU em Roterdão e gera email para agente local',
        '🌐 Avalia concorrentes em Algeciras e propõe estratégia de cold outreach',
        '🌐 Suporte técnico ao motor CAT 3516 com proposta comercial e email ao armador',
        '🌐 Análise de portos no Mediterrâneo com lista de contactos a abordar',
    ],
    sales: [
        '💼 Tenho um motor MTU Série 4000 — preciso de pistões e camisas',
        '💼 Cotação urgente: selos SKF SternTube para navio em Sines',
        '💼 Quero uma proposta para revisão completa de um MAK M32',
        '💼 Preciso de peças Schottel SRP para tug em Lisboa',
        '💼 Qual o prazo de entrega para turbo Caterpillar 3516?',
        '💼 Jenbacher J620 — qual a disponibilidade de kits de válvulas?',
    ],
    support: [
        '🔧 MTU 12V4000 — alarm HT coolant temp alta, código F0203',
        '🔧 CAT 3516B — consumo excessivo de óleo após revisão, o que verificar?',
        '🔧 MAK M25 — vibração anormal no 3º cilindro em carga alta',
        '🔧 Schottel SRP — vedante de proa com fuga, como proceder?',
        '🔧 SKF SternTube — folga axial fora de spec, valores de referência?',
        '🔧 Jenbacher J320 — falha de ignição intermitente no cilindro 6',
    ],
    email: [
        '📧 Cold outreach em inglês para shipping agent em Hamburgo — peças MTU',
        '📧 Proposta comercial em espanhol para armador em Algeciras',
        '📧 Follow-up de cotação para navio em Roterdão — Caterpillar C32',
        '📧 Email urgente para agente em Pireu: temos selos SKF em stock',
        '📧 Apresentação PartYard Defense para agente naval em Lisboa',
        '📧 Email de parceria para agente marítimo em Barcelona — peças MAK',
    ],
    sap: [
        '📊 Mostra o stock actual de peças MTU Série 2000',
        '📊 Qual o estado da encomenda #PY-2025-0847?',
        '📊 Clientes com facturas em atraso há mais de 30 dias',
        '📊 Relatório de vendas por marca — MTU vs Caterpillar Q1 2026',
        '📊 Cria uma cotação SAP para o cliente Navios do Tejo Lda',
        '📊 Quanto stock temos de kits de pistões CAT 3516?',
    ],
    document: [
        '📄 Analisa este manual técnico MTU e lista os intervalos de manutenção',
        '📄 Extrai as especificações técnicas do contrato de fornecimento anexo',
        '📄 Verifica se este certificado ISO está dentro da validade',
        '📄 Compara duas propostas técnicas e diz qual é mais vantajosa',
        '📄 Resume este relatório de inspeção do navio em 5 pontos',
        '📄 Traduz este documento técnico do inglês para português',
    ],
    claude: [
        '🧠 Qual a melhor estratégia para expandir para o mercado grego?',
        '🧠 Analisa os riscos de entrar no mercado de peças para navios militares',
        '🧠 Compara os modelos de negócio da Wilhelmsen e da PartYard',
        '🧠 Escreve um plano de negócio para abrir escritório em Roterdão',
        '🧠 Quais as tendências da indústria naval até 2030?',
        '🧠 Como posicionar a PartYard Defense face à concorrência NATO?',
    ],
    nvidia: [
        '⚡ Gera 10 subject lines para cold email a armadores gregos',
        '⚡ Cria uma descrição de produto para peças MTU Série 4000',
        '⚡ Optimiza este texto de proposta comercial para maior impacto',
        '⚡ Gera FAQ técnico sobre manutenção de motores MAK',
        '⚡ Cria post LinkedIn sobre as peças Schottel da PartYard',
        '⚡ Traduz catálogo técnico Caterpillar para espanhol',
    ],
    aria: [
        '🔐 Faz scan de segurança completo ao partyard.eu com STRIDE',
        '🔐 Analisa o hp-group.org e lista vulnerabilidades OWASP',
        '🔐 O ClawYard tem proteção contra SQL Injection?',
        '🔐 Verifica certificados SSL dos sites do grupo H&P',
        '🔐 Gera threat model para a API do ClawYard',
        '🔐 Quais os riscos de cibersegurança para uma empresa marítima?',
    ],
    quantum: [
        '⚛️ Faz o digest completo de hoje: papers arXiv + patentes USPTO para PartYard',
        '🏛️ Quais as 7 melhores patentes novas para a PartYard crescer?',
        '⚛️ Explica o que é superposição quântica de forma simples',
        '🏛️ Há patentes recentes sobre manutenção preditiva para motores marítimos?',
        '⚛️ Como a criptografia quântica pode proteger comunicações navais?',
        '🏛️ Analisa patentes de propulsão Schottel e o que a HP-Group pode fazer',
    ],
};

function renderStarterChips(agent) {
    const chips = AGENT_CHIPS[agent] || AGENT_CHIPS['auto'];
    const container = document.getElementById('starter-chips');
    if (!container) return;
    container.innerHTML = chips.map(c =>
        `<div class="starter-chip" onclick="startChat(this)">${c}</div>`
    ).join('');
}

// Init chips on page load
renderStarterChips(agentSelect.value || 'auto');

// Update chips when agent changes
agentSelect.addEventListener('change', () => {
    renderStarterChips(agentSelect.value);
    const agentName = AGENT_NAMES[agentSelect.value] || agentSelect.value;
    const emptyState = document.getElementById('empty-state');
    if (emptyState) {
        emptyState.querySelector('p').textContent =
            'A falar com ' + AGENT_EMOJIS[agentSelect.value] + ' ' + agentName +
            ' · Escolhe um exemplo ou escreve a tua pergunta';
    }
});

let isRecording  = false;
let recognition  = null;
let currentImg   = null;
let panelOpen    = true;
let actCount     = 0;

// ── Agent from URL ──
const urlAgent = new URLSearchParams(window.location.search).get('agent');
if (urlAgent && agentSelect.querySelector(`option[value="${urlAgent}"]`)) {
    agentSelect.value = urlAgent;
}

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

// ── Image ──
document.getElementById('image-btn').addEventListener('click', () =>
    document.getElementById('image-input').click()
);

document.getElementById('image-input').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
        currentImg = ev.target.result.split(',')[1];
        document.getElementById('preview-img').src = ev.target.result;
        document.getElementById('image-preview').style.display = 'block';
    };
    reader.readAsDataURL(file);
});

document.getElementById('remove-image').addEventListener('click', clearImage);
function clearImage() {
    currentImg = null;
    document.getElementById('image-preview').style.display = 'none';
    document.getElementById('image-input').value = '';
}

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
    return esc(text)
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`(.+?)`/g, '<code>$1</code>')
        .replace(/^---$/gm, '<hr>')
        .replace(/^[-•] (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>(\n|$))+/g, (m) => `<ul>${m}</ul>`)
        .replace(/\n/g, '<br>');
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
        const data = await res.json();
        if (data.success) {
            btn.textContent = '✅';
            btn.classList.add('saved');
            btn.title = 'Guardado! Ver em /reports';
            logActivity('💾', 'Relatório guardado: ' + title, 'done');
        } else {
            btn.textContent = '❌';
            setTimeout(() => { btn.textContent = '💾'; btn.classList.remove('saved'); }, 2000);
        }
    } catch(e) {
        btn.textContent = '❌';
        setTimeout(() => { btn.textContent = '💾'; }, 2000);
    }
}

// ── TTS (desactivado) ──
function speak(text) { /* TTS disabled */ }

// ═══════════════════════════════
//  SEND MESSAGE
// ═══════════════════════════════
async function sendMessage() {
    const text = input.value.trim();
    if (!text || sendBtn.disabled) return;

    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;

    const selectedAgent = agentSelect.value;
    document.getElementById('empty-state')?.remove();

    // ── User message ──
    addMessage('user', text);

    // ── Activity log ──
    logActivity('📨', 'Mensagem recebida: "' + text.substring(0,50) + (text.length>50?'…':'') + '"', 'done');
    const stepRAG    = logActivity('📚', 'A consultar base de conhecimento (RAG)…');
    const stepAgent  = logActivity('🤖', 'A encaminhar para agente ' + (AGENT_NAMES[selectedAgent]||selectedAgent) + '…');
    setAgentActive(selectedAgent);
    modelBadge.textContent = '⏳ ' + (AGENT_NAMES[selectedAgent]||selectedAgent);

    const typing = addTyping(selectedAgent);

    try {
        const payload = { message: text, agent: selectedAgent, session_id: SESSION_ID };
        if (currentImg) {
            payload.image = currentImg;
            clearImage();
            logActivity('🖼️', 'Imagem incluída (multimodal)', 'done');
        }

        resolveStep(stepRAG);

        const res  = await fetch('/api/chat', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify(payload),
        });

        // Try to parse JSON — if server returns HTML (fatal error), catch that separately
        let data;
        if (!res.ok && res.status !== 422) {
            const raw = await res.text();
            typing.remove();
            const snippet = raw.replace(/<[^>]+>/g,'').trim().substring(0,200);
            addMessage('ai', `❌ Erro HTTP ${res.status}: ${snippet || 'Sem detalhe'}`);
            logActivity('❌', `HTTP ${res.status}`, 'done');
            sendBtn.disabled = false; clearAgentActive(); modelBadge.textContent = 'pronto'; input.focus();
            return;
        }
        data = await res.json();

        typing.remove();
        resolveStep(stepAgent);

        if (data.success) {
            const agent = data.agent || selectedAgent;

            // ── Log agent actions ──
            if (data.agent_log) {
                data.agent_log.forEach(l => logActivity(l.icon, l.text, 'done'));
            }

            // ── Add response ──
            addMessage('ai', data.reply, agent);

            modelBadge.textContent = data.model || data.agents?.join(', ') || agent;

            // ── Suggestions ──
            if (data.suggestions) {
                addSuggestions(data.suggestions, agent);
            }

            // ── Autonomous action proposal ──
            if (data.reply && !data.reply.startsWith('__EMAIL__')) {
                const replyLower = data.reply.toLowerCase();

                // If sales/support mentions competitors → propose analysis
                if (replyLower.includes('concorrente') || replyLower.includes('competitor')) {
                    setTimeout(() => addActionApproval({
                        icon: '🔍',
                        title: 'Análise de concorrentes detectada',
                        description: 'Posso pesquisar automaticamente os concorrentes mencionados na base de dados de portos e enviar-lhe um email com o relatório completo.',
                        agent: 'email',
                        prompt: encodeURIComponent('Escreve um email com análise dos concorrentes nos portos europeus para enviar ao CEO'),
                    }), 800);
                }

                // If email mentioned → propose email creation
                if ((replyLower.includes('proposta') || replyLower.includes('proposal')) && agent !== 'email') {
                    setTimeout(() => addActionApproval({
                        icon: '📧',
                        title: 'Transformar em email profissional?',
                        description: 'O agente gerou uma proposta. Queres que o Daniel Email a transforme num email profissional pronto a enviar?',
                        agent: 'email',
                        prompt: encodeURIComponent('Transforma em email profissional: ' + data.reply.substring(0,300)),
                    }), 800);
                }
            }

            // ── TTS ──
            if (selectedAgent !== 'orchestrator' && !data.reply.startsWith('__EMAIL__')) {
                speak(data.reply);
            }
        } else {
            addMessage('ai', '❌ Erro: ' + (data.error || 'Erro desconhecido'));
            logActivity('❌', 'Erro: ' + (data.error||''), 'done');
        }
    } catch (err) {
        typing.remove();
        addMessage('ai', '❌ Erro de ligação. Verifica a configuração da API.');
        logActivity('❌', 'Erro de ligação: ' + err.message, 'done');
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
