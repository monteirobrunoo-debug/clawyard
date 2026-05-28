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

    public function __construct(
        public int $tenderId,
        public ?int $userId = null,
        public bool $bypassKillSwitch = false,
    ) {
        // 2026-05-20 FIX CRÍTICO: a trait Queueable já define
        // ?string $queue protegido — declarar "public string $queue
        // = 'default'" cria colisão fatal em PHP 8.x ("define the
        // same property in composition"). Isto fez com que NENHUMA
        // chamada a este job desde a criação tenha funcionado
        // (TenderQuickPdfService::handle*, etc) — todos os "Marta
        // a analisar em background" davam silent fatal no worker.
        // Setar via onQueue() é a forma idiomática.
        $this->onQueue('default');
    }

    public function handle(TenderServiceAnalysisService $analyser): void
    {
        // Master kill-switch 2026-05-22 — auto-análise multi-agente desligada
        // por defeito. Defense-in-depth: mesmo que algum dispatcher upstream
        // se esqueça da flag, o job dropa-se silenciosamente. Para activar:
        // AUTO_ANALYSIS_ENABLED=true no .env.
        //
        // 2026-05-28: o botão manual no UI agora também passa por este job
        // (era sync, dava HTTP 408 com 5 agentes × 15s = 75s+ ≥ Cloudflare 100s).
        // Quando o controller dispatch com $bypassKillSwitch=true, ignoramos
        // a flag — o user clicou explicitamente, queremos correr.
        if (!$this->bypassKillSwitch
            && !config('services.tenders.auto_analysis', false)) {
            Log::info('RunTenderAnalysisJob: dropped — auto-analysis disabled', [
                'tender_id' => $this->tenderId,
            ]);
            return;
        }

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

    /**
     * Called by Laravel quando o job esgota retries — tipicamente porque o
     * worker morreu mid-job (Octane reload, deploy, queue:restart). Como
     * temos $tries=1 isto acontece sempre que o worker é re-spawned. Sem
     * este método, Laravel manda MaxAttemptsExceededException para o log
     * com stack trace gigante. Com isto, ficamos com 1 linha clara.
     *
     * NÃO re-enfileira nem chama analyse() — re-cobrar a Anthropic $1-2
     * por um worker que morreu não vale o custo. O user pode re-submeter
     * manualmente do dashboard se a análise não estiver no tender.
     */
    public function failed(?\Throwable $e): void
    {
        Log::warning('RunTenderAnalysisJob: dropped after worker died mid-job', [
            'tender_id' => $this->tenderId,
            'user_id'   => $this->userId,
            'reason'    => $e?->getMessage() ?? 'max attempts exceeded',
        ]);
    }
}
