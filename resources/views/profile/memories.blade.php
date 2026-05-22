@extends('layouts.app')

@section('content')
<div style="max-width:1100px;margin:24px auto;padding:0 16px;color:#e6e6e6">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <h1 style="margin:0">🧠 Memórias dos agentes</h1>
        <div style="display:flex;gap:8px;">
            <a href="{{ url('/') }}" style="background:#2a2f36;border:1px solid #3a3f46;color:#dde;padding:8px 14px;border-radius:6px;text-decoration:none;font-size:13px;">← Dashboard</a>
        </div>
    </div>

    @if(session('status'))
        <div style="background:#10391d;border:1px solid #1f7a3d;color:#a3f0c0;padding:10px 14px;border-radius:8px;margin-bottom:14px;">
            {{ session('status') }}
        </div>
    @endif

    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px 18px;margin-bottom:18px;">
        <div style="font-size:13px;color:#dde;line-height:1.6;">
            <strong>O que isto é:</strong> Os agentes do ClawYard (Cor. Rodrigues, Marco Sales, etc.)
            guardam <em>memórias</em> sobre ti — preferências, regras, contexto recorrente — para
            personalizar respostas em sessões futuras.
            <br>
            <strong>Quando se grava:</strong> Quando dizes "lembra-te que…", "anota…", "regra…",
            "for future…", "remember…" no chat.
            <br>
            <strong>Privacidade:</strong> só TU vês estas memórias. Não há partilha entre users —
            mesmo entre os admins.
        </div>
        <div style="margin-top:10px;font-size:13px;color:#9ab;">
            Total: <strong style="color:#fbbf24;">{{ $total }}</strong> memórias guardadas.
        </div>
    </div>

    @forelse($grouped as $agentKey => $memories)
        @php
            $agentName = $agentNames[$agentKey] ?? $agentKey;
        @endphp
        <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;overflow:hidden;margin-bottom:14px;">
            <div style="background:#0a0a0a;padding:12px 16px;border-bottom:1px solid #2a2f36;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:14px;font-weight:600;">{{ $agentName }}</span>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:11px;color:#9ab;">{{ count($memories) }} memórias</span>
                    <form method="POST" action="{{ route('memories.forget-agent', $agentKey) }}" onsubmit="return confirm('Apagar TODAS as memórias do {{ $agentName }}? Sem volta.');">
                        @csrf
                        @method('DELETE')
                        <button style="background:#7a1f1f;border:1px solid #ef4444;color:#fff;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:11px;">🗑 esqueceu tudo</button>
                    </form>
                </div>
            </div>
            <table style="width:100%;font-size:12px;border-collapse:collapse;">
                <thead>
                    <tr style="background:#0f0f0f;">
                        <th style="text-align:left;padding:8px 12px;color:#9ab;font-weight:600;font-size:10px;text-transform:uppercase;">Key</th>
                        <th style="text-align:left;padding:8px 12px;color:#9ab;font-weight:600;font-size:10px;text-transform:uppercase;">Value</th>
                        <th style="text-align:center;padding:8px 12px;color:#9ab;font-weight:600;font-size:10px;text-transform:uppercase;">Importance</th>
                        <th style="text-align:center;padding:8px 12px;color:#9ab;font-weight:600;font-size:10px;text-transform:uppercase;">Recalls</th>
                        <th style="text-align:center;padding:8px 12px;color:#9ab;font-weight:600;font-size:10px;text-transform:uppercase;">Origem</th>
                        <th style="text-align:right;padding:8px 12px;color:#9ab;font-weight:600;font-size:10px;text-transform:uppercase;">Acções</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($memories as $m)
                        <tr style="border-top:1px solid #2a2f36;">
                            <form method="POST" action="{{ route('memories.update', $m->id) }}">
                                @csrf
                                @method('PATCH')
                                <td style="padding:8px 12px;font-family:monospace;color:#bcd;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $m->memory_key }}">{{ $m->memory_key }}</td>
                                <td style="padding:8px 12px;">
                                    <textarea name="memory_value" rows="1" style="width:100%;background:#0a0a0a;border:1px solid #2a2f36;color:#dde;padding:6px 8px;border-radius:4px;font-size:12px;font-family:inherit;resize:vertical;min-height:30px;">{{ $m->memory_value }}</textarea>
                                </td>
                                <td style="padding:8px 12px;text-align:center;">
                                    <input type="number" name="importance" min="0" max="1" step="0.1" value="{{ $m->importance }}" style="width:60px;background:#0a0a0a;border:1px solid #2a2f36;color:#dde;padding:4px 6px;border-radius:4px;font-size:12px;text-align:center;">
                                </td>
                                <td style="padding:8px 12px;text-align:center;color:#9ab;font-family:monospace;">{{ $m->recall_count }}</td>
                                <td style="padding:8px 12px;text-align:center;font-size:10px;">
                                    @php
                                        $sourceColors = ['explicit' => '#10b981', 'inferred' => '#f59e0b', 'system' => '#6366f1'];
                                        $c = $sourceColors[$m->source] ?? '#9ab';
                                    @endphp
                                    <span style="background:{{ $c }}22;color:{{ $c }};padding:2px 8px;border-radius:10px;">{{ $m->source }}</span>
                                </td>
                                <td style="padding:8px 12px;text-align:right;white-space:nowrap;">
                                    <button style="background:#1d4ed8;border:none;color:#fff;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:11px;">💾 save</button>
                            </form>
                                    <form method="POST" action="{{ route('memories.destroy', $m->id) }}" style="display:inline;" onsubmit="return confirm('Apagar esta memória?');">
                                        @csrf
                                        @method('DELETE')
                                        <button style="background:#7a1f1f;border:1px solid #ef4444;color:#fff;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:11px;margin-left:4px;">🗑</button>
                                    </form>
                                </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:30px;text-align:center;">
            <div style="font-size:48px;margin-bottom:12px;">🧠</div>
            <div style="color:#dde;margin-bottom:8px;">Nenhuma memória guardada ainda.</div>
            <div style="color:#9ab;font-size:13px;">
                No chat de qualquer agente, escreve <strong style="color:#fbbf24;">"lembra-te que…"</strong> ou
                <strong style="color:#fbbf24;">"anota…"</strong> para criar memórias.
            </div>
        </div>
    @endforelse

</div>
@endsection
