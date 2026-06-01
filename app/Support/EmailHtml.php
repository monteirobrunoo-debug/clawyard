<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Render de emails "bonitos" — fonte única (Projecto emails Fase 2, 2026-06-01).
 *
 * Os agentes (Daniel, Marta, Dra. Sofia…) escrevem em MARKDOWN. Esta classe
 * transforma esse markdown em HTML bem formatado para email:
 *   1. markdown → HTML (GitHub Flavored: tabelas, listas, links) com config
 *      segura (html_input='strip', sem links unsafe — vem de LLM/user).
 *   2. CSS INLINE (TijsVerkoyen\CssToInlineStyles) — o Outlook ignora <style>,
 *      por isso cada elemento leva style="…". É o que faz ficar "alinhado".
 *   3. Template de marca ClawYard com layout em TABELA (o esqueleto que o
 *      Outlook respeita: tabela exterior + tabela interior 600px centrada).
 *
 * Usado por EmailSendController (render completo) e por templates existentes
 * como maritime.blade / AgentShareController (bodyHtml — só o fragmento).
 */
final class EmailHtml
{
    /** CSS dos elementos do corpo, scoped a .email-body (não afecta o layout). */
    private const BODY_CSS = <<<CSS
.email-body { color:#333333; font-size:15px; line-height:1.7; }
.email-body h1 { font-size:22px; margin:24px 0 12px; color:#1a1a1a; line-height:1.3; }
.email-body h2 { font-size:18px; margin:20px 0 10px; color:#1a1a1a; line-height:1.3; }
.email-body h3 { font-size:16px; margin:16px 0 8px; color:#333333; }
.email-body p { margin:0 0 14px; }
.email-body ul { margin:0 0 14px; padding-left:22px; }
.email-body ol { margin:0 0 14px; padding-left:22px; }
.email-body li { margin-bottom:6px; }
.email-body strong { color:#1a1a1a; }
.email-body em { color:#333333; }
.email-body a { color:#2563eb; text-decoration:underline; }
.email-body blockquote { margin:0 0 14px; padding:8px 16px; border-left:3px solid #76b900; background:#f6f9f0; color:#555555; }
.email-body code { background:#f0f0f0; padding:2px 5px; border-radius:3px; font-family:Consolas,monospace; font-size:13px; }
.email-body pre { background:#f6f8fa; padding:14px; border-radius:6px; }
.email-body table { border-collapse:collapse; width:100%; margin:0 0 14px; }
.email-body th { border:1px solid #dddddd; padding:8px 12px; text-align:left; font-size:13px; background:#f4f4f4; font-weight:600; }
.email-body td { border:1px solid #dddddd; padding:8px 12px; text-align:left; font-size:13px; }
.email-body hr { border:0; border-top:1px solid #eeeeee; margin:20px 0; }
CSS;

    /** Markdown → HTML seguro (GFM). Fragmento sem estilos. */
    public static function markdownToHtml(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);
        return (string) $converter->convert($markdown);
    }

    /**
     * Corpo renderizado com estilos INLINE — fragmento para colar dentro de
     * templates de email já existentes (maritime.blade, share emails).
     */
    public static function bodyHtml(string $markdown): string
    {
        $html    = '<div class="email-body">' . self::markdownToHtml($markdown) . '</div>';
        $inlined = (new CssToInlineStyles())->convert($html, self::BODY_CSS);
        // o inliner devolve um documento completo — extrai só o conteúdo do <body>
        if (preg_match('#<body[^>]*>(.*)</body>#si', $inlined, $m)) {
            return trim($m[1]);
        }
        return $inlined;
    }

    /**
     * Email COMPLETO: template de marca ClawYard (layout em tabela) + corpo,
     * tudo com CSS inline → seguro em Outlook, Gmail, Apple Mail.
     */
    public static function render(string $markdown, string $title = ''): string
    {
        $body = '<div class="email-body">' . self::markdownToHtml($markdown) . '</div>';
        $full = self::brandTemplate($body);
        return (new CssToInlineStyles())->convert($full, self::BODY_CSS);
    }

    private static function brandTemplate(string $bodyHtml): string
    {
        $from = htmlspecialchars((string) config('mail.from.address', 'no-reply@hp-group.org'));
        return <<<HTML
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,'Segoe UI',Arial,sans-serif;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;padding:24px 0;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;">
      <tr><td style="border-top:4px solid #76b900;padding:24px 32px 16px 32px;border-bottom:1px solid #eeeeee;">
        <span style="font-size:22px;font-weight:bold;color:#76b900;">🐾 ClawYard</span>
      </td></tr>
      <tr><td style="padding:28px 32px 28px 32px;">{$bodyHtml}</td></tr>
      <tr><td style="padding:16px 32px 28px 32px;border-top:1px solid #eeeeee;font-size:12px;color:#888888;line-height:1.6;">
        ClawYard &middot; IT Partyard LDA<br>
        Marine Spare Parts &amp; Technical Services<br>
        Setúbal, Portugal &middot; {$from}
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }
}
