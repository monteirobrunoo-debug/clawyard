@extends('layouts.app')

@section('content')
<div style="max-width:1200px;margin:24px auto;padding:0 16px;color:#e6e6e6">
    <h1 style="margin:0 0 6px 0">🩺 Health Dashboard</h1>
    <p style="opacity:.65;margin:0 0 22px 0">Cached 30s · refreshed: {{ $data['fetched_at'] }}</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">

        <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px">
            <h3 style="margin:0 0 8px 0;font-size:14px;color:#9ec5ff">📊 Database</h3>
            <table style="width:100%;font-size:13px">
                @foreach($data['db'] as $k => $v)
                <tr><td style="opacity:.7">{{ str_replace('_',' ',$k) }}</td><td style="text-align:right;font-variant-numeric:tabular-nums">{{ number_format($v) }}</td></tr>
                @endforeach
            </table>
        </div>

        <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px">
            <h3 style="margin:0 0 8px 0;font-size:14px;color:#9ec5ff">📚 Knowledge Library</h3>
            <table style="width:100%;font-size:13px">
                <tr><td style="opacity:.7">total livros</td><td style="text-align:right">{{ number_format($data['books']['total_books']) }}</td></tr>
                <tr><td style="opacity:.7">total chunks</td><td style="text-align:right">{{ number_format($data['books']['total_chunks']) }}</td></tr>
                <tr><td style="opacity:.7">embedded</td><td style="text-align:right;color:{{ $data['books']['coverage_pct'] == 100 ? '#74e0a3' : '#f4c361' }}">{{ number_format($data['books']['embedded']) }} ({{ $data['books']['coverage_pct'] }}%)</td></tr>
                @if($data['books']['missing'] > 0)
                <tr><td style="opacity:.7">missing</td><td style="text-align:right;color:#f78a8a">{{ number_format($data['books']['missing']) }}</td></tr>
                @endif
            </table>
            <hr style="border:none;border-top:1px solid #2a2f36;margin:10px 0">
            <table style="width:100%;font-size:12px">
                @foreach($data['books']['by_domain'] as $d)
                <tr><td style="opacity:.7">{{ $d->domain }}</td><td style="text-align:right">{{ $d->books }} / {{ number_format($d->chunks) }}</td></tr>
                @endforeach
            </table>
        </div>

        <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px">
            <h3 style="margin:0 0 8px 0;font-size:14px;color:#9ec5ff">🤖 Agentes (última hora)</h3>
            <table style="width:100%;font-size:13px">
                @forelse($data['agents'] as $a)
                    <tr><td style="opacity:.7">{{ $a->agent }}</td><td style="text-align:right">{{ $a->c }}</td></tr>
                @empty
                    <tr><td colspan="2" style="opacity:.5;text-align:center">sem actividade na última hora</td></tr>
                @endforelse
            </table>
        </div>

        <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px">
            <h3 style="margin:0 0 8px 0;font-size:14px;color:#9ec5ff">💾 Sistema</h3>
            <table style="width:100%;font-size:13px">
                @if(!empty($data['disk']))
                <tr><td style="opacity:.7">disk usage</td><td style="text-align:right;color:{{ $data['disk']['used_pct'] > 85 ? '#f78a8a' : '#74e0a3' }}">{{ $data['disk']['used_pct'] }}% ({{ $data['disk']['free_gb'] }} GB free of {{ $data['disk']['total_gb'] }})</td></tr>
                @endif
                <tr><td style="opacity:.7;vertical-align:top">postgres</td><td style="text-align:right;font-size:11px">{{ \Illuminate\Support\Str::limit($data['pg_version'], 80) }}</td></tr>
            </table>
        </div>

    </div>

    <div style="background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:14px;margin-top:14px">
        <h3 style="margin:0 0 8px 0;font-size:14px;color:#f4c361">⚠️ Stream errors recentes</h3>
        @if(empty($data['errors']))
            <p style="opacity:.55;margin:0">Sem erros recentes ✓</p>
        @else
            <pre style="font-size:11px;line-height:1.4;color:#f78a8a;margin:0;max-height:340px;overflow:auto">@foreach($data['errors'] as $e)[{{ $e['at'] }}] {{ $e['line'] }}
@endforeach</pre>
        @endif
    </div>
</div>
@endsection
