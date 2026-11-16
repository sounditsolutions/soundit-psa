<?php

namespace Tests\Feature\Cipp;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippMcpAuthException;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippMcpConnector;
use App\Services\Cipp\CippMcpToolRelay;
use App\Services\Cipp\CippTenantScope;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card Cn86O1kp: one interactive deadline from HandlesCippTools::cippDispatch()
 * through the CIPP MCP relay (sign-in, ExecMCP) and any REST fallback.
 *
 * Synthetic throughout: Http::fake() with preventStrayRequests() for the relay,
 * a Guzzle MockHandler with a history seam for REST, and the one clock seam
 * (CippClient::setClockForTesting()), which drives CippClient::monotonicNow(),
 * the only clock. Time passes only when a stub moves $this->now.
 */
class CippMcpRelayDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'CIPP did not answer within 20 s (upstream timeout; this read is safe to retry)';

    private float $now = 100.0;

    /** @var array<int, array{url: string, timeout: mixed, connect_timeout: mixed}> */
    private array $sent = [];

    /** @var array<int, array<string, mixed>> REST requests seen by the Guzzle history seam */
    private array $restHistory = [];

    /** @var array<int, string> */
    private array $logs = [];

    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $logEntries = [];

    private bool $faked = false;

    private ?\Closure $signInHandler = null;

    private ?\Closure $execHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        $this->now = 100.0;
        CippClient::setClockForTesting(fn (): float => $this->now);
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logs[] = $e->message;
            $this->logEntries[] = ['message' => $e->message, 'context' => $e->context];
        });
    }

    protected function tearDown(): void
    {
        CippClient::setClockForTesting(null);

        parent::tearDown();
    }

    // --- helpers ---------------------------------------------------------------

    private function mcpClient(bool $withConnector = false): CippMcpClient
    {
        return new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client',
            'client_secret' => 'mcp-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34'], $withConnector ? app(CippMcpConnector::class) : null);
    }

    /**
     * Fake the token endpoint and ExecMCP. Each handler gets the request and may
     * move the clock; its return is the response. Every request's timeouts are
     * recorded in $this->sent.
     */
    private function fakeRelay(?\Closure $signIn = null, ?\Closure $exec = null): void
    {
        // Http::fake() appends stubs behind earlier ones, so the stubs are
        // registered once and a later call only swaps the handlers they run.
        $this->signInHandler = $signIn;
        $this->execHandler = $exec;
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        $record = function ($request, array $options): void {
            $this->sent[] = [
                'url' => $request->url(),
                'timeout' => $options['timeout'] ?? null,
                'connect_timeout' => $options['connect_timeout'] ?? null,
            ];
        };

        Http::fake([
            'login.microsoftonline.com/*' => function ($request, array $options) use ($record) {
                $record($request, $options);
                $handler = $this->signInHandler;

                return $handler ? $handler($request) : Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
            },
            'cipp.example.test/api/ExecMCP*' => function ($request, array $options) use ($record) {
                $record($request, $options);
                $handler = $this->execHandler;

                return $handler ? $handler($request) : $this->execOk();
            },
        ]);
    }

    private function execOk()
    {
        return Http::response(
            'data: {"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"[]"}]}}'."\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        );
    }

    /** A rejected transfer, as cURL reports it, with the errno Guzzle records. */
    private function curlFailure($request, int $errno, string $text)
    {
        return Create::rejectionFor(new ConnectException(
            "cURL error {$errno}: {$text}",
            $request->toPsrRequest(),
            null,
            ['errno' => $errno],
        ));
    }

    /** @return array<int, array{url: string, timeout: mixed, connect_timeout: mixed}> */
    private function sentTo(string $needle): array
    {
        return array_values(array_filter($this->sent, fn (array $s): bool => str_contains($s['url'], $needle)));
    }

    private function signInFailureLogged(): bool
    {
        foreach ($this->logs as $message) {
            if (str_contains($message, 'Token request failed') || str_contains($message, 'connector refresh failed') || str_contains($message, 'could not sign in')) {
                return true;
            }
        }

        return false;
    }

    private function connectDelegated(): void
    {
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setValue('cipp_mcp_backend_host', 'api://cipp-mcp-backend.example.test');
        Setting::setEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING, 'synthetic-refresh');
        Setting::setValue(CippMcpConnector::CONNECTED_AT, '2026-01-01T00:00:00+00:00');
    }

    private function configureBoth(bool $restSecretReadable = true): Client
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'rest-client');
        if ($restSecretReadable) {
            Setting::setEncrypted('cipp_client_secret', 'rest-secret');
        } else {
            // Stored but not ciphertext: decrypting it throws, as after an APP_KEY rotation.
            Setting::setValue('cipp_client_secret', 'synthetic-not-ciphertext');
        }
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', '1');

        app()->instance(CippMcpClient::class, $this->mcpClient());

        // One mapped client: the tenant check needs no tenant-list read.
        return Client::factory()->create(['cipp_tenant_domain' => 'example.test']);
    }

    /** A real REST client on a Guzzle MockHandler, its token pre-cached (no sign-in). */
    private function bindRest(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->restHistory));
        $http = new \GuzzleHttp\Client(['base_uri' => 'https://cipp.example.test/', 'timeout' => 60, 'handler' => $stack]);
        Cache::store()->put('cipp_oauth_token', 'synthetic-rest-token');

        app()->instance(CippClient::class, new CippClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'rest-client',
            'client_secret' => 'rest-secret',
        ], Cache::store(), $http));
    }

    private function listUsers(Client $client): array
    {
        return (new AssistantToolExecutor(null, $client->id, null))->execute('cipp_list_users', []);
    }

    // --- point 2: each request gets min(cap, what is left) ---------------------

    public function test_exec_mcp_is_sent_with_a_timeout_no_larger_than_the_remaining_budget(): void
    {
        $this->fakeRelay(signIn: function () {
            $this->now += 4;

            return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
        });

        $this->assertSame([], $this->mcpClient()->callTool('ListUsers', ['tenantFilter' => 'example.test'], 120.0));

        $exec = $this->sentTo('/api/ExecMCP');
        $this->assertCount(1, $exec);
        $this->assertEqualsWithDelta(16.0, $exec[0]['timeout'], 1e-9);
        $this->assertEqualsWithDelta(16.0, $exec[0]['connect_timeout'], 1e-9);
    }

    public function test_sign_in_is_sent_with_a_timeout_no_larger_than_the_remaining_budget(): void
    {
        $this->fakeRelay();

        $this->mcpClient()->callTool('ListUsers', ['tenantFilter' => 'example.test'], 105.0);

        $signIn = $this->sentTo('login.microsoftonline.com');
        $this->assertCount(1, $signIn);
        $this->assertEqualsWithDelta(5.0, $signIn[0]['timeout'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $signIn[0]['connect_timeout'], 1e-9);
    }

    public function test_delegated_sign_in_is_sent_with_a_timeout_no_larger_than_the_remaining_budget(): void
    {
        $this->connectDelegated();
        $this->fakeRelay();

        $this->mcpClient(withConnector: true)->callTool('ListUsers', ['tenantFilter' => 'example.test'], 107.0);

        $signIn = $this->sentTo('login.microsoftonline.com');
        $this->assertCount(1, $signIn);
        $this->assertEqualsWithDelta(7.0, $signIn[0]['timeout'], 1e-9);
        $this->assertEqualsWithDelta(7.0, $signIn[0]['connect_timeout'], 1e-9);
    }

    public function test_sign_in_and_exec_mcp_together_cannot_pass_the_deadline(): void
    {
        $this->fakeRelay(signIn: function () {
            $this->now += 12;

            return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
        });

        $this->mcpClient()->callTool('ListUsers', ['tenantFilter' => 'example.test'], 120.0);

        [$signIn] = $this->sentTo('login.microsoftonline.com');
        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertEqualsWithDelta(15.0, $signIn['timeout'], 1e-9);
        // 12 s spent signing in leaves 8: the two together end by the deadline.
        $this->assertEqualsWithDelta(8.0, $exec['timeout'], 1e-9);
        $this->assertLessThanOrEqual(CippClient::READ_BUDGET_SECONDS, 12 + $exec['timeout']);
    }

    public function test_a_spent_deadline_sends_nothing(): void
    {
        $this->fakeRelay();

        try {
            // Half a millisecond left: cURL would read that as no timeout at all.
            $this->mcpClient()->callTool('ListUsers', ['tenantFilter' => 'example.test'], 100.0005);
            $this->fail('Expected the deadline failure');
        } catch (CippClientException $e) {
            $this->assertNotInstanceOf(CippMcpAuthException::class, $e);
            $this->assertTrue(CippClient::isDeadlineFailure($e));
            $this->assertSame(self::SENTENCE, $e->getMessage());
        }

        $this->assertSame([], $this->sent);
    }

    public function test_a_deadline_spent_after_sign_in_sends_no_exec_mcp(): void
    {
        $this->fakeRelay(signIn: function () {
            $this->now = 119.9995;

            return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
        });

        try {
            $this->mcpClient()->callTool('ListUsers', ['tenantFilter' => 'example.test'], 120.0);
            $this->fail('Expected the deadline failure');
        } catch (CippClientException $e) {
            $this->assertTrue(CippClient::isDeadlineFailure($e));
        }

        $this->assertCount(1, $this->sentTo('login.microsoftonline.com'));
        $this->assertSame([], $this->sentTo('/api/ExecMCP'));
    }

    public function test_without_a_deadline_the_caps_are_unchanged(): void
    {
        $this->fakeRelay();

        $this->mcpClient()->callTool('ListUsers', ['tenantFilter' => 'example.test']);

        // Base 9e8c54f8: sign-in timeout(15), ExecMCP timeout(60), and neither sets
        // connect_timeout, so both keep the HTTP client's default 10.
        [$signIn] = $this->sentTo('login.microsoftonline.com');
        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertSame(15, $signIn['timeout']);
        $this->assertSame(10, $signIn['connect_timeout']);
        $this->assertSame(60, $exec['timeout']);
        $this->assertSame(10, $exec['connect_timeout']);
    }

    public function test_without_a_deadline_the_delegated_refresh_and_list_tools_caps_are_unchanged(): void
    {
        $this->connectDelegated();
        $this->fakeRelay(exec: fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]]));

        $this->mcpClient(withConnector: true)->listTools();

        [$signIn] = $this->sentTo('login.microsoftonline.com');
        [$list] = $this->sentTo('/api/ExecMCP');
        $this->assertSame(15, $signIn['timeout']);
        $this->assertSame(10, $signIn['connect_timeout']);
        $this->assertSame(60, $list['timeout']);
        $this->assertSame(10, $list['connect_timeout']);
    }

    // --- points 1 and 3: one deadline from cippDispatch, even without REST -----

    public function test_an_unreadable_rest_secret_still_bounds_the_relay(): void
    {
        $client = $this->configureBoth(restSecretReadable: false);
        $this->fakeRelay(signIn: function () {
            $this->now += 3;

            return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
        });

        $this->assertSame([], $this->listUsers($client));

        [$signIn] = $this->sentTo('login.microsoftonline.com');
        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertEqualsWithDelta(15.0, $signIn['timeout'], 1e-9);
        $this->assertEqualsWithDelta(17.0, $exec['timeout'], 1e-9);
        $this->assertEqualsWithDelta(17.0, $exec['connect_timeout'], 1e-9);
    }

    public function test_the_relay_gets_only_what_the_tenant_check_left(): void
    {
        $client = $this->configureBoth();
        Client::factory()->create(['cipp_tenant_domain' => 'other.example.test']);
        // Invoke-ListTenants rows (customerId, defaultDomainName), synthetic identities.
        $this->bindRest([function () {
            $this->now += 5;

            return new Response(200, [], json_encode([
                ['customerId' => '22222222-2222-4222-8222-222222222222', 'defaultDomainName' => 'example.test'],
                ['customerId' => '33333333-3333-4333-8333-333333333333', 'defaultDomainName' => 'other.example.test'],
            ]));
        }]);
        $this->fakeRelay();

        $this->assertSame([], $this->listUsers($client));

        $this->assertCount(1, $this->restHistory, 'only the tenant list went over REST');
        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertEqualsWithDelta(15.0, $exec['timeout'], 1e-9);
    }

    public function test_one_clock_and_one_budget(): void
    {
        $this->now = 250.0;
        $rest = new CippClient([], Cache::store());

        $this->assertSame(250.0 + CippClient::READ_BUDGET_SECONDS, $rest->readDeadline());
        $this->assertSame(250.0 + CippClient::READ_BUDGET_SECONDS, CippClient::freshReadDeadline());
        $this->assertSame(250.0, CippClient::monotonicNow());
        // The sentence production builds from the constant, not this file's copy.
        $production = CippClient::readDeadlineFailure()->getMessage();
        $this->assertStringContainsString('within '.CippClient::READ_BUDGET_SECONDS.' s', $production);
        $this->assertSame(self::SENTENCE, $production);
    }

    // --- points 4 and 5, condition 1: what the caller gets ----------------------

    public function test_a_relay_timeout_answers_with_the_deadline_sentence_and_does_not_fall_back_to_rest(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([]);
        $this->fakeRelay(exec: function ($request) {
            $this->now = 120.0;

            return $this->curlFailure($request, 28, 'Operation timed out after 18000 milliseconds with 0 bytes received');
        });

        $this->assertSame(['error' => 'CIPP query failed: '.self::SENTENCE], $this->listUsers($client));
        $this->assertSame([], $this->restHistory, 'no REST read after a relay timeout');
        $this->assertCount(1, $this->sentTo('/api/ExecMCP'));
    }

    public function test_a_non_timeout_relay_connection_failure_keeps_the_query_failed_path(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([]);
        $this->fakeRelay(exec: fn ($request) => $this->curlFailure($request, 7, 'Failed to connect to cipp.example.test port 443: Connection refused'));

        $result = $this->listUsers($client);

        $error = (string) ($result['error'] ?? '');
        $this->assertStringStartsWith('CIPP query failed: ', $error);
        $this->assertStringContainsString('UNTRUSTED CIPP QUERY ERROR', $error);
        $this->assertStringContainsString('Connection refused', $error);
        $this->assertStringNotContainsString(self::SENTENCE, $error);
        $this->assertSame([], $this->restHistory);
    }

    public function test_a_relay_http_error_keeps_the_query_failed_path(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([]);
        $this->fakeRelay(exec: fn () => Http::response('synthetic upstream error', 502));

        $error = (string) ($this->listUsers($client)['error'] ?? '');

        $this->assertStringStartsWith('CIPP query failed: ', $error);
        $this->assertStringContainsString('HTTP 502', $error);
        $this->assertStringNotContainsString(self::SENTENCE, $error);
    }

    public function test_sign_in_failure_falls_back_to_rest_with_the_remaining_budget_not_a_fresh_one(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([new Response(200, [], '[]')]);
        $this->fakeRelay(signIn: function () {
            $this->now += 6;

            return Http::response(['error' => 'invalid_client'], 400);
        });

        $this->assertSame([], $this->listUsers($client));

        $this->assertSame([], $this->sentTo('/api/ExecMCP'));
        $this->assertCount(1, $this->restHistory);
        $this->assertEqualsWithDelta(14.0, $this->restHistory[0]['options']['timeout'], 1e-9);
        $this->assertEqualsWithDelta(14.0, $this->restHistory[0]['options']['connect_timeout'], 1e-9);
    }

    public function test_sign_in_failure_that_used_the_whole_budget_answers_with_the_deadline_sentence(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([]);
        $this->fakeRelay(signIn: function () {
            $this->now = 120.0;

            return Http::response(['error' => 'invalid_client'], 400);
        });

        $this->assertSame(['error' => 'CIPP query failed: '.self::SENTENCE], $this->listUsers($client));
        $this->assertSame([], $this->restHistory, 'nothing left, so REST sends nothing');
    }

    // --- condition 3: a second attempt draws on the same deadline ---------------

    public function test_exec_mcp_refused_with_401_retries_over_rest_on_the_same_deadline(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([new Response(200, [], '[]')]);
        $this->fakeRelay(
            signIn: function () {
                $this->now += 2;

                return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
            },
            exec: function () {
                $this->now += 3;

                return Http::response('', 401);
            },
        );

        $this->assertSame([], $this->listUsers($client));

        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertEqualsWithDelta(18.0, $exec['timeout'], 1e-9);
        $this->assertCount(1, $this->restHistory);
        $this->assertEqualsWithDelta(15.0, $this->restHistory[0]['options']['timeout'], 1e-9);
    }

    // --- condition 2: a sign-in cut short by the deadline is not a failure -----

    public function test_a_deadline_cut_sign_in_leaves_the_failure_cache_empty(): void
    {
        $this->fakeRelay(signIn: function ($request) {
            $this->now = 105.0;

            return $this->curlFailure($request, 28, 'Operation timed out after 5000 milliseconds with 0 bytes received');
        });
        $relay = $this->mcpClient();

        try {
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 105.0);
            $this->fail('Expected the deadline failure');
        } catch (CippClientException $e) {
            $this->assertNotInstanceOf(CippMcpAuthException::class, $e);
            $this->assertSame(self::SENTENCE, $e->getMessage());
        }
        $this->assertFalse($this->signInFailureLogged());
        $this->assertFalse($this->appSignInFailureCached(), 'a deadline-cut sign-in is not remembered');

        // Not remembered: the next read signs in again instead of being refused.
        $this->sent = [];
        $this->fakeRelay();
        $this->assertSame([], $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 125.0));
        $this->assertCount(1, $this->sentTo('login.microsoftonline.com'));
    }

    public function test_a_sign_in_refused_for_no_budget_leaves_the_failure_cache_empty(): void
    {
        $this->fakeRelay();
        $relay = $this->mcpClient();

        try {
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 100.0);
            $this->fail('Expected the deadline failure');
        } catch (CippClientException $e) {
            $this->assertTrue(CippClient::isDeadlineFailure($e));
        }

        $this->assertSame([], $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 120.0));
        $this->assertCount(1, $this->sentTo('login.microsoftonline.com'));
    }

    public function test_a_deadline_cut_delegated_refresh_leaves_the_failure_cache_empty(): void
    {
        $this->connectDelegated();
        $this->fakeRelay(signIn: function ($request) {
            $this->now = 105.0;

            return $this->curlFailure($request, 28, 'Operation timed out after 5000 milliseconds with 0 bytes received');
        });
        $relay = $this->mcpClient(withConnector: true);

        try {
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 105.0);
            $this->fail('Expected the deadline failure');
        } catch (CippClientException $e) {
            $this->assertNotInstanceOf(CippMcpAuthException::class, $e);
            $this->assertTrue(CippClient::isDeadlineFailure($e));
        }
        $this->assertFalse($this->signInFailureLogged());
        $this->assertNull(Setting::getValue(CippMcpConnector::FAILED_CODE));
        $this->assertFalse(
            Cache::store()->has('cipp_mcp_sign_in_failed:'.sha1('tenant-1|mcp-client|delegated|2026-01-01T00:00:00+00:00')),
            'a deadline-cut delegated refresh is not remembered',
        );

        $this->sent = [];
        $this->fakeRelay();
        $this->assertSame([], $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 125.0));
        $this->assertCount(1, $this->sentTo('login.microsoftonline.com'));
    }

    public function test_a_full_cap_sign_in_timeout_is_still_a_sign_in_failure(): void
    {
        $this->fakeRelay(signIn: function ($request) {
            $this->now += 15;

            return $this->curlFailure($request, 28, 'Operation timed out after 15000 milliseconds with 0 bytes received');
        });
        $relay = $this->mcpClient();

        try {
            // 20 s left: the sign-in ran with its full 15 s cap.
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 120.0);
            $this->fail('Expected the sign-in failure');
        } catch (CippClientException $e) {
            $this->assertInstanceOf(CippMcpAuthException::class, $e);
            $this->assertFalse(CippClient::isDeadlineFailure($e));
        }
        $this->assertTrue($this->signInFailureLogged());

        // Remembered for SIGN_IN_FAILURE_TTL: the next read is refused without a POST.
        $this->sent = [];
        try {
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 140.0);
            $this->fail('Expected the remembered sign-in failure');
        } catch (CippMcpAuthException $e) {
            $this->assertStringContainsString('not retrying yet', $e->getMessage());
        }
        $this->assertSame([], $this->sent);
    }

    public function test_a_full_cap_sign_in_http_failure_still_writes_the_failure_cache(): void
    {
        $this->fakeRelay(signIn: fn () => Http::response(['error' => 'invalid_client'], 400));
        $relay = $this->mcpClient();

        try {
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 120.0);
            $this->fail('Expected the sign-in failure');
        } catch (CippMcpAuthException) {
        }

        $this->sent = [];
        try {
            $relay->callTool('ListUsers', ['tenantFilter' => 'example.test'], 140.0);
            $this->fail('Expected the remembered sign-in failure');
        } catch (CippMcpAuthException $e) {
            $this->assertStringContainsString('not retrying yet', $e->getMessage());
        }
        $this->assertSame([], $this->sent);
    }

    public function test_sign_in_error_detail_stays_in_the_log(): void
    {
        $client = $this->configureBoth();
        $this->bindRest([new Response(200, [], '[]')]);
        $this->fakeRelay(signIn: fn () => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215 synthetic'], 400));

        $result = $this->listUsers($client);

        $this->assertSame([], $result);
        $this->assertStringNotContainsString('AADSTS', json_encode($result));

        // ...and it is in the log: the client's sign-in failure and the Assistant's
        // fail-over line both carry it in their context, never in the answer.
        $logged = array_values(array_filter(
            $this->logEntries,
            fn (array $entry): bool => str_contains((string) json_encode($entry['context']), 'AADSTS7000215 synthetic'),
        ));
        $this->assertNotSame([], $logged, 'the sign-in error detail reached the log');
        $this->assertContains('[CippMcpClient] Token request failed', array_column($logged, 'message'));
    }

    // --- r1 survivors (Jeeves's probes P7 to P9) --------------------------------

    public function test_a_relay_timeout_with_budget_left_sends_no_rest_request(): void
    {
        $client = $this->configureBoth();
        // A REST answer is queued, so a fallback would be sent and recorded.
        $this->bindRest([new Response(200, [], '[]')]);
        $this->fakeRelay(exec: function ($request) {
            // 12 s of the 20 still left when ExecMCP times out below its cap.
            $this->now = 108.0;

            return $this->curlFailure($request, 28, 'Operation timed out after 8000 milliseconds with 0 bytes received');
        });

        $this->assertSame(['error' => 'CIPP query failed: '.self::SENTENCE], $this->listUsers($client));
        $this->assertSame([], $this->restHistory, 'no REST read after a relay timeout, even with budget left');
    }

    public function test_a_delegated_refresh_with_half_a_millisecond_left_sends_nothing(): void
    {
        $this->connectDelegated();
        $this->fakeRelay();

        try {
            $this->mcpClient(withConnector: true)->callTool('ListUsers', ['tenantFilter' => 'example.test'], 100.0005);
            $this->fail('Expected the deadline failure');
        } catch (CippClientException $e) {
            $this->assertNotInstanceOf(CippMcpAuthException::class, $e);
            $this->assertTrue(CippClient::isDeadlineFailure($e));
        }

        $this->assertSame([], $this->sent);
    }

    public function test_the_relay_called_directly_carries_its_deadline_into_its_own_tenant_check(): void
    {
        $client = $this->configureBoth();
        Client::factory()->create(['cipp_tenant_domain' => 'other.example.test']);
        $this->bindRest([new Response(200, [], json_encode($this->tenantRows()))]);
        $this->fakeRelay();

        $relay = app(CippMcpToolRelay::class);
        $this->assertSame([], $relay->execute('cipp_list_users', [], $client, $client->id, 107.0));

        $this->assertCount(1, $this->restHistory, 'the tenant list went over REST');
        $this->assertSame('/api/ListTenants', $this->restHistory[0]['request']->getUri()->getPath());
        $this->assertEqualsWithDelta(7.0, $this->restHistory[0]['options']['timeout'], 1e-9);
    }

    // --- ruling A: the tenant list's MCP failover (CippTenantScope) ------------

    /** Invoke-ListTenants rows (customerId, defaultDomainName), synthetic identities. */
    private function tenantRows(): array
    {
        return [
            ['customerId' => '22222222-2222-4222-8222-222222222222', 'defaultDomainName' => 'example.test'],
            ['customerId' => '33333333-3333-4333-8333-333333333333', 'defaultDomainName' => 'other.example.test'],
        ];
    }

    private function execTenantList()
    {
        $text = json_encode(json_encode($this->tenantRows()));

        return Http::response(
            'data: {"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":'.$text.'}]}}'."\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        );
    }

    /**
     * Cold tenant list, a REST secret that no longer decrypts and a second mapped
     * client: the tenant check can only ask ListTenants over MCP.
     */
    private function tenantListOverMcpOnly(): Client
    {
        $client = $this->configureBoth(restSecretReadable: false);
        Client::factory()->create(['cipp_tenant_domain' => 'other.example.test']);

        return $client;
    }

    private function unreadableWithTimeout(Client $client): string
    {
        return 'PSA could not read a usable CIPP tenant list, so it cannot check PSA client '.$client->id.'\'s CIPP tenant\'s other domains against other PSA clients\' mappings, and this read was not run. '.self::SENTENCE.'.';
    }

    private function appSignInFailureCached(): bool
    {
        return Cache::store()->has('cipp_mcp_sign_in_failed:'.sha1('tenant-1|mcp-client|app'));
    }

    public function test_the_tenant_list_mcp_failover_is_sent_with_what_is_left(): void
    {
        $client = $this->tenantListOverMcpOnly();
        $this->fakeRelay(
            signIn: function () {
                $this->now += 2;

                return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
            },
            exec: fn () => $this->execTenantList(),
        );

        $this->assertSame('example.test', CippTenantScope::resolve($client, $client->id, 107.0));

        [$signIn] = $this->sentTo('login.microsoftonline.com');
        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertStringContainsString('tools=ListTenants', $exec['url']);
        $this->assertEqualsWithDelta(7.0, $signIn['timeout'], 1e-9);
        $this->assertEqualsWithDelta(7.0, $signIn['connect_timeout'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $exec['timeout'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $exec['connect_timeout'], 1e-9);
    }

    public function test_an_exec_mcp_timeout_on_the_tenant_list_failover_is_the_deadline_sentence(): void
    {
        $client = $this->tenantListOverMcpOnly();
        $this->fakeRelay(exec: function ($request) {
            // 3 s of the 7 still left when ExecMCP times out below its cap.
            $this->now = 104.0;

            return $this->curlFailure($request, 28, 'Operation timed out after 4000 milliseconds with 0 bytes received');
        });

        $result = CippTenantScope::resolve($client, $client->id, 107.0);

        $this->assertSame(['error' => $this->unreadableWithTimeout($client)], $result);
        $this->assertStringNotContainsString('sign-in failed', $result['error']);
        $this->assertFalse($this->appSignInFailureCached());
    }

    public function test_a_sign_in_timeout_on_the_tenant_list_failover_is_the_deadline_sentence(): void
    {
        $client = $this->tenantListOverMcpOnly();
        $this->fakeRelay(signIn: function ($request) {
            // 4 s of the 7 still left when the sign-in times out below its cap.
            $this->now = 103.0;

            return $this->curlFailure($request, 28, 'Operation timed out after 3000 milliseconds with 0 bytes received');
        });

        $result = CippTenantScope::resolve($client, $client->id, 107.0);

        $this->assertSame(['error' => $this->unreadableWithTimeout($client)], $result);
        $this->assertStringNotContainsString('sign-in failed', $result['error']);
        $this->assertFalse($this->appSignInFailureCached(), 'a deadline-cut sign-in is not remembered');
        $this->assertSame([], $this->sentTo('/api/ExecMCP'));
    }

    public function test_a_spent_deadline_sends_no_tenant_list_failover(): void
    {
        $client = $this->tenantListOverMcpOnly();
        $this->fakeRelay(exec: fn () => $this->execTenantList());

        $result = CippTenantScope::resolve($client, $client->id, 100.0);

        $this->assertSame(['error' => $this->unreadableWithTimeout($client)], $result);
        $this->assertSame([], $this->sent);
    }

    public function test_a_refused_connection_on_the_tenant_list_failover_keeps_todays_cause(): void
    {
        $client = $this->tenantListOverMcpOnly();
        $this->fakeRelay(exec: fn ($request) => $this->curlFailure($request, 7, 'Failed to connect to cipp.example.test port 443: Connection refused'));

        $result = CippTenantScope::resolve($client, $client->id, 107.0);

        $error = (string) ($result['error'] ?? '');
        $this->assertStringContainsString('(PSA could not read its CIPP REST API settings; the CIPP MCP request failed)', $error);
        $this->assertStringNotContainsString(self::SENTENCE, $error);
        $this->assertStringNotContainsString('sign-in failed', $error);
    }

    public function test_the_tenant_list_failover_without_a_deadline_keeps_the_fixed_caps(): void
    {
        $client = $this->tenantListOverMcpOnly();
        $this->fakeRelay(exec: fn () => $this->execTenantList());

        // CippMcpDynamicToolExecutor resolves with no deadline.
        $this->assertSame('example.test', CippTenantScope::resolve($client, $client->id));

        [$signIn] = $this->sentTo('login.microsoftonline.com');
        [$exec] = $this->sentTo('/api/ExecMCP');
        $this->assertSame(15, $signIn['timeout']);
        $this->assertSame(10, $signIn['connect_timeout']);
        $this->assertSame(60, $exec['timeout']);
        $this->assertSame(10, $exec['connect_timeout']);
    }
}
