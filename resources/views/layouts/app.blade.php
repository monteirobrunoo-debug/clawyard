<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

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
    </body>
</html>
