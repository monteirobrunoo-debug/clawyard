<?php

namespace App\Jobs;

use App\Models\Tender;
use App\Services\TenderServiceAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background multi-agent analysis para um tender.
 *
 * Pedido 2026-05-20:
 *   "a marta está a analisar logo, deixa carregar os ficheiros todos
 *    se depois os agentes marco sales, marta, porto e outros do marine
 *    analisam"
 *
 * O multi-agent panel (5-8 agentes autónomos com tool-use loops após
 * commit 0488752) demora 60-120s. Quando corrido síncrono dentro do
 * upload, excede o nginx proxy timeout (60s) → 504. Solução: dispatch
 * via Supervisor queue worker (always-on desde commit fa927c2),
 * upload returns fast (~2s) e analysis corre em background.
 *
 * Idempotente: re-running na mesma tender sobrescreve a row de
 * TenderServiceAnalysis (firstOrNew). Sem retries para evitar custo
 * em tempest ($1-2 por panel).
 */
class RunTenderAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 240;       // 4min — 5-8 agentes × ~20s média
    public string $queue = 'default';

    public function __construct(
        public int $tenderId,
        public ?int $userId = null,
    ) {}

    public function handle(TenderServiceAnalysisService $analyser): void
    {
        $tender = Tender::find($this->tenderId);
        if (!$tender) {
            Log::info('RunTenderAnalysisJob: tender not found', ['id' => $this->tenderId]);
            return;
        }
        if ($tender->is_confidential) {
            Log::info('RunTenderAnalysisJob: skipped (confidential)', ['id' => $this->tenderId]);
            return;
        }

        try {
            $analyser->analyse($tender, $this->userId);
            Log::info('RunTenderAnalysisJob: done', ['tender_id' => $this->tenderId]);
        } catch (\Throwable $e) {
            Log::error('RunTenderAnalysisJob: failed', [
                'tender_id' => $this->tenderId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
