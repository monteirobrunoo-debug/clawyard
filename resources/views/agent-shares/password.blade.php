<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Protegido</title>
    <style>
        :root{
            --bg:#0a0a0f;--card:#111118;--border:#2a2a3a;--text:#e2e8f0;
            --muted:#64748b;--input:#1a1a24;--accent:#76b900;--accent-ink:#000;
            --danger:#ef4444;
            --toggle-bg:rgba(255,255,255,.04);--toggle-border:rgba(255,255,255,.10);
        }
        :root[data-theme="day"]{
            --bg:#f4f6fa;--card:#ffffff;--border:#e2e8f0;--text:#111827;
            --muted:#6b7280;--input:#f9fafb;--accent:#4d7a00;--accent-ink:#fff;
            --danger:#b91c1c;
            --toggle-bg:rgba(0,0,0,.04);--toggle-border:rgba(0,0,0,.10);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;transition:background .2s,color .2s}
        .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:36px 32px;width:100%;max-width:360px;text-align:center;position:relative}
        .icon{font-size:42px;margin-bottom:14px}
        .title{font-size:18px;font-weight:800;margin-bottom:6px}
        .sub{font-size:13px;color:var(--muted);margin-bottom:24px}
        input{width:100%;background:var(--input);border:1px solid var(--border);color:var(--text);padding:12px 16px;border-radius:10px;font-size:14px;outline:none;text-align:center;letter-spacing:2px;margin-bottom:12px;transition:.15s}
        input:focus{border-color:var(--accent)}
        .error{font-size:12px;color:var(--danger);margin-bottom:12px}
        button{width:100%;background:var(--accent);color:var(--accent-ink);font-weight:700;padding:12px;border:none;border-radius:10px;cursor:pointer;font-size:14px}
        button:hover{filter:brightness(1.1)}
        .theme-toggle{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:10px;background:var(--toggle-bg);border:1px solid var(--toggle-border);color:var(--muted);cursor:pointer;font-size:14px;display:inline-flex;align-items:center;justify-content:center;padding:0;transition:.15s}
        .theme-toggle:hover{color:var(--text);border-color:var(--accent)}
    </style>
</head>
<body>
<div class="card">
    <button type="button" class="theme-toggle" onclick="toggleClawTheme()" aria-label="Alternar modo claro/escuro" title="Alternar modo claro/escuro"><span id="themeIcon">🌙</span></button>
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
<script>
(function(){
    var KEY='clawyard_theme',saved=null;
    try{saved=localStorage.getItem(KEY);}catch(e){}
    var t=(saved==='day'?'day':'night');
    document.documentElement.setAttribute('data-theme',t);
    var ic=document.getElementById('themeIcon');if(ic)ic.textContent=(t==='day'?'☀️':'🌙');
})();
function toggleClawTheme(){
    var cur=document.documentElement.getAttribute('data-theme')==='day'?'day':'night';
    var next=cur==='day'?'night':'day';
    document.documentElement.setAttribute('data-theme',next);
    var ic=document.getElementById('themeIcon');if(ic)ic.textContent=(next==='day'?'☀️':'🌙');
    try{localStorage.setItem('clawyard_theme',next);}catch(e){}
}
</script>
</body>
</html>
