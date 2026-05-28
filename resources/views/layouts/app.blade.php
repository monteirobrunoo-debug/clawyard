<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- PWA: manifest + theme colour + apple-touch icon. O service
             worker é registado mais abaixo (após @vite) para evitar
             race com os assets. 2026-05-19. --}}
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#4f46e5">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="ClawYard">
        <link rel="apple-touch-icon" href="/images/clawyard-icon.svg">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <script>
            // Global helper: detect 401 OTP requirements from any AJAX
            // call and redirect to /otp/challenge automatically. Pages
            // wrap their fetches with:
            //   if (res.status === 401 && await maybeRedirectOnOtp(res)) return;
            // Returns true when handled (caller should bail), false otherwise.
            window.maybeRedirectOnOtp = async function (res) {
                try {
                    const data = await res.clone().json();
                    if (data && data.error === 'otp_required' && data.redirect) {
                        window.location.href = data.redirect;
                        return true;
                    }
                } catch (_) { /* not JSON or no body — fall through */ }
                return false;
            };

            // PWA: regista o service worker para offline read-only nos
            // dashboards principais. Só corre em contextos seguros
            // (HTTPS ou localhost) — em http puro o registo falharia
            // silenciosamente, por isso checamos isSecureContext primeiro.
            if ('serviceWorker' in navigator && window.isSecureContext) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker
                        .register('/sw.js', { scope: '/' })
                        .catch((e) => console.warn('SW registration failed:', e));
                });

                // 2026-05-28 anti-freeze: quando o SW activa uma nova versão
                // (após deploy), envia postMessage 'sw-updated'. Mostramos um
                // banner amarelo "⚡ Nova versão ClawYard — Reload" no topo
                // da página. Click reloads → user vê código novo sem hard
                // refresh manual. Resolve o "octane freeze" definitivamente.
                navigator.serviceWorker.addEventListener('message', (event) => {
                    if (event.data?.type !== 'sw-updated') return;
                    if (document.getElementById('sw-update-banner')) return; // anti-dup
                    const banner = document.createElement('div');
                    banner.id = 'sw-update-banner';
                    banner.setAttribute('role', 'alert');
                    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:linear-gradient(90deg,#fbbf24,#f59e0b);color:#1f2937;padding:10px 16px;font-size:13.5px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.18);font-family:system-ui,-apple-system,sans-serif;';
                    banner.innerHTML = `
                        <span>⚡ Nova versão ClawYard disponível</span>
                        <button id="sw-reload-btn" style="background:#1f2937;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                            🔄 Reload
                        </button>
                        <button id="sw-dismiss-btn" aria-label="Dispensar" style="background:transparent;border:none;color:#1f2937;font-size:18px;cursor:pointer;padding:0 4px;line-height:1;">×</button>
                    `;
                    document.body.appendChild(banner);
                    document.getElementById('sw-reload-btn').onclick = () => {
                        // skipWaiting + clean reload — apanha sempre o código novo.
                        location.reload();
                    };
                    document.getElementById('sw-dismiss-btn').onclick = () => banner.remove();
                });
            }
        </script>

        {{-- 2026-05-21: InstantPage — hover sobre links começa a fazer
             pre-fetch antes do click, então a navegação seguinte é
             quase instantânea. Self-hosted (sem CDN externo, respeita
             CSP). Ignora hashes na URL, links externos, e respeita
             prefers-reduced-motion / save-data.
             https://instant.page MIT licence — 2.5kB gzipped. --}}
        <script>
        (function () {
            // Skip se browser pediu data-saver
            if (navigator.connection?.saveData) return;
            // Skip em mobile (touch tem hover events estranhos)
            if (matchMedia('(hover: hover) and (pointer: fine)').matches === false) return;

            const prefetched = new Set();
            const head = document.head;

            const isLocal = (url) => {
                try {
                    const u = new URL(url, location.href);
                    if (u.origin !== location.origin) return false;
                    if (u.pathname === location.pathname && u.hash) return false;
                    if (u.pathname.match(/\.(pdf|docx|xlsx|zip|png|jpg)$/i)) return false;
                    return true;
                } catch { return false; }
            };

            const prefetch = (url) => {
                if (prefetched.has(url)) return;
                prefetched.add(url);
                const link = document.createElement('link');
                link.rel  = 'prefetch';
                link.href = url;
                head.appendChild(link);
            };

            let hoverTimer = null;
            document.addEventListener('mouseover', (e) => {
                const a = e.target.closest('a[href]');
                if (!a || !isLocal(a.href)) return;
                clearTimeout(hoverTimer);
                hoverTimer = setTimeout(() => prefetch(a.href), 65);
            }, { passive: true });
            document.addEventListener('mouseout', () => clearTimeout(hoverTimer), { passive: true });
        })();
        </script>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @include('partials.command-palette')
        @include('partials.toast-system')
        @include('partials.view-transitions')
        @include('partials.cheat-sheet')
        {{-- global-dropzone removido propositadamente: o overlay capturava QUALQUER
             drag em QUALQUER página e redirecionava para /hp-history/upload, mesmo
             quando o utilizador queria anexar ao chat de um agente. Substituído
             por dropzones explícitas (ver dashboard.blade.php → secção "Base de
             Conhecimento"). Para anexar ao chat o handler já existe em
             welcome.blade.php → fileInputChangeHandler. --}}
        @include('partials.presence')
        @include('partials.activity-meter')
        {{-- Mobile UX: modais → bottom-sheet em viewport <sm, textarea
             auto-grow, 16px font para evitar iOS zoom. 2026-05-19. --}}
        @include('partials.mobile-ux')
        {{-- Web Push subscribe CTA — só aparece em users autenticados,
             1× por sessão (dispensável 24h). 2026-05-20. --}}
        @include('partials.push-subscribe')
    </body>
</html>
