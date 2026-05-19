<?php

namespace App\Console\Commands;

use App\Models\TenderAttachment;
use App\Services\ImageOcrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Re-processa anexos de imagem que ficaram com STATUS_SKIPPED (eram
 * o default antes do Camera+OCR ter sido implementado). Aplica
 * ImageOcrService (Claude Vision) a cada um.
 *
 * Usage:
 *   php artisan attachments:ocr-backfill            — corre tudo (live)
 *   php artisan attachments:ocr-backfill --dry-run  — só lista candidates
 *   php artisan attachments:ocr-backfill --limit=20 — bate até 20 imagens
 *   php artisan attachments:ocr-backfill --tender=123 — só anexos de 1 tender
 *
 * Custo: ~$0.005 por imagem (Claude Sonnet 4.6 Vision).
 */
class OcrBackfillAttachmentsCommand extends Command
{
    protected $signature = 'attachments:ocr-backfill
                            {--dry-run : Lista os candidatos sem chamar a Claude Vision}
                            {--limit=0 : Cap de imagens a processar (0=todas)}
                            {--tender= : Limitar a 1 tender_id}';

    protected $description = 'Re-extrai texto de imagens com STATUS_SKIPPED via Claude Vision OCR';

    public function handle(ImageOcrService $ocr): int
    {
        $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'];

        $q = TenderAttachment::where('extraction_status', TenderAttachment::STATUS_SKIPPED)
            ->where(function ($q) use ($imageExts) {
                $q->where('mime_type', 'like', 'image/%');
                foreach ($imageExts as $ext) {
                    $q->orWhere('original_name', 'ilike', '%.' . $ext);
                }
            });

        if ($this->option('tender')) {
            $q->where('tender_id', (int) $this->option('tender'));
        }

        $total = $q->count();
        $this->info("📸 Candidatos OCR backfill: {$total}");

        if ($total === 0) {
            $this->info('Nada para fazer — todas as imagens já têm OCR ou STATUS_OK.');
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0 && $limit < $total) {
            $q->limit($limit);
            $this->warn("→ Limitado a {$limit}.");
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'tender_id', 'original_name', 'mime', 'size'],
                $q->get(['id', 'tender_id', 'original_name', 'mime_type', 'size_bytes'])
                  ->map(fn ($a) => [$a->id, $a->tender_id, $a->original_name, $a->mime_type, number_format($a->size_bytes / 1024, 1) . ' KB'])
                  ->all()
            );
            $this->info('(dry-run: nada foi alterado)');
            return self::SUCCESS;
        }

        $processed = 0;
        $okCount   = 0;
        $failCount = 0;
        $estCostUsd = 0.0;

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        foreach ($q->cursor() as $att) {
            $processed++;
            $bar->advance();

            $absolute = Storage::disk('local')->path($att->disk_path);
            if (!is_file($absolute)) {
                $att->extraction_status = TenderAttachment::STATUS_FAILED;
                $att->extraction_error  = 'OCR backfill: ficheiro não encontrado em ' . $att->disk_path;
                $att->save();
                $failCount++;
                continue;
            }

            $res = $ocr->extract($absolute, $att->mime_type);
            if (($res['ok'] ?? false) === true) {
                $att->extracted_text    = $res['text'];
                $att->extracted_chars   = mb_strlen((string) $res['text']);
                $att->extraction_status = TenderAttachment::STATUS_OK;
                $att->extraction_error  = $res['text'] === ''
                    ? 'OCR backfill: imagem sem texto legível.'
                    : null;
                $att->save();
                $okCount++;
                $estCostUsd += 0.005;
                Log::info('OcrBackfill: ok', [
                    'attachment_id' => $att->id,
                    'tender_id'     => $att->tender_id,
                    'chars'         => $att->extracted_chars,
                ]);
            } else {
                $att->extraction_status = TenderAttachment::STATUS_FAILED;
                $att->extraction_error  = mb_substr('OCR backfill falhou: ' . (string) ($res['error'] ?? 'unknown'), 0, 500);
                $att->save();
                $failCount++;
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ {$okCount} OCR'ed · ✗ {$failCount} falhas · processados: {$processed}");
        $this->line('   Custo estimado: $' . number_format($estCostUsd, 3) . ' USD');

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
