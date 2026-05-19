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
            }
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
