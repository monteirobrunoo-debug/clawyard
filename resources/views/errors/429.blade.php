{{--
    Friendly 429 — replaces Laravel's stock "Too Many Requests" page.

    Why it exists: a user (catarina.sequeira) hit the email-verification
    rate limit by clicking the link multiple times. Stock Laravel showed
    her a bare-bones "429 Too Many Requests" with no actionable guidance.
    This page tells her exactly what to do next, in Portuguese.

    Retry-After: when Laravel rate-limits a route it sets the
    Retry-After header. We surface it as a countdown so the user
    knows when they can try again.
--}}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demasiadas tentativas — PartYard</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0f1115; color: #e5e7eb; margin: 0;
            min-height: 100vh; display: grid; place-items: center; padding: 24px;
        }
        .card {
            max-width: 520px; width: 100%; background: #1a1d24;
            border: 1px solid #2a2f3a; border-radius: 14px; padding: 32px;
            box-shadow: 0 10px 40px rgba(0,0,0,.4);
        }
        h1 { margin: 0 0 8px; font-size: 22px; }
        h1 .icon { color: #f59e0b; margin-right: 8px; }
        p { color: #9ca3af; line-height: 1.55; margin: 8px 0; }
        .countdown {
            display: inline-block; background: #243248; color: #93c5fd;
            border: 1px solid #354a66; border-radius: 8px;
            padding: 10px 16px; font-weight: 600; font-size: 15px;
            margin: 12px 0 4px;
        }
        ul { color: #9ca3af; padding-left: 20px; line-height: 1.7; }
        ul li b { color: #e5e7eb; }
        a.btn {
            display: inline-block; margin-top: 18px; padding: 10px 18px;
            background: #4f46e5; color: white; border-radius: 8px;
            text-decoration: none; font-weight: 600;
        }
        a.btn:hover { background: #4338ca; }
        .meta { color: #6b7280; font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
<div class="card">
    <h1><span class="icon">⏳</span>Demasiadas tentativas</h1>
    <p>
        Detectámos demasiados pedidos seguidos a partir do teu IP no espaço de
        um minuto. Isto é uma protecção contra abuso, mas pode disparar quando
        carregas várias vezes na mesma ligação.
    </p>

    @php
        $retryAfter = request()->header('Retry-After') ?? null;
        if (!$retryAfter && isset($exception)) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
        }
        $retryAfter = (int) ($retryAfter ?? 60);
    @endphp

    <div class="countdown" id="countdown">
        Tenta novamente em <span id="seconds">{{ $retryAfter }}</span> s
    </div>

    <ul>
        <li>Fecha as outras abas e janelas que tenhas abertas deste site.</li>
        <li>Espera o contador acabar acima.</li>
        <li>Clica <b>uma vez</b> no botão / link e aguarda a página carregar.</li>
        <li>Não carregues "Refresh" repetidamente.</li>
    </ul>

    <a href="/" class="btn">Voltar ao início</a>

    <div class="meta">
        Se vires esta página repetidamente, contacta o administrador. Pode haver
        outro utilizador no mesmo IP a esgotar o limite.
    </div>
</div>

<script>
    // Decremental countdown so the user knows EXACTLY when they can try.
    (function () {
        var n = parseInt(document.getElementById('seconds').textContent, 10) || 60;
        var t = setInterval(function () {
            n -= 1;
            if (n <= 0) {
                document.getElementById('countdown').innerHTML = '✅ Já podes tentar novamente.';
                clearInterval(t);
                return;
            }
            document.getElementById('seconds').textContent = n;
        }, 1000);
    })();
</script>
</body>
</html>
