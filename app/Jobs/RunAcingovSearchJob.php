<?php

namespace App\Jobs;

use App\Agents\AcingovAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RunAcingovSearchJob — recolhe os concursos públicos / fundos UE da Dra. Ana
 * Contratos em background e cacheia o resultado em Redis.
 *
 * PROBLEMA QUE RESOLVE (2026-05-31):
 *   AcingovAgent::stream() faz 6 fetches HTTP sequenciais a portais
 *   governamentais (Acingov + TED Europa + UNGM + base.gov.pt + SAM.gov +
 *   EU Funding) + análise Anthropic. Total medido >180s. Corrido SÍNCRONO
 *   dentro do SSE stream, o Octane mata o worker
 *   (OCTANE_MAX_EXECUTION_TIME=180) → "Error in input stream".
 *
 * SOLUÇÃO (cache-first async — mesmo padrão validado do RunQuantumDigestJob):
 *   - Este job corre no queue worker (timeout 600s — sem constraint do
 *     Octane). Faz os 6 fetches + Anthropic SEM pressa.
 *   - Guarda o resultado em Redis cache `acingov:contracts:latest` (TTL 4h).
 *   - AcingovAgent::stream() no path de portal search verifica este cache
 *     primeiro:
 *       • HIT  → devolve o conteúdo instantaneamente (< 1s, sem fetch)
 *       • MISS → dispatch este job + mensagem "a recolher, recarrega ~3min"
 *   - Cron a cada 4h dispatch este job para pre-warm o cache, por isso quase
 *     sempre o user vê cache HIT.
 *
 * IDEMPOTENTE: re-running sobrescreve o cache. Sem retries (custo Anthropic).
 */
class RunAcingovSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;   // 10min — 6 fetches (>120s) + Anthropic + buffer

    /** Redis cache key. Partilhado com AcingovAgent::stream(). */
    public const CACHE_KEY = 'acingov:contracts:latest';
    /** TTL 4h — concursos públicos mudam mais que o digest diário do Quantum. */
    public const CACHE_TTL = 14400;

    public function __construct(
        public ?int $requestedByUserId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $started = microtime(true);
        Log::info('RunAcingovSearchJob: start', ['user_id' => $this->requestedByUserId]);

        try {
            /** @var AcingovAgent $agent */
            $agent = app(AcingovAgent::class);

            // generateContractsContent() faz os 6 fetches + Anthropic non-stream
            // e devolve o markdown completo. Método público extraído do
            // stream() para reutilização aqui (ver AcingovAgent).
            $content = $agent->generateContractsContent();

            if (trim($content) === '') {
                Log::warning('RunAcingovSearchJob: empty content — não cacheia');
                return;
            }

            Cache::put(self::CACHE_KEY, [
                'content'      => $content,
                'generated_at' => now()->toIso8601String(),
            ], self::CACHE_TTL);

            $elapsed = round(microtime(true) - $started, 1);
            Log::info('RunAcingovSearchJob: done', [
                'elapsed_s' => $elapsed,
                'len'       => strlen($content),
            ]);
        } catch (\Throwable $e) {
            Log::error('RunAcingovSearchJob: failed', [
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            // Não re-throw — o cron tenta de novo daqui a 4h, e o stream()
            // path faz dispatch de novo se o cache continuar vazio.
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('RunAcingovSearchJob: dropped after worker died', [
            'reason' => $e?->getMessage() ?? 'max attempts',
        ]);
    }
}
