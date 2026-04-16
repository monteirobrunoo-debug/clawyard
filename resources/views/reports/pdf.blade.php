<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>{{ $report->title }}</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; color:#111; background:#fff; padding:40px 48px; font-size:12px; line-height:1.7; }

        .pdf-header { border-bottom:3px solid {{ $report->typeColor() }}; padding-bottom:16px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-end; }
        .pdf-logo { font-size:20px; font-weight:900; color:{{ $report->typeColor() }}; letter-spacing:-0.5px; display:flex; align-items:center; gap:8px; }
        .pdf-logo span { color:#333; font-weight:400; font-size:13px; }
        .pdf-logo .wave-icon { flex-shrink:0; }
        .pdf-meta { text-align:right; font-size:11px; color:#666; }

        .type-badge { display:inline-block; background:#f0f0f0; border:1px solid #ccc; padding:2px 10px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; color:#333; }
        h1 { font-size:18px; font-weight:700; color:#111; margin-bottom:6px; line-height:1.3; }
        .meta-line { font-size:11px; color:#888; margin-bottom:24px; }

        .content { font-size:12px; line-height:1.8; color:#222; white-space:pre-wrap; word-break:break-word; }
        .content h2 { font-size:14px; font-weight:700; color:{{ $report->typeColor() }}; margin:20px 0 8px; border-bottom:1px solid #e5e5e5; padding-bottom:4px; }
        .content h3 { font-size:12px; font-weight:700; color:#555; margin:14px 0 6px; }
        .content strong { font-weight:700; }
        .content hr { border:none; border-top:1px solid #ddd; margin:12px 0; }
        .content code { background:#f5f5f5; border:1px solid #ddd; border-radius:3px; padding:0 4px; font-family:monospace; font-size:11px; }

        .pdf-footer { margin-top:40px; padding-top:12px; border-top:1px solid #ddd; display:flex; justify-content:space-between; font-size:10px; color:#aaa; }

        @media print {
            body { padding:20px 28px; }
            .no-print { display:none !important; }
        }
    </style>
</head>
<body>

<!-- Print button (hidden in print) -->
<div class="no-print" style="position:fixed;top:16px;right:16px;z-index:100;">
    <button onclick="window.print()" style="background:{{ $report->typeColor() }};color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">🖨️ Imprimir / Guardar PDF</button>
    <a href="/reports/{{ $report->id }}" style="margin-left:8px;background:#333;color:#e5e5e5;border:none;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;">← Voltar</a>
</div>

<div class="pdf-header">
    <div>
        <div class="pdf-logo">
            <svg class="wave-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 26" width="48" height="26">
                <path d="M2 13 Q7 3 12 13 Q17 23 22 13 Q27 3 32 13 Q37 23 42 13 Q45 8 46 10"
                      stroke="{{ $report->typeColor() }}" stroke-width="2.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M2 19 Q7 9 12 19 Q17 29 22 19 Q27 9 32 19 Q37 29 42 19 Q45 14 46 16"
                      stroke="{{ $report->typeColor() }}" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="0.4"/>
            </svg>
            ClawYard <span>/ IT Partyard</span>
        </div>
        <div style="font-size:10px;color:#888;margin-top:3px;">© PartYard/Setq.AI Rights reserved 2026</div>
    </div>
    <div class="pdf-meta">
        Gerado em {{ now()->format('d/m/Y H:i') }}<br>
        Confidencial — Uso Interno
    </div>
</div>

<div class="type-badge">{{ strtoupper($report->typeBadge()) }}</div>
<h1>{{ $report->title }}</h1>
<div class="meta-line">
    {{ $report->created_at->format('d \d\e F Y \à\s H:i') }}
    @if($report->user) · Criado por {{ $report->user->name }} @endif
</div>

<div class="content">{{ $report->content }}</div>

<div class="pdf-footer">
    <span>PartYard/Setq.AI Rights reserved 2026</span>
    <span>Relatório #{{ $report->id }} · {{ $report->created_at->format('d/m/Y') }}</span>
</div>

</body>
</html>
