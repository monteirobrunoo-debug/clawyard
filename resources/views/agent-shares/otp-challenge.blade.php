<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Verificação — ClawYard</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0a0f;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#111118;border:1px solid #2a2a3a;border-radius:16px;padding:36px 32px;width:100%;max-width:400px;text-align:center}
        .icon{font-size:42px;margin-bottom:10px}
        .title{font-size:18px;font-weight:800;margin-bottom:4px}
        .sub{font-size:13px;color:#94a3b8;margin-bottom:22px;line-height:1.5}
        .sub strong{color:#cbd5e1}
        input{width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:12px 16px;border-radius:10px;font-size:14px;outline:none;margin-bottom:10px;transition:.15s}
        input:focus{border-color:#76b900}
        input.code{text-align:center;letter-spacing:8px;font-size:18px;font-weight:700;font-variant-numeric:tabular-nums}
        .error{font-size:12px;color:#ef4444;margin:-4px 0 12px;text-align:left}
        button{width:100%;background:#76b900;color:#000;font-weight:700;padding:12px;border:none;border-radius:10px;cursor:pointer;font-size:14px;transition:.15s}
        button:hover{filter:brightness(1.1)}
        .muted{font-size:12px;color:#64748b;margin-top:16px}
        .muted a{color:#76b900;text-decoration:none}
        .badge{display:inline-block;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:600;margin-bottom:14px}
        .badge.success{background:rgba(118,185,0,.1);border-color:rgba(118,185,0,.3);color:#a3e635}
    </style>
</head>
<body>
<div class="card">
    @if(!empty($new_device))
        <div class="badge">🛡️ novo dispositivo detectado</div>
    @endif

    @if(empty($otp_sent))
        {{-- Step 1: ask for the email --}}
        <div class="icon">📧</div>
        <div class="title">Verifica o teu acesso</div>
        <div class="sub">
            Este link foi emitido para uma pessoa específica.<br>
            Introduz o email <strong>registado</strong> para receberes um código de acesso.
        </div>

        @if($errors->has('email'))
        <div class="error">{{ $errors->first('email') }}</div>
        @endif

        <form method="POST" action="/a/{{ $share->token }}/otp/request">
            @csrf
            <input type="email" name="email" placeholder="o-teu-email@empresa.pt" autocomplete="email" required autofocus>
            <button type="submit">Enviar código →</button>
        </form>
    @else
        {{-- Step 2: enter the OTP code --}}
        <div class="badge success">✉️ Código enviado para {{ $sent_to ?? 'o email indicado' }}</div>
        <div class="icon">🔑</div>
        <div class="title">Introduz o código</div>
        <div class="sub">
            Verifica a tua caixa de entrada (e o spam).<br>
            O código tem <strong>6 dígitos</strong> e é válido por 10 minutos.
        </div>

        @if($errors->has('code'))
        <div class="error">{{ $errors->first('code') }}</div>
        @endif

        <form method="POST" action="/a/{{ $share->token }}/otp/verify">
            @csrf
            <input type="hidden" name="email" value="{{ old('email', request()->input('email', '')) }}">
            <input type="text" name="code" class="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="······" required autofocus>
            <button type="submit">Entrar →</button>
        </form>

        <div class="muted">
            Não recebeste? <a href="/a/{{ $share->token }}">Pedir novo código</a>
        </div>
    @endif
</div>
</body>
</html>
