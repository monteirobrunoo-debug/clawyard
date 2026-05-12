@extends('layouts.app')
@section('content')
<div style="max-width:520px;margin:40px auto;background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:24px;color:#e6e6e6">
    <h2 style="margin:0 0 14px 0">🔒 Configurar autenticação 2FA</h2>

    @if($already)
        <p style="color:#74e0a3">✓ 2FA já está activado nesta conta.</p>
        <form method="post" action="{{ route('profile.2fa.disable') }}" style="margin-top:14px">@csrf
            <label style="display:block;margin-bottom:6px;opacity:.8">Confirma a tua password para desactivar:</label>
            <input type="password" name="password" required style="width:100%;padding:8px 12px;background:#0f1115;border:1px solid #2a2f36;color:#e6e6e6;border-radius:6px">
            <button style="margin-top:10px;background:#a83232;border:none;color:#fff;padding:10px 18px;border-radius:6px;cursor:pointer">Desactivar 2FA</button>
        </form>
    @else
        <p style="opacity:.75">
            1. Abre a tua app de autenticação (Google Authenticator, Authy, 1Password, Bitwarden…)<br>
            2. Lê o código QR abaixo (ou introduz o secret manualmente)<br>
            3. Insere o código de 6 dígitos que aparece para confirmar
        </p>

        <div style="background:#fff;padding:14px;border-radius:10px;display:inline-block;margin:14px 0">
            {!! $qrSvg !!}
        </div>

        <p style="font-family:ui-monospace,monospace;font-size:12px;opacity:.6">Secret: <code>{{ $secret }}</code></p>

        @if($errors->any())
            <p style="color:#f78a8a">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ route('profile.2fa.enable') }}" style="margin-top:14px">@csrf
            <label style="display:block;margin-bottom:6px;opacity:.8">Código de 6 dígitos:</label>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" required style="width:140px;padding:10px 14px;font-size:18px;letter-spacing:3px;text-align:center;background:#0f1115;border:1px solid #2a2f36;color:#e6e6e6;border-radius:6px">
            <button style="margin-left:8px;background:#1e7a3d;border:none;color:#fff;padding:10px 18px;border-radius:6px;cursor:pointer">Activar 2FA</button>
        </form>
    @endif
</div>
@endsection
