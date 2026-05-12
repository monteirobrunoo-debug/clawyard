<x-guest-layout>
    <div style="text-align:center;color:#e5e5e5">
        <h2 style="margin:0 0 14px 0;font-size:22px;color:#76b900">🔒 Código OTP</h2>

        @if($mode === 'email')
            <p style="opacity:.85;line-height:1.5;margin:0 0 6px 0">
                Enviámos um código de 6 dígitos para<br>
                <strong style="color:#fff">{{ $maskedEmail }}</strong>.
            </p>
            <p style="opacity:.55;font-size:12px;margin:0 0 18px 0">
                Verifica a caixa de entrada (e o spam). Válido durante 10 minutos · uso único.
            </p>
        @else
            <p style="opacity:.85;margin:0 0 18px 0">
                Introduz o código OTP de 6 dígitos da tua app autenticadora<br>
                (ou um código de recuperação que guardaste).
            </p>
        @endif

        @if(session('success'))
            <p style="color:#74e0a3;font-size:13px">{{ session('success') }}</p>
        @endif
        @if(session('error'))
            <p style="color:#f4c361;font-size:13px">{{ session('error') }}</p>
        @endif
        @if($errors->any())
            <p style="color:#ff6666;font-size:13px">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ route('login.2fa.verify') }}">@csrf
            <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required
                   placeholder="······"
                   style="width:220px;padding:14px 18px;font-size:24px;letter-spacing:8px;text-align:center;
                          background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;color:#e5e5e5;
                          font-family:ui-monospace,Menlo,monospace;box-sizing:border-box">
            <br>
            <button style="margin-top:16px;background:#76b900;border:none;color:#000;padding:14px 32px;
                           border-radius:12px;cursor:pointer;font-size:15px;font-weight:700">
                Continuar
            </button>
        </form>

        @if($mode === 'email')
            <form method="post" action="{{ route('login.2fa.resend') }}" style="margin-top:18px">@csrf
                <button style="background:none;border:none;color:#76b900;font-size:13px;
                               text-decoration:underline;cursor:pointer">
                    Reenviar código OTP
                </button>
            </form>
            <p style="margin-top:24px;font-size:12px;opacity:.5;line-height:1.5">
                💡 Para evitar o email a cada login, configura uma app autenticadora<br>
                (Google Authenticator / 1Password / Authy) em
                <a href="{{ route('profile.2fa') }}" style="color:#76b900">/profile/2fa</a>
                depois de entrares.
            </p>
        @endif
    </div>
</x-guest-layout>
