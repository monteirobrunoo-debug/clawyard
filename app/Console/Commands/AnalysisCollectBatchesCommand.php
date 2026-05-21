<?php

namespace App\Console\Commands;

use App\Models\AnthropicBatch;
use App\Services\AnthropicBatchService;
use App\Services\TenderServiceAnalysisService;
use Illuminate\Console\Command;

/**
 * Polla todos os batches pendentes (status != ended) e colecta
 * results dos que já endaram. Despachado horariamente.
 *
 * Idempotente: results_collected impede dupla recolha.
 */
class AnalysisCollectBatchesCommand extends Command
{
    protected $signature = 'analysis:collect-batches
                            {--max=20 : Máximo de batches a processar nesta run}';

    protected $description = 'Polla batches Anthropic pendentes + assembla análises ended';

    public function handle(
        AnthropicBatchService $batchSvc,
        TenderServiceAnalysisService $analSvc,
    ): int {
        $max = max(1, (int) $this->option('max'));

        // 1. Pollar todos os pending
        $pending = AnthropicBatch::pending()
            ->where('kind', 'tender-analysis')
            ->whereNotNull('batch_id')
            ->orderBy('submitted_at')
            ->limit($max)
            ->get();

        if ($pending->isNotEmpty()) {
            $this->info("Polling {$pending->count()} pending batches…");
            foreach ($pending as $b) {
                $batchSvc->poll($b);
                $this->line("  #{$b->id} ({$b->batch_id}): {$b->status}");
            }
        }

        // 2. Colectar os que estão ended
        $ready = AnthropicBatch::readyToCollect()
            ->where('kind', 'tender-analysis')
            ->orderBy('ended_at')
            ->limit($max)
            ->get();

        if ($ready->isEmpty()) {
            $this->info('Nada pronto para colectar.');
            return self::SUCCESS;
        }

        $this->info("Collecting {$ready->count()} ended batches…");
        $totalTenders = 0;
        foreach ($ready as $b) {
            $count = $analSvc->collectBatch($b);
            $totalTenders += $count;
            $this->line("  ✓ #{$b->id}: {$count} tenders assembled");
        }

        $this->info("Total tenders processed: {$totalTenders}");
        return self::SUCCESS;
    }
}
