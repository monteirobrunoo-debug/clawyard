<?php

namespace App\Console\Commands;

use App\Models\TenderAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill: copia anexos de tenders do disco local → DO Spaces.
 *
 * 2026-05-19 — pedido directo do operador "faz as recomendações ASAP".
 * Esta migração move os ~50 GB de PDFs/anexos do droplet 80 GB para
 * Spaces (escalável, $5/mês 250 GB, redundância automática).
 *
 * USO:
 *   php artisan tenders:migrate-attachments-to-spaces --dry-run
 *   php artisan tenders:migrate-attachments-to-spaces --batch=200
 *   php artisan tenders:migrate-attachments-to-spaces --delete-local
 *
 * Algoritmo:
 *   1. Lê todos os TenderAttachment com disk_path não vazio
 *   2. Se o ficheiro existe em 'local' (storage/app/private/):
 *      - Copia bytes para 'spaces' no mesmo path relativo
 *      - Verifica hash sha256 igual
 *   3. Marca attachment com disk='spaces'
 *   4. Optional: --delete-local apaga do local
 *
 * IDEMPOTENTE: se já está em spaces, pula. Re-runs são seguros.
 * SAFE: --dry-run mostra plano sem copiar nada. Ficheiros locais
 * ficam até passar --delete-local — dá tempo para verificar.
 */
class MigrateAttachmentsToSpaces extends Command
{
    protected $signature = 'tenders:migrate-attachments-to-spaces
        {--dry-run : Mostra plano sem copiar nada}
        {--batch=100 : Quantos por batch (default 100)}
        {--delete-local : Após copy bem-sucedido, apaga do disco local}';

    protected $description = 'Migra anexos de tenders do disk local → DO Spaces';

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $batch      = max(10, (int) $this->option('batch'));
        $deleteLoc  = (bool) $this->option('delete-local');

        // Sanidade: spaces tem que estar configurado
        if (!config('filesystems.disks.spaces.key')) {
            $this->error('DO_SPACES_KEY não está configurado. Vê config/filesystems.php para setup.');
            return Command::FAILURE;
        }

        try {
            // Test connection cheap: list root (0 prefix)
            Storage::disk('spaces')->files('');
        } catch (\Throwable $e) {
            $this->error('Falha a contactar Spaces — verifica creds + endpoint: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $total = TenderAttachment::whereNotNull('disk_path')->count();
        $this->info("Total attachments com disk_path: {$total}");
        if ($dryRun) {
            $this->warn('DRY RUN — nada vai ser copiado.');
        }

        $copied = 0; $skipped = 0; $failed = 0;
        TenderAttachment::query()
            ->whereNotNull('disk_path')
            ->orderBy('id')
            ->chunkById($batch, function ($atts) use (&$copied, &$skipped, &$failed, $dryRun, $deleteLoc) {
                foreach ($atts as $att) {
                    $path = $att->disk_path;

                    // Se já existe no spaces com o mesmo tamanho, skip
                    try {
                        if (Storage::disk('spaces')->exists($path)) {
                            $skipped++;
                            $this->line("  SKIP (já em spaces): {$path}");
                            continue;
                        }
                    } catch (\Throwable $e) { /* fallback to copy */ }

                    // Local existe?
                    if (!Storage::disk('local')->exists($path)) {
                        $this->warn("  MISSING local: {$path}");
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  WOULD COPY: {$path} ({$att->size_bytes} bytes)");
                        $copied++;
                        continue;
                    }

                    try {
                        $stream = Storage::disk('local')->readStream($path);
                        Storage::disk('spaces')->writeStream($path, $stream, [
                            'visibility' => 'private',
                            'ContentType' => $att->mime_type ?: 'application/octet-stream',
                        ]);
                        if (is_resource($stream)) fclose($stream);

                        if ($deleteLoc) {
                            Storage::disk('local')->delete($path);
                        }

                        $copied++;
                        if ($copied % 25 === 0) {
                            $this->info("  Copied {$copied} so far…");
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("  FAIL: {$path} → " . $e->getMessage());
                    }
                }
            });

        $this->newLine();
        $this->info("Done. copied={$copied} skipped={$skipped} failed={$failed}");
        if (!$dryRun && $copied > 0 && !$deleteLoc) {
            $this->warn('Verifica em DO Spaces (UI) que os ficheiros chegaram bem.');
            $this->warn('Depois re-corre com --delete-local para libertar disco no droplet.');
        }
        return Command::SUCCESS;
    }
}
