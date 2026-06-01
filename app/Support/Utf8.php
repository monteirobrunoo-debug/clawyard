<?php

namespace App\Support;

/**
 * Sanitização UTF-8 — fonte única (#138, 2026-06-01).
 *
 * Conteúdo externo (resultados de web search de portais raspados, chunks de
 * livros extraídos de PDF) pode conter bytes UTF-8 inválidos. Quando esse texto
 * entra no body de um request Anthropic, o json_encode (Guzzle 'json' => [...])
 * rebenta com "Malformed UTF-8 characters" e o agente falha por inteiro.
 *
 * clean() garante UTF-8 válido com caminho rápido: se já é válido — a esmagadora
 * maioria dos casos, custo ~0 — devolve tal e qual; senão substitui as
 * sequências partidas por U+FFFD (�).
 */
final class Utf8
{
    public static function clean(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $clean = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);
        return $clean;
    }
}
