<?php

namespace App\Jobs;

use App\Agents\QuantumAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RunQuantumDigestJob — gera o digest científico do Prof. Quantum Leap em
 * background e cacheia o resultado em Redis.
 *
 * PROBLEMA QUE RESOLVE (Bruno 2026-05-29: "tem de ser rectificado"):
 *   O digest faz 4 fetches HTTP externos (arXiv + PeerJ + EPO + TechLink)
 *   + Anthropic Opus com extended thinking. Total medido: ~165s. Quando
 *   corrido SÍNCRONO dentro do SSE stream, o Cloudflare (cap 100s) corta
 *   a connection → user via "Erro: network error".
 *
 *   Tentativa anterior (timer pulse OpenSwoole) rebentou em production
 *   (async-io must be PHP CLI mode — commit b96873e revertido em 2cfea88).
 *
 * SOLUÇÃO (cache-first async, escolha directa do Bruno):
 *   - Este job corre no queue worker (timeout 900s — sem constraint de
 *     Cloudflare). Faz todos os fetches + Anthropic SEM pressa.
 *   - Guarda o resultado em Redis cache `quantum:digest:latest` (TTL 24h).
 *   - QuantumAgent::stream() no digest path verifica este cache primeiro:
 *       • HIT  → devolve o conteúdo instantaneamente (< 1s, sem fetch)
 *       • MISS → dispatch este job + mensagem "a gerar, recarrega em 2min"
 *   - Cron diário 06:00 dispatch este job para pre-warm o cache, por isso
 *     99% das vezes o user vê cache HIT.
 *
 * IDEMPOTENTE: re-running sobrescreve o cache. Sem retries (custo Anthropic).
 */
class RunQuantumDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;   // 10min — fetches (~90s) + Anthropic (~90s) + buffer

    /** Redis cache key. Partilhado com QuantumAgent::stream(). */
    public const CACHE_KEY = 'quantum:digest:latest';
    /** TTL 25h — sobrevive ao gap até o cron do dia seguinte correr. */
    public const CACHE_TTL = 90000;

    public function __construct(
        public ?int $requestedByUserId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $started = microtime(true);
        Log::info('RunQuantumDigestJob: start', ['user_id' => $this->requestedByUserId]);

        try {
            /** @var QuantumAgent $agent */
            $agent = app(QuantumAgent::class);

            // generateDigestContent() faz os 4 fetches + Anthropic non-stream
            // e devolve o markdown completo. Método público extraído do
            // stream() para reutilização aqui (ver QuantumAgent).
            $content = $agent->generateDigestContent();

            if (trim($content) === '') {
                Log::warning('RunQuantumDigestJob: empty content — não cacheia');
                return;
            }

            Cache::put(self::CACHE_KEY, [
                'content'      => $content,
                'generated_at' => now()->toIso8601String(),
            ], self::CACHE_TTL);

            $elapsed = round(microtime(true) - $started, 1);
            Log::info('RunQuantumDigestJob: done', [
                'elapsed_s' => $elapsed,
                'len'       => strlen($content),
            ]);
        } catch (\Throwable $e) {
            Log::error('RunQuantumDigestJob: failed', [
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            // Não re-throw — o cron tenta de novo amanhã, e o stream()
            // path faz dispatch de novo se o cache continuar vazio.
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('RunQuantumDigestJob: dropped after worker died', [
            'reason' => $e?->getMessage() ?? 'max attempts',
        ]);
    }
}
