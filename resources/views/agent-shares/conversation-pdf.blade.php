{{-- ═══════════════════════════════════════════════════════════════════════
     Export PDF de uma conversa do agent share — pedido directo do operador:
     "o user tem de conseguir aceder ao histórico de conversas e o LLM
      também põe uma barra ao lado com os pdf de conversas"
     2026-05-18 — gerado via dompdf
     ═══════════════════════════════════════════════════════════════════════ --}}
@php
    $agentName  = $agentMeta['name']  ?? $share->agent_key;
    $agentEmoji = $agentMeta['emoji'] ?? '🤖';
    $agentColor = $agentMeta['color'] ?? '#76b900';
    $title      = $conversation->title ?: 'Conversa #' . $conversation->id;
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.6; margin: 0; }

        .corp-header {
            background: {{ $agentColor }}; color: #ffffff;
            padding: 10px 14px; margin: -2px -2px 16px;
            display: table; width: 100%;
        }
        .corp-header .left  { display: table-cell; vertical-align: middle; font-size: 14px; font-weight: 700; }
        .corp-header .right { display: table-cell; vertical-align: middle; text-align: right; font-size: 9px; color: rgba(255,255,255,.85); }

        .meta-block { margin: 0 0 16px; font-size: 9.5px; color: #6b7280; }
        .meta-block .row { margin: 1px 0; }
        .meta-block strong { color: #1f2937; }

        hr.divider { border: 0; border-top: 1px solid #cbd5e1; margin: 12px 0 16px; }

        .turn { margin: 0 0 14px; page-break-inside: avoid; }
        .turn .head {
            font-weight: 700; font-size: 11px; margin-bottom: 4px;
            padding-bottom: 3px; border-bottom: 1px dashed #cbd5e1;
        }
        .turn.user .head      { color: #2563eb; }
        .turn.assistant .head { color: #0f1b4c; }
        .turn .ts { float: right; font-weight: 400; color: #9ca3af; font-size: 9px; }
        .turn .body {
            padding: 6px 10px;
            background: #f8fafc;
            border-left: 3px solid #e2e8f0;
            border-radius: 2px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 10px;
            line-height: 1.55;
        }
        .turn.user .body      { background: #eff6ff; border-left-color: #2563eb; }
        .turn.assistant .body { background: #f1f5f9; border-left-color: {{ $agentColor }}; }

        .empty {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-style: italic;
            font-size: 11px;
        }

        .corp-footer {
            position: fixed; bottom: -10mm; left: 0; right: 0;
            font-size: 7.5px; color: #6b7280;
            border-top: 1px solid #e2e8f0; padding-top: 4px;
            text-align: left; padding-left: 14mm; padding-right: 14mm;
        }
        .corp-footer .right { float: right; }
    </style>
</head>
<body>

<div class="corp-header">
    <div class="left">{{ $agentEmoji }} {{ $agentName }} — {{ $title }}</div>
    <div class="right">
        ClawYard · {{ $now->format('Y-m-d H:i') }}<br>
        Share #{{ $share->id }}
    </div>
</div>

<div class="meta-block">
    <div class="row"><strong>Cliente:</strong> {{ $share->client_name }} @if($share->client_email) &lt;{{ $share->client_email }}&gt; @endif</div>
    <div class="row"><strong>Agente:</strong> {{ $agentName }} · <strong>Mensagens:</strong> {{ $messages->count() }} · <strong>Início:</strong> {{ $conversation->created_at?->format('Y-m-d H:i') }}</div>
</div>

<hr class="divider">

@if($messages->isEmpty())
    <div class="empty">Esta conversa ainda não tem mensagens.</div>
@else
    @foreach($messages as $m)
        @php
            $role = $m->role === 'user' ? 'user' : 'assistant';
            $label = $role === 'user' ? '👤 ' . ($share->client_name ?: 'Cliente') : $agentEmoji . ' ' . $agentName;
            // Strip structured tokens para legibilidade no PDF
            $content = (string) $m->content;
            $content = preg_replace('/__(TABLE|CHART|PPT|EMAIL)__\{.*?\}(?=[\s\n]|$)/s', '[bloco estruturado: $1]', $content) ?? $content;
        @endphp
        <div class="turn {{ $role }}">
            <div class="head">
                {{ $label }}
                <span class="ts">{{ $m->created_at?->format('Y-m-d H:i') }}</span>
            </div>
            <div class="body">{{ $content }}</div>
        </div>
    @endforeach
@endif

<div class="corp-footer">
    ClawYard · Share #{{ $share->id }} · Conversa exportada em {{ $now->format('Y-m-d H:i') }} · {{ $messages->count() }} mensagens
    <span class="right">www.clawyard.partyard.eu</span>
</div>

</body>
</html>
