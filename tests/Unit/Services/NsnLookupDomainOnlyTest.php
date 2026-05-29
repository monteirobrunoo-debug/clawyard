<?php

namespace Tests\Unit\Services;

use App\Services\AgentTools\NsnLookupTool;
use ReflectionMethod;
use Tests\TestCase;

/**
 * NsnLookupTool::domainOnly() unit tests (Fase B3-lite 2026-05-28).
 *
 * Catch regressions no strip de URL→domínio (commit 13f890e que fix-ou
 * "Cor. Rodrigues dar erro 404"). Tests via reflection porque o método
 * é private (encapsulação correcta).
 */
class NsnLookupDomainOnlyTest extends TestCase
{
    private function invoke(string $url): string
    {
        $tool = app(NsnLookupTool::class);
        $m = new ReflectionMethod($tool, 'domainOnly');
        $m->setAccessible(true);
        return $m->invoke($tool, $url);
    }

    public function test_strips_https_url_with_path(): void
    {
        $this->assertSame(
            'wbparts.com',
            $this->invoke('https://www.wbparts.com/rfq/1560-00-806-5287.html')
        );
    }

    public function test_strips_http_homepage(): void
    {
        $this->assertSame(
            'aerospaceunlimited.com',
            $this->invoke('http://www.aerospaceunlimited.com')
        );
    }

    public function test_removes_www_prefix(): void
    {
        $this->assertSame(
            'example.com',
            $this->invoke('https://www.example.com/path/to/page?q=1')
        );
    }

    public function test_keeps_subdomain_other_than_www(): void
    {
        // shop.example.com mantém-se — só www. é stripado
        $this->assertSame(
            'shop.example.com',
            $this->invoke('https://shop.example.com/product/123')
        );
    }

    public function test_lowercases_host(): void
    {
        $this->assertSame(
            'wbparts.com',
            $this->invoke('https://WWW.WBparts.COM/rfq/x')
        );
    }

    public function test_returns_unchanged_when_not_url(): void
    {
        // Strings que não são URLs ficam intactas (defensive)
        $this->assertSame('not-a-url', $this->invoke('not-a-url'));
        $this->assertSame('email@example.com', $this->invoke('email@example.com'));
    }

    public function test_handles_url_with_port(): void
    {
        // parse_url com port — devolvemos só o host
        $this->assertSame(
            'localhost',
            $this->invoke('http://localhost:8000/test')
        );
    }
}
