<?php

namespace App\Console\Commands;

use App\Services\AgentTools\NsnLookupTool;
use Illuminate\Console\Command;

/**
 * Test/lookup manual de um NSN. Útil para diagnosticar ou prefetch.
 *
 * Uso:
 *   php artisan nsn:lookup 5331-01-234-5678
 *   php artisan nsn:lookup 5331012345678 --hint="O-ring 25mm"
 */
class NsnLookupTestCommand extends Command
{
    protected $signature = 'nsn:lookup {nsn : 13 dígitos com ou sem hifens}
                            {--hint= : Item description hint to refine search}';

    protected $description = 'Lookup um NSN — devolve OEM, distribuidores, emails';

    public function handle(NsnLookupTool $tool): int
    {
        $nsn  = (string) $this->argument('nsn');
        $hint = (string) ($this->option('hint') ?? '');

        $this->info("Looking up NSN: {$nsn}");
        if ($hint !== '') $this->line("Item hint: {$hint}");
        $this->line('');

        $res = $tool->execute(
            input:   ['nsn' => $nsn, 'item_hint' => $hint],
            context: ['agent_key' => 'cli', 'user_id' => null, 'tender_id' => null],
        );

        if (!($res['ok'] ?? false)) {
            $this->error('FAIL: ' . ($res['error'] ?? 'unknown'));
            return self::FAILURE;
        }

        $this->line($res['result']);
        $this->line('');
        $this->info('Cost: $' . number_format((float) ($res['cost_usd'] ?? 0), 4));

        return self::SUCCESS;
    }
}
