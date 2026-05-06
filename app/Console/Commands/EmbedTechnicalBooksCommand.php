<?php

namespace App\Console\Commands;

use App\Models\TechnicalBookChunk;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gera embeddings para chunks da biblioteca técnica via NVIDIA NIM
 * e guarda no campo `embedding` (pgvector) para semantic search.
 *
 * Idempotente: por defeito salta chunks que já têm embedding.
 *
 *   php artisan books:embed                  (incremental, sem refresh)
 *   php artisan books:embed --refresh        (regenera TODOS)
 *   php artisan books:embed --batch=20       (20 chunks/request, default 16)
 *   php artisan books:embed --limit=100      (só os primeiros 100, debug)
 */
class EmbedTechnicalBooksCommand extends Command
{
    protected $signature = 'books:embed
                            {--refresh : regenera embeddings já existentes}
                            {--batch=16 : nº de chunks por chamada à NVIDIA}
                            {--limit= : limit total (debug; default ilimitado)}';

    protected $description = 'Gera embeddings semânticos para a biblioteca técnica via NVIDIA NIM';

    public function handle(EmbeddingService $emb): int
    {
        if (!$emb->isAvailable()) {
            $this->error('NVIDIA_API_KEY não configurada — abortar.');
            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('pgvector requer Postgres. Driver actual: ' . DB::connection()->getDriverName());
            return self::FAILURE;
        }

        $batchSize = max(1, min(32, (int) $this->option('batch')));
        $refresh   = (bool) $this->option('refresh');
        $limit     = (int) ($this->option('limit') ?? 0);

        $this->info("📐 NVIDIA NIM: {$emb->getModel()} ({$emb->getDimensions()}-dim)");
        $this->info("   batch=$batchSize · refresh=" . ($refresh ? 'yes' : 'no') . ($limit > 0 ? " · limit=$limit" : ''));

        $query = TechnicalBookChunk::query();
        if (!$refresh) {
            $query->whereNull('embedding');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('✅ Nada a fazer — todos os chunks já têm embedding (use --refresh para forçar).');
            return self::SUCCESS;
        }

        $this->info("→ {$total} chunks para processar");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done    = 0;
        $errors  = 0;
        $apiCalls= 0;

        $query->orderBy('id')->chunkById($batchSize, function ($chunks) use ($emb, $batchSize, &$done, &$errors, &$apiCalls, $bar) {
            $texts = $chunks->pluck('content')->all();

            // Concatena book_title + content para dar mais contexto ao embedder
            // (ajuda a desambiguar "soldagem" do Modenesi vs ESAB).
            $payload = $chunks->map(fn($c) => trim(($c->book_title ?? '') . ': ' . $c->content))->all();

            $vectors = $emb->embedBatch($payload, 'passage');
            $apiCalls++;

            if (count($vectors) !== count($chunks)) {
                $errors += count($chunks);
                $bar->advance(count($chunks));
                return;
            }

            foreach ($chunks as $i => $chunk) {
                $vec = $vectors[$i] ?? null;
                if (!$vec || count($vec) === 0) {
                    $errors++;
                    $bar->advance();
                    continue;
                }

                // pgvector aceita formato '[1.2,3.4,...]' como string
                $literal = '[' . implode(',', array_map(fn($f) => sprintf('%.6f', $f), $vec)) . ']';
                DB::update(
                    'UPDATE technical_book_chunks SET embedding = ?::vector, embedding_model = ?, updated_at = NOW() WHERE id = ?',
                    [$literal, $emb->getModel(), $chunk->id]
                );
                $done++;
                $bar->advance();
            }

            // Pause leve entre batches para não saturar o NVIDIA tier free
            usleep(150_000);
        });

        $bar->finish();
        $this->newLine();
        $this->info("📊 Embeddings gerados: {$done} · erros: {$errors} · API calls: {$apiCalls}");
        return self::SUCCESS;
    }
}
