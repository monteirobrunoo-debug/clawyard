<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<title>ClawYard — alerta token pool</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;padding:30px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.08);">

@php
    $isExhausted = $kind === 'exhausted';
    $color   = $isExhausted ? '#dc2626' : '#f59e0b';
    $emoji   = $isExhausted ? '🚨' : '⚠️';
    $title   = $isExhausted ? 'POOL ESGOTADO' : 'POOL EM ALERTA';
    $msg     = $isExhausted
        ? 'O pool de tokens Anthropic deste mês foi totalmente consumido. Novos chats com agentes vão ser rejeitados se o hard-gate estiver activo, OU vão continuar a debitar fora do orçamento.'
        : 'O pool de tokens Anthropic deste mês está perto do limite. Os agentes vão começar a ser mais concisos automaticamente, mas o consumo continua.';
@endphp

<tr><td style="background:{{ $color }};padding:24px 30px;color:#ffffff;">
    <div style="font-size:13px;text-transform:uppercase;letter-spacing:2px;opacity:0.85;">ClawYard · Token Budget</div>
    <div style="font-size:24px;font-weight:bold;margin-top:4px;">{{ $emoji }} {{ $title }}</div>
    <div style="font-size:14px;margin-top:6px;opacity:0.95;">Período: {{ $summary['period'] }}</div>
</td></tr>

<tr><td style="padding:24px 30px;">
    <p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#1f2937;">{{ $msg }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:20px;">
    <tr><td style="font-size:13px;color:#374151;">
        <div style="margin-bottom:6px;"><strong>Pool:</strong> €{{ number_format($summary['pool_eur'], 2) }}</div>
        <div style="margin-bottom:6px;"><strong>Gasto:</strong> <span style="color:{{ $color }};font-weight:bold;">€{{ number_format($summary['spent_eur'], 2) }} ({{ $summary['percent_used'] }}%)</span></div>
        <div><strong>Restante:</strong> €{{ number_format($summary['remaining_eur'], 2) }}</div>
    </td></tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr><td align="center" style="padding:8px 0 20px 0;">
        <a href="{{ $dashboardUrl }}"
           style="display:inline-block;background:#10b981;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:15px;font-weight:bold;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
            ✚ Adicionar Mais Tokens
        </a>
    </td></tr>
    <tr><td align="center">
        <a href="{{ $dashboardUrl }}" style="color:#6366f1;font-size:13px;text-decoration:none;">
            Ver dashboard completo →
        </a>
    </td></tr>
    </table>

    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 16px 0;">

    <p style="font-size:11px;color:#9ca3af;margin:0;line-height:1.5;">
        Este email foi enviado a Bruno, Catarina e Mónica como gestores de orçamento ClawYard.<br>
        Para alterar destinatários: <code style="background:#f3f4f6;padding:1px 6px;border-radius:3px;">.env → TOKEN_ADMIN_EMAILS</code>
    </p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
