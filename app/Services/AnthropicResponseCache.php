<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AnthropicResponseCache — cache Redis de respostas Anthropic por hash exacto
 * de input (model + system + messages + max_tokens).
 *
 * Pedido Bruno 2026-05-28 (Fase B2):
 *   "Semantic cache de respostas (Redis + embeddings — corta 30-50% custo
 *    em perguntas repetidas)"
 *
 * IMPLEMENTAÇÃO ACTUAL (v1, hash exacto):
 *   - Cache key = hash(model, system, messages, max_tokens, temperature)
 *   - Hit → devolve resposta cached + grava métrica
 *   - Miss → caller corre Anthropic normalmente + chama put() no fim
 *   - TTL configurável (default 3600s = 1h)
 *
 * NÃO cacheado quando:
 *   - temperature > 0 (não determinístico)
 *   - max_tokens > 8000 (long-form, unique-by-design)
 *   - messages têm > 5 turns (conversational, context-heavy)
 *   - system inclui marker "no-cache" (skip explícito do agente)
 *
 * ROADMAP v2 (futuro): semantic similarity via embeddings (Voyage AI ou
 * OpenAI ada-002), pgvector search, threshold 0.92+. Vai exigir migration
 * + worker para popular embeddings em background. Por agora, hash exacto
 * cobre os casos high-frequency:
 *   - User refresh accidental (mesma pergunta segundos depois)
 *   - "Tenta de novo" depois de network error
 *   - Briefing daily/weekly com mesmo prompt
 *   - Suggestions chips para mesmo agente+contexto
 *
 * Uso típico no agente:
 *
 *   $cached = $this->cache->get($model, $system, $messages, $maxTokens);
 *   if ($cached !== null) return $cached;
 *
 *   // ... call Anthropic ...
 *
 *   $this->cache->put($model, $system, $messages, $maxTokens, $response);
 *   return $response;
 */
class AnthropicResponseCache
{
    /** Default TTL in seconds (1 hour). */
    private const DEFAULT_TTL = 3600;

    /** Skip cache for max_tokens above this (long-form content). */
    private const MAX_TOKENS_SKIP_CACHE = 8000;

    /** Skip cache when conversation has more turns than this. */
    private const MAX_TURNS_FOR_CACHE = 5;

    public function get(
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
        float $temperature = 0.0,
    ): ?string {
        if (!$this->isCacheable($system, $messages, $maxTokens, $temperature)) {
            return null;
        }
        $key = $this->cacheKey($model, $system, $messages, $maxTokens);
        $cached = Cache::get($key);
        if ($cached !== null) {
            Log::info('AnthropicResponseCache: HIT', [
                'model'     => $model,
                'msg_count' => count($messages),
                'key_hash'  => substr($key, -16),
            ]);
            $this->recordHit($model);
        }
        return $cached;
    }

    public function put(
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
        string $response,
        float $temperature = 0.0,
        ?int $ttl = null,
    ): void {
        if (!$this->isCacheable($system, $messages, $maxTokens, $temperature)) {
            return;
        }
        if (trim($response) === '') {
            // Não cachear respostas vazias / falhadas.
            return;
        }
        $key = $this->cacheKey($model, $system, $messages, $maxTokens);
        Cache::put($key, $response, $ttl ?? self::DEFAULT_TTL);
    }

    /**
     * Convenience: get or compute. Se hit, devolve cached. Senão, corre
     * $compute() e guarda o resultado antes de devolver.
     */
    public function remember(
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
        callable $compute,
        float $temperature = 0.0,
        ?int $ttl = null,
    ): string {
        $cached = $this->get($model, $system, $messages, $maxTokens, $temperature);
        if ($cached !== null) return $cached;

        $response = $compute();
        if (is_string($response)) {
            $this->put($model, $system, $messages, $maxTokens, $response, $temperature, $ttl);
            return $response;
        }
        return '';
    }

    private function isCacheable(
        string $system,
        array $messages,
        int $maxTokens,
        float $temperature,
    ): bool {
        if ($temperature > 0.001) return false;        // non-deterministic
        if ($maxTokens > self::MAX_TOKENS_SKIP_CACHE) return false;
        if (count($messages) > self::MAX_TURNS_FOR_CACHE) return false;
        if (str_contains($system, 'no-cache')) return false;
        return true;
    }

    private function cacheKey(
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
    ): string {
        // xxh3 if available (8x faster than sha256), else sha256.
        $algo = in_array('xxh3', hash_algos(), true) ? 'xxh3' : 'sha256';
        $serialized = json_encode([
            'model'      => $model,
            'system'     => $system,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return 'llm:resp:' . hash($algo, $serialized);
    }

    /**
     * Métrica de hits para o dashboard /admin/anthropic-cost — incrementa
     * counter Redis (não DB para não fustigar) com TTL 24h. Total ↑ +1
     * por hit, total visitable via Cache::get('llm:hits:total:<date>').
     */
    private function recordHit(string $model): void
    {
        $date = now()->format('Y-m-d');
        $totalKey = "llm:hits:total:{$date}";
        $modelKey = "llm:hits:model:{$model}:{$date}";

        try {
            Cache::increment($totalKey);
            Cache::increment($modelKey);
            // Ensure TTL (Cache::increment não define TTL — só na 1ª put).
            if (Cache::get($totalKey . ':init') === null) {
                Cache::put($totalKey . ':init', 1, 90000);  // 25h
                Cache::put($modelKey . ':init', 1, 90000);
            }
        } catch (\Throwable $e) {
            // Métrica não-crítica, silent fail.
        }
    }
}
