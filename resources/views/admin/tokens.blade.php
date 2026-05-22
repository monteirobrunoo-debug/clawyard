@extends('layouts.app')

@section('content')
<div style="max-width:1400px;margin:24px auto;padding:0 16px;color:#e6e6e6">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <h1 style="margin:0">💰 Token Budget — {{ $summary['period'] }}</h1>
        <div style="display:flex;gap:8px;">
            <a href="{{ url('/') }}" style="background:#2a2f36;border:1px solid #3a3f46;color:#dde;padding:8px 14px;border-radius:6px;text-decoration:none;font-size:13px;">← Dashboard</a>
        </div>
    </div>

    @if(session('status'))
        <div style="background:#10391d;border:1px solid #1f7a3d;color:#a3f0c0;padding:10px 14px;border-radius:8px;margin-bottom:14px;">
            {{ session('status') }}
        </div>
    @endif

    {{-- SUMMARY CARD --}}
    @php
        $pct = $summary['percent_used'];
        $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#10b981');
    @endphp
    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:18px;margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
            <div>
                <span style="font-size:28px;font-weight:bold;color:{{ $barColor }};font-family:monospace;">€{{ number_format($summary['spent_eur'], 2) }}</span>
                <span style="color:#9ab;font-size:14px;margin-left:6px;">/ €{{ number_format($summary['pool_eur'], 2) }}</span>
            </div>
            <div style="font-size:14px;color:#9ab;">
                Restante: <strong style="color:#dde;">€{{ number_format($summary['remaining_eur'], 2) }}</strong>
                · Rate USD→EUR: {{ number_format($summary['usd_eur_rate'], 3) }}
            </div>
        </div>
        <div style="height:14px;background:#0a0a0a;border-radius:7px;overflow:hidden;margin-bottom:8px;">
            <div style="height:100%;width:{{ min(100, $pct) }}%;background:{{ $barColor }};transition:width 0.5s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:#9ab;">
            <span>0%</span>
            <span style="color:#f59e0b;">alerta {{ $summary['alert_at'] }}%</span>
            @if($budget->hard_gate_at_percent > 0)
                <span style="color:#ef4444;">hard-gate {{ $budget->hard_gate_at_percent }}%</span>
            @endif
            <span>100% ({{ number_format($pct, 1) }}% usado)</span>
        </div>
    </div>

    {{-- TIMELINE 7d --}}
    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:18px;margin-bottom:18px;">
        <h3 style="margin:0 0 14px 0;font-size:14px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">📊 Últimos 7 dias</h3>
        @php
            $maxDay = max(0.01, max(array_column($timeline, 'eur_spent')));
        @endphp
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:6px;height:120px;">
            @foreach($timeline as $day)
                @php
                    $h = $maxDay > 0 ? max(2, ($day['eur_spent'] / $maxDay) * 100) : 2;
                @endphp
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0;">
                    <span style="font-size:10px;color:#9ab;font-family:monospace;">€{{ number_format($day['eur_spent'], 2) }}</span>
                    <div style="width:100%;background:{{ $barColor }};height:{{ $h }}%;border-radius:3px 3px 0 0;min-height:4px;transition:height 0.5s;"></div>
                    <span style="font-size:10px;color:#bcd;">{{ $day['date'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- FORM SET-POOL --}}
    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:18px;margin-bottom:18px;">
        <h3 style="margin:0 0 14px 0;font-size:14px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">⚙️ Configurar pool — {{ $summary['period'] }}</h3>
        <form method="POST" action="{{ route('admin.tokens.update') }}" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
            @csrf
            <div>
                <label style="display:block;font-size:11px;color:#9ab;margin-bottom:4px;">Pool (EUR)</label>
                <input type="number" name="pool_eur" step="0.01" min="0" max="100000" value="{{ $budget->pool_eur }}" style="background:#0a0a0a;border:1px solid #2a2f36;color:#e6e6e6;padding:8px 12px;border-radius:6px;width:130px;">
            </div>
            <div>
                <label style="display:block;font-size:11px;color:#9ab;margin-bottom:4px;">Alerta (%)</label>
                <input type="number" name="alert_at_percent" min="1" max="100" value="{{ $budget->alert_at_percent }}" style="background:#0a0a0a;border:1px solid #2a2f36;color:#e6e6e6;padding:8px 12px;border-radius:6px;width:90px;">
            </div>
            <div>
                <label style="display:block;font-size:11px;color:#9ab;margin-bottom:4px;">Hard gate (%, 0 = off)</label>
                <input type="number" name="hard_gate_at_percent" min="0" max="100" value="{{ $budget->hard_gate_at_percent }}" style="background:#0a0a0a;border:1px solid #2a2f36;color:#e6e6e6;padding:8px 12px;border-radius:6px;width:130px;">
            </div>
            <button style="background:#3b82f6;border:none;color:#fff;padding:9px 22px;border-radius:6px;cursor:pointer;font-weight:600;">Guardar</button>
        </form>
        <form method="POST" action="{{ route('admin.tokens.reset-notifications') }}" style="margin-top:12px;">
            @csrf
            <button style="background:#2a2f36;border:1px solid #3a3f46;color:#dde;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;">↺ Reset notificações (re-enviar próximo alerta)</button>
            @if($budget->notified_at_80)
                <span style="color:#9ab;font-size:11px;margin-left:10px;">alerta 80% enviado em {{ $budget->notified_at_80->format('d/m H:i') }}</span>
            @endif
            @if($budget->notified_at_100)
                <span style="color:#ef4444;font-size:11px;margin-left:10px;">⚠ alerta 100% em {{ $budget->notified_at_100->format('d/m H:i') }}</span>
            @endif
        </form>
    </div>

    {{-- RANKING --}}
    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;overflow:hidden;margin-bottom:18px;">
        <div style="padding:14px 18px;border-bottom:1px solid #2a2f36;">
            <h3 style="margin:0;font-size:14px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">🏆 Ranking — gasto real EUR</h3>
        </div>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead style="background:#0a0a0a;">
                <tr>
                    <th style="text-align:left;padding:10px 14px;color:#9ab;font-weight:600;font-size:11px;">#</th>
                    <th style="text-align:left;padding:10px 14px;color:#9ab;font-weight:600;font-size:11px;">User</th>
                    <th style="text-align:left;padding:10px 14px;color:#9ab;font-weight:600;font-size:11px;">Email</th>
                    <th style="text-align:right;padding:10px 14px;color:#9ab;font-weight:600;font-size:11px;">Gasto €</th>
                    <th style="text-align:right;padding:10px 14px;color:#9ab;font-weight:600;font-size:11px;">% Pool</th>
                    <th style="text-align:right;padding:10px 14px;color:#9ab;font-weight:600;font-size:11px;">× Fair Share</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ranking as $r)
                    @php
                        $medal = ['🥇','🥈','🥉'][$r['rank'] - 1] ?? '';
                        $isMe = auth()->check() && $r['user_id'] === auth()->id();
                    @endphp
                    <tr style="border-top:1px solid #2a2f36;{{ $isMe ? 'background:rgba(251,191,36,0.05);' : '' }}">
                        <td style="padding:9px 14px;font-family:monospace;">{{ $medal }} {{ $r['rank'] }}</td>
                        <td style="padding:9px 14px;color:{{ $isMe ? '#fbbf24' : '#dde' }};font-weight:{{ $isMe ? 'bold' : 'normal' }};">{{ $r['name'] }}{{ $isMe ? ' (tu)' : '' }}</td>
                        <td style="padding:9px 14px;color:#9ab;font-size:11px;">{{ $r['email'] }}</td>
                        <td style="padding:9px 14px;text-align:right;font-family:monospace;color:{{ $barColor }};font-weight:600;">€{{ number_format($r['eur_spent'], 2) }}</td>
                        <td style="padding:9px 14px;text-align:right;color:#dde;">{{ number_format($r['pct_of_pool'], 1) }}%</td>
                        <td style="padding:9px 14px;text-align:right;color:{{ $r['vs_fair_share'] > 1.5 ? '#f59e0b' : '#9ab' }};">{{ number_format($r['vs_fair_share'], 2) }}×</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:24px;text-align:center;color:#9ab;">Sem actividade este mês.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- HISTORY --}}
    @if($history->count() > 1)
    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px 18px;">
        <h3 style="margin:0 0 10px 0;font-size:13px;color:#9ab;text-transform:uppercase;letter-spacing:1px;">Histórico de períodos</h3>
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:6px;color:#9ab;font-size:11px;">Período</th>
                    <th style="text-align:right;padding:6px;color:#9ab;font-size:11px;">Pool €</th>
                    <th style="text-align:right;padding:6px;color:#9ab;font-size:11px;">Notif 80%</th>
                    <th style="text-align:right;padding:6px;color:#9ab;font-size:11px;">Notif 100%</th>
                </tr>
            </thead>
            <tbody>
                @foreach($history as $h)
                    <tr style="border-top:1px solid #2a2f36;">
                        <td style="padding:6px;font-family:monospace;">{{ $h->period_yyyy_mm }}</td>
                        <td style="padding:6px;text-align:right;">€{{ number_format($h->pool_eur, 2) }}</td>
                        <td style="padding:6px;text-align:right;color:#9ab;">{{ $h->notified_at_80?->format('d/m H:i') ?? '—' }}</td>
                        <td style="padding:6px;text-align:right;color:{{ $h->notified_at_100 ? '#ef4444' : '#9ab' }};">{{ $h->notified_at_100?->format('d/m H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

</div>
@endsection
