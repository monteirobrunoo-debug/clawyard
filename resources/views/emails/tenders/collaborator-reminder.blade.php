@php
    /**
     * Manual super-user reminder — lists all active, not-expired tenders
     * for one collaborator. Inline CSS, email-client safe.
     *
     * Mirrors the dark/green ClawYard identity used elsewhere but with a
     * yellow "LEMBRETE MANUAL" badge to distinguish from the red
     * auto-deadline alert and the green daily digest.
     */
    $appUrl = rtrim(config('app.url', 'https://clawyard.partyard.eu'), '/');
    $statusLabels = [
        \App\Models\Tender::STATUS_PENDING       => 'Pendente',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'Em Tratamento',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'Submetido',
        \App\Models\Tender::STATUS_AVALIACAO     => 'Em Avaliação',
    ];
    $urgencyPalette = [
        'overdue'  => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'bd' => '#fca5a5'],
        'critical' => ['bg' => '#fed7aa', 'fg' => '#9a3412', 'bd' => '#fdba74'],
        'urgent'   => ['bg' => '#fef3c7', 'fg' => '#92400e', 'bd' => '#fcd34d'],
        'soon'     => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'bd' => '#93c5fd'],
        'normal'   => ['bg' => '#f3f4f6', 'fg' => '#374151', 'bd' => '#d1d5db'],
        'unknown'  => ['bg' => '#f9fafb', 'fg' => '#6b7280', 'bd' => '#e5e7eb'],
    ];
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lembrete de concursos</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Helvetica Neue',Arial,sans-serif;">
<div style="max-width:680px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.08);">

    <div style="background:#0a0a0a;padding:24px 32px;">
        <div style="font-size:22px;font-weight:800;color:#76b900;letter-spacing:-0.5px;">
            🐾 ClawYard
            <span style="display:inline-block;background:#ca8a04;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;vertical-align:middle;">LEMBRETE MANUAL</span>
        </div>
        <div style="font-size:12px;color:#aaa;margin-top:4px;">
            {{ now()->setTimezone('Europe/Lisbon')->format('d/m/Y H:i') }} Lisboa
        </div>
    </div>
    <div style="height:3px;background:linear-gradient(90deg,#ca8a04,#f59e0b);"></div>

    <div style="padding:32px;">
        <p style="font-size:15px;color:#333;margin:0 0 12px;">
            Olá {{ $collaborator->name }},
        </p>
        <p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 20px;">
            {{ $sender->name }} pediu para te relembrar dos concursos activos que tens atribuídos.
            Estão listados abaixo por ordem de urgência.
        </p>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:20px;">
            <div style="font-size:13px;color:#92400e;">
                <strong>{{ $tenders->count() }}</strong> concurso{{ $tenders->count() === 1 ? '' : 's' }} activo{{ $tenders->count() === 1 ? '' : 's' }} — não inclui expirados (&gt;{{ \App\Models\Tender::OVERDUE_WINDOW_DAYS }} dias de atraso).
            </div>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f9fafb;color:#374151;text-align:left;">
                    <th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:700;">Referência</th>
                    <th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:700;">Título</th>
                    <th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:700;">Estado</th>
                    <th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:700;">SAP</th>
                    <th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:700;">Deadline</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tenders as $t)
                    @php
                        $bucket = $t->urgency_bucket ?? 'unknown';
                        $pal = $urgencyPalette[$bucket] ?? $urgencyPalette['unknown'];
                        $deadline = $t->deadline_at ? $t->deadline_at->setTimezone('Europe/Lisbon')->format('d/m/Y H:i') : '—';
                        $days = $t->days_to_deadline;
                    @endphp
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:8px 10px;vertical-align:top;font-family:Menlo,Consolas,monospace;font-size:12px;color:#374151;white-space:nowrap;">
                            {{ $t->reference }}
                        </td>
                        <td style="padding:8px 10px;vertical-align:top;">
                            <a href="{{ $appUrl }}/tenders/{{ $t->id }}" style="color:#4f46e5;text-decoration:none;">
                                {{ \Illuminate\Support\Str::limit($t->title, 80) }}
                            </a>
                        </td>
                        <td style="padding:8px 10px;vertical-align:top;color:#555;">
                            {{ $statusLabels[$t->status] ?? $t->status }}
                        </td>
                        <td style="padding:8px 10px;vertical-align:top;font-family:Menlo,Consolas,monospace;font-size:12px;">
                            @if($t->sap_opportunity_number)
                                <span style="color:#111;">{{ $t->sap_opportunity_number }}</span>
                            @else
                                <span style="color:#b45309;">⚠ falta</span>
                            @endif
                        </td>
                        <td style="padding:8px 10px;vertical-align:top;white-space:nowrap;">
                            <span style="display:inline-block;background:{{ $pal['bg'] }};color:{{ $pal['fg'] }};border:1px solid {{ $pal['bd'] }};border-radius:4px;padding:2px 6px;font-size:11px;font-weight:600;">
                                @if($bucket === 'overdue'){{ abs($days) }}d atraso
                                @elseif($days !== null){{ $days }}d
                                @else sem deadline
                                @endif
                            </span>
                            @if($t->deadline_at)
                                <div style="font-size:11px;color:#6b7280;margin-top:3px;">{{ $deadline }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:28px;padding:16px;background:#f9f9f9;border-radius:6px;text-align:center;">
            <a href="{{ $appUrl }}/tenders"
               style="display:inline-block;background:#76b900;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:700;">
                Abrir ClawYard →
            </a>
        </div>
    </div>

    <div style="background:#f9f9f9;border-top:1px solid #eee;padding:20px 32px;">
        <div style="font-size:13px;font-weight:700;color:#76b900;">🐾 ClawYard — IT Partyard</div>
        <div style="font-size:11px;color:#999;margin-top:4px;line-height:1.5;">
            Lembrete manual enviado por {{ $sender->name }} ({{ $sender->email }}).
            Se houver concursos que já trataste, marca-os como "Submetido" ou "Em Tratamento" no ClawYard para saírem da lista.
            <br>© PartYard/Setq.AI 2026
        </div>
    </div>
</div>
</body>
</html>
