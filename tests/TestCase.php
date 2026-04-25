<?php

namespace Tests;

use App\Models\Tender;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reset per-request static caches between tests. Without this, the
     * scopeForUser cache (added 2026-04-25 to avoid 3-4 redundant DB
     * queries per dashboard render) persists across the same PHP
     * process and causes tests that recreate users with the same id
     * to see stale collaborator rows.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Tender::flushScopeForUserCache();
    }
}
