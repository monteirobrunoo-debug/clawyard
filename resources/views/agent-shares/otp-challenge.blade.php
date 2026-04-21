<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Verificação — ClawYard</title>
    <style>
        /* ── Theme tokens ────────────────────────────────────────────────
           Defaults below are "night" (dark). The [data-theme="light"] block
           overrides them for light mode. Toggle is stored in localStorage
           so the recipient's choice persists across share/portal pages. */
        :root{
            --bg:#0a0a0f;
            --card:#111118;
            --border:#2a2a3a;
            --text:#e2e8f0;
            --text-strong:#cbd5e1;
            --muted:#94a3b8;
            --muted2:#64748b;
            --input-bg:#1a1a24;
            --accent:#76b900;
            --accent-ink:#000;
            --danger:#ef4444;
            --danger-ink:#fca5a5;
            --danger-bg:rgba(239,68,68,.1);
            --danger-border:rgba(239,68,68,.3);
            --ok-ink:#a3e635;
            --ok-bg:rgba(118,185,0,.1);
            --ok-border:rgba(118,185,0,.3);
            --toggle-bg:rgba(255,255,255,.04);
            --toggle-border:rgba(255,255,255,.10);
        }
        :root[data-theme="light"]{
            --bg:#f4f6fa;
            --card:#ffffff;
            --border:#e2e8f0;
            --text:#111827;
            --text-strong:#0b1220;
            --muted:#4b5563;
            --muted2:#6b7280;
            --input-bg:#f9fafb;
            --accent:#4d7a00;
            --accent-ink:#fff;
            --danger:#b91c1c;
            --danger-ink:#991b1b;
            --danger-bg:rgba(239,68,68,.08);
            --danger-border:rgba(239,68,68,.35);
            --ok-ink:#3f6212;
            --ok-bg:rgba(118,185,0,.12);
            --ok-border:rgba(77,122,0,.35);
            --toggle-bg:rgba(0,0,0,.04);
            --toggle-border:rgba(0,0,0,.10);
        }

        *{box-sizing:border-box;margin:0;padding:0}
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;transition:background .2s,color .2s}
        .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:36px 32px;width:100%;max-width:400px;text-align:center;position:relative}
        .icon{font-size:42px;margin-bottom:10px}
        .title{font-size:18px;font-weight:800;margin-bottom:4px}
        .sub{font-size:13px;color:var(--muted);margin-bottom:22px;line-height:1.5}
        .sub strong{color:var(--text-strong)}
        input{width:100%;background:var(--input-bg);border:1px solid var(--border);color:var(--text);padding:12px 16px;border-radius:10px;font-size:14px;outline:none;margin-bottom:10px;transition:.15s}
        input:focus{border-color:var(--accent)}
        input.code{text-align:center;letter-spacing:8px;font-size:18px;font-weight:700;font-variant-numeric:tabular-nums}
        .error{font-size:12px;color:var(--danger);margin:-4px 0 12px;text-align:left}
        button{width:100%;background:var(--accent);color:var(--accent-ink);font-weight:700;padding:12px;border:none;border-radius:10px;cursor:pointer;font-size:14px;transition:.15s}
        button:hover{filter:brightness(1.1)}
        .muted{font-size:12px;color:var(--muted2);margin-top:16px}
        .muted a{color:var(--accent);text-decoration:none}
        .badge{display:inline-block;background:var(--danger-bg);border:1px solid var(--danger-border);color:var(--danger-ink);padding:5px 10px;border-radius:999px;font-size:11px;font-weight:600;margin-bottom:14px}
        .badge.success{background:var(--ok-bg);border-color:var(--ok-border);color:var(--ok-ink)}

        /* ── Theme toggle (sun/moon) ───────────────────────────────── */
        .theme-toggle{
            position:absolute;top:12px;right:12px;
            width:34px;height:34px;border-radius:10px;
            background:var(--toggle-bg);border:1px solid var(--toggle-border);
            color:var(--muted);cursor:pointer;font-size:15px;line-height:1;
            display:inline-flex;align-items:center;justify-content:center;
            padding:0;transition:.15s;
        }
        .theme-toggle:hover{color:var(--text);border-color:var(--accent)}
    </style>
</head>
<body>
<div class="card">
    <button type="button"
            class="theme-toggle"
            onclick="window.__toggleClawTheme && window.__toggleClawTheme()"
            aria-label="Alternar modo claro/escuro"
            title="Alternar modo claro/escuro">
        <span id="themeIcon">🌙</span>
    </button>
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
            <input type="email" name="email"
                   value="{{ old('email') }}"
                   placeholder="o-teu-email@empresa.pt"
                   autocomplete="email" required autofocus>
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

        @if(!empty($error_code) || $errors->has('code'))
        <div class="error">{{ $error_code ?? $errors->first('code') }}</div>
        @endif

        <form method="POST" action="/a/{{ $share->token }}/otp/verify">
            @csrf
            {{-- Email pinning (CRITICAL for multi-recipient):
                 The OTP row was stored against a SPECIFIC email (the one the
                 recipient typed on step 1, or client_email if auto-issued).
                 Step 2 MUST POST back that same email or verifyOtp can't
                 find the row. Priority order:
                 1) $entered_email — always passed from the controller, the
                    source of truth. Covers request/verify/auto-issue flows.
                 2) old('email') — if the view was rendered from a redirect
                    that flashed input.
                 3) request()->input('email') — legacy fallback for the
                    first render from requestOtp() (no longer strictly
                    needed, kept defensively). --}}
            <input type="hidden"
                   name="email"
                   value="{{ $entered_email ?? old('email', !empty($auto_issued) ? ($share->client_email ?? '') : request()->input('email', '')) }}">
            <input type="text" name="code" class="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="······" required autofocus>
            <button type="submit">Entrar →</button>
        </form>

        <div class="muted">
            Não recebeste? <a href="/a/{{ $share->token }}">Pedir novo código</a>
        </div>
    @endif
</div>
<script>
/* Theme (day/night) toggle shared with the other share pages.
   Key is scoped to 'clawyard' so it persists across /a/{token},
   /p/{portal_token} and the chat view. */
(function(){
    var KEY = 'cy-theme';
    function apply(t){
        document.documentElement.setAttribute('data-theme', t);
        var ic = document.getElementById('themeIcon');
        if (ic) ic.textContent = (t === 'light' ? '☀️' : '🌙');
    }
    var saved = null;
    try { saved = localStorage.getItem(KEY); } catch (e) {}
    apply(saved === 'light' ? 'light' : 'dark');

    window.__toggleClawTheme = function(){
        var cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        var next = cur === 'light' ? 'dark' : 'light';
        apply(next);
        try { localStorage.setItem(KEY, next); } catch (e) {}
    };
})();
</script>
</body>
</html>
