<?php

namespace Tests\Unit\AgentSwarm;

use App\Services\AgentSwarm\AgentDispatcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Tests\TestCase;

/**
 * Pin the Anthropic dispatch contract WITHOUT touching the network.
 *
 * Guzzle's MockHandler lets us script exactly what the upstream
 * "returns" so we can prove:
 *   • token usage maps to USD cost via the rate card
 *   • 5xx is retried once
 *   • 4xx is NOT retried (caller's prompt is bad — no point)
 *   • transport errors retry once then fail gracefully
 *   • missing API key short-circuits without firing an HTTP request
 */
class AgentDispatcherTest extends TestCase
{
    private function dispatcherWithResponses(array $responses): array
    {
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        $client  = new Client(['handler' => $stack, 'http_errors' => false]);

        // Force a known API key so the short-circuit doesn't fire.
        config()->set('services.anthropic.api_key', 'sk-test-fake');
        config()->set('services.agent_swarm.token_rates', [
            'haiku'  => ['input' => 1.0,  'output' => 5.0],
            'sonnet' => ['input' => 3.0,  'output' => 15.0],
            'opus'   => ['input' => 15.0, 'output' => 75.0],
        ]);

        return [new AgentDispatcher($client), $mock];
    }

    public function test_success_response_is_parsed_with_correct_cost(): void
    {
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => 'hello world']],
            'usage'   => ['input_tokens' => 1_000_000, 'output_tokens' => 500_000],
            'model'   => 'claude-sonnet-4-6-20251101',
        ]);
        [$dispatcher] = $this->dispatcherWithResponses([new Response(200, [], $body)]);

        $res = $dispatcher->dispatch('sys', 'usr', model: 'claude-sonnet-4-6');

        $this->assertTrue($res['ok'], 'success path returns ok=true');
        $this->assertSame('hello world', $res['text']);
        $this->assertSame(1_000_000, $res['tokens_in']);
        $this->assertSame(500_000,   $res['tokens_out']);
        // sonnet: 1.0M in × $3 + 0.5M out × $15 = 3 + 7.5 = $10.50
        $this->assertEqualsWithDelta(10.50, $res['cost_usd'], 0.001);
        $this->assertSame('claude-sonnet-4-6-20251101', $res['model']);
        $this->assertNull($res['error']);
    }

    public function test_5xx_is_retried_once_then_succeeds(): void
    {
        $okBody = json_encode([
            'content' => [['type' => 'text', 'text' => 'ok after retry']],
            'usage'   => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);
        [$dispatcher, $mock] = $this->dispatcherWithResponses([
            new Response(503, [], 'upstream busy'),
            new Response(200, [], $okBody),
        ]);

        $res = $dispatcher->dispatch('sys', 'usr');

        $this->assertTrue($res['ok'], '5xx must retry once and succeed on attempt 2');
        $this->assertSame('ok after retry', $res['text']);
        $this->assertSame(0, $mock->count(), 'both queued responses must have been consumed');
    }

    public function test_4xx_is_not_retried_and_returns_error(): void
    {
        [$dispatcher, $mock] = $this->dispatcherWithResponses([
            new Response(400, [], '{"error":"bad prompt"}'),
            // A second response that should NEVER be consumed if we behave correctly.
            new Response(200, [], '{"content":[{"type":"text","text":"surprise"}]}'),
        ]);

        $res = $dispatcher->dispatch('sys', 'usr');

        $this->assertFalse($res['ok'], '4xx must not retry');
        $this->assertStringContainsString('anthropic_4xx_400', $res['error']);
        $this->assertSame(1, $mock->count(),
            'second response must remain unconsumed — proves retry was skipped');
    }

    public function test_transport_error_retries_once_then_fails(): void
    {
        [$dispatcher, $mock] = $this->dispatcherWithResponses([
            new ConnectException('timeout', new Request('POST', 'v1/messages')),
            new ConnectException('timeout', new Request('POST', 'v1/messages')),
        ]);

        $res = $dispatcher->dispatch('sys', 'usr');

        $this->assertFalse($res['ok'], 'two transport failures → ok=false');
        $this->assertStringContainsString('anthropic_transport', $res['error']);
        $this->assertSame(0, $mock->count(), 'both attempts consumed (1 + retry)');
    }

    public function test_missing_api_key_short_circuits_without_http_call(): void
    {
        config()->set('services.anthropic.api_key', '');
        $mock    = new MockHandler([]);   // empty — would throw if HTTP fired
        $client  = new Client(['handler' => HandlerStack::create($mock)]);
        $dispatcher = new AgentDispatcher($client);

        $res = $dispatcher->dispatch('sys', 'usr');

        $this->assertFalse($res['ok']);
        $this->assertSame('anthropic_api_key_missing', $res['error']);
    }

    public function test_haiku_model_is_priced_with_haiku_rates(): void
    {
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => 'haiku reply']],
            'usage'   => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
            'model'   => 'claude-haiku-4-5-20251001',
        ]);
        [$dispatcher] = $this->dispatcherWithResponses([new Response(200, [], $body)]);

        $res = $dispatcher->dispatch('sys', 'usr', model: 'claude-haiku-4-5');

        $this->assertTrue($res['ok']);
        // haiku: 1M × $1 + 1M × $5 = $6
        $this->assertEqualsWithDelta(6.0, $res['cost_usd'], 0.001);
    }

    public function test_malformed_json_response_returns_error(): void
    {
        [$dispatcher] = $this->dispatcherWithResponses([
            new Response(200, [], 'this is not json'),
        ]);

        $res = $dispatcher->dispatch('sys', 'usr');

        $this->assertFalse($res['ok']);
        $this->assertSame('anthropic_json_decode', $res['error']);
    }
}
