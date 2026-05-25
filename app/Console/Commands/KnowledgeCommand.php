<?php

namespace App\Console\Commands;

use App\Models\OrganizationalKnowledge;
use App\Services\OrganizationalMemoryService;
use Illuminate\Console\Command;

/**
 * knowledge — gere a memória organizacional partilhada PartYard.
 *
 * Uso:
 *   php artisan knowledge list                                    # listar tudo
 *   php artisan knowledge list --category=supplier --limit=10
 *   php artisan knowledge add "key" "value" [--category=...]
 *   php artisan knowledge search "MTU"
 *   php artisan knowledge stats                                   # breakdown
 *   php artisan knowledge forget "key"
 */
class KnowledgeCommand extends Command
{
    protected $signature = 'knowledge
                            {action : list|add|search|stats|forget}
                            {key? : Key ou query}
                            {value? : Value (para add)}
                            {--category= : Categoria (vazio = sem filtro em list/search)}
                            {--importance=0.5 : Importância 0-1}
                            {--limit=20 : Limite list/search}';

    protected $description = 'Gere memória organizacional partilhada (PartYard)';

    public function handle(OrganizationalMemoryService $svc): int
    {
        $action = (string) $this->argument('action');
        return match ($action) {
            'list'   => $this->cmdList($svc),
            'add'    => $this->cmdAdd($svc),
            'search' => $this->cmdSearch($svc),
            'stats'  => $this->cmdStats($svc),
            'forget' => $this->cmdForget($svc),
            default  => $this->failWith("Acção inválida: {$action}. Usa list|add|search|stats|forget"),
        };
    }

    private function cmdList(OrganizationalMemoryService $svc): int
    {
        $limit = (int) $this->option('limit');
        $cat = $this->option('category') ?: null;

        $q = OrganizationalKnowledge::query()->fresh();
        if ($cat) {  // só filtra se explicitamente passado
            $q->where('category', $cat);
        }
        $rows = $q->orderByRelevance()->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->warn('Sem memórias guardadas ainda.');
            $this->line('Adiciona com: php artisan knowledge add "key" "value" --category=supplier');
            return self::SUCCESS;
        }

        $this->info("Total: {$rows->count()} memórias");
        $this->table(
            ['Key', 'Value', 'Cat', 'Imp', 'Src', 'Recalls'],
            $rows->map(fn ($m) => [
                mb_strimwidth($m->knowledge_key, 0, 30, '…'),
                mb_strimwidth($m->knowledge_value, 0, 60, '…'),
                $m->category,
                number_format((float) $m->importance, 2),
                $m->source,
                $m->recall_count,
            ])->toArray(),
        );

        return self::SUCCESS;
    }

    private function cmdAdd(OrganizationalMemoryService $svc): int
    {
        $key   = (string) $this->argument('key');
        $value = (string) $this->argument('value');
        if ($key === '' || $value === '') {
            return $this->failWith('Key e value obrigatórios: knowledge add "key" "value"');
        }

        // Para add, default 'general' quando não passado.
        $cat = ((string) $this->option('category')) ?: 'general';
        $imp = (float)  $this->option('importance');

        $row = $svc->remember(key: $key, value: $value, category: $cat, importance: $imp, source: 'manual');
        if (!$row) return $this->failWith('Não foi possível gravar.');

        $this->info("✓ Memória gravada [{$row->category}, importance {$row->importance}]: {$row->knowledge_key}");
        return self::SUCCESS;
    }

    private function cmdSearch(OrganizationalMemoryService $svc): int
    {
        $query = (string) $this->argument('key');
        $limit = (int) $this->option('limit');
        $cat   = $this->option('category') ?: null;

        $rows = $svc->search($query, $limit, $cat);
        if (empty($rows)) {
            $this->warn("Sem hits para '{$query}'.");
            return self::SUCCESS;
        }

        $this->info(count($rows) . " hits:");
        foreach ($rows as $m) {
            $this->line(sprintf(
                "  [%s · %.2f · %s] %s",
                $m->category, (float) $m->importance, $m->source, $m->knowledge_key,
            ));
            $this->line('    ' . mb_strimwidth($m->knowledge_value, 0, 150, '…'));
        }
        return self::SUCCESS;
    }

    private function cmdStats(OrganizationalMemoryService $svc): int
    {
        $this->info("Total: {$svc->count()} memórias");
        $stats = $svc->statsByCategory();
        if (empty($stats)) {
            $this->warn('  Sem memórias ainda.');
            return self::SUCCESS;
        }
        $this->table(
            ['Category', 'Count', 'Avg Importance'],
            array_map(fn ($s) => [$s['category'], $s['count'], $s['avg_imp']], $stats),
        );
        return self::SUCCESS;
    }

    private function cmdForget(OrganizationalMemoryService $svc): int
    {
        $key = (string) $this->argument('key');
        if ($key === '') return $this->failWith('Key obrigatória: knowledge forget "key"');

        $count = OrganizationalKnowledge::where('knowledge_key', $key)->delete();
        $this->info("✓ {$count} memória(s) apagada(s).");
        return self::SUCCESS;
    }

    /**
     * Helper local — Laravel 11+ tem Command::fail() público nativo,
     * por isso usamos nome diferente para evitar conflito de visibilidade.
     */
    private function failWith(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}
