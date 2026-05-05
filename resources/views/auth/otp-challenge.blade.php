<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — Verificação de IP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0a0a0a; color: #e5e5e5;
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .card {
            background: #111; border: 1px solid #1e1e1e; border-radius: 16px;
            padding: 36px 32px; width: 100%; max-width: 420px;
            box-shadow: 0 12px 36px rgba(0,0,0,0.5);
        }
        .icon { font-size: 38px; margin-bottom: 12px; }
        h1 { font-size: 22px; font-weight: 800; margin-bottom: 6px; color: #fff; }
        p.lede { font-size: 13px; color: #aaa; margin-bottom: 22px; line-height: 1.5; }
        .ip-chip {
            display: inline-block; padding: 3px 9px; border-radius: 5px;
            background: #1a1a1a; color: #fbbf24;
            font-family: ui-monospace, monospace; font-size: 11px;
        }
        .input-row { margin-bottom: 16px; }
        label { display: block; font-size: 11px; color: #888; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        input[name=code] {
            width: 100%; padding: 14px 16px; font-size: 22px; letter-spacing: 8px;
            text-align: center; font-family: ui-monospace, monospace;
            background: #1a1a1a; color: #fff; border: 1.5px solid #2a2a2a;
            border-radius: 10px; outline: none;
            transition: border-color 0.15s, background 0.15s;
        }
        input[name=code]:focus { border-color: #76b900; background: #1f1f1f; }
        button[type=submit] {
            width: 100%; padding: 12px; font-size: 14px; font-weight: 700;
            background: #76b900; color: #000; border: none; border-radius: 10px;
            cursor: pointer; transition: background 0.15s, transform 0.1s;
        }
        button[type=submit]:hover { background: #8fd400; }
        button[type=submit]:active { transform: scale(0.98); }
        .resend-form { display: inline; }
        .resend-btn {
            background: transparent; border: none; color: #6ec3ff; cursor: pointer;
            font-size: 12px; text-decoration: underline; padding: 0;
        }
        .resend-btn:hover { color: #b3ff4a; }
        .alert { padding: 10px 12px; border-radius: 8px; font-size: 12px; margin-bottom: 14px; }
        .alert.error   { background: #2a1717; color: #fca5a5; border: 1px solid #5b2222; }
        .alert.success { background: #103820; color: #86efac; border: 1px solid #2a6b3e; }
        .footer { margin-top: 18px; font-size: 11px; color: #666; line-height: 1.5; text-align: center; }
        .footer a { color: #888; }
        .footer a:hover { color: #ccc; }
    </style>
</head>
<body>

<div class="card">
    <div class="icon">🔐</div>
    <h1>Verifica o teu IP</h1>
    <p class="lede">
        Detectámos um endereço IP novo para a tua sessão.<br>
        Enviámos um código de 6 dígitos para <strong>{{ $email_masked }}</strong>.
        Insere-o abaixo para continuar.
    </p>

    <p class="lede" style="margin-top:-12px;margin-bottom:22px;">
        <span class="ip-chip">IP: {{ $current_ip }}</span>
    </p>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('otp.verify') }}" autocomplete="off">
        @csrf
        <div class="input-row">
            <label for="code">Código de verificação</label>
            <input type="text" name="code" id="code"
                   inputmode="numeric" pattern="\d{6}" maxlength="6"
                   placeholder="000000" autofocus required>
        </div>
        <button type="submit">Verificar e entrar</button>
    </form>

    <div class="footer">
        Não recebeste o email?
        <form method="POST" action="{{ route('otp.resend') }}" class="resend-form">
            @csrf
            <button type="submit" class="resend-btn">Reenviar código</button>
        </form>
        <br>
        <a href="{{ route('logout') }}"
           onclick="event.preventDefault();document.getElementById('cy-logout').submit();">
            Sair desta conta
        </a>
        <form id="cy-logout" method="POST" action="{{ route('logout') }}" style="display:none">@csrf</form>
    </div>
</div>

<script>
// Auto-submit when 6 digits pasted/typed
document.getElementById('code').addEventListener('input', (e) => {
    const v = e.target.value.replace(/\D/g, '').slice(0, 6);
    e.target.value = v;
    if (v.length === 6) e.target.form.submit();
});
</script>

</body>
</html>
