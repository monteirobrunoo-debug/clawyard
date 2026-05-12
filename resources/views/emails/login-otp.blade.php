<!doctype html>
<html lang="pt"><head><meta charset="utf-8"><title>Código ClawYard</title></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f4f6f8;padding:30px;margin:0">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e2e6ea">
        <tr><td style="background:#0f1115;color:#fff;padding:18px 22px">
            <h2 style="margin:0;font-size:18px">🔒 ClawYard — Código de acesso</h2>
        </td></tr>
        <tr><td style="padding:24px 22px;color:#222;line-height:1.55">
            <p style="margin:0 0 14px 0">Olá {{ $userName }},</p>
            <p style="margin:0 0 18px 0">O teu código de acesso para iniciar sessão é:</p>
            <div style="background:#f4f6f8;border:1px solid #d1d5db;border-radius:8px;padding:18px;text-align:center;margin:0 0 18px 0">
                <span style="font-family:ui-monospace,Menlo,monospace;font-size:32px;letter-spacing:8px;font-weight:700;color:#0f1115">{{ $code }}</span>
            </div>
            <p style="margin:0 0 12px 0;font-size:13px;color:#555">
                Válido durante <strong>{{ $ttlMinutes }} minutos</strong> · uso único · só funciona uma vez.
            </p>
            @if($ip)
            <p style="margin:0 0 12px 0;font-size:12px;color:#777">
                Pedido do IP <code>{{ $ip }}</code>.
            </p>
            @endif
            <hr style="border:none;border-top:1px solid #e2e6ea;margin:18px 0">
            <p style="margin:0;font-size:12px;color:#888">
                Se não foste tu a tentar entrar, ignora este email <strong>e muda a tua password</strong> em ClawYard.
                Este código sozinho não dá acesso — é preciso a tua password também.
            </p>
        </td></tr>
        <tr><td style="background:#fafbfc;color:#999;font-size:11px;text-align:center;padding:12px;border-top:1px solid #e2e6ea">
            ClawYard · H&amp;P Group · automatic — não respondas
        </td></tr>
    </table>
</body></html>
