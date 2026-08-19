<?php

namespace Tests;

use App\Support\TeamsPersonaConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

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

        // SQLite has no MD5(), so any query through HuntressService's
        // subject-hash dedup branch (whereRaw('MD5(subject) = ?')) fails at
        // prepare time under the test driver while working fine on prod
        // MariaDB. Register it so that branch is testable at all — earlier
        // tests dodged it by always including a dedup URL in payloads.
        $pdo = DB::connection()->getPdo();
        if (str_contains($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME), 'sqlite')) {
            $pdo->sqliteCreateFunction('MD5', fn ($v) => md5((string) $v), 1);
        }
    }
}
