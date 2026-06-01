{{-- ═══════════════════════════════════════════════════════════════════════
     PDF-friendly version of the multi-agent tender analysis.
     Rendered by barryvdh/laravel-dompdf — NO @vite (dompdf não suporta JS
     ou CSS externo via vite). Tudo inline, fontes default do PDF, e cores
     em hex para máxima compatibilidade com dompdf.

     Usado por TenderServiceAnalysisController::pdf() que:
       1. Renderiza esta view
       2. PDF::loadView() → bytes
       3. Salva como TenderAttachment (file_hash + dedup)
       4. Anexa ao concurso na tabela de Anexos
       5. Devolve o ficheiro como download
     ═══════════════════════════════════════════════════════════════════════ --}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Análise do Serviço · {{ $tender->reference ?: '#'.$tender->id }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; line-height: 1.5; margin: 0; }
        h1 { font-size: 20px; color: #0f172a; margin: 0 0 6px; }
        .subtitle { font-size: 12px; color: #6b7280; margin-bottom: 16px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10px; }
        .meta-table td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .meta-table td.label { color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; width: 25%; }
        .exec {
            background: #eff6ff; border-left: 3px solid #4f46e5;
            padding: 12px 14px; margin-bottom: 18px; font-size: 11px;
        }
        .exec h1, .exec h2, .exec h3 { color: #0f172a; margin: 8px 0 5px; line-height: 1.3; border: 0; padding: 0; }
        .exec h1 { font-size: 13px; } .exec h2 { font-size: 12px; } .exec h3 { font-size: 11px; }
        .exec p { margin: 0 0 7px; }
        .exec ul, .exec ol { margin: 0 0 7px; padding-left: 16px; }
        .exec li { margin-bottom: 3px; }
        .exec table { border-collapse: collapse; width: 100%; margin: 6px 0; }
        .exec th, .exec td { border: 1px solid #c7d2fe; padding: 4px 7px; text-align: left; font-size: 10px; }
        .exec th { background: #e0e7ff; }
        .exec code { background: #e0e7ff; padding: 1px 3px; font-size: 10px; }
        h2 {
            font-size: 13px; margin: 22px 0 10px 0; padding-bottom: 4px;
            border-bottom: 2px solid #e5e7eb; color: #0f172a;
        }
        .action-block {
            background: #f0fdf4; border-left: 3px solid #10b981;
            padding: 12px 14px; margin-bottom: 18px;
        }
        .action-block h3 { margin: 0 0 8px; font-size: 12px; color: #065f46; }
        .action-list { margin: 0; padding-left: 18px; }
        .action-list li { margin: 4px 0; }
        .agent-tag {
            display: inline-block; margin-left: 6px; font-size: 9px;
            padding: 1px 6px; border-radius: 8px;
            background: #ede9fe; color: #5b21b6;
        }

        .agent-card {
            border: 1px solid #e5e7eb; border-radius: 6px;
            padding: 10px 12px; margin-bottom: 12px; page-break-inside: avoid;
        }
        .agent-card .agent-head { font-weight: 700; font-size: 12px; color: #0f172a; margin-bottom: 6px; }
        .agent-card .agent-key { font-size: 9px; color: #6b7280; font-weight: 500; }
        .agent-card .agent-summary { font-style: italic; color: #4b5563; margin: 6px 0; font-size: 10px; }
        .agent-card .panel { margin-top: 6px; }
        .agent-card .panel-title { font-weight: 700; font-size: 10px; color: #374151; margin-bottom: 2px; }
        .agent-card .panel ul { margin: 0; padding-left: 16px; }
        .agent-card .panel li { margin: 2px 0; font-size: 10px; }
        .agent-card .risks .panel-title { color: #b91c1c; }
        .agent-card .recos .panel-title { color: #047857; }

        .footer {
            position: fixed; bottom: -10mm; left: 0; right: 0;
            text-align: center; font-size: 9px; color: #9ca3af;
        }
    </style>
</head>
<body>

<h1>📋 Análise do Serviço</h1>
<div class="subtitle">{{ $tender->title }}</div>

<table class="meta-table">
    <tr>
        <td class="label">Concurso</td>
        <td><strong>{{ $tender->reference ?: '#'.$tender->id }}</strong></td>
    </tr>
    <tr>
        <td class="label">Fonte</td>
        <td>{{ strtoupper($tender->source) }}</td>
    </tr>
    <tr>
        <td class="label">Organização</td>
        <td>{{ $tender->purchasing_org ?: '—' }}</td>
    </tr>
    <tr>
        <td class="label">Deadline</td>
        <td>{{ $tender->deadline_at?->format('d/m/Y') ?? '—' }}</td>
    </tr>
    @if($tender->sap_opportunity_number)
    <tr>
        <td class="label">Nº Oportunidade SAP</td>
        <td><strong>#{{ $tender->sap_opportunity_number }}</strong></td>
    </tr>
    @endif
    <tr>
        <td class="label">Análise gerada</td>
        <td>
            {{ $analysis->generated_at?->format('d/m/Y H:i') ?? '—' }}
            @if($analysis->generatedBy) por {{ $analysis->generatedBy->name }} @endif
            · {{ count($analysis->agents_consulted ?? []) }} agentes consultados
        </td>
    </tr>
</table>

@if($analysis->executive_summary)
    <div class="exec">
        <strong>Resumo executivo:</strong>
        {!! \App\Support\Markdown::toHtml($analysis->executive_summary) !!}
    </div>
@endif

@php $actionItems = $analysis->extractActionItems(); @endphp
@if(!empty($actionItems))
<div class="action-block">
    <h3>📋 Plano de acção ({{ count($actionItems) }} passos consolidados)</h3>
    <ol class="action-list">
        @foreach($actionItems as $it)
            <li>
                {{ $it['text'] }}
                <span class="agent-tag">{{ $it['agent_name'] }}</span>
            </li>
        @endforeach
    </ol>
</div>
@endif

<h2>🎯 Análise por especialista</h2>

@foreach(($analysis->sections ?? []) as $key => $sec)
    <div class="agent-card">
        <div class="agent-head">
            {{ $sec['agent_emoji'] ?? '🤖' }} {{ $sec['agent_name'] ?? $key }}
            <span class="agent-key">· {{ $key }}</span>
        </div>

        @if(!empty($sec['summary']))
            <div class="agent-summary">{{ $sec['summary'] }}</div>
        @endif

        @if(!empty($sec['key_points']))
        <div class="panel">
            <div class="panel-title">📌 Pontos-chave</div>
            <ul>
                @foreach($sec['key_points'] as $p)
                    <li>{{ $p }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if(!empty($sec['risks']))
        <div class="panel risks">
            <div class="panel-title">⚠ Riscos</div>
            <ul>
                @foreach($sec['risks'] as $r)
                    <li>{{ $r }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if(!empty($sec['recommendations']))
        <div class="panel recos">
            <div class="panel-title">✅ Recomendações</div>
            <ul>
                @foreach($sec['recommendations'] as $r)
                    <li>{{ $r }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if(!empty($sec['lead_time']) || !empty($sec['compliance']))
        <div style="margin-top:6px;font-size:10px;color:#6b7280;">
            @if(!empty($sec['lead_time']))
                ⏱ Lead time: {{ $sec['lead_time'] }}
            @endif
            @foreach(($sec['compliance'] ?? []) as $flag)
                · 🛡 {{ $flag }}
            @endforeach
        </div>
        @endif
    </div>
@endforeach

<div class="footer">
    ClawYard · HP-Group · Análise gerada por agentes IA · Documento interno · {{ $analysis->generated_at?->format('d/m/Y H:i') }}
</div>

</body>
</html>
