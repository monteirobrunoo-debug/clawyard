@php
    $s = $stats;
    $pts        = (int) ($s['points_earned'] ?? 0);
    $total      = (int) ($s['total_points'] ?? 0);
    $levelName  = $s['level_name']  ?? 'Recruta';
    $nextName   = $s['next_level_name'] ?? null;
    $toNext     = (int) ($s['points_to_next'] ?? 0);
    $streak     = (int) ($s['streak']  ?? 0);
    $bestStreak = (int) ($s['best_streak'] ?? 0);
    $chats      = (int) ($s['chats']   ?? 0);
    $topAgents  = $s['top_agents'] ?? [];
    $leadsQ     = (int) ($s['leads_qualified']  ?? 0);
    $tendersI   = (int) ($s['tenders_imported'] ?? 0);
    $badges     = $s['badges_earned'] ?? [];
    $rank       = $s['rank'] ?? null;
@endphp
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <title>Relatório Semanal · ClawYard</title>
</head>
<body style="margin:0;background:#0a0a0a;color:#e5e5e5;font-family:-apple-system,Segoe UI,Roboto,sans-serif;">
<div style="max-width:620px;margin:0 auto;padding:30px 20px;">

    <div style="text-align:center;margin-bottom:24px;">
        <div style="font-size:48px;margin-bottom:8px;">🏆</div>
        <h1 style="margin:0;font-size:24px;color:#fff;">Olá {{ $recipient->name }}</h1>
        <p style="color:#999;font-size:13px;margin:6px 0 0;">A tua semana no ClawYard</p>
    </div>

    {{-- Hero — pontos ganhos --}}
    <div style="background:linear-gradient(135deg,#1a2a3a 0%,#2a1a3a 100%);border-radius:14px;padding:24px;text-align:center;margin-bottom:20px;">
        <div style="font-size:11px;color:#9ab;text-transform:uppercase;letter-spacing:2px;">Ganhos esta semana</div>
        <div style="font-size:54px;font-weight:700;color:#7c3;line-height:1;margin:8px 0;">
            {{ $pts > 0 ? '+' . number_format($pts) : '0' }} <span style="font-size:18px;color:#bcd;">pts</span>
        </div>
        <div style="font-size:13px;color:#bcd;">
            Total acumulado: <strong style="color:#fff;">{{ number_format($total) }}</strong> ·
            Nível {{ $s['level'] ?? 0 }} <strong style="color:#7c3;">{{ $levelName }}</strong>
            @if($rank)
                · Ranking: <strong style="color:#fbbf24;">#{{ $rank }}</strong>
            @endif
        </div>
        @if($nextName && $toNext > 0)
            <div style="margin-top:12px;font-size:12px;color:#9ab;">
                Faltam <strong style="color:#7c3;">{{ number_format($toNext) }}</strong> pts para <strong>{{ $nextName }}</strong>
            </div>
        @endif
    </div>

    {{-- KPIs da semana --}}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
        <tr>
            @php
                $kpis = [
                    ['💬', $chats,     'Chats'],
                    ['🔥', $streak,    'Streak (dias)'],
                    ['🎯', $leadsQ,    'Leads qualificados'],
                    ['📑', $tendersI,  'Concursos importados'],
                ];
            @endphp
            @foreach($kpis as [$emo, $val, $lbl])
                <td width="25%" style="padding:0 4px;">
                    <div style="background:#111;border:1px solid #1e1e1e;border-radius:10px;padding:14px;text-align:center;">
                        <div style="font-size:24px;">{{ $emo }}</div>
                        <div style="font-size:20px;font-weight:700;color:#fff;margin-top:4px;">{{ number_format($val) }}</div>
                        <div style="font-size:10px;color:#9ab;text-transform:uppercase;margin-top:4px;">{{ $lbl }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    {{-- Agentes mais usados --}}
    @if(count($topAgents) > 0)
        <h2 style="font-size:14px;color:#fff;text-transform:uppercase;letter-spacing:1px;margin:24px 0 8px;">🤖 Os teus agentes da semana</h2>
        <div style="background:#111;border:1px solid #1e1e1e;border-radius:10px;padding:12px 16px;">
            @foreach($topAgents as $a)
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #1e1e1e;">
                    <span style="color:#dde;">{{ $a['emoji'] ?? '🤖' }} {{ $a['name'] ?? $a['agent'] }}</span>
                    <span style="color:#7c3;font-family:monospace;font-weight:700;">{{ $a['chats'] }} chats</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Badges desbloqueados --}}
    @if(count($badges) > 0)
        <h2 style="font-size:14px;color:#fff;text-transform:uppercase;letter-spacing:1px;margin:24px 0 8px;">🏅 Badges desbloqueados</h2>
        <div style="background:#111;border:1px solid #1e1e1e;border-radius:10px;padding:12px 16px;">
            @foreach($badges as $b)
                <div style="padding:6px 0;color:#fbbf24;">⭐ {{ $b }}</div>
            @endforeach
        </div>
    @endif

    {{-- CTA --}}
    <div style="text-align:center;margin-top:30px;">
        <a href="{{ config('app.url') }}/dashboard" style="display:inline-block;background:#76b900;color:#000;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;">Abrir ClawYard →</a>
        <p style="font-size:11px;color:#666;margin-top:18px;">
            Recebes este resumo todas as segundas-feiras às 8h.
            <br>Continua a usar o ClawYard para subir de nível e desbloquear badges.
        </p>
    </div>

</div>
</body>
</html>
