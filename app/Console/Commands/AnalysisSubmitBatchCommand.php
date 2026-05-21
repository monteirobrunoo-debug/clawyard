<?php

namespace App\Console\Commands;

use App\Models\Tender;
use App\Models\TenderServiceAnalysis;
use App\Services\TenderServiceAnalysisService;
use Illuminate\Console\Command;

/**
 * Submete tenders pendentes a multi-agent análise via Batch API
 * (50% off vs sync). Runs overnight (23:30).
 *
 * Critérios de selecção:
 *   • status != confidential
 *   • Tem PDF anexado com texto OK (necessário para o context)
 *   • Sem análise OU análise stale >X dias
 *   • Não está já em queued_batch / running
 *
 * Limites:
 *   • --max N — quantos tenders por batch (default 20). Cada tender × 6
 *     agentes ≈ 120 requests. Anthropic aceita até 100k por batch.
 *   • --dry-run — só mostra quais seriam submetidos
 */
class AnalysisSubmitBatchCommand extends Command
{
    protected $signature = 'analysis:submit-batch
                            {--max=20 : Máximo de tenders por batch}
                            {--stale-days=14 : Re-analisar tenders >N dias sem update}
                            {--dry-run : Mostra mas não submete}';

    protected $description = 'Submete tenders pendentes ao Anthropic Batch API (50% off)';

    public function handle(TenderServiceAnalysisService $svc): int
    {
        $max = max(1, (int) $this->option('max'));
        $staleDays = max(0, (int) $this->option('stale-days'));
        $dryRun = (bool) $this->option('dry-run');

        // IDs de tenders com análise fresh — para excluir
        $freshIds = TenderServiceAnalysis::query()
            ->where('status', 'done')
            ->when($staleDays > 0, fn ($q) =>
                $q->where('generated_at', '>=', now()->subDays($staleDays)))
            ->pluck('tender_id')
            ->all();

        // IDs com batch ainda em flight (queued_batch / running)
        $inflightIds = TenderServiceAnalysis::query()
            ->whereIn('status', ['queued_batch', 'running'])
            ->pluck('tender_id')
            ->all();

        $excludeIds = array_unique(array_merge($freshIds, $inflightIds));

        $tenders = Tender::query()
            ->where('is_confidential', false)
            ->whereNotIn('id', $excludeIds)
            ->whereHas('attachments', fn ($q) => $q->where('extraction_status', 'ok'))
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->limit($max)
            ->get();

        if ($tenders->isEmpty()) {
            $this->info('Sem tenders elegíveis para batch agora.');
            return self::SUCCESS;
        }

        $this->info("Tenders elegíveis: {$tenders->count()}");
        foreach ($tenders as $t) {
            $this->line("  #{$t->id}  ref={$t->reference}  src={$t->source}  " . mb_substr((string) $t->title, 0, 60));
        }

        if ($dryRun) {
            $this->info('Dry-run — nada submetido.');
            return self::SUCCESS;
        }

        $batch = $svc->submitBatch($tenders);
        if (!$batch) {
            $this->error('Submissão falhou (vê laravel.log).');
            return self::FAILURE;
        }

        $this->info("✓ Batch submetido:");
        $this->line("   Row id     : {$batch->id}");
        $this->line("   Batch id   : " . ($batch->batch_id ?? 'pending'));
        $this->line("   Status     : {$batch->status}");
        $this->line("   Requests   : {$batch->request_count}");
        $this->line('');
        $this->line('Será colectado pelo cron `analysis:collect-batches` hourly.');

        return self::SUCCESS;
    }
}
