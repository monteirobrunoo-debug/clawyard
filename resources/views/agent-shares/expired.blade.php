<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Expirado</title>
    <style>
        :root{
            --bg:#0a0a0f;--card:#111118;--border:#2a2a3a;--text:#e2e8f0;
            --muted:#64748b;--accent:#76b900;
            --toggle-bg:rgba(255,255,255,.04);--toggle-border:rgba(255,255,255,.10);
        }
        :root[data-theme="light"]{
            --bg:#f4f6fa;--card:#ffffff;--border:#e2e8f0;--text:#111827;
            --muted:#6b7280;--accent:#4d7a00;
            --toggle-bg:rgba(0,0,0,.04);--toggle-border:rgba(0,0,0,.10);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;transition:background .2s,color .2s}
        .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:40px 32px;width:100%;max-width:380px;text-align:center;position:relative}
        .icon{font-size:48px;margin-bottom:14px}
        .title{font-size:20px;font-weight:800;margin-bottom:8px}
        .sub{font-size:14px;color:var(--muted);line-height:1.6}
        .contact{margin-top:20px;font-size:12px;color:var(--muted)}
        .contact a{color:var(--accent);text-decoration:none}
        .theme-toggle{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:10px;background:var(--toggle-bg);border:1px solid var(--toggle-border);color:var(--muted);cursor:pointer;font-size:14px;display:inline-flex;align-items:center;justify-content:center;padding:0;transition:.15s}
        .theme-toggle:hover{color:var(--text);border-color:var(--accent)}
    </style>
</head>
<body>
<div class="card">
    <button type="button" class="theme-toggle" onclick="toggleClawTheme()" aria-label="Alternar modo claro/escuro" title="Alternar modo claro/escuro"><span id="themeIcon">🌙</span></button>
    <div class="icon">⏱️</div>
    <div class="title">Link Expirado ou Inactivo</div>
    <div class="sub">Este link de acesso foi desactivado ou expirou.<br>Contacta quem te enviou o link para obter um novo acesso.</div>
    <div class="contact">© PartYard/Setq.AI Rights reserved 2026</div>
</div>
<script>
(function(){
    var KEY='cy-theme',saved=null;
    try{saved=localStorage.getItem(KEY);}catch(e){}
    var t=(saved==='light'?'light':'dark');
    document.documentElement.setAttribute('data-theme',t);
    var ic=document.getElementById('themeIcon');if(ic)ic.textContent=(t==='light'?'☀️':'🌙');
})();
function toggleClawTheme(){
    var cur=document.documentElement.getAttribute('data-theme')==='light'?'light':'dark';
    var next=cur==='light'?'dark':'light';
    document.documentElement.setAttribute('data-theme',next);
    var ic=document.getElementById('themeIcon');if(ic)ic.textContent=(next==='light'?'☀️':'🌙');
    try{localStorage.setItem('cy-theme',next);}catch(e){}
}
</script>
</body>
</html>
