<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Briefing Executivo {{ now()->format('d/m/Y') }} — PartYard / HP-Group</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#fff;color:#111;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.7;padding:32px 40px}
        @media print{
            body{padding:16px 24px}
            .no-print{display:none!important}
            .page-break{page-break-before:always}
            @page{margin:15mm 12mm}
        }
        .print-btn{position:fixed;top:16px;right:16px;background:#1a3a00;color:#76b900;border:2px solid #76b900;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;z-index:999;display:flex;align-items:center;gap:8px}
        .print-btn:hover{background:#76b900;color:#000}

        /* Header */
        .doc-top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #111;padding-bottom:16px;margin-bottom:20px}
        .company-block .company{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}
        .company-block .doc-type{font-size:20px;font-weight:900;color:#111}
        .company-block .doc-date{font-size:12px;color:#555;margin-top:3px}
        .logo-block{text-align:right}
        .logo-block .logo{font-size:22px;font-weight:900;color:#4a7a00;display:inline-flex;align-items:center;gap:7px}
        .logo-block .tagline{font-size:10px;color:#888;margin-top:2px}

        /* Classification badge */
        .classification{display:inline-block;background:#111;color:#fff;font-size:9px;font-weight:700;padding:3px 10px;border-radius:3px;text-transform:uppercase;letter-spacing:1px;margin-bottom:18px}

        /* Content */
        h1{font-size:18px;font-weight:800;color:#111;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #eee}
        h2{font-size:13px;font-weight:800;color:#2d5a00;margin:20px 0 8px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px}
        h2::before{content:'';display:block;width:4px;height:16px;background:#76b900;border-radius:2px}
        h3{font-size:12px;font-weight:700;color:#333;margin:12px 0 5px}
        p{color:#333;margin:5px 0;line-height:1.65}
        strong{font-weight:700;color:#111}
        em{font-style:italic;color:#555}
        ul,ol{padding-left:18px;margin:6px 0;color:#333}
        li{margin:3px 0}
        hr{border:none;border-top:1px solid #e0e0e0;margin:16px 0}
        code{font-family:monospace;background:#f5f5f5;padding:1px 5px;border-radius:3px;font-size:11px}
        pre{background:#f5f5f5;padding:10px;border-radius:4px;margin:6px 0;font-size:10px;overflow-x:auto}
        blockquote{border-left:3px solid #76b900;padding-left:12px;color:#666;margin:6px 0}
        a{color:#4a7a00}

        /* Action plan special styling */
        .action-item{background:#f9fbf5;border:1px solid #d0e8b0;border-radius:4px;padding:10px 14px;margin:8px 0}
        .action-priority-urgente{border-left:4px solid #cc0000;background:#fff5f5}
        .action-priority-alta{border-left:4px solid #ff6600;background:#fff9f0}
        .action-priority-media{border-left:4px solid #ffaa00;background:#fffbf0}
        .action-priority-baixa{border-left:4px solid #76b900;background:#f9fbf5}

        /* Footer */
        .doc-footer{margin-top:32px;padding-top:12px;border-top:2px solid #111;display:flex;justify-content:space-between;font-size:9px;color:#888}
        .confidential{font-weight:700;color:#333;text-transform:uppercase;letter-spacing:1px}

        /* Page numbers */
        @media print{
            .doc-footer::after{content:' · Pág. 'counter(page);counter-increment:page}
        }
    </style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">🖨️ Guardar como PDF</button>

<div class="doc-top">
    <div class="company-block">
        <div class="company">PartYard/Setq.AI Rights reserved 2026</div>
        <div class="doc-type">📊 Briefing Executivo Diário</div>
        <div class="doc-date">{{ $report->created_at->format('d \d\e F \d\e Y · H:i') }}</div>
    </div>
    <div class="logo-block">
        <div class="logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 26" width="44" height="24">
                <path d="M2 13 Q7 3 12 13 Q17 23 22 13 Q27 3 32 13 Q37 23 42 13 Q45 8 46 10"
                      stroke="#4a7a00" stroke-width="2.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M2 19 Q7 9 12 19 Q17 29 22 19 Q27 9 32 19 Q37 29 42 19 Q45 14 46 16"
                      stroke="#4a7a00" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="0.4"/>
            </svg>
            ClawYard
        </div>
        <div class="tagline">AI-Powered Strategic Intelligence</div>
    </div>
</div>

<div class="classification">Confidencial — Uso Interno</div>

<div class="briefing-body" id="content" data-raw="{{ e($report->content) }}"></div>

<div class="doc-footer">
    <span class="confidential">Confidencial · PartYard / HP-Group</span>
    <span>ClawYard AI · © PartYard/Setq.AI Rights reserved 2026 · {{ $report->created_at->format('d/m/Y H:i') }}</span>
</div>

<script>
function renderMarkdown(text) {
    return text
        .replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>')
        .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^---$/gm, '<hr>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>[^]*?<\/li>\n?)+/g, m => '<ul>' + m + '</ul>')
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        .replace(/\n\n/g, '<br><br>')
        .replace(/\n/g, '<br>');
}
const el = document.getElementById('content');
el.innerHTML = renderMarkdown(el.dataset.raw);
</script>
</body>
</html>
