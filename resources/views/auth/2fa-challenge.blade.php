@extends('layouts.app')
@section('content')
<div style="max-width:440px;margin:60px auto;background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:30px;color:#e6e6e6;text-align:center">
    <h2 style="margin-top:0">🔒 Código de acesso</h2>

    @if($mode === 'email')
        <p style="opacity:.78;line-height:1.5">Enviámos um código de 6 dígitos para<br><strong>{{ $maskedEmail }}</strong>.<br>Verifica a caixa de entrada (e o spam).</p>
        <p style="opacity:.5;font-size:12px;margin-top:-4px">Válido durante 10 minutos · uso único</p>
    @else
        <p style="opacity:.78">Introduz o código de 6 dígitos da tua app autenticadora<br>(ou um código de recuperação que guardaste).</p>
    @endif

    @if(session('success'))
        <p style="color:#74e0a3;font-size:13px">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p style="color:#f4c361;font-size:13px">{{ session('error') }}</p>
    @endif
    @if($errors->any())
        <p style="color:#f78a8a;font-size:13px">{{ $errors->first() }}</p>
    @endif

    <form method="post" action="{{ route('login.2fa.verify') }}">@csrf
        <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required
               placeholder="······"
               style="width:200px;padding:14px 18px;font-size:22px;letter-spacing:6px;text-align:center;background:#0f1115;border:1px solid #2a2f36;color:#e6e6e6;border-radius:8px">
        <br>
        <button style="margin-top:16px;background:#3b82f6;border:none;color:#fff;padding:12px 30px;border-radius:8px;cursor:pointer;font-size:15px">Continuar</button>
    </form>

    @if($mode === 'email')
        <form method="post" action="{{ route('login.2fa.resend') }}" style="margin-top:16px">@csrf
            <button style="background:none;border:none;color:#9ec5ff;font-size:13px;text-decoration:underline;cursor:pointer">Reenviar código</button>
        </form>
        <p style="margin-top:24px;font-size:12px;opacity:.55;line-height:1.5">
            💡 Para evitar o email a cada login, configura uma app autenticadora<br>
            (Google Authenticator / 1Password / Authy) em <a href="{{ route('profile.2fa') }}" style="color:#9ec5ff">/profile/2fa</a> depois de entrares.
        </p>
    @endif
</div>
@endsection
