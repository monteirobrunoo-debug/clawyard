<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClawYard — Tarefas Agendadas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background:#0a0a0a; color:#e5e5e5; font-family:system-ui,sans-serif; min-height:100vh; }
        header { display:flex; align-items:center; gap:12px; padding:14px 28px; border-bottom:1px solid #1e1e1e; background:#111; }
        .logo { font-size:18px; font-weight:800; color:#76b900; }
        .back-btn { color:#555; text-decoration:none; font-size:20px; margin-right:4px; }
        .back-btn:hover { color:#e5e5e5; }
        .page-title { font-size:14px; font-weight:700; color:#aaa; }
        .hdr-right { margin-left:auto; display:flex; gap:10px; align-items:center; }
        .btn-sm { font-size:12px; padding:6px 14px; border-radius:8px; border:1px solid #333; background:none; color:#aaa; cursor:pointer; text-decoration:none; }
        .btn-sm:hover { border-color:#76b900; color:#76b900; }

        .container { max-width:960px; margin:0 auto; padding:32px 24px; }
        h1 { font-size:24px; font-weight:800; color:#76b900; margin-bottom:6px; }
        .subtitle { font-size:13px; color:#555; margin-bottom:32px; }

        .task-grid { display:grid; gap:16px; }

        .task-card {
            background:#111; border:1px solid #1e1e1e; border-radius:16px;
            padding:20px 24px; display:flex; align-items:flex-start; gap:18px;
            transition:border-color 0.2s;
        }
        .task-card:hover { border-color:#2a2a2a; }
        .task-card.active { border-color:#76b900; }

        .task-icon { font-size:36px; flex-shrink:0; margin-top:2px; }

        .task-info { flex:1; }
        .task-name { font-size:16px; font-weight:700; color:#e5e5e5; margin-bottom:4px; }
        .task-desc { font-size:13px; color:#666; margin-bottom:10px; line-height:1.5; }

        .task-meta { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .tag { font-size:11px; padding:3px 10px; border-radius:20px; border:1px solid #2a2a2a; color:#666; background:#1a1a1a; }
        .tag.green { border-color:#1a3300; color:#76b900; background:#0a1500; }
        .tag.yellow { border-color:#332200; color:#ffaa00; background:#1a1000; }
        .tag.blue { border-color:#001a33; color:#4499ff; background:#001020; }

        .task-actions { display:flex; flex-direction:column; gap:8px; align-items:flex-end; flex-shrink:0; }
        .status-dot { width:10px; height:10px; border-radius:50%; background:#76b900; animation:blink 2s infinite; margin-bottom:4px; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .status-dot.paused { background:#555; animation:none; }

        .btn-primary { background:#76b900; color:#000; border:none; padding:8px 18px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; }
        .btn-primary:hover { background:#8fd400; }
        .btn-secondary { background:none; color:#555; border:1px solid #2a2a2a; padding:7px 16px; border-radius:8px; font-size:12px; cursor:pointer; white-space:nowrap; }
        .btn-secondary:hover { border-color:#555; color:#aaa; }

        .divider { height:1px; background:#1a1a1a; margin:8px 0; }

        .info-box { background:#0a1500; border:1px solid #1a3300; border-radius:12px; padding:16px 20px; margin-bottom:24px; }
        .info-box p { font-size:13px; color:#76b900; line-height:1.6; }
        .info-box strong { color:#8fd400; }
    </style>
</head>
<body>

<header>
    <a href="/chat" class="back-btn">←</a>
    <a href="/dashboard" style="display:flex;align-items:center;text-decoration:none;"><img src="/images/setq-logo.svg" alt="SETQ.AI" style="height:32px;filter:drop-shadow(0 0 1px rgba(255,255,255,0.1));"></a>
    <span class="page-title">/ Tarefas Agendadas</span>
    <div class="hdr-right">
        <a href="/dashboard" class="btn-sm">Dashboard</a>
        @if(Auth::user()->isAdmin())
            <a href="/admin/users" class="btn-sm" style="border-color:#ff4444;color:#ff6666;">⚙️ Admin</a>
        @endif
    </div>
</header>

<div class="container">
    <h1>🗓️ Tarefas Agendadas</h1>
    <p class="subtitle">Agentes autónomos que correm automaticamente todos os dias</p>

    <div class="info-box">
        <p>
            💡 Estas tarefas correm automaticamente no <strong>Claude Code</strong> local.
            Para gerir horários, pausar ou correr manualmente, vai à sidebar do Claude Code → <strong>Scheduled</strong>.
            Os relatórios chegam como notificação quando a tarefa termina.
        </p>
    </div>

    <div class="task-grid">

        <!-- ARIA 3x -->
        <div class="task-card active">
            <div class="task-icon">🔐</div>
            <div class="task-info">
                <div class="task-name">ARIA — Security Scan × 3 / dia</div>
                <div class="task-desc">
                    Scan STRIDE + OWASP Top 10 três vezes por dia de todos os sites do grupo H&P.
                    Analisa www.partyard.eu, www.hp-group.org e todas as empresas associadas.
                    Reporta vulnerabilidades por severidade com mitigações recomendadas.
                </div>
                <div class="task-meta">
                    <span class="tag green">🟢 Activo × 3</span>
                    <span class="tag">⏰ 07:00 · 13:00 · 19:00</span>
                    <span class="tag blue">STRIDE · OWASP · SSL · Headers</span>
                    <span class="tag">partyard.eu · hp-group.org + subsidiárias</span>
                </div>
            </div>
            <div class="task-actions">
                <div class="status-dot"></div>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-end;">
                    <span style="font-size:11px;color:#76b900;">🌅 07:00 manhã</span>
                    <span style="font-size:11px;color:#76b900;">☀️ 13:00 tarde</span>
                    <span style="font-size:11px;color:#76b900;">🌆 19:00 noite</span>
                </div>
            </div>
        </div>

        <!-- QUANTUM LEAP 3x -->
        <div class="task-card active">
            <div class="task-icon">⚛️</div>
            <div class="task-info">
                <div class="task-name">Prof. Quantum Leap — Digest × 3 / dia</div>
                <div class="task-desc">
                    <strong>08:00 Manhã:</strong> Top 5 papers arXiv + Top 7 patentes USPTO para PartYard/HP-Group.<br>
                    <strong>14:00 Tarde:</strong> Update arXiv + 5 novas patentes (marine propulsion, MTU, CAT, IoT).<br>
                    <strong>20:00 Noite:</strong> Resumo do dia + Top 1 patente a actuar amanhã + oportunidades para o dia seguinte.
                </div>
                <div class="task-meta">
                    <span class="tag green">🟢 Activo × 3</span>
                    <span class="tag">⏰ 08:00 · 14:00 · 20:00</span>
                    <span class="tag blue">arXiv · USPTO · patents.google.com</span>
                    <span class="tag">MTU · CAT · MAK · SKF · Schottel · Jenbacher</span>
                </div>
            </div>
            <div class="task-actions">
                <div class="status-dot"></div>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-end;">
                    <span style="font-size:11px;color:#76b900;">🌅 08:00 manhã</span>
                    <span style="font-size:11px;color:#76b900;">☀️ 14:00 tarde</span>
                    <span style="font-size:11px;color:#76b900;">🌆 20:00 noite</span>
                </div>
            </div>
        </div>

        <!-- PLACEHOLDER: MARKET MONITOR -->
        <div class="task-card" style="opacity:0.5;">
            <div class="task-icon">📊</div>
            <div class="task-info">
                <div class="task-name">Market Monitor — Próximamente</div>
                <div class="task-desc">
                    Monitorização diária de preços de concorrentes, novas rotas marítimas
                    e alertas de negócio nos portos europeus prioritários.
                </div>
                <div class="task-meta">
                    <span class="tag yellow">🟡 Em desenvolvimento</span>
                    <span class="tag">Sines · Lisboa · Roterdão · Pireu</span>
                </div>
            </div>
            <div class="task-actions">
                <div class="status-dot paused"></div>
            </div>
        </div>

        <!-- PLACEHOLDER: WHATSAPP -->
        <div class="task-card" style="opacity:0.5;">
            <div class="task-icon">📱</div>
            <div class="task-info">
                <div class="task-name">WhatsApp Monitor — Próximamente</div>
                <div class="task-desc">
                    Resposta automática a mensagens WhatsApp Business de clientes e
                    armadores, com escalamento inteligente para os agentes certos.
                </div>
                <div class="task-meta">
                    <span class="tag yellow">🟡 Aguarda conta Meta Business</span>
                </div>
            </div>
            <div class="task-actions">
                <div class="status-dot paused"></div>
            </div>
        </div>

    </div>
</div>

</body>
</html>
