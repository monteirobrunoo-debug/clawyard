@php
    /**
     * Daily tender digest — inline CSS only (email clients strip <style>
     * selectors unpredictably). Matches the maritime.blade.php visual
     * identity (dark header, green accent, rounded wrapper).
     */
    $bucketLabels = [
        'overdue'  => ['Em atraso',    '#b91c1c', '#fee2e2'],
        'critical' => ['Críticos ≤3d', '#c2410c', '#ffedd5'],
        'urgent'   => ['Urgentes ≤7d', '#a16207', '#fef9c3'],
        'soon'     => ['Brevemente',   '#1e40af', '#dbeafe'],
        'normal'   => ['Normal',       '#374151', '#f3f4f6'],
        'unknown'  => ['Sem deadline', '#6b7280', '#f9fafb'],
    ];
    $statusLabels = [
        \App\Models\Tender::STATUS_PENDING       => 'Pendente',
        \App\Models\Tender::STATUS_EM_TRATAMENTO => 'Em Tratamento',
        \App\Models\Tender::STATUS_SUBMETIDO     => 'Submetido',
        \App\Models\Tender::STATUS_AVALIACAO     => 'Em Avaliação',
    ];
    $appUrl = rtrim(config('app.url', 'https://clawyard.partyard.eu'), '/');
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digest de concursos</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Helvetica Neue',Arial,sans-serif;">
<div style="max-width:720px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.08);">

    <div style="background:#0a0a0a;padding:24px 32px;">
        <div style="font-size:22px;font-weight:800;color:#76b900;letter-spacing:-0.5px;">
            🐾 ClawYard
            <span style="display:inline-block;background:#76b900;color:#000;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;vertical-align:middle;">CONCURSOS</span>
        </div>
        <div style="font-size:12px;color:#aaa;margin-top:4px;">
            {{ $slot === 'morning' ? 'Digest matinal' : 'Digest final de tarde' }}
            · {{ now()->setTimezone('Europe/Lisbon')->format('d/m/Y H:i') }} Lisboa
        </div>
    </div>
    <div style="height:3px;background:linear-gradient(90deg,#76b900,#4a7300);"></div>

    <div style="padding:32px;">
        <p style="font-size:15px;color:#333;margin:0 0 12px;">
            Olá {{ $recipient->name }},
        </p>
        <p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 20px;">
            @if($role === 'manager')
                Resumo de todos os concursos activos que precisam de seguimento
                ({{ $total }} no total). Os itens em atraso ou sem nº SAP têm prioridade.
            @else
                Tem {{ $total }} concurso{{ $total === 1 ? '' : 's' }} activo{{ $total === 1 ? '' : 's' }}
                atribuído{{ $total === 1 ? '' : 's' }} a si que precisa{{ $total === 1 ? '' : 'm' }} de actualização.
                Passe por cada um e registe o ponto de situação.
            @endif
        </p>

        @foreach($groups as $bucketKey => $rows)
            @php
                [$label, $textColor, $bg] = $bucketLabels[$bucketKey] ?? $bucketLabels['unknown'];
            @endphp
            <div style="margin-top:24px;">
                <div style="background:{{ $bg }};color:{{ $textColor }};padding:8px 14px;border-radius:6px;font-size:13px;font-weight:700;display:inline-block;">
                    {{ $label }} · {{ $rows->count() }}
                </div>

                <table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin-top:10px;">
                    @foreach($rows as $t)
                        <tr>
                            <td style="padding:12px 0;border-bottom:1px solid #eee;vertical-align:top;">
                                <div style="font-size:11px;color:#888;font-family:monospace;">
                                    {{ strtoupper($t->source) }} · {{ $t->reference }}
                                </div>
                                <div style="font-size:14px;color:#111;margin-top:2px;font-weight:600;">
                                    <a href="{{ $appUrl }}/tenders/{{ $t->id }}"
                                       style="color:#0369a1;text-decoration:none;">
                                        {{ \Illuminate\Support\Str::limit($t->title, 110) }}
                                    </a>
                                </div>
                                <div style="font-size:12px;color:#666;margin-top:4px;">
                                    @if($t->collaborator)
                                        👤 {{ $t->collaborator->name }} ·
                                    @endif
                                    {{ $statusLabels[$t->status] ?? $t->status }}
                                    @if($t->deadline_at)
                                        · 🇵🇹 {{ $t->deadline_lisbon->format('d/m H:i') }}
                                        · 🇱🇺 {{ $t->deadline_luxembourg->format('d/m H:i') }}
                                        @if($t->days_to_deadline !== null)
                                            @if($t->days_to_deadline < 0)
                                                · <span style="color:#b91c1c;font-weight:600;">{{ abs($t->days_to_deadline) }}d atraso</span>
                                            @else
                                                · <span style="color:#1e40af;">{{ $t->days_to_deadline }}d</span>
                                            @endif
                                        @endif
                                    @endif
                                </div>

                                @php $prompts = $t->digestPrompts(); @endphp
                                @if(!empty($prompts))
                                    <ul style="margin:8px 0 0 18px;padding:0;font-size:12px;color:#92400e;line-height:1.55;">
                                        @foreach($prompts as $p)
                                            <li style="margin-bottom:2px;">{{ $p }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach

        <div style="margin-top:32px;padding:16px;background:#f9f9f9;border-radius:6px;">
            <a href="{{ $appUrl }}/tenders"
               style="display:inline-block;background:#76b900;color:#000;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:700;">
                Abrir dashboard →
            </a>
        </div>
    </div>

    <div style="background:#f9f9f9;border-top:1px solid #eee;padding:20px 32px;">
        <div style="font-size:13px;font-weight:700;color:#76b900;">🐾 ClawYard — IT Partyard</div>
        <div style="font-size:11px;color:#999;margin-top:4px;line-height:1.5;">
            Este email foi enviado automaticamente porque tem concursos activos atribuídos
            ou supervisiona a equipa. Para deixar de receber, contacte o administrador.
            <br>© PartYard/Setq.AI 2026
        </div>
    </div>
</div>
</body>
</html>
