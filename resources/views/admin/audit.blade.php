@extends('layouts.app')

@section('content')
<div style="max-width:1400px;margin:24px auto;padding:0 16px;color:#e6e6e6">
    <h1 style="margin:0 0 18px 0">📜 Audit Log</h1>

    <form method="get" style="margin-bottom:14px;display:flex;gap:10px;align-items:center">
        <input name="action" placeholder="filter action (ex: tender.assign)" value="{{ request('action') }}" style="background:#1c1f24;border:1px solid #2a2f36;color:#e6e6e6;padding:8px 12px;border-radius:6px">
        <input name="user_id" type="number" placeholder="user_id" value="{{ request('user_id') }}" style="background:#1c1f24;border:1px solid #2a2f36;color:#e6e6e6;padding:8px 12px;border-radius:6px;width:120px">
        <button style="background:#3b82f6;border:none;color:#fff;padding:8px 18px;border-radius:6px;cursor:pointer">filter</button>
        <a href="{{ route('admin.audit') }}" style="color:#9ec5ff">clear</a>
    </form>

    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;overflow:hidden">
        <table style="width:100%;font-size:13px;border-collapse:collapse">
            <thead style="background:#2a2f36">
                <tr>
                    <th style="text-align:left;padding:8px 12px">when</th>
                    <th style="text-align:left;padding:8px 12px">user</th>
                    <th style="text-align:left;padding:8px 12px">action</th>
                    <th style="text-align:left;padding:8px 12px">resource</th>
                    <th style="text-align:left;padding:8px 12px">ip</th>
                    <th style="text-align:left;padding:8px 12px">payload</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr style="border-top:1px solid #2a2f36">
                    <td style="padding:8px 12px;font-variant-numeric:tabular-nums">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td style="padding:8px 12px">{{ $log->user?->name ?? '—' }}</td>
                    <td style="padding:8px 12px;color:#9ec5ff;font-family:ui-monospace,monospace">{{ $log->action }}</td>
                    <td style="padding:8px 12px">{{ $log->resource_type }}{{ $log->resource_id ? '#'.$log->resource_id : '' }}</td>
                    <td style="padding:8px 12px;font-family:ui-monospace,monospace;font-size:11px">{{ $log->ip }}</td>
                    <td style="padding:8px 12px;font-size:11px;font-family:ui-monospace,monospace;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ json_encode($log->payload) }}</td>
                </tr>
                @empty
                <tr><td colspan="6" style="padding:30px;text-align:center;opacity:.55">no audit events recorded yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:18px">{{ $logs->links() }}</div>
</div>
@endsection
