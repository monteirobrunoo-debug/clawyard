<?php

namespace Tests\Feature;

use App\Services\HpHistoryClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the contract of the hp-history client. The real droplet isn't
 * running in tests; we hand the client a Guzzle MockHandler so we can
 * assert request shape (auth, body) and response handling without a
 * server.
 *
 * Locked behaviours:
 *   • OFF by default — disabled config returns null/[] without ever
 *     hitting HTTP. Marco/Vasco will degrade silently.
 *   • augmentContextFor returns null when the user message has no
 *     historical-intent token.
 *   • augmentContextFor returns null when there's no hit, even if the
 *     server is reachable.
 *   • search() signs every request with HMAC-SHA256 over
 *     "{ts}.{method}.{path}.{sha256(body)}".
 *   • 5xx triggers ONE retry; persistent 5xx returns [] and logs.
 *   • Successful response is cached in Cache for cache_ttl seconds —
 *     a second call with the same query/filters does NOT re-issue
 *     an HTTP request.
 *   • renderBlock cites source verbatim and tags the domain.
 */
class HpHistoryClientTest extends TestCase
{
    /** Holds the history container so tests can introspect requests. */
    private array $sent = [];
    private ?MockHandler $mock = null;

    /**
     * Build a client with an in-memory Guzzle mock and the given config.
     * Records every outgoing request into $this->sent for assertion.
     *
     * @param array<int, Response> $responses
     */
    private function clientWith(array $responses, array $configOverrides = []): HpHistoryClient
    {
        config()->set('services.hp_history', array_merge([
            'enabled'     => true,
            'base_url'    => 'http://hp-history.test/',
            'hmac_secret' => 'test-secret',
            'timeout'     => 5,
            'cache_ttl'   => 300,
            'max_results' => 5,
        ], $configOverrides));

        $this->sent = [];
        $this->mock = new MockHandler($responses);
        $stack      = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->sent));
        $http = new Client([
            'base_uri'    => 'http://hp-history.test/',
            'handler'     => $stack,
            'http_errors' => false,
        ]);

        return new HpHistoryClient($http);
    }

    public function test_disabled_client_returns_null_without_http(): void
    {
        config()->set('services.hp_history', ['enabled' => false]);
        $client = new HpHistoryClient();

        $this->assertFalse($client->isEnabled());
        $this->assertSame([], $client->search('anything'));
        $this->assertNull($client->augmentContextFor('história Wärtsilä'));
    }

    public function test_augment_returns_null_when_message_has_no_history_intent(): void
    {
        $client = $this->clientWith([]);

        // No "history"/"última vez"/"precedente" token → no lookup.
        $this->assertNull($client->augmentContextFor('preciso de filtro Wartsila'));
    }

    public function test_augment_returns_null_when_server_returns_empty_hits(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['hits' => []])),
        ]);

        $this->assertNull(
            $client->augmentContextFor('histórico de RFQ Wartsila Singapore', 'spares')
        );
    }

    public function test_augment_returns_block_with_domain_tag_when_hits_present(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode([
                'hits' => [[
                    'title'        => 'RFQ-2024-0241 — MTU Series 4000',
                    'source'       => 'qnap://archive/2024/rfq.pdf',
                    'snippet'      => 'Cliente PT Navy adjudicou €54k em 2024 para overhaul de 2× MTU 4000',
                    'score'        => 0.91,
                    'citation_url' => 'https://hp-history.partyard.eu/doc/rfq-2024-0241',
                ]],
            ])),
        ]);

        $block = $client->augmentContextFor('última vez que vendemos MTU para PT Navy?', 'spares');
        $this->assertNotNull($block);
        $this->assertStringContainsString('<hp_history domain="spares">', $block);
        $this->assertStringContainsString('RFQ-2024-0241 — MTU Series 4000', $block);
        $this->assertStringContainsString('PT Navy adjudicou €54k', $block);
        $this->assertStringContainsString('https://hp-history.partyard.eu/doc/rfq-2024-0241', $block);
    }

    public function test_search_signs_request_with_hmac(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['hits' => []])),
        ]);

        $client->search('foo', ['domain' => 'spares']);

        $this->assertCount(1, $this->sent);
        /** @var \Psr\Http\Message\RequestInterface $req */
        $req = $this->sent[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/search', $req->getUri()->getPath());

        $ts        = $req->getHeaderLine('X-HP-Timestamp');
        $sig       = $req->getHeaderLine('X-HP-Signature');
        $bodyHash  = $req->getHeaderLine('X-HP-Body-SHA256');
        $bodyRaw   = (string) $req->getBody();

        $this->assertNotEmpty($ts);
        $this->assertNotEmpty($sig);
        $this->assertSame(64, strlen($sig), 'sha256 hex = 64 chars');
        $this->assertSame(hash('sha256', $bodyRaw), $bodyHash);

        $expected = hash_hmac('sha256', "{$ts}.POST./search.{$bodyHash}", 'test-secret');
        $this->assertSame($expected, $sig, 'Signature must match HMAC-SHA256 of canonical string');
    }

    public function test_5xx_triggers_one_retry_then_gives_up(): void
    {
        $client = $this->clientWith([
            new Response(503, [], 'overloaded'),
            new Response(503, [], 'still overloaded'),
        ]);

        $hits = $client->search('whatever');
        $this->assertSame([], $hits);
        $this->assertCount(2, $this->sent, 'one retry on 5xx, then bail');
    }

    public function test_5xx_then_200_succeeds_on_retry(): void
    {
        $client = $this->clientWith([
            new Response(502, [], 'bad gateway'),
            new Response(200, [], json_encode(['hits' => [['title' => 'ok', 'source' => 's', 'score' => 1.0]]])),
        ]);

        $hits = $client->search('whatever');
        $this->assertCount(1, $hits);
        $this->assertCount(2, $this->sent);
    }

    public function test_response_is_cached_for_cache_ttl(): void
    {
        // Single response in the queue. A second call with the same query
        // must NOT pop from the mock again — it must hit Cache.
        $client = $this->clientWith([
            new Response(200, [], json_encode(['hits' => [['title' => 'x', 'source' => 's', 'score' => 1.0]]])),
        ]);
        Cache::flush();

        $first  = $client->search('same-query');
        $second = $client->search('same-query');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->sent, 'Second call must reuse the cache, not re-hit HTTP');
        $this->assertSame(0, $this->mock->count(), 'Mock queue should be drained exactly once');
    }

    public function test_4xx_does_not_retry(): void
    {
        // 4xx is "we did something wrong" — retrying would be useless.
        $client = $this->clientWith([
            new Response(400, [], 'bad request'),
        ]);

        $this->assertSame([], $client->search('whatever'));
        $this->assertCount(1, $this->sent);
    }

    public function test_render_block_caps_long_snippet(): void
    {
        $client = new HpHistoryClient();
        $longSnippet = str_repeat('lorem ipsum dolor sit amet ', 100);
        $block = $client->renderBlock([
            ['title' => 't', 'source' => 's', 'snippet' => $longSnippet, 'score' => 0.5, 'citation_url' => 'u'],
        ], 'spares');

        // Cap is 280 chars + ellipsis.
        $this->assertStringContainsString('…', $block);
    }

    public function test_intent_detection_matches_portuguese_and_english(): void
    {
        $client = new HpHistoryClient();
        $this->assertTrue($client->looksLikeHistoryQuestion('histórico de rfq mtu'));
        $this->assertTrue($client->looksLikeHistoryQuestion('última vez que vendemos a portugal'));
        $this->assertTrue($client->looksLikeHistoryQuestion('last time we sold to PT Navy'));
        $this->assertTrue($client->looksLikeHistoryQuestion('precedente Wartsila Singapore'));
        $this->assertFalse($client->looksLikeHistoryQuestion('preciso de filtro novo agora'));
        $this->assertFalse($client->looksLikeHistoryQuestion('quanto custa um wartsila w32?'));
    }
}
