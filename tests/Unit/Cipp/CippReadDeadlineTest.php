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
    use \Illuminate\Foundation\Testing\RefreshDatabase;

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
        $client = null;
        $client = $this->client([function () use (&$client) {
            $client->clock = 120;

            return $this->timeout();
        }]);
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
            $this->assertSame('CIPP API error: 429 ClientException', $e->getMessage());
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

    public function test_uncached_token_timeout_is_budgeted(): void
    {
        $this->tokenExpiry(false);
    }

    public function test_refresh_token_timeout_is_budgeted(): void
    {
        $this->tokenExpiry(true);
    }

    private function tokenExpiry(bool $refresh): void
    {
        $client = null;
        $timeout = function () use (&$client) {
            $client->clock = 120;

            return $this->timeout();
        };
        $client = $this->client($refresh ? [new Response(401), $timeout] : [$timeout], $refresh);
        $client->clock = 112;
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected token timeout');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP API error: 0 ConnectException (curl 28); CIPP did not answer within 20 s (upstream timeout; this read is safe to retry)', $e->getMessage());
        }
        $this->assertSame(8.0, $this->history[array_key_last($this->history)]['options']['timeout']);
    }

    public function test_token_own_cap_does_not_claim_twenty_seconds(): void
    {
        $client = null;
        $client = $this->client([function () use (&$client) {
            $client->clock += 15;

            return $this->timeout();
        }], false);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected token failure');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP OAuth token request failed: 0 ConnectException (curl 28)', $e->getMessage());
        }
        $this->assertSame(15.0, $this->history[0]['options']['timeout']);
    }

    public function test_spent_before_send_does_not_invent_transport_details(): void
    {
        $client = $this->client([new Response(200, [], '[]')]);
        try {
            $client->get('api/ListUsers', [], 100);
            $this->fail('Expected refusal');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP did not answer within 20 s (upstream timeout; this read is safe to retry)', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function test_late_token_success_preserves_observed_401(): void
    {
        $client = null;
        $client = $this->client([new Response(401), function () use (&$client) {
            $client->clock = 120;

            return new Response(200, [], '{"access_token":"synthetic"}');
        }, new Response(200, [], '[]')]);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected refusal');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP API error: 401 ClientException; CIPP did not answer within 20 s (upstream timeout; this read is safe to retry)', $e->getMessage());
        }
        $this->assertCount(2, $this->history);
    }

    public function test_early_api_timeout_has_no_twenty_second_clause(): void
    {
        $client = $this->client([$this->timeout()]);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected failure');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP API error: 0 ConnectException (curl 28)', $e->getMessage());
        }
    }

    public function test_http_date_retry_after_refuses_without_sleep(): void
    {
        $client = $this->client([new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 60).' GMT']), new Response(200, [], '[]')]);
        try {
            $client->get('api/ListUsers', [], 120);
            $this->fail('Expected refusal');
        } catch (CippClientException $e) {
            $this->assertSame('CIPP API error: 429 ClientException', $e->getMessage());
        }
        $this->assertSame([], $client->pauses);
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
            new Response(200, [], json_encode([['id' => $id, 'userPrincipalName' => 'source@example.test', 'assignedLicenses' => []]])),
            new Response(200, [], '[]'),
            new Response(200, [], '[]'),
        ]);
        $service = new \App\Services\Cipp\CippCompareUserAccess($client, app(\App\Services\Cipp\CippToolContract::class));
        $result = $service->compare('example.test', ['source_user' => $id, 'target_user' => $id], null);
        $this->assertSame(['error'], array_keys($result));
        $this->assertStringContainsString('api/ListGraphRequest', $result['error']);
        $this->assertStringContainsString('this read is safe to retry', $result['error']);
        $this->assertSame(8.0, $this->history[1]['options']['timeout']);
        $this->assertCount(2, $this->history);
    }

    public function test_dispatch_boundary_reports_exact_timeout(): void
    {
        $scope = \App\Models\Client::factory()->create(['cipp_tenant_domain' => 'example.test']);
        $client = null;
        $client = $this->client([function () use (&$client) {
            $client->clock = 120;

            return $this->timeout();
        }]);
        app()->instance(CippClient::class, $client);
        $executor = new class($scope)
        {
            use \App\Services\Cipp\HandlesCippTools;

            protected ?int $clientId;

            public function __construct(private \App\Models\Client $scope)
            {
                $this->clientId = $scope->id;
            }

            protected function cippScopeClient(): ?\App\Models\Client
            {
                return $this->scope;
            }

            protected function cippLogPrefix(): string
            {
                return '[Test]';
            }

            public function run(): array
            {
                return $this->cippListMessageTrace([]);
            }
        };
        $result = $executor->run();
        $this->assertSame(['error' => 'CIPP query failed: CIPP API error: 0 ConnectException (curl 28); CIPP did not answer within 20 s (upstream timeout; this read is safe to retry)'], $result);
        $this->assertLessThanOrEqual(200, strlen($result['error']));
        $this->assertSame(20.0, $this->history[0]['options']['timeout']);
    }

    public function test_write_transport_keeps_sixty_seconds(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $options = [];
        \Illuminate\Support\Facades\Http::fake(function ($request, $requestOptions) use (&$options) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return \Illuminate\Support\Facades\Http::response(['access_token' => 'synthetic', 'expires_in' => 3600]);
            }
            $options[] = $requestOptions;

            return \Illuminate\Support\Facades\Http::response(['Results' => 'Success']);
        });
        $cache = new Repository(new ArrayStore);
        $cache->put('cipp_oauth_token', 'synthetic');
        $client = new \App\Services\Cipp\CippRestWriteClient(['api_url' => 'https://example.test', 'tenant_id' => 'synthetic', 'client_id' => 'synthetic', 'client_secret' => 'synthetic'], $cache, fn () => ['93.184.216.34']);
        $client->setUserSignInState('example.test', 'synthetic-user', false);
        $this->assertSame(60, $options[0]['timeout']);
    }

    public function test_message_trace_read_post_has_deadline(): void
    {
        // Invoke-ListMessageTrace.ps1 success envelope, as in CippClientMessageTraceTest.
        $client = $this->client([new Response(200, [], '{"Results":[],"Metadata":{"Returned":0,"Source":"Graph"}}')]);
        $client->listMessageTrace('example.test', 4, 'sender@example.test', null, 120);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/ListMessageTrace', $request->getUri()->getPath());
        $this->assertSame(['tenantFilter' => 'example.test', 'days' => 4, 'sender' => 'sender@example.test'], json_decode((string) $request->getBody(), true));
        $this->assertSame(20.0, $this->history[0]['options']['timeout']);
    }

    public function test_invalid_and_missing_retry_after_use_bounded_backoff(): void
    {
        foreach (['', 'not-a-date'] as $header) {
            $this->history = [];
            $client = $this->client([new Response(429, ['Retry-After' => $header]), new Response(200, [], '[]')]);
            $this->assertSame([], $client->get('api/ListUsers', [], 120));
            $this->assertSame([2], $client->pauses);
            $this->assertSame(18.0, $this->history[1]['options']['timeout']);
        }
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
