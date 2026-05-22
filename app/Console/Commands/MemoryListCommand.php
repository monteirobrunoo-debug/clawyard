<?php

namespace App\Console\Commands;

use App\Models\AgentMemory;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * memory:list — debug/inspect das memórias LTM dos agentes.
 *
 * Substitui as tentativas de `php artisan tinker --execute="..."` que
 * sofriam com quoting hell (várias camadas de bash + PHP namespace
 * separators a serem comidos).
 *
 * Uso:
 *   php artisan memory:list                    # todas as memórias, tabela
 *   php artisan memory:list --user=bruno      # filtra por substring email
 *   php artisan memory:list --agent=mildef    # filtra por agent_key
 *   php artisan memory:list --count           # só contagem
 */
class MemoryListCommand extends Command
{
    protected $signature = 'memory:list
                            {--user= : Filtra por email do user (substring match)}
                            {--agent= : Filtra por agent_key (mildef, hr, etc.)}
                            {--count : Só mostra contagens, sem listar}';

    protected $description = 'Lista as memórias LTM (long-term memory) dos agentes';

    public function handle(): int
    {
        $query = AgentMemory::query()->with('user:id,name,email');

        if ($email = $this->option('user')) {
            $query->whereHas('user', fn ($q) => $q->where('email', 'like', "%{$email}%"));
        }
        if ($agent = $this->option('agent')) {
            $query->where('agent_key', $agent);
        }

        $total = (clone $query)->count();
        $this->info("Total: {$total} memórias");

        if ($total === 0) {
            $this->warn('Nenhuma memória encontrada.');
            $this->line('Para gravar uma memória, no chat de um agente escreve:');
            $this->line('  "lembra-te que prefiro X"  ou  "anota: regra Y"  ou  "para futuro Z"');
            return self::SUCCESS;
        }

        // Breakdown por (user, agent)
        $breakdown = (clone $query)
            ->selectRaw('user_id, agent_key, COUNT(*) as n')
            ->groupBy('user_id', 'agent_key')
            ->orderByDesc('n')
            ->get();

        $this->line('');
        $this->line('Breakdown por (user × agent):');
        foreach ($breakdown as $b) {
            $u = User::find($b->user_id);
            $name = $u ? $u->email : "user#{$b->user_id}";
            $this->line(sprintf('  %-40s %-12s %d', $name, $b->agent_key, $b->n));
        }

        if ($this->option('count')) {
            return self::SUCCESS;
        }

        $this->line('');
        $rows = $query->orderBy('user_id')->orderBy('agent_key')->orderByDesc('importance')->get();
        $tableRows = [];
        foreach ($rows as $m) {
            $tableRows[] = [
                $m->user?->email ?? "user#{$m->user_id}",
                $m->agent_key,
                $m->memory_key,
                mb_strimwidth($m->memory_value, 0, 60, '…'),
                number_format((float) $m->importance, 2),
                $m->recall_count,
                $m->source,
            ];
        }

        $this->table(
            ['user', 'agent', 'key', 'value', 'imp', 'recalls', 'source'],
            $tableRows,
        );

        return self::SUCCESS;
    }
}
