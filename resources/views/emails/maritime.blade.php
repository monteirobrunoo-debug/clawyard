<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #f4f4f4; font-family: 'Helvetica Neue', Arial, sans-serif; }
        .wrapper { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 16px rgba(0,0,0,0.08); }
        .header { background: #0a0a0a; padding: 28px 36px; display: flex; align-items: center; gap: 12px; }
        .logo { font-size: 22px; font-weight: 800; color: #76b900; letter-spacing: -0.5px; }
        .tagline { font-size: 11px; color: #555; margin-top: 2px; }
        .divider { height: 3px; background: linear-gradient(90deg, #76b900, #4a7300); }
        .body { padding: 40px 36px; }
        .body p { font-size: 15px; line-height: 1.8; color: #333; margin-bottom: 14px; white-space: pre-line; }
        .footer { background: #f9f9f9; border-top: 1px solid #eee; padding: 24px 36px; }
        .footer-logo { font-size: 14px; font-weight: 700; color: #76b900; }
        .footer-info { font-size: 12px; color: #999; margin-top: 6px; line-height: 1.6; }
        .footer-links { margin-top: 12px; }
        .footer-links a { font-size: 12px; color: #76b900; text-decoration: none; margin-right: 16px; }
        .badge { display: inline-block; background: #76b900; color: #000; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-left: 8px; vertical-align: middle; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <div>
            <div class="logo">🐾 ClawYard <span class="badge">MARITIME</span></div>
            <div class="tagline">Marine Spare Parts & Technical Services</div>
        </div>
    </div>
    <div class="divider"></div>
    <div class="body">
        <p>{{ $emailBody }}</p>
    </div>
    <div class="footer">
        <div class="footer-logo">🐾 ClawYard — IT Partyard</div>
        <div class="footer-info">
            Marine Spare Parts &amp; Technical Services<br>
            © PartYard_B.Mont_H&P Group rights reserved 2026<br>
            {{ config('mail.from.address') }}
        </div>
        <div class="footer-links">
            <a href="https://clawyard_py.on-forge.com">Website</a>
            <a href="mailto:{{ config('mail.from.address') }}">Contact</a>
        </div>
    </div>
</div>
</body>
</html>
