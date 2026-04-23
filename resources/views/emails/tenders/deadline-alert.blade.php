@php
    /**
     * Single-tender deadline alert — inline CSS (email-client safe). Fires
     * ~24h before the tender's deadline, once per tender. Matches the
     * daily-digest.blade.php maritime-identity (dark header, green accent).
     */
    $appUrl = rtrim(config('app.url', 'https://clawyard.partyard.eu'), '/');
    $hoursLeft = $tender->deadline_at
        ? max(0, (int) now()->diffInHours($tender->deadline_at, false))
        : null;
    $statusLabels = [
        \App\Models\Tender::STATUS_PENDING       => 'Pendente',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'Em Tratamento',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'Submetido',
        \App\Models\Tender::STATUS_AVALIACAO     => 'Em Avaliação',
    ];
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lembrete de deadline</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Helvetica Neue',Arial,sans-serif;">
<div style="max-width:640px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.08);">

    <div style="background:#0a0a0a;padding:24px 32px;">
        <div style="font-size:22px;font-weight:800;color:#76b900;letter-spacing:-0.5px;">
            🐾 ClawYard
            <span style="display:inline-block;background:#b91c1c;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;vertical-align:middle;">LEMBRETE</span>
        </div>
        <div style="font-size:12px;color:#aaa;margin-top:4px;">
            {{ now()->setTimezone('Europe/Lisbon')->format('d/m/Y H:i') }} Lisboa
        </div>
    </div>
    <div style="height:3px;background:linear-gradient(90deg,#b91c1c,#7f1d1d);"></div>

    <div style="padding:32px;">
        <p style="font-size:15px;color:#333;margin:0 0 12px;">
            Olá {{ $recipientName }},
        </p>
        <p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 20px;">
            Este concurso tem deadline
            @if($hoursLeft !== null)
                <strong style="color:#b91c1c;">em cerca de {{ $hoursLeft }} hora{{ $hoursLeft === 1 ? '' : 's' }}</strong>.
            @else
                hoje.
            @endif
            É o último lembrete individual que vais receber antes da deadline.
        </p>

        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px;margin-bottom:16px;">
            <div style="font-size:11px;font-family:monospace;color:#991b1b;text-transform:uppercase;">
                {{ strtoupper($tender->source) }} · {{ $tender->reference }}
            </div>
            <div style="font-size:16px;font-weight:700;color:#111;margin-top:4px;">
                {{ $tender->title }}
            </div>
            <div style="font-size:13px;color:#555;margin-top:10px;line-height:1.7;">
                Estado: <strong>{{ $statusLabels[$tender->status] ?? $tender->status }}</strong><br>
                @if($tender->deadline_at)
                    🇵🇹 {{ $tender->deadline_lisbon->format('d/m/Y H:i') }} Lisboa<br>
                    🇱🇺 {{ $tender->deadline_luxembourg->format('d/m/Y H:i') }} Luxembourg<br>
                @endif
                @if($tender->sap_opportunity_number)
                    Nº SAP: <code>{{ $tender->sap_opportunity_number }}</code>
                @else
                    <span style="color:#b45309;">⚠ Ainda sem nº SAP</span>
                @endif
            </div>
        </div>

        @php $prompts = $tender->digestPrompts(); @endphp
        @if(!empty($prompts))
            <div style="margin-top:16px;">
                <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:8px;">
                    A fazer antes do prazo:
                </div>
                <ul style="margin:0;padding-left:18px;font-size:13px;color:#555;line-height:1.7;">
                    @foreach($prompts as $p)
                        <li>{{ $p }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div style="margin-top:28px;padding:16px;background:#f9f9f9;border-radius:6px;text-align:center;">
            <a href="{{ $appUrl }}/tenders/{{ $tender->id }}"
               style="display:inline-block;background:#76b900;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:700;">
                Abrir concurso →
            </a>
        </div>
    </div>

    <div style="background:#f9f9f9;border-top:1px solid #eee;padding:20px 32px;">
        <div style="font-size:13px;font-weight:700;color:#76b900;">🐾 ClawYard — IT Partyard</div>
        <div style="font-size:11px;color:#999;margin-top:4px;line-height:1.5;">
            Lembrete único enviado ~24h antes da deadline. Para desactivar, contacte o administrador.
            <br>© PartYard/Setq.AI 2026
        </div>
    </div>
</div>
</body>
</html>
