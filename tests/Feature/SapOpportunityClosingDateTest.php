<?php

namespace Tests\Feature;

use App\Services\SapService;
use Tests\TestCase;

/**
 * Regression test for the past-date bug reported 2026-04-25:
 *
 *   SAP B1 returned "Date deviates from permissible range [OOPR.PredDate]"
 *   because the LLM produced ExpectedClosingDate=2025-05-24 (last year)
 *   while reading an email from 2025. The whole opportunity create
 *   failed and the operator had to retype.
 *
 * Lock the contract: SapService::createOpportunity / updateOpportunity
 * MUST snap any past date forward to today + ClosingDays (default 30)
 * before sending to SAP, so the SAP call always succeeds even when the
 * upstream agent provides a stale date.
 *
 * We don't hit a real SAP B1 — we subclass the service to capture the
 * payload that would have been POST'd / PATCH'd.
 */
class SapOpportunityClosingDateTest extends TestCase
{
    private function service(): SapService
    {
        // Anonymous subclass that captures the payload instead of HTTP-ing.
        // Self-contained: caller reads the captured calls via the public
        // `posts` / `patches` arrays on the returned instance.
        return new class extends SapService {
            public array $posts = [];
            public array $patches = [];

            public function __construct()
            {
                // Don't call parent::__construct — we'd need real SAP
                // creds. The methods we override are pure logic that
                // doesn't need a session.
            }
            protected function post(string $endpoint, array $payload, bool $retry = true): ?array
            {
                $this->posts[] = ['endpoint' => $endpoint, 'payload' => $payload];
                return ['ok' => true, 'SequentialNo' => 99999];
            }
            protected function patch(string $endpoint, array $payload, bool $retry = true): ?array
            {
                $this->patches[] = ['endpoint' => $endpoint, 'payload' => $payload];
                return ['ok' => true];
            }
            // Stub the BP lookup helpers so the createOpportunity flow
            // doesn't try a real SAP call when CardCode is missing.
            public function searchBPByVAT(string $vat, int $limit = 3): array { return []; }
            public function searchBusinessPartners(string $name, int $limit = 5): array { return []; }
            protected function get(string $endpoint, array $query = [], bool $retry = true): ?array { return null; }
        };
    }

    public function test_past_expected_closing_date_is_snapped_to_today_plus_closing_days(): void
    {
        $svc = $this->service();

        // Caller hands a stale date (last year) — exactly the LLM bug.
        $svc->createOpportunity([
            'CardCode'            => 'C000499',
            'OpportunityName'     => 'Test stale date',
            'StageId'             => 5,
            'ClosingDays'         => 30,
            'ExpectedClosingDate' => '2025-05-24',
        ]);

        $this->assertNotEmpty($svc->posts);
        $sent = $svc->posts[0]['payload'];
        $this->assertArrayHasKey('PredictedClosingDate', $sent);

        // Must be today + 30 days, NOT the 2025 value the caller passed.
        $expected = date('Y-m-d\T00:00:00\Z', strtotime('+30 days'));
        $this->assertSame($expected, $sent['PredictedClosingDate']);
    }

    public function test_future_expected_closing_date_is_passed_through(): void
    {
        $svc = $this->service();

        $future = date('Y-m-d', strtotime('+60 days'));
        $svc->createOpportunity([
            'CardCode'            => 'C000499',
            'OpportunityName'     => 'Test future date',
            'StageId'             => 5,
            'ClosingDays'         => 30,
            'ExpectedClosingDate' => $future,
        ]);

        $sent = $svc->posts[0]['payload'];
        $expected = date('Y-m-d\T00:00:00\Z', strtotime($future));
        $this->assertSame($expected, $sent['PredictedClosingDate'],
            'Caller-provided future date must be honoured verbatim');
    }

    public function test_today_is_acceptable_not_snapped(): void
    {
        $svc = $this->service();

        $today = date('Y-m-d');
        $svc->createOpportunity([
            'CardCode'            => 'C000499',
            'OpportunityName'     => 'Test today',
            'StageId'             => 5,
            'ExpectedClosingDate' => $today,
        ]);

        $sent = $svc->posts[0]['payload'];
        $expected = date('Y-m-d\T00:00:00\Z', strtotime($today));
        $this->assertSame($expected, $sent['PredictedClosingDate']);
    }

    public function test_no_date_field_uses_today_plus_closing_days(): void
    {
        $svc = $this->service();

        $svc->createOpportunity([
            'CardCode'        => 'C000499',
            'OpportunityName' => 'Test only ClosingDays',
            'StageId'         => 5,
            'ClosingDays'     => 45,
        ]);

        $sent = $svc->posts[0]['payload'];
        $expected = date('Y-m-d\T00:00:00\Z', strtotime('+45 days'));
        $this->assertSame($expected, $sent['PredictedClosingDate']);
    }

    public function test_update_opportunity_also_snaps_past_dates(): void
    {
        $svc = $this->service();

        $svc->updateOpportunity(12345, [
            'StageId'             => 6,
            'ExpectedClosingDate' => '2024-01-15',
            'ClosingDays'         => 14,
        ]);

        $this->assertNotEmpty($svc->patches);
        $sent = $svc->patches[0]['payload'];
        $this->assertArrayHasKey('PredictedClosingDate', $sent);

        $expected = date('Y-m-d\T00:00:00\Z', strtotime('+14 days'));
        $this->assertSame($expected, $sent['PredictedClosingDate']);
    }
}
