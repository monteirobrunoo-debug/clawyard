<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — Login</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            background: #0a0a0a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Background agents decoration */
        .bg-agents {
            position: fixed; inset: 0;
            display: grid; grid-template-columns: repeat(5, 1fr);
            opacity: 0.04; pointer-events: none; z-index: 0;
            font-size: 80px;
            align-items: center; justify-items: center;
            padding: 20px;
            gap: 40px;
        }

        .login-box {
            position: relative; z-index: 1;
            background: #111; border: 1px solid #1e1e1e;
            border-radius: 24px; padding: 40px;
            width: 100%; max-width: 400px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.5);
        }

        .login-logo {
            text-align: center; margin-bottom: 32px;
        }

        .login-logo .paw { font-size: 48px; display: block; margin-bottom: 8px; }
        .login-logo h1 { font-size: 28px; font-weight: 800; color: #76b900; margin: 0 0 4px; }
        .login-logo p { font-size: 12px; color: #444; }

        .form-group { margin-bottom: 16px; }

        label {
            display: block; font-size: 12px; color: #666;
            margin-bottom: 6px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
        }

        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%; background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 10px; padding: 12px 16px; color: #e5e5e5;
            font-size: 14px; outline: none; transition: border-color 0.2s;
            box-sizing: border-box;
        }

        input:focus { border-color: #76b900; }

        .remember-row {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 24px; font-size: 13px;
        }

        .remember-row label { text-transform: none; letter-spacing: 0; color: #555; margin: 0; }
        .remember-row a { color: #76b900; text-decoration: none; font-size: 12px; }

        .login-btn {
            width: 100%; background: #76b900; color: #000; border: none;
            border-radius: 12px; padding: 14px; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: background 0.2s;
        }
        .login-btn:hover { background: #8fd400; }

        .register-link {
            text-align: center; margin-top: 20px; font-size: 13px; color: #555;
        }
        .register-link a { color: #76b900; text-decoration: none; font-weight: 600; }

        .error-msg {
            background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255,68,68,0.3);
            color: #ff6666; font-size: 12px; padding: 10px 14px;
            border-radius: 8px; margin-bottom: 16px;
        }
    </style>
</head>
<body>

<!-- Background decoration -->
<div class="bg-agents">
    <span>🚢</span><span>💼</span><span>🔧</span><span>📧</span><span>📊</span>
    <span>📄</span><span>🧠</span><span>⚡</span><span>🌐</span><span>🚢</span>
    <span>💼</span><span>🔧</span><span>📧</span><span>📊</span><span>📄</span>
</div>

<div class="login-box">
    <div class="login-logo">
        <span class="paw">🐾</span>
        <h1>ClawYard</h1>
        <p>© PartYard_B.Mont_H&P Group rights reserved 2026</p>
    </div>

    {{ $slot }}

    <div class="register-link">
        Novo utilizador? <a href="{{ route('register') }}">Criar conta</a>
    </div>
</div>

</body>
</html>
