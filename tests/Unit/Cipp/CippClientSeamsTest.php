<?php

namespace App\Services\Cipp {
    function sleep(int $seconds): int
    {
        if (\Tests\Unit\Cipp\CippClientSeamsTest::$sleepCalls !== null) {
            \Tests\Unit\Cipp\CippClientSeamsTest::$sleepCalls[] = $seconds;

            return 0;
        }

        return \sleep($seconds);
    }
}

namespace Tests\Unit\Cipp {
    use App\Services\Cipp\CippClient;
    use Illuminate\Contracts\Cache\Repository;
    use Tests\TestCase;

    class CippClientSeamsTest extends TestCase
    {
        public static ?array $sleepCalls = null;

        public function test_real_pause_calls_namespaced_sleep(): void
        {
            $client = new CippClient([], app(Repository::class));
            self::$sleepCalls = [];
            try {
                (new \ReflectionMethod(CippClient::class, 'pause'))->invoke($client, 3);
                $this->assertSame([3], self::$sleepCalls);
            } finally {
                self::$sleepCalls = null;
            }
        }

        public function test_real_token_client_timeout(): void
        {
            $client = new CippClient([], app(Repository::class));
            $http = (new \ReflectionMethod(CippClient::class, 'tokenClient'))->invoke($client);
            $this->assertSame(15, $http->getConfig('timeout'));
        }
    }
}
