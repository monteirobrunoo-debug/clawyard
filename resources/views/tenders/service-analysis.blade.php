<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Análise do Serviço · {{ $tender->reference ?: '#'.$tender->id }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f7f8fa; color: #1f2937;
            margin: 0; padding: 0;
        }
        .doc-wrap { max-width: 880px; margin: 0 auto; padding: 32px 24px 80px; }
        .toolbar {
            position: sticky; top: 0; z-index: 50;
            background: #fff; border-bottom: 1px solid #e5e7eb;
            padding: 12px 24px; display: flex; gap: 8px; align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .toolbar a, .toolbar button {
            font-size: 13px; padding: 7px 14px; border-radius: 8px;
            border: 1px solid #d1d5db; background: #fff; color: #374151;
            text-decoration: none; cursor: pointer; transition: all 0.15s;
        }
        .toolbar a:hover, .toolbar button:hover { background: #f3f4f6; }
        .toolbar .primary { background: #4f46e5; color: #fff; border-color: #4f46e5; }
        .toolbar .primary:hover { background: #4338ca; }
        .toolbar .meta { margin-left: auto; font-size: 11px; color: #6b7280; }

        h1 { font-size: 28px; font-weight: 800; line-height: 1.2; margin: 24px 0 8px; color: #0f172a; }
        .subtitle { font-size: 14px; color: #6b7280; margin-bottom: 24px; }
        .meta-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px; padding: 16px; background: #fff;
            border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 28px;
        }
        .meta-grid div { font-size: 12px; }
        .meta-grid label { display: block; font-size: 10px; color: #6b7280; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 700; margin-bottom: 2px; }

        .exec-summary {
            background: linear-gradient(135deg, #eff6ff 0%, #fff 100%);
            border-left: 4px solid #4f46e5;
            padding: 18px 22px; border-radius: 10px; margin-bottom: 32px;
            font-size: 13px; line-height: 1.65;
        }
        .exec-summary strong { color: #0f172a; }
        .exec-summary h1, .exec-summary h2, .exec-summary h3 { color: #0f172a; margin: 14px 0 7px; line-height: 1.3; }
        .exec-summary h1 { font-size: 17px; } .exec-summary h2 { font-size: 15px; } .exec-summary h3 { font-size: 14px; }
        .exec-summary p { margin: 0 0 10px; }
        .exec-summary ul, .exec-summary ol { margin: 0 0 10px; padding-left: 20px; }
        .exec-summary li { margin-bottom: 4px; }
        .exec-summary a { color: #4f46e5; }
        .exec-summary blockquote { margin: 0 0 10px; padding: 6px 12px; border-left: 3px solid #76b900; color: #475569; }
        .exec-summary code { background: #e0e7ff; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
        .exec-summary table { border-collapse: collapse; width: 100%; margin: 8px 0; }
        .exec-summary th, .exec-summary td { border: 1px solid #c7d2fe; padding: 6px 10px; text-align: left; font-size: 12px; }
        .exec-summary th { background: #e0e7ff; }

        h2.section-title {
            font-size: 18px; font-weight: 700; margin: 36px 0 14px;
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 8px; border-bottom: 2px solid #e5e7eb;
        }
        h2.section-title .agent-badge {
            font-size: 11px; padding: 3px 9px; border-radius: 999px;
            background: #fff; border: 1px solid; font-weight: 600;
        }

        .agent-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 20px; margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .agent-summary {
            font-size: 14px; color: #1f2937; line-height: 1.6;
            padding: 12px 14px; background: #f9fafb; border-radius: 6px;
            margin-bottom: 14px; border-left: 3px solid;
        }

        .panel-row {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 14px; margin-top: 12px;
        }
        @media (max-width: 720px) { .panel-row { grid-template-columns: 1fr; } }

        .panel {
            border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px;
            background: #fafbfc;
        }
        .panel-title {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
            font-weight: 700; color: #6b7280; margin-bottom: 8px;
            display: flex; align-items: center; gap: 5px;
        }
        .panel ul { margin: 0; padding-left: 18px; font-size: 12.5px; line-height: 1.55; color: #374151; }
        .panel li { margin-bottom: 4px; }
        .panel.risks { border-color: #fecaca; background: #fef2f2; }
        .panel.risks .panel-title { color: #b91c1c; }
        .panel.recos { border-color: #bbf7d0; background: #f0fdf4; }
        .panel.recos .panel-title { color: #15803d; }

        .footnotes {
            margin-top: 12px; display: flex; gap: 14px; flex-wrap: wrap;
            font-size: 11px; color: #6b7280;
        }
        .footnotes .badge {
            padding: 2px 8px; border-radius: 4px; background: #f3f4f6;
            border: 1px solid #e5e7eb;
        }
        .footnotes .compliance { background: #fef3c7; border-color: #fde68a; color: #92400e; font-weight: 600; }

        .footer {
            margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb;
            font-size: 11px; color: #9ca3af; text-align: center;
        }

        /* ── Print stylesheet — Cmd+P / Ctrl+P → Save as PDF ──── */
        @media print {
            .toolbar { display: none !important; }
            .doc-wrap { max-width: 100%; padding: 0 !important; }
            body { background: #fff; }
            .agent-card { page-break-inside: avoid; }
            h2.section-title { page-break-after: avoid; }
            .exec-summary { page-break-after: avoid; }
            a { color: #1f2937 !important; text-decoration: none !important; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a href="{{ route('tenders.show', $tender) }}">← Voltar ao concurso</a>
    {{-- 2026-05-18: PDF é agora um endpoint server-side com dompdf
         (cria o ficheiro + anexa-o ao concurso). O window.print() fica
         como fallback / vista rápida sem persistência. --}}
    <a class="primary" href="{{ route('tenders.service-analysis.pdf', $tender) }}"
       title="Gerar PDF e anexá-lo automaticamente ao concurso">
        📄 Guardar PDF como anexo
    </a>
    <button onclick="window.print()" title="Apenas vista rápida — não fica guardada">
        🖨 Imprimir (rápido)
    </button>
    <form method="POST" action="{{ route('tenders.service-analysis.sync-todo', $tender) }}" style="display:inline">
        @csrf
        <button type="submit"
                title="Mete o plano de acção no campo Notas → sincroniza com SAP Opportunity Remarks"
                style="background:#10b981;color:#fff;border-color:#10b981;">
            🔄 Sincronizar to-do → SAP
        </button>
    </form>
    <form method="POST" action="{{ route('tenders.service-analysis.generate', $tender) }}" style="display:inline">
        @csrf
        <input type="hidden" name="force" value="1">
        <button type="submit">↻ Re-correr análise</button>
    </form>
    <span class="meta">
        Gerada {{ $analysis->generated_at?->diffForHumans() ?? '—' }}
        @if($analysis->generatedBy) por {{ $analysis->generatedBy->name }} @endif
        · {{ count($analysis->agents_consulted ?? []) }} agentes
        · ${{ number_format((float) $analysis->total_cost_usd, 4) }}
    </span>
</div>

<div class="doc-wrap">

    <h1>📋 Análise do Serviço</h1>
    <div class="subtitle">{{ $tender->title }}</div>

    <div class="meta-grid">
        <div>
            <label>Concurso</label>
            <strong>{{ $tender->reference ?: '#'.$tender->id }}</strong>
        </div>
        <div>
            <label>Fonte</label>
            {{ $tender->source }}
        </div>
        <div>
            <label>Organização</label>
            {{ $tender->purchasing_org ?: '—' }}
        </div>
        <div>
            <label>Deadline</label>
            {{ $tender->deadline_at?->format('d/m/Y') ?? '—' }}
        </div>
        @if($tender->sap_opportunity_number)
        <div>
            <label>Oportunidade SAP</label>
            <strong>#{{ $tender->sap_opportunity_number }}</strong>
        </div>
        @endif
    </div>

    @if($analysis->executive_summary)
    <div class="exec-summary">
        {!! \App\Support\Markdown::toHtml($analysis->executive_summary) !!}
    </div>
    @endif

    {{-- 2026-05-18: To-do consolidado de TODOS os agentes — vista
         de checkbox para o operador marcar progresso + esta é a base
         do que vai para tender.notes → SAP Remarks. --}}
    @php $actionItems = $analysis->extractActionItems(); @endphp
    @if(!empty($actionItems))
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 22px;margin-bottom:32px;border-left:4px solid #10b981;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
            <h2 style="margin:0;font-size:16px;font-weight:700;color:#0f172a;">
                📋 Plano de acção consolidado
                <span style="font-size:11px;font-weight:500;color:#6b7280;margin-left:6px;">
                    {{ count($actionItems) }} passos · {{ count($analysis->agents_consulted ?? []) }} agentes
                </span>
            </h2>
            @if($tender->sap_opportunity_number)
                <span style="font-size:11px;color:#10b981;font-weight:600;">
                    Sincroniza para SAP Opp #{{ $tender->sap_opportunity_number }}
                </span>
            @else
                <span style="font-size:11px;color:#f59e0b;font-weight:500;">
                    Sem Nº Oportunidade SAP — só guarda local
                </span>
            @endif
        </div>
        <ol style="margin:0;padding-left:20px;font-size:13px;line-height:1.7;color:#374151;">
            @foreach($actionItems as $i => $it)
                <li style="margin:4px 0;">
                    <span>{{ $it['text'] }}</span>
                    <span style="display:inline-block;margin-left:8px;font-size:10px;padding:2px 8px;border-radius:999px;background:rgba(79,70,229,0.08);color:#4f46e5;font-weight:600;text-transform:lowercase;">
                        {{ $it['emoji'] }} {{ $it['agent_name'] }}
                    </span>
                </li>
            @endforeach
        </ol>
    </div>
    @endif

    <h2 class="section-title">🎯 Análise por especialista</h2>

    @foreach(($analysis->sections ?? []) as $key => $sec)
        @php
            $color = $sec['agent_color'] ?? '#76b900';
        @endphp
        <div class="agent-card">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <span style="font-size:24px">{{ $sec['agent_emoji'] ?? '🤖' }}</span>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#0f172a;">
                        {{ $sec['agent_name'] ?? $key }}
                    </div>
                    <div style="font-size:11px; color:#6b7280;">{{ ucfirst($key) }}</div>
                </div>
            </div>

            @if(!empty($sec['summary']))
            <div class="agent-summary" style="border-left-color:{{ $color }}">
                <em>{{ $sec['summary'] }}</em>
            </div>
            @endif

            @if(!empty($sec['key_points']))
            <div class="panel" style="margin-bottom:10px;">
                <div class="panel-title">📌 Pontos-chave</div>
                <ul>
                    @foreach($sec['key_points'] as $p)
                        <li>{{ $p }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="panel-row">
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
            </div>

            <div class="footnotes">
                @if(!empty($sec['lead_time']))
                    <span class="badge">⏱ Lead time: {{ $sec['lead_time'] }}</span>
                @endif
                @foreach(($sec['compliance'] ?? []) as $flag)
                    <span class="badge compliance">🛡 {{ $flag }}</span>
                @endforeach
                @if(!empty($sec['iterations']) && $sec['iterations'] > 0)
                    <span class="badge" style="background:#eef;color:#558;border-color:#bbe;">
                        🔄 {{ $sec['iterations'] }} iter
                    </span>
                @endif
            </div>

            {{-- 2026-05-20 (#65): tool trace do agente autónomo. Se houver
                 tool_use durante a análise, mostramos as chamadas em
                 detalhe colapsável para transparência. --}}
            @if(!empty($sec['tool_trace']))
                <details class="tool-trace" style="margin-top:8px;padding:6px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;">
                    <summary style="cursor:pointer;color:#475569;font-weight:600;">
                        🔧 {{ count($sec['tool_trace']) }} tool call(s) — clica para ver raciocínio
                    </summary>
                    <ol style="margin:8px 0 0 18px;padding:0;color:#334155;">
                        @foreach($sec['tool_trace'] as $tc)
                            <li style="margin-bottom:6px;">
                                <strong>{{ $tc['tool'] ?? '?' }}</strong>
                                @if(!empty($tc['ok']))
                                    <span style="color:#16a34a;">✓</span>
                                @else
                                    <span style="color:#dc2626;">✗</span>
                                @endif
                                <span style="color:#94a3b8;">· {{ $tc['ms'] ?? 0 }}ms</span>
                                @if(!empty($tc['input']))
                                    <div style="color:#64748b;font-family:monospace;font-size:10px;margin-top:2px;">
                                        input: {{ \Illuminate\Support\Str::limit(json_encode($tc['input'], JSON_UNESCAPED_UNICODE), 120) }}
                                    </div>
                                @endif
                                @if(!empty($tc['output']))
                                    <div style="color:#475569;margin-top:2px;white-space:pre-wrap;">
                                        {{ \Illuminate\Support\Str::limit($tc['output'], 280) }}
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </details>
            @endif
        </div>
    @endforeach

    <div class="footer">
        ClawYard · HP-Group · Análise gerada por agentes IA · Documento interno
    </div>

</div>

</body>
</html>
