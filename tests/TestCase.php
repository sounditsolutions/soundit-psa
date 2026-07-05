<?php

namespace Tests;

use App\Support\TeamsPersonaConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset per-process static memos that RefreshDatabase does NOT clear.
        // TeamsPersonaConfig::enabled() caches in a bare static that otherwise
        // leaks across test methods in the same PHPUnit process — an isolation
        // footgun on the auth-boundary persona registry (a warm memo from one
        // test's writes can bleed into the next test's pre-write assertions).
        // Centralized here so no registry-touching test has to remember it.
        TeamsPersonaConfig::flush();
    }
}
