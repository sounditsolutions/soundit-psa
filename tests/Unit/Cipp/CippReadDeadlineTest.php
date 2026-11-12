<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippClientException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class CippReadDeadlineTest extends TestCase
{
    private array $history = [];

    private function client(array $queue, bool $cached = true): CippClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $http = new Client(['base_uri' => 'https://example.test/', 'timeout' => 60, 'handler' => $stack]);
        $cache = new Repository(new ArrayStore);
        if ($cached) {
            $cache->put('cipp_oauth_token', 'synthetic');
        }

        return new class([], $cache, $http) extends CippClient
        {
            public float $clock = 100;

            public array $pauses = [];

            private Client $fake;

            public function __construct($config, $cache, $http)
            {
                parent::__construct($config, $cache, $http);
                $this->fake = $http;
            }

            protected function monotonicTime(): float
            {
                return $this->clock;
            }

            protected function tokenClient(): Client
            {
                return $this->fake;
            }

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
                $this->clock += $seconds;
            }
        };
    }

    private function timeout(): ConnectException
    {
        return new ConnectException('synthetic timeout', new Request('GET', 'https://example.test/'), null, ['errno' => 28]);
    }

    public function test_timeout_clause_and_remaining_options(): void
    {
        $client = $this->client([$this->timeout()]);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected timeout');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP API error: 0 ConnectException (curl 28); CIPP did not answer within 20 s (upstream timeout; this read is safe to retry)', $e->getMessage());
            $this->assertLessThanOrEqual(200, strlen('CIPP query failed: '.$e->getMessage()));
        }
        $this->assertSame(20.0, $this->history[0]['options']['timeout']);
        $this->assertSame(20.0, $this->history[0]['options']['connect_timeout']);
    }

    public function test_retry_after_over_budget_refuses_without_sleep(): void
    {
        $client = $this->client([new Response(429, ['Retry-After' => '30']), new Response(200, [], '[]')]);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected refusal');
        } catch (CippClientException $e) {
            $this->assertSame(429, $e->getCode());
        }
        $this->assertSame([], $client->pauses);
        $this->assertCount(1, $this->history);
    }

    public function test_retry_consumes_shared_budget(): void
    {
        $client = $this->client([new Response(429, ['Retry-After' => '3']), new Response(200, [], '[]')]);
        $this->assertSame([], $client->get('api/ListUsers', [], 120));
        $this->assertSame(17.0, $this->history[1]['options']['timeout']);
    }

    public function test_token_timeout_and_refresh_are_budgeted(): void
    {
        foreach ([false, true] as $refresh) {
            $this->history = [];
            $client = $this->client($refresh ? [new Response(401), $this->timeout()] : [$this->timeout()], $refresh);
            try {
                $client->get('api/ListUsers', [], 108);
                $this->fail('Expected token timeout');
            } catch (CippClientException $e) {
                $this->assertStringContainsString('this read is safe to retry', $e->getMessage());
            }
            $this->assertSame(8.0, $this->history[array_key_last($this->history)]['options']['timeout']);
        }
    }

    public function test_comparison_second_read_exhausts_one_deadline_without_partial_result(): void
    {
        // Invoke-ListUsers selects id, userPrincipalName and assignedLicenses;
        // same producer fixture as CippCompareUserAccessTest.
        $id = '11111111-1111-4111-8111-111111111111';
        $client = null;
        $client = $this->client([
            function () use (&$client, $id) {
                $client->clock += 12;

                return new Response(200, [], json_encode([['id' => $id, 'userPrincipalName' => 'source@example.test', 'assignedLicenses' => []]]));
            },
            function () use (&$client) {
                $client->clock += 9;

                return new Response(200, [], '[]');
            },
        ]);
        $service = new \App\Services\Cipp\CippCompareUserAccess($client, app(\App\Services\Cipp\CippToolContract::class));
        $result = $service->compare('example.test', ['source_user' => $id, 'target_user' => $id], null);
        $this->assertSame(['error'], array_keys($result));
        $this->assertStringContainsString('api/ListGraphRequest', $result['error']);
        $this->assertStringContainsString('this read is safe to retry', $result['error']);
        $this->assertSame(8.0, $this->history[1]['options']['timeout']);
        $this->assertCount(2, $this->history);
    }

    public function test_unbudgeted_sync_and_500_are_unchanged(): void
    {
        $client = $this->client([new Response(200, [], '[]'), new Response(500)]);
        $this->assertSame([], $client->listUsers('example.test'));
        $this->assertSame(60, $this->history[0]['options']['timeout']);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected server failure');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP API error: 500 ServerException', $e->getMessage());
        }
    }
}
