<?php

namespace App\Console\Commands;

use App\Jobs\SyncSupplierWebIntelJob;
use App\Models\Supplier;
use App\Services\SupplierWebIntelService;
use Illuminate\Console\Command;

/**
 * Batch sync de web-intel para fornecedores aprovados.
 *
 * Pedido directo do Bruno (2026-05-21):
 *   "Os agentes tem de verificar na web o que faz os fornecedores
 *    e confrontar se os que temos aprovado tem o mesmo material
 *    também ... fazer a sincronização da info dos fornecedores
 *    aprovados para ter já essa informação das páginas etc"
 *
 * Modos:
 *   --missing    Só os que nunca foram sincronizados (default)
 *   --stale      Os synced há >30 dias (re-fresh periódico, p/ cron)
 *   --all        Todos os approved (incluindo recém-sincronizados)
 *   --id=N       Apenas o ID indicado
 *   --sync       Correr inline (não despacha para queue). Útil para debug.
 */
class SyncSupplierWebIntelCommand extends Command
{
    protected $signature = 'suppliers:sync-web-intel
                            {--missing : Só os nunca sincronizados (default se nada especificado)}
                            {--stale : Só os sincronizados há >30 dias}
                            {--all : Todos os approved}
                            {--id= : Apenas este supplier id}
                            {--sync : Correr inline em vez de despachar para a queue}
                            {--limit=0 : Cap manual (0 = sem cap)}';

    protected $description = 'Sincroniza web-intel (catálogo, produtos) dos fornecedores aprovados via Tavily + Claude';

    public function handle(SupplierWebIntelService $svc): int
    {
        $query = Supplier::query()
            ->where('status', Supplier::STATUS_APPROVED);

        if ($id = (int) $this->option('id')) {
            $query->where('id', $id);
        } elseif ($this->option('all')) {
            // todos approved
        } elseif ($this->option('stale')) {
            $query->where(function ($q) {
                $q->whereNull('web_intel_synced_at')
                  ->orWhere('web_intel_synced_at', '<', now()->subDays(SupplierWebIntelService::SYNC_TTL_DAYS));
            });
        } else {
            // default: --missing
            $query->whereNull('web_intel_synced_at');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) $query->limit($limit);

        $suppliers = $query->orderBy('id')->get();
        $total = $suppliers->count();

        if ($total === 0) {
            $this->info('Nada para sincronizar.');
            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info("Suppliers a processar: {$total} (modo " . ($sync ? 'inline' : 'queue') . ')');

        $ok = 0; $failed = 0; $skipped = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($suppliers as $s) {
            if ($sync) {
                $res = $svc->syncOne($s);
                if ($res['ok'] ?? false) $ok++;
                elseif (($res['status'] ?? '') === 'skipped_restricted') $skipped++;
                else $failed++;
            } else {
                SyncSupplierWebIntelJob::dispatch($s->id);
                $ok++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info("Inline: {$ok} OK · {$skipped} skipped (restricted) · {$failed} failed");
        } else {
            $this->info("Dispatched {$ok} jobs para queue 'low'. Monitor com: tail -f storage/logs/laravel.log | grep SyncSupplierWebIntel");
            $this->line('Worker do Supervisor processa em background — não é preciso esperar.');
        }

        return self::SUCCESS;
    }
}
