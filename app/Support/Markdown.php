<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Markdown → HTML seguro (GitHub Flavored) — helper genérico.
 *
 * Conteúdo escrito por agentes (LLM) vem em markdown. Esta classe converte-o
 * para HTML (tabelas, listas, links, negrito) de forma SEGURA: html_input=strip
 * remove HTML cru e allow_unsafe_links=false bloqueia javascript:/data:.
 *
 * Usado em qualquer superfície que mostre prosa de agente: emails (App\Support\
 * EmailHtml), análises no dashboard e export PDF (service-analysis*.blade).
 * O styling fica a cargo de cada superfície (CSS da página / inline no email).
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);
        return (string) $converter->convert($markdown);
    }
}
