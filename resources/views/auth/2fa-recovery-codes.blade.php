@extends('layouts.app')
@section('content')
<div style="max-width:520px;margin:40px auto;background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:24px;color:#e6e6e6">
    <h2>✅ 2FA activado</h2>
    <p style="color:#f4c361">⚠️ Guarda estes códigos de recuperação num sítio seguro. Cada um só pode ser usado uma vez. Se perderes o telefone, é com eles que entras.</p>
    <div style="background:#0f1115;border:1px solid #2a2f36;border-radius:8px;padding:14px;margin:14px 0">
        <pre style="margin:0;font-family:ui-monospace,monospace;font-size:14px;line-height:1.8">@foreach($codes as $c){{ $c }}
@endforeach</pre>
    </div>
    <p><a href="{{ route('profile.edit') }}" style="color:#9ec5ff">Voltar ao perfil →</a></p>
</div>
@endsection
