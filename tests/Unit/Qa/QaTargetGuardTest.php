<?php

namespace Tests\Unit\Qa;

use App\Services\Qa\QaTargetGuard;
use PHPUnit\Framework\TestCase;

class QaTargetGuardTest extends TestCase
{
    public function test_allows_configured_dev_hosts(): void
    {
        $guard = new QaTargetGuard(['soundit-dev', '192.168.1.51', 'localhost', '127.0.0.1']);

        foreach (['https://soundit-dev/dashboard', 'https://192.168.1.51/wiki', 'http://localhost:8080/'] as $url) {
            $this->assertSame($url, $guard->assertAllowed($url));
        }
    }

    public function test_rejects_non_dev_hosts(): void
    {
        $guard = new QaTargetGuard(['soundit-dev']);

        $this->expectException(\RuntimeException::class);
        $guard->assertAllowed('https://soundit.couttspnw.com/dashboard'); // a production-looking host
    }

    public function test_rejects_malformed_url(): void
    {
        $guard = new QaTargetGuard(['soundit-dev']);

        $this->expectException(\RuntimeException::class);
        $guard->assertAllowed('not-a-url');
    }
}
