<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ClawYard · {{ $client_name }}</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root {
            --green:#76b900;
            --bg:#0a0a0a; --bg2:#111; --bg3:#1a1a1a;
            --border:#1e1e1e; --border2:#2a2a2a;
            --text:#e5e5e5; --muted:#555;
            --role-ink:#666;
            --toggle-bg:rgba(255,255,255,.04); --toggle-border:rgba(255,255,255,.10);
        }
        :root[data-theme="light"]{
            --green:#4d7a00;
            --bg:#f4f6fa; --bg2:#ffffff; --bg3:#f1f5f9;
            --border:#e2e8f0; --border2:#cbd5e1;
            --text:#0f172a; --muted:#64748b;
            --role-ink:#64748b;
            --toggle-bg:rgba(15,23,42,.04); --toggle-border:rgba(15,23,42,.12);
        }
        body{font-family:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;margin:0;transition:background .2s,color .2s}
        .theme-toggle{margin-left:8px;width:34px;height:34px;border-radius:10px;background:var(--toggle-bg);border:1px solid var(--toggle-border);color:var(--muted);cursor:pointer;font-size:14px;display:inline-flex;align-items:center;justify-content:center;padding:0;transition:.15s}
        .theme-toggle:hover{color:var(--text);border-color:var(--green)}

        .header{display:flex;align-items:center;gap:12px;padding:14px 28px;border-bottom:1px solid var(--border);background:var(--bg2);position:sticky;top:0;z-index:100;flex-wrap:wrap}
        .logo{font-size:20px;font-weight:800;color:var(--green);letter-spacing:-0.5px}
        .badge{font-size:10px;background:var(--green);color:#000;padding:2px 8px;border-radius:20px;font-weight:700}
        .user{margin-left:auto;font-size:13px;color:#888}
        .user strong{color:var(--text)}

        .hero{text-align:center;padding:40px 24px 24px}
        .hero-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:10px}
        .hero h1{font-size:30px;font-weight:800;margin-bottom:6px;letter-spacing:-1px}
        .hero h1 span{color:var(--green)}
        .hero p{font-size:13px;color:var(--muted);max-width:520px;margin:0 auto;line-height:1.5}

        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;padding:0 28px 40px;max-width:1200px;margin:0 auto}

        .card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:22px 18px 18px;text-align:center;cursor:pointer;transition:transform .18s,box-shadow .18s,border-color .18s;text-decoration:none;display:block;position:relative;overflow:hidden;color:var(--text)}
        .card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--card-color,var(--green));opacity:.55;transition:opacity .2s}
        .card:hover{transform:translateY(-4px);box-shadow:0 10px 36px color-mix(in srgb, var(--card-color, var(--green)) 18%, transparent);border-color:color-mix(in srgb, var(--card-color, var(--green)) 40%, transparent)}
        .card:hover::before{opacity:1}

        .avatar{width:72px;height:72px;border-radius:50%;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-size:32px;background:var(--bg3);border:2px solid var(--border2);overflow:hidden;transition:border-color .2s}
        .card:hover .avatar{border-color:color-mix(in srgb, var(--card-color,var(--green)) 60%, transparent)}
        .avatar img{width:100%;height:100%;object-fit:cover}
        .name{font-size:13px;font-weight:700;margin-bottom:4px}
        .role{font-size:11px;color:var(--role-ink);line-height:1.4;min-height:30px;margin-bottom:14px}
        .btn{display:inline-block;background:var(--green);color:#000;padding:7px 20px;border-radius:20px;font-size:12px;font-weight:700;transition:background .15s,transform .15s}
        .card:hover .btn{background:#8fd400;transform:scale(1.05)}
        .dot{position:absolute;top:12px;right:12px;width:8px;height:8px;background:var(--green);border-radius:50%;box-shadow:0 0 6px var(--green);animation:pulse 2.5s ease-in-out infinite}
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }

        .empty{text-align:center;padding:60px 20px;color:var(--muted);font-size:13px}

        @media (max-width:640px){
            .header{padding:10px 16px;font-size:13px}
            .hero{padding:28px 16px 16px}
            .hero h1{font-size:22px}
            .grid{gap:10px;padding:0 14px 28px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
            .card{padding:18px 12px 14px}
            .avatar{width:58px;height:58px;font-size:26px}
            .name{font-size:12px}
            .role{font-size:10px;min-height:26px}
        }
    </style>
</head>
<body>

<header class="header">
    <span class="logo">ClawYard</span>
    <span class="badge">Portal privado</span>
    <div class="user">Bem-vindo, <strong>{{ $client_name }}</strong></div>
    <button type="button" class="theme-toggle" onclick="toggleClawTheme()" aria-label="Alternar modo claro/escuro" title="Alternar modo claro/escuro"><span id="themeIcon">🌙</span></button>
</header>

<div class="hero">
    <p class="hero-label">HP-Group · PartYard</p>
    <h1>Os teus <span>Agentes</span></h1>
    <p>{{ count($shares) }} assistente{{ count($shares) === 1 ? '' : 's' }} disponíve{{ count($shares) === 1 ? 'l' : 'is' }} para ti. Escolhe um para começar.</p>
</div>

<div class="grid">
    @forelse($shares as $share)
        @php
            $meta  = $agentMeta[$share->agent_key] ?? ['name' => $share->agent_key, 'emoji' => '🤖', 'color' => '#76b900', 'photo' => null];
            $title = $share->custom_title ?: $meta['name'];
            $href  = '/a/' . $share->token;
        @endphp
        <a class="card" href="{{ $href }}" style="--card-color: {{ $meta['color'] ?? '#76b900' }}">
            <div class="dot" style="background:{{ $meta['color'] ?? '#76b900' }};box-shadow:0 0 6px {{ $meta['color'] ?? '#76b900' }}"></div>
            <div class="avatar">
                @if(!empty($meta['photo']))
                    <img src="{{ $meta['photo'] }}" alt="{{ $title }}">
                @else
                    <span>{{ $meta['emoji'] ?? '🤖' }}</span>
                @endif
            </div>
            <div class="name">{{ $title }}</div>
            <div class="role">{{ \Illuminate\Support\Str::limit($share->welcome_message ?: ($meta['name'] ?? ''), 90) }}</div>
            <span class="btn">Conversar</span>
        </a>
    @empty
        <div class="empty">Sem agentes activos neste portal.</div>
    @endforelse
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
