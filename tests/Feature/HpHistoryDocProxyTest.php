<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HpHistoryClient;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /hp-history/doc/{uuid} — Laravel proxy that fetches an archived
 * document from the hp-history droplet on behalf of the user. Hides
 * the HMAC shared secret from the browser.
 *
 * Locked behaviour:
 *   • Unauthenticated → 302 to /login (auth middleware).
 *   • Bad UUID shape → 404 (route regex rejects).
 *   • hp-history disabled or returns null → 404 with a friendly body.
 *   • Successful fetch → streamed response with the original
 *     Content-Type AND Content-Disposition forwarded, but
 *     X-HP-Doc-Source NOT leaked to the client.
 */
class HpHistoryDocProxyTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';

    private function authedUser(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $r = $this->get('/hp-history/doc/'.self::VALID_UUID);
        $r->assertRedirect('/login');
    }

    public function test_invalid_uuid_returns_404(): void
    {
        $u = $this->authedUser();
        $r = $this->actingAs($u)->get('/hp-history/doc/not-a-uuid');
        // Route regex `[A-Fa-f0-9\-]{36}` rejects malformed input → 404.
        $r->assertStatus(404);
    }

    public function test_disabled_or_missing_returns_404(): void
    {
        $u = $this->authedUser();

        // Stub the client to return null (matches disabled OR
        // upstream "not found" / "not in library").
        $this->app->bind(HpHistoryClient::class, fn() => new class extends HpHistoryClient {
            public function fetchDocument(string $docId): ?array { return null; }
        });

        $r = $this->actingAs($u)->get('/hp-history/doc/'.self::VALID_UUID);
        $r->assertStatus(404);
    }

    public function test_successful_fetch_streams_pdf_with_safe_headers(): void
    {
        $u = $this->authedUser();

        $stream = Utils::streamFor("%PDF-1.4 fake bytes for the test");
        $this->app->bind(HpHistoryClient::class, fn() => new class($stream) extends HpHistoryClient {
            private $stream;
            public function __construct($s) { $this->stream = $s; }
            public function fetchDocument(string $docId): ?array
            {
                return [
                    $this->stream,
                    'application/pdf',
                    [
                        'Content-Disposition' => 'inline; filename="RFQ-2024-0241.pdf"',
                        'X-HP-Doc-Source'     => 'qnap://archive/sensitive/path',
                    ],
                ];
            }
        });

        $r = $this->actingAs($u)->get('/hp-history/doc/'.self::VALID_UUID);
        $r->assertStatus(200);
        $r->assertHeader('Content-Type', 'application/pdf');
        $r->assertHeader('Content-Disposition', 'inline; filename="RFQ-2024-0241.pdf"');
        // X-HP-Doc-Source must NOT leak to the client (only to logs).
        $this->assertEmpty(
            $r->headers->get('X-HP-Doc-Source'),
            'Origin URL must not leak to the browser'
        );

        // Body is streamed — capture it with the test response helper.
        $body = $r->streamedContent();
        $this->assertStringContainsString('%PDF-1.4 fake bytes', $body);
    }
}
