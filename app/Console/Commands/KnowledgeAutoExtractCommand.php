<?php

namespace App\Console\Commands;

use App\Jobs\AutoExtractKnowledgeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * knowledge:auto-extract — varre conversas recentes e dispatch jobs
 * para extrair factos.
 *
 * Estratégia:
 *   • Cada run procura mensagens 'assistant' criadas DESDE o último run
 *   • Agrupa por conversation_id e dispatch 1 job por conversa única
 *   • High-water-mark armazenado em Redis cache (não migration)
 *   • Custo: 1 Haiku call ≈ $0.0005 por conversa COM factos a gravar
 *   • Cron 30 min — chega para apanhar a maioria das interações
 *
 * Uso manual:
 *   php artisan knowledge:auto-extract                   # since last run
 *   php artisan knowledge:auto-extract --since-minutes=120  # last 2h
 *   php artisan knowledge:auto-extract --max=5 --dry-run
 */
class KnowledgeAutoExtractCommand extends Command
{
    protected $signature = 'knowledge:auto-extract
                            {--since-minutes= : override high-water-mark (default uses Redis)}
                            {--max=20 : máx conversas a despachar nesta run}
                            {--dry-run : mostra plano sem dispatch}';

    protected $description = 'Dispatch jobs de auto-extract de factos para conversas recentes';

    private const CACHE_KEY = 'knowledge:autoextract:last_processed_id';

    public function handle(): int
    {
        $max     = (int) $this->option('max');
        $dryRun  = (bool) $this->option('dry-run');
        $sinceMin = $this->option('since-minutes');

        // High-water-mark: maior message_id já considerado.
        $lastId = (int) Cache::get(self::CACHE_KEY, 0);

        // Override por janela temporal (útil para backfill manual).
        $query = DB::table('messages')->where('role', 'assistant');
        if ($sinceMin !== null) {
            $cutoff = now()->subMinutes((int) $sinceMin);
            $query->where('created_at', '>=', $cutoff);
            $this->info("Filtro: últimos {$sinceMin} min");
        } else {
            $query->where('id', '>', $lastId);
            $this->info("Filtro: messages.id > {$lastId} (high-water-mark)");
        }

        // Agrupa por conversation_id, pega os primeiros N únicos.
        $rows = $query
            ->select('conversation_id', DB::raw('MAX(id) as max_message_id'))
            ->groupBy('conversation_id')
            ->orderBy('max_message_id', 'asc')
            ->limit($max)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nada novo para processar.');
            return self::SUCCESS;
        }

        $this->info('Encontradas ' . $rows->count() . ' conversas com novas mensagens.');

        if ($dryRun) {
            foreach ($rows as $r) {
                $this->line("  → conversation #{$r->conversation_id} (max msg {$r->max_message_id})");
            }
            $this->warn('Dry-run — nenhum job despachado.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $highestId = $lastId;
        foreach ($rows as $r) {
            try {
                AutoExtractKnowledgeJob::dispatch((int) $r->conversation_id);
                $dispatched++;
                if ($r->max_message_id > $highestId) $highestId = (int) $r->max_message_id;
            } catch (\Throwable $e) {
                $this->warn("  ⚠ falha dispatch conversa #{$r->conversation_id}: " . $e->getMessage());
            }
        }

        // Atualiza high-water-mark (Redis, TTL infinito praticamente — 1 ano).
        if ($highestId > $lastId) {
            Cache::put(self::CACHE_KEY, $highestId, now()->addYear());
        }

        $this->info("✓ Despachados {$dispatched} job(s). High-water-mark agora: {$highestId}");
        return self::SUCCESS;
    }
}
