<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $report->title }} — ClawYard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background:#0a0a0a; color:#e5e5e5; font-family:system-ui,sans-serif; min-height:100vh; }
        header { display:flex; align-items:center; gap:12px; padding:14px 28px; border-bottom:1px solid #1e1e1e; background:#111; }
        .logo { font-size:18px; font-weight:800; color:#76b900; }
        .back-btn { color:#555; text-decoration:none; font-size:20px; }
        .back-btn:hover { color:#e5e5e5; }
        .hdr-right { margin-left:auto; display:flex; gap:10px; }
        .btn { font-size:12px; padding:7px 16px; border-radius:8px; border:1px solid #333; background:none; color:#aaa; cursor:pointer; text-decoration:none; transition:all .2s; }
        .btn:hover { border-color:#76b900; color:#76b900; }
        .btn-pdf { border-color:#ff6600; color:#ff9944; }
        .btn-pdf:hover { background:#1a0800; }

        .container { max-width:900px; margin:0 auto; padding:32px 24px; }

        .report-header { margin-bottom:28px; }
        .type-badge { display:inline-block; font-size:12px; padding:3px 12px; border-radius:20px; font-weight:600; margin-bottom:12px; }
        .type-aria    { background:#1a0000; color:#ff6666; border:1px solid #330000; }
        .type-quantum { background:#0f0020; color:#cc66ff; border:1px solid #220044; }
        .type-market  { background:#1a1000; color:#ffaa00; border:1px solid #332200; }
        .type-custom  { background:#0a1500; color:#76b900; border:1px solid #1a3300; }

        h1 { font-size:22px; font-weight:800; color:#e5e5e5; margin-bottom:8px; line-height:1.3; }
        .report-meta { font-size:12px; color:#444; }

        .report-content {
            background:#111; border:1px solid #1e1e1e; border-radius:14px;
            padding:28px 32px; line-height:1.75; font-size:13.5px; color:#ccc;
            white-space:pre-wrap; word-break:break-word;
        }
        .report-content h2 { font-size:16px; font-weight:700; color:#76b900; margin:16px 0 8px; }
        .report-content h3 { font-size:14px; font-weight:700; color:#aaa; margin:12px 0 6px; }
        .report-content strong { color:#e5e5e5; }
        .report-content hr { border:none; border-top:1px solid #222; margin:16px 0; }
        .report-content code { background:#0f0f0f; border:1px solid #222; border-radius:4px; padding:1px 5px; font-family:monospace; font-size:12px; color:#76b900; }
    </style>
</head>
<body>

<header>
    <a href="/reports" class="back-btn">←</a>
    <span class="logo">🐾 ClawYard</span>
    <span style="font-size:13px;color:#555;">/ Relatórios</span>
    <div class="hdr-right">
        <a href="/reports/{{ $report->id }}/pdf" target="_blank" class="btn btn-pdf">📥 Download PDF</a>
        <a href="/reports" class="btn">← Lista</a>
    </div>
</header>

<div class="container">
    <div class="report-header">
        <span class="type-badge type-{{ $report->type }}">{{ $report->typeBadge() }}</span>
        <h1>{{ $report->title }}</h1>
        <div class="report-meta">
            {{ $report->created_at->format('d \d\e F Y \à\s H:i') }}
            @if($report->user) · {{ $report->user->name }} @endif
        </div>
    </div>

    <div class="report-content">{{ $report->content }}</div>
</div>

</body>
</html>
