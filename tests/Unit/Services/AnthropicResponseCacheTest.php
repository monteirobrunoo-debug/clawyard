<?php

namespace Tests\Unit\Services;

use App\Services\AnthropicResponseCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AnthropicResponseCache unit tests (Fase B3-lite 2026-05-28).
 *
 * Catch regressions no cache de respostas (B2 — commit ab036cf):
 *   - Hash exacto: mesmo input → mesma chave
 *   - Skip rules: temp>0, max_tokens>8000, msgs>5 turns, 'no-cache' marker
 *   - put + get roundtrip funciona
 *   - remember() corre compute() em miss e cacheia
 */
class AnthropicResponseCacheTest extends TestCase
{
    public function test_hit_returns_cached_response(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $model = 'claude-sonnet-4-6';
        $system = 'You are helpful';
        $messages = [['role' => 'user', 'content' => 'olá']];
        $maxTokens = 100;

        $this->assertNull($svc->get($model, $system, $messages, $maxTokens));

        $svc->put($model, $system, $messages, $maxTokens, 'hello back');
        $this->assertSame('hello back', $svc->get($model, $system, $messages, $maxTokens));
    }

    public function test_skip_when_temperature_positive(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $svc->put('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 100, 'resp', temperature: 0.5);
        $this->assertNull($svc->get('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 100, temperature: 0.5));
    }

    public function test_skip_when_max_tokens_too_large(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $svc->put('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 16000, 'resp');
        $this->assertNull($svc->get('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 16000));
    }

    public function test_skip_when_many_turns(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $messages = array_fill(0, 8, ['role' => 'user', 'content' => 'turn']);
        $svc->put('claude-sonnet-4-6', 'sys', $messages, 100, 'resp');
        $this->assertNull($svc->get('claude-sonnet-4-6', 'sys', $messages, 100));
    }

    public function test_skip_when_system_has_no_cache_marker(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $svc->put('claude-sonnet-4-6', 'sys with no-cache flag', [['role' => 'user', 'content' => 'a']], 100, 'resp');
        $this->assertNull($svc->get('claude-sonnet-4-6', 'sys with no-cache flag', [['role' => 'user', 'content' => 'a']], 100));
    }

    public function test_empty_response_not_cached(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $svc->put('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 100, '');
        $this->assertNull($svc->get('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 100));
    }

    public function test_remember_runs_compute_on_miss(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $called = 0;
        $compute = function () use (&$called) {
            $called++;
            return 'computed response';
        };

        $r1 = $svc->remember('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 100, $compute);
        $r2 = $svc->remember('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'a']], 100, $compute);

        $this->assertSame('computed response', $r1);
        $this->assertSame('computed response', $r2);
        $this->assertSame(1, $called, '2ª chamada deve ser cache hit, não correr compute');
    }

    public function test_different_messages_produce_different_keys(): void
    {
        Cache::flush();
        $svc = app(AnthropicResponseCache::class);

        $svc->put('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'pergunta A']], 100, 'resp A');
        $svc->put('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'pergunta B']], 100, 'resp B');

        $this->assertSame('resp A', $svc->get('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'pergunta A']], 100));
        $this->assertSame('resp B', $svc->get('claude-sonnet-4-6', 'sys', [['role' => 'user', 'content' => 'pergunta B']], 100));
    }
}
