<?php

namespace App\Jobs;

use App\Models\ReviewChainRun;
use App\Models\Tender;
use App\Services\ReviewChainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunReviewChainJob — corre a cadeia de revisão por comité em background.
 *
 * Disparado pelo TenderController::launchReviewChain.
 * ~30-90s dependendo dos 4 revisores (Haiku, rápido).
 */
class RunReviewChainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(public int $runId) {}

    public function handle(ReviewChainService $svc): void
    {
        $run = ReviewChainRun::find($this->runId);
        if (!$run || $run->status === ReviewChainRun::STATUS_DONE) return;

        $tender = $run->tender;
        if (!$tender) {
            $run->update(['status' => ReviewChainRun::STATUS_FAILED, 'finished_at' => now()]);
            return;
        }

        try {
            $svc->review($run, $tender);
        } catch (\Throwable $e) {
            Log::error('RunReviewChainJob: failed', ['run_id' => $this->runId, 'error' => $e->getMessage()]);
            $run->update(['status' => ReviewChainRun::STATUS_FAILED, 'finished_at' => now()]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunReviewChainJob: exhausted retries', ['run_id' => $this->runId, 'error' => $e->getMessage()]);
        try {
            ReviewChainRun::where('id', $this->runId)
                ->update(['status' => ReviewChainRun::STATUS_FAILED, 'finished_at' => now()]);
        } catch (\Throwable) {}
    }
}
