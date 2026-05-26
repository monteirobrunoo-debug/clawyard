#!/usr/bin/env perl
# fix-stream-error.pl
#
# Injecta try/catch em torno do "$buf .= $body->read(1024);" em todos os
# agentes que ainda têm a leitura SSE sem protecção.
#
# Sintoma a corrigir: "❌ Erro: Error in input stream" — Guzzle/PSR-7
# lança quando Anthropic fecha connection após message_stop. Sem try/catch
# isso vira erro frontend apesar do user ter visto resposta completa.
#
# Match preciso: só apanha "while (!$body->eof()) {" SEGUIDO directamente
# de "    $buf .= $body->read(1024);" (com whitespace entre). NÃO apanha:
#   • QuantumAgent (já tem try/catch dentro do while)
#   • MilDef (usa trait HandlesAnthropicStream)
#
# Uso: perl scripts/fix-stream-error.pl app/Agents/*Agent.php

use strict;
use warnings;

my $modified = 0;
my $skipped  = 0;

for my $file (@ARGV) {
    open my $fh, '<', $file or die "Cannot read $file: $!";
    local $/;
    my $content = <$fh>;
    close $fh;

    # Match: "while (!$body->eof()) {" + whitespace + "$buf .= $body->read(1024);"
    # Captura indentação do read para preservar
    my $pattern = qr/(while \(!\$body->eof\(\)\) \{\n)(\s+)\$buf \.= \$body->read\(1024\);/;

    my $original = $content;
    $content =~ s{$pattern}{
        my ($prefix, $indent) = ($1, $2);
        my $wrapped =
            $prefix .
            "${indent}try {\n" .
            "${indent}    \$buf .= \$body->read(1024);\n" .
            "${indent}} catch (\\Throwable \$readErr) {\n" .
            "${indent}    if (\$full === '') throw \$readErr;\n" .
            "${indent}    \\Log::info('stream read graceful end after partial response', ['msg' => \$readErr->getMessage(), 'len' => strlen(\$full)]);\n" .
            "${indent}    break;\n" .
            "${indent}}";
        $wrapped;
    }ge;

    if ($content ne $original) {
        open my $out, '>', $file or die "Cannot write $file: $!";
        print $out $content;
        close $out;
        $modified++;
        print "  ✓ $file\n";
    } else {
        $skipped++;
    }
}

print "\nModified: $modified  ·  Skipped (no match): $skipped\n";
