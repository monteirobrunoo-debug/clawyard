<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Protegido</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0a0f;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#111118;border:1px solid #2a2a3a;border-radius:16px;padding:36px 32px;width:100%;max-width:360px;text-align:center}
        .icon{font-size:42px;margin-bottom:14px}
        .title{font-size:18px;font-weight:800;margin-bottom:6px}
        .sub{font-size:13px;color:#64748b;margin-bottom:24px}
        input{width:100%;background:#1a1a24;border:1px solid #2a2a3a;color:#e2e8f0;padding:12px 16px;border-radius:10px;font-size:14px;outline:none;text-align:center;letter-spacing:2px;margin-bottom:12px;transition:.15s}
        input:focus{border-color:#76b900}
        .error{font-size:12px;color:#ef4444;margin-bottom:12px}
        button{width:100%;background:#76b900;color:#000;font-weight:700;padding:12px;border:none;border-radius:10px;cursor:pointer;font-size:14px}
        button:hover{filter:brightness(1.1)}
    </style>
</head>
<body>
<div class="card">
    <div class="icon">🔒</div>
    <div class="title">Acesso Protegido</div>
    <div class="sub">Introduz a palavra-passe para aceder ao assistente.</div>

    @if($errors->has('password'))
    <div class="error">{{ $errors->first('password') }}</div>
    @endif

    <form method="POST" action="/a/{{ $token }}/password">
        @csrf
        <input type="password" name="password" placeholder="Palavra-passe" autofocus autocomplete="current-password">
        <button type="submit">Entrar →</button>
    </form>
</div>
</body>
</html>
