@extends('layouts.app')
@section('content')
<div style="max-width:420px;margin:60px auto;background:#1c1f24;border:1px solid #2a2f36;border-radius:10px;padding:30px;color:#e6e6e6;text-align:center">
    <h2 style="margin-top:0">🔒 Autenticação 2FA</h2>
    <p style="opacity:.75">Introduz o código de 6 dígitos da tua app de autenticação, ou um dos códigos de recuperação.</p>
    @if($errors->any())<p style="color:#f78a8a">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('login.2fa.verify') }}">@csrf
        <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required
               style="width:170px;padding:14px 18px;font-size:22px;letter-spacing:5px;text-align:center;background:#0f1115;border:1px solid #2a2f36;color:#e6e6e6;border-radius:8px">
        <br>
        <button style="margin-top:14px;background:#3b82f6;border:none;color:#fff;padding:12px 26px;border-radius:8px;cursor:pointer;font-size:15px">Continuar</button>
    </form>
</div>
@endsection
