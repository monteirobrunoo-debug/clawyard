<?php

namespace App\Jobs;

use App\Models\Tender;
use App\Services\TenderSupplierSuggesterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pre-analysis job dispatched when a tender is created.
 *
 * Pre-warms the "Sugerir fornecedores" panel so when the operator
 * opens /tenders/{id} for the first time the suggestions are
 * already there, with no waiting on Tavily.
 *
 * What it does:
 *   1. inferCategories(tender)
 *   2. matchLocal — top 8 H&P approved suppliers for those categories
 *   3. searchWeb — Tavily query + parsed results
 *   4. Stores the bundle in tenders.prelim_analysis (jsonb)
 *
 * Skips:
 *   • Confidential tenders (is_confidential=true) — no LLM/web egress
 *   • Tenders with prelim_analysed_at already set (idempotent re-runs)
 *
 * Failure tolerance:
 *   • tries = 2 (Tavily glitches happen)
 *   • backoff = 30s
 *   • Any thrown exception falls into the standard failed_jobs table
 *     for inspection; the tender row stays usable (just no pre-warm).
 */
class AnalyseTenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries     = 2;
    public int $timeout   = 60;
    public int $backoff   = 30;

    public function __construct(public int $tenderId) {}

    public function handle(TenderSupplierSuggesterService $svc): void
    {
        $tender = Tender::find($this->tenderId);
        if (!$tender) return;

        // Confidential tenders never run the suggester — see migration
        // 2026_04_30_000004 for rationale.
        if ($tender->is_confidential) return;

        // Idempotency: don't re-run if already analysed and the row
        // hasn't changed materially. Use prelim_analysed_at + a 1h
        // window so a quick re-import doesn't hammer Tavily.
        if ($tender->prelim_analysed_at && $tender->prelim_analysed_at->gt(now()->subHour())) {
            return;
        }

        try {
            $bundle = $svc->suggest($tender, localLimit: 8, includeWeb: true);
        } catch (\Throwable $e) {
            Log::warning('AnalyseTenderJob: suggester failed', [
                'tender_id' => $tender->id,
                'error'     => $e->getMessage(),
            ]);
            throw $e;   // hand back to the queue for retry
        }

        $payload = [
            'version'          => 1,
            'categories'       => $bundle['categories'],
            'top_supplier_ids' => $bundle['local']->pluck('id')->all(),
            'web_query'        => $bundle['query']         ?? null,
            'web_results'      => $bundle['web']           ?? [],
            'web_available'    => $bundle['web_available'] ?? false,
        ];

        $tender->prelim_analysis    = $payload;
        $tender->prelim_analysed_at = now();
        $tender->save();
    }
}
