#!/usr/bin/env perl
# wire-nsn-lookup-everywhere.pl
#
# Alarga o NSN/NATO local lookup a TODOS os agentes que têm WebSearchTrait
# mas ainda não chamam augmentWithNsnLookup. Adiciona:
#   1. use App\Agents\Traits\NsnLookupTrait;   (importação)
#   2. use NsnLookupTrait;                       (no class)
#   3. $X = $this->augmentWithNsnLookup($X, …);  (depois de cada augmentWithWebSearch)
#
# Idempotente: skipa agentes que já têm o trait.
# Custo runtime: ~0 — NsnLookupTrait extractNsns só faz regex match; só
# chama o tool se houver NSN na mensagem. 95%+ das mensagens não têm NSN
# portanto overhead é negligenciável.
#
# Agentes excluídos automaticamente: os que NÃO têm WebSearchTrait
# (Acingov/Batch/Orchestrator — não fazem chat livre).
#
# Uso: perl scripts/wire-nsn-lookup-everywhere.pl app/Agents/*Agent.php

use strict;
use warnings;

my $modified = 0;
my $skipped  = 0;

for my $file (@ARGV) {
    open my $fh, '<', $file or die "Cannot read $file: $!";
    local $/;
    my $content = <$fh>;
    close $fh;

    # Skip se não tem WebSearchTrait (não faz augment, nem precisa)
    if ($content !~ /use\s+WebSearchTrait;/) {
        $skipped++;
        print "  ⏭  $file (sem WebSearchTrait — não aplicável)\n";
        next;
    }

    # Skip se já tem NsnLookupTrait usage (já wired)
    if ($content =~ /use\s+NsnLookupTrait;/) {
        $skipped++;
        print "  ✓  $file (já tem NsnLookupTrait)\n";
        next;
    }

    my $original = $content;

    # 1. Import statement: add after WebSearchTrait import (if not present)
    if ($content !~ m{use\s+App\\Agents\\Traits\\NsnLookupTrait;}) {
        $content =~ s{
            (use\s+App\\Agents\\Traits\\WebSearchTrait;\n)
        }{$1use App\\Agents\\Traits\\NsnLookupTrait;\n}x;
    }

    # 2. Class use trait: add after `use WebSearchTrait;` (with leading whitespace)
    $content =~ s{
        (^(\s+)use\s+WebSearchTrait;\s*\n)
    }{$1$2use NsnLookupTrait;\n}xm;

    # 3. Method calls: after each `$X = $this->augmentWithWebSearch(...)` add
    #    `$X = $this->augmentWithNsnLookup($X, ...)` preserving args.
    #    Pattern: any $var = $this->augmentWithWebSearch($var [, $heartbeat]);
    $content =~ s{
        (^(\s+)(\$\w+)\s*=\s*\$this->augmentWithWebSearch\(\3(?:\s*,\s*([^)]*))?\)\s*;\s*\n)
        (?!.*\$this->augmentWithNsnLookup\()  # negative lookahead: don't double-wire
    }{
        my ($line, $indent, $var, $extra) = ($1, $2, $3, $4);
        my $hbArg = defined $extra && $extra =~ /\S/ ? ", $extra" : "";
        $line . "${indent}${var} = \$this->augmentWithNsnLookup(${var}${hbArg});\n";
    }xmge;

    if ($content ne $original) {
        open my $out, '>', $file or die "Cannot write $file: $!";
        print $out $content;
        close $out;
        $modified++;
        print "  ✓✓ $file\n";
    } else {
        print "  =  $file (nada para alterar)\n";
    }
}

print "\nModified: $modified  ·  Skipped: $skipped\n";
