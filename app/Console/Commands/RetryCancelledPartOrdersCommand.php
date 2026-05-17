<?php

namespace App\Console\Commands;

use App\Models\PartOrder;
use App\Services\Robotparts\PartSearchService;
use Illuminate\Console\Command;

/**
 * Re-corre PartSearchService::findAndPick() para todas as PartOrders
 * canceladas por falha do agente buyer (dispatch_failed / parse_failed).
 * Ignora as canceladas por "no_fit" (essas são deliberadas — agente
 * decidiu que nada cabia no budget) e as canceladas por over-budget
 * (essas precisam de mais saldo no wallet, retry não resolve).
 *
 *   php artisan parts:retry-cancelled              # todas as recuperáveis
 *   php artisan parts:retry-cancelled --dry        # só conta, não retry
 *   php artisan parts:retry-cancelled --order=33   # uma específica
 *   php artisan parts:retry-cancelled --all        # incluir "no_fit" (perigoso)
 *
 * Cost guard: cada retry consome 1 call Anthropic + 1 call Tavily
 * (≈ $0.003 + 1 search). Sem cap por defeito mas faz dry-run primeiro
 * para ver quantas vais correr.
 */
class RetryCancelledPartOrdersCommand extends Command
{
    protected $signature = 'parts:retry-cancelled
                            {--dry          : Só conta, não tenta retry}
                            {--order=       : ID específica para retry}
                            {--all          : Inclui ordens canceladas por "no_fit" (deliberado)}
                            {--limit=20     : Máximo de retries por execução (cost guard)}';

    protected $description = 'Re-corre PartSearchService para PartOrders canceladas por falha do buyer';

    public function handle(PartSearchService $svc): int
    {
        $orders = $this->resolveOrders();

        if ($orders->isEmpty()) {
            $this->info('Nada para retry — sem ordens canceladas recuperáveis.');
            return self::SUCCESS;
        }

        $this->info("Ordens elegíveis para retry: {$orders->count()}");
        foreach ($orders as $o) {
            $this->line(sprintf('  #%-4d  %-12s  $%-6.2f  "%s"  (%s)',
                $o->id,
                $o->agent_key,
                (float) $o->cost_usd,
                \Str::limit($o->name ?? '?', 50),
                $this->reasonFor($o),
            ));
        }

        if ($this->option('dry')) {
            $this->info("\n[DRY] não foi feito retry. Re-corre sem --dry para executar.");
            return self::SUCCESS;
        }

        if (!$this->confirm('Confirmar retry de ' . $orders->count() . ' ordem(s)?', true)) {
            return self::SUCCESS;
        }

        $ok = $fail = $noFit = 0;
        foreach ($orders as $o) {
            $this->line("Retrying #{$o->id} (\"" . \Str::limit($o->search_query ?? '?', 40) . '")...');
            $refreshed = $svc->retryCancelled($o);
            switch ($refreshed->status) {
                case PartOrder::STATUS_PURCHASED:
                case PartOrder::STATUS_STL_READY:
                    $ok++;
                    $this->info("  ✅ comprado por \${$refreshed->cost_usd}");
                    break;
                case PartOrder::STATUS_CANCELLED:
                    if (str_contains((string) $refreshed->notes, 'nenhuma peça')) {
                        $noFit++;
                        $this->warn('  ⚠️  voltou a cancelar (no fit) — agente diz que nada cabe no budget');
                    } else {
                        $fail++;
                        $this->warn('  ⚠️  voltou a cancelar: ' . \Str::limit((string) $refreshed->notes, 100));
                    }
                    break;
                default:
                    $this->line("  status agora: {$refreshed->status}");
            }
        }

        $this->info(sprintf("\nResumo: %d sucesso · %d no-fit · %d ainda em falha", $ok, $noFit, $fail));
        return self::SUCCESS;
    }

    private function resolveOrders()
    {
        if ($only = $this->option('order')) {
            $o = PartOrder::find((int) $only);
            if (!$o) {
                $this->error("Ordem #{$only} não encontrada.");
                return collect();
            }
            if ($o->status !== PartOrder::STATUS_CANCELLED) {
                $this->error("Ordem #{$only} não está cancelada (está '{$o->status}').");
                return collect();
            }
            return collect([$o]);
        }

        $q = PartOrder::where('status', PartOrder::STATUS_CANCELLED);

        // Por defeito, ignorar as canceladas por "no_fit" e "over budget"
        // — esses são estado deliberado, retry sem mudança de contexto
        // (saldo/persona) não resolve.
        if (!$this->option('all')) {
            $q->where(function ($w) {
                $w->where('notes', 'like', '%LLM call falhou%')
                  ->orWhere('notes', 'like', '%não foi JSON%')
                  ->orWhere('notes', 'like', '%buyer dispatch failed or picked nothing%')   // legacy
                  ->orWhere('notes', 'like', '%PartSearchService: anthropic_%')             // legacy stack-trace
                  ->orWhere('notes', 'like', '%anthropic_transport%');
            });
        }

        return $q->orderBy('id')->limit((int) $this->option('limit'))->get();
    }

    private function reasonFor(PartOrder $o): string
    {
        $n = (string) $o->notes;
        if (str_contains($n, 'LLM call falhou'))   return 'dispatch_failed';
        if (str_contains($n, 'não foi JSON'))      return 'parse_failed';
        if (str_contains($n, 'nenhuma peça'))      return 'no_fit';
        if (str_contains($n, 'over-budget'))       return 'over_budget';
        if (str_contains($n, 'buyer dispatch'))    return 'legacy_unknown';
        return 'other';
    }
}
