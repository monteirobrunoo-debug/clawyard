@php
    $u  = $digest['user'];
    $w  = $digest['week'];
    $c  = $digest['core'];
    $a  = $digest['agents'];
    $s  = $digest['stats'];
    $tc = $digest['top_categories'] ?? [];
    $ts = $digest['top_suppliers']  ?? [];
    $cost = $digest['cost'] ?? ['week_usd' => 0, 'prev_usd' => 0, 'month_usd' => 0];
    $td = $digest['todos'];
    $intel = $digest['intel'] ?? ['discoveries' => [], 'orphan_matches' => []];
    $teamCmp = $digest['team_compare'] ?? [];
    $r  = $digest['rewards'];
    $m  = $digest['manager'];
    $appUrl = config('app.url', 'https://clawyard.partyard.eu');
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>ClawYard · Resumo semanal · {{ $w['start'] }}–{{ $w['end'] }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#1f2937;">
<center>
<table width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; width:100%; margin:24px 0;">

  {{-- ── HEADER ──────────────────────────────────────── --}}
  <tr><td style="background:#0f172a; padding:24px 28px; border-radius:10px 10px 0 0;">
    <div style="font-size:11px; color:#76b900; letter-spacing:1px; text-transform:uppercase; font-weight:700;">
      📊 Resumo semanal · {{ $w['start'] }}–{{ $w['end'] }}
    </div>
    <div style="font-size:22px; font-weight:800; color:#fff; margin-top:6px;">
      Olá, {{ explode(' ', $u['name'])[0] }} 👋
    </div>
    <div style="font-size:13px; color:#9ca3af; margin-top:4px;">
      O teu trabalho com a ClawYard esta semana, num só email.
    </div>
  </td></tr>

  {{-- ── CORE: 4 cards ─────────────────────────────────── --}}
  <tr><td style="background:#fff; padding:20px 28px;">
    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:12px;">🎯 Concursos esta semana</div>
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        @foreach([
            ['n' => $c['tenders_touched'],   'label' => 'Trabalhados', 'color' => '#4f46e5'],
            ['n' => $c['tenders_submitted'], 'label' => 'Submetidos',  'color' => '#0891b2'],
            ['n' => $c['tenders_won'],       'label' => 'Ganhos',      'color' => '#059669'],
            ['n' => $c['tenders_lost'],      'label' => 'Perdidos',    'color' => '#dc2626'],
        ] as $card)
        <td style="padding:8px 6px; text-align:center; border:1px solid #e5e7eb; background:#fafbfc; border-radius:8px;">
          <div style="font-size:24px; font-weight:800; color:{{ $card['color'] }};">{{ $card['n'] }}</div>
          <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px;">{{ $card['label'] }}</div>
        </td>
        @endforeach
      </tr>
    </table>

    @if(!empty($c['submitted_list']))
    <div style="margin-top:14px; font-size:12px;">
      <strong style="color:#0891b2;">📑 Propostas entregues:</strong>
      <ul style="margin:6px 0 0 18px; padding:0; line-height:1.6;">
        @foreach($c['submitted_list'] as $st)
          <li style="color:#374151;">
            <strong>{{ $st['reference'] ?: '#'.$st['id'] }}</strong> — {{ $st['title'] }}
            @if($st['sap']) <span style="color:#6b7280;">· SAP #{{ $st['sap'] }}</span>@endif
          </li>
        @endforeach
      </ul>
    </div>
    @endif
  </td></tr>

  {{-- ── AGENTES ─────────────────────────────────────── --}}
  <tr><td style="background:#fff; padding:0 28px 20px;">
    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:8px;">🤖 Agentes que usaste</div>
    <div style="font-size:12px; color:#6b7280; margin-bottom:10px;">
      {{ $a['conversations'] }} conversa(s) · {{ $a['total_messages'] }} mensagens recebidas
    </div>
    @if(!empty($a['top']))
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px;">
      @foreach($a['top'] as $i => $row)
      <tr>
        <td width="32" style="padding:4px 0; color:#9ca3af;">{{ $i+1 }}.</td>
        <td style="padding:4px 0; color:#1f2937;">
          <strong>{{ ucfirst($row['agent']) }}</strong>
          <span style="color:#6b7280;">· {{ $row['msgs'] }} msgs</span>
        </td>
        <td width="180" style="padding:4px 0;">
          <div style="background:#e5e7eb; border-radius:4px; height:6px; overflow:hidden;">
            <div style="background:#76b900; height:6px; width:{{ $row['pct'] }}%;"></div>
          </div>
        </td>
        <td width="40" style="padding:4px 0 4px 8px; color:#6b7280; text-align:right; font-variant-numeric:tabular-nums;">
          {{ $row['pct'] }}%
        </td>
      </tr>
      @endforeach
    </table>
    @else
      <div style="font-size:12px; color:#9ca3af; font-style:italic;">Nenhuma conversa esta semana.</div>
    @endif
  </td></tr>

  {{-- ── STATS extra ─────────────────────────────────── --}}
  <tr><td style="background:#f9fafb; padding:16px 28px; border-top:1px solid #e5e7eb;">
    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:10px;">📈 Mais números</div>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px; line-height:1.7;">
      <tr>
        <td><span style="color:#6b7280;">PDFs processados</span></td>
        <td style="text-align:right; font-weight:700;">{{ $s['pdfs_processed'] }}</td>
      </tr>
      <tr>
        <td><span style="color:#6b7280;">Análises multi-agente</span></td>
        <td style="text-align:right; font-weight:700;">{{ $s['service_analyses'] }}</td>
      </tr>
      <tr>
        <td><span style="color:#6b7280;">Oportunidades SAP criadas</span></td>
        <td style="text-align:right; font-weight:700;">{{ $s['sap_opps_created'] }}</td>
      </tr>
      <tr>
        <td><span style="color:#6b7280;">Dias activos</span></td>
        <td style="text-align:right; font-weight:700;">{{ $s['active_days'] }} / 7</td>
      </tr>
      <tr>
        <td><span style="color:#6b7280;">vs semana passada</span></td>
        <td style="text-align:right; font-weight:700; color:{{ $s['delta_pct'] >= 0 ? '#059669' : '#dc2626' }};">
          {{ $s['delta_pct'] > 0 ? '+' : '' }}{{ $s['delta_pct'] }}%
        </td>
      </tr>
    </table>
  </td></tr>

  {{-- ── 8. TOP CATEGORIAS H&P TRABALHADAS ─────────────── --}}
  @if(!empty($tc))
  <tr><td style="background:#fff; padding:16px 28px; border-top:1px solid #e5e7eb;">
    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:10px;">🏷 Top categorias trabalhadas</div>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px;">
      @foreach($tc as $i => $cat)
      <tr>
        <td width="32" style="padding:3px 0; color:#9ca3af;">{{ $i+1 }}.</td>
        <td style="padding:3px 0;"><strong>{{ $cat['label'] }}</strong>
          <span style="color:#6b7280;">· cat {{ $cat['code'] }}</span></td>
        <td width="60" style="padding:3px 0; text-align:right; color:#374151; font-weight:700;">
          {{ $cat['count'] }} concurso(s)
        </td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif

  {{-- ── 9. TOP FORNECEDORES CONTACTADOS ───────────────── --}}
  @if(!empty($ts))
  <tr><td style="background:#fff; padding:16px 28px; border-top:1px solid #e5e7eb;">
    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:10px;">🤝 Fornecedores mais contactados</div>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px;">
      @foreach($ts as $i => $sup)
      <tr>
        <td width="32" style="padding:3px 0; color:#9ca3af;">{{ $i+1 }}.</td>
        <td style="padding:3px 0;"><strong>{{ $sup['name'] }}</strong></td>
        <td width="60" style="padding:3px 0; text-align:right; color:#374151; font-weight:700;">
          {{ $sup['touches'] }} contacto(s)
        </td>
      </tr>
      @endforeach
    </table>
  </td></tr>
  @endif

  {{-- ── 14. CUSTO IA ──────────────────────────────────── --}}
  @if($cost['week_usd'] > 0 || $cost['month_usd'] > 0)
  <tr><td style="background:#f9fafb; padding:14px 28px; border-top:1px solid #e5e7eb;">
    <div style="font-size:12px; color:#6b7280;">
      💰 <strong>Custo IA</strong> ·
      semana <strong style="color:#1f2937;">${{ number_format($cost['week_usd'], 4) }}</strong>
      @if($cost['prev_usd'] > 0)
        <span style="color:#9ca3af;">(vs ${{ number_format($cost['prev_usd'], 4) }})</span>
      @endif
      · mês <strong style="color:#1f2937;">${{ number_format($cost['month_usd'], 4) }}</strong>
    </div>
  </td></tr>
  @endif

  {{-- ── 15-18. A FAZER ────────────────────────────────── --}}
  @if(!empty($td['upcoming_deadlines']) || $td['missing_sap_count'] > 0 || $td['pending_suppliers'] > 0 || !empty($td['overdue_recoverable']))
  <tr><td style="background:#fff; padding:20px 28px; border-top:1px solid #e5e7eb;">
    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:10px;">⏰ A não esquecer</div>

    @if(!empty($td['upcoming_deadlines']))
      <div style="font-size:12px; color:#6b7280; margin-bottom:6px;">Deadlines até 7 dias:</div>
      <ul style="margin:0 0 12px 18px; padding:0; line-height:1.6; font-size:12px;">
        @foreach($td['upcoming_deadlines'] as $up)
        <li>
          <a href="{{ $appUrl }}/tenders/{{ $up['id'] }}" style="color:#4f46e5; text-decoration:none;">
            <strong>{{ $up['reference'] ?: '#'.$up['id'] }}</strong>
          </a>
          — {{ $up['title'] }}
          <span style="color:{{ ($up['days'] ?? 99) <= 2 ? '#dc2626' : '#f59e0b' }};">
            · {{ $up['deadline'] }} ({{ $up['days'] }}d)
          </span>
        </li>
        @endforeach
      </ul>
    @endif

    @if(!empty($td['overdue_recoverable']))
      <div style="font-size:12px; color:#6b7280; margin-bottom:6px;">
        🚨 <strong style="color:#dc2626;">Concursos atrasados ainda recuperáveis:</strong>
      </div>
      <ul style="margin:0 0 12px 18px; padding:0; line-height:1.6; font-size:12px;">
        @foreach($td['overdue_recoverable'] as $od)
        <li>
          <a href="{{ $appUrl }}/tenders/{{ $od['id'] }}" style="color:#dc2626; text-decoration:none;">
            <strong>{{ $od['reference'] ?: '#'.$od['id'] }}</strong>
          </a>
          — {{ $od['title'] }}
          <span style="color:#dc2626;">· atrasado {{ $od['days_late'] }}d</span>
        </li>
        @endforeach
      </ul>
    @endif

    @if($td['missing_sap_count'] > 0)
      <div style="font-size:12px; color:#6b7280; margin-bottom:6px;">
        🆔 <strong>{{ $td['missing_sap_count'] }}</strong> concurso(s) sem nº oportunidade SAP
        @if(!empty($td['missing_sap_sample']))
          (ex: {{ implode(', ', $td['missing_sap_sample']) }})
        @endif
        — usa a Marta para criar.
      </div>
    @endif

    @if($td['pending_suppliers'] > 0)
      <div style="font-size:12px; color:#6b7280;">
        🏷 <strong>{{ $td['pending_suppliers'] }}</strong> fornecedor(es) PENDING aguardam validação em
        <a href="{{ $appUrl }}/suppliers-review" style="color:#4f46e5;">/suppliers-review</a>
        — aprová-los desbloqueia mais sugestões nos concursos.
      </div>
    @endif
  </td></tr>
  @endif

  {{-- ── 19+20. INTELIGÊNCIA: discoveries + concursos órfãos ── --}}
  @if(!empty($intel['discoveries']) || !empty($intel['orphan_matches']))
  <tr><td style="background:#eef2ff; padding:18px 28px; border-top:1px solid #c7d2fe;">
    <div style="font-size:13px; font-weight:700; color:#1e1b4b; margin-bottom:10px;">🔮 Inteligência para ti</div>

    @if(!empty($intel['discoveries']))
      <div style="font-size:12px; color:#3730a3; margin-bottom:6px;">Descobertas relevantes (arXiv / Patents últimos 14d):</div>
      <ul style="margin:0 0 12px 18px; padding:0; line-height:1.55; font-size:12px;">
        @foreach($intel['discoveries'] as $d)
        <li>
          @if($d['url'])<a href="{{ $d['url'] }}" style="color:#4f46e5; text-decoration:none;"><strong>{{ $d['title'] }}</strong></a>
          @else<strong>{{ $d['title'] }}</strong>@endif
          <span style="color:#6366f1; font-size:10px;">· {{ strtoupper($d['source']) }} · {{ $d['category'] }} · score {{ $d['score'] }}/10</span>
        </li>
        @endforeach
      </ul>
    @endif

    @if(!empty($intel['orphan_matches']))
      <div style="font-size:12px; color:#3730a3; margin-bottom:6px;">Concursos novos sem owner que matcham o teu perfil:</div>
      <ul style="margin:0; padding:0 0 0 18px; line-height:1.55; font-size:12px;">
        @foreach($intel['orphan_matches'] as $om)
        <li>
          <a href="{{ $appUrl }}/tenders/{{ $om['id'] }}" style="color:#4f46e5; text-decoration:none;">
            <strong>{{ $om['reference'] ?: '#'.$om['id'] }}</strong>
          </a>
          — {{ $om['title'] }}
        </li>
        @endforeach
      </ul>
    @endif
  </td></tr>
  @endif

  {{-- ── 21. COMPARAÇÃO ANONIMIZADA ────────────────────── --}}
  @if(!empty($teamCmp))
  <tr><td style="background:#f9fafb; padding:14px 28px; border-top:1px solid #e5e7eb;">
    <div style="font-size:12px; color:#6b7280;">
      🏅 <strong>Top 3 da equipa esta semana</strong> (mensagens enviadas, anónimo):
      @foreach($teamCmp as $tcRow)
        <span style="display:inline-block; margin-left:4px; padding:1px 6px; border-radius:4px; background:#e0e7ff; color:#3730a3; font-size:11px;">
          #{{ $tcRow['rank'] }} · {{ $tcRow['msgs'] }} msgs
        </span>
      @endforeach
    </div>
  </td></tr>
  @endif

  {{-- ── REWARDS ─────────────────────────────────────── --}}
  @if($r['points_this_week'] > 0 || $r['total_points'] > 0)
  <tr><td style="background:#fef3c7; padding:16px 28px; border-top:1px solid #fde68a;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td>
          <div style="font-size:13px; font-weight:700; color:#92400e;">🏆 Rewards</div>
          <div style="font-size:11px; color:#92400e; opacity:0.8;">
            <strong style="color:#92400e;">+{{ $r['points_this_week'] }}</strong> pontos esta semana
            · total <strong>{{ number_format($r['total_points']) }}</strong>
            · nível <strong>{{ $r['level'] }}</strong>
          </div>
        </td>
      </tr>
    </table>
  </td></tr>
  @endif

  {{-- ── MANAGER BLOCK (22-25) ───────────────────────── --}}
  @if($m && (($m['team_submitted'] ?? 0) > 0 || ($m['team_won'] ?? 0) > 0 || ($m['orphan_tenders'] ?? 0) > 0 || ($m['team_week_cost'] ?? 0) > 0 || !empty($m['down_integrations'] ?? [])))
  <tr><td style="background:#1e1b4b; color:#e0e7ff; padding:18px 28px; border-top:2px solid #4f46e5;">
    <div style="font-size:13px; font-weight:700; color:#fff;">👔 Visão de equipa</div>
    <div style="font-size:12px; line-height:1.7; margin-top:8px;">
      📑 Submetidos pela equipa: <strong style="color:#fff;">{{ $m['team_submitted'] ?? 0 }}</strong><br>
      🏆 Ganhos esta semana: <strong style="color:#fff;">{{ $m['team_won'] ?? 0 }}</strong><br>
      🆔 Concursos sem owner há +14 dias: <strong style="color:#fbbf24;">{{ $m['orphan_tenders'] ?? 0 }}</strong>
    </div>

    @if(($m['team_week_cost'] ?? 0) > 0 || ($m['team_month_cost'] ?? 0) > 0)
    <div style="font-size:12px; line-height:1.7; margin-top:10px; padding-top:10px; border-top:1px solid #4338ca;">
      💸 <strong>Custo LLM equipa</strong> ·
      semana <strong style="color:#fff;">${{ number_format($m['team_week_cost'] ?? 0, 4) }}</strong> ·
      mês <strong style="color:#fff;">${{ number_format($m['team_month_cost'] ?? 0, 4) }}</strong>
    </div>
    @endif

    @if(!empty($m['down_integrations'] ?? []))
    <div style="font-size:12px; line-height:1.7; margin-top:10px; padding:8px 12px; background:#7f1d1d; border-radius:6px;">
      🚨 <strong style="color:#fff;">Integrações em down:</strong>
      <span style="color:#fca5a5;">{{ implode(', ', $m['down_integrations']) }}</span>
      — verifica em <a href="{{ $appUrl }}/admin/panel" style="color:#fbbf24;">/admin/panel</a>
    </div>
    @endif
  </td></tr>
  @endif

  {{-- ── CTA + footer ────────────────────────────────── --}}
  <tr><td style="background:#fff; padding:18px 28px; text-align:center; border-radius:0 0 10px 10px; border-top:1px solid #e5e7eb;">
    <a href="{{ $appUrl }}/dashboard"
       style="display:inline-block; background:#76b900; color:#000; padding:10px 22px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none;">
      Abrir ClawYard →
    </a>
    <div style="font-size:11px; color:#9ca3af; margin-top:14px; line-height:1.6;">
      ClawYard · HP-Group · resumo automático todas as sextas-feiras<br>
      Não queres receber? Marca <em>weekly_digest_enabled = false</em> no /admin/users
    </div>
  </td></tr>

</table>
</center>
</body>
</html>
