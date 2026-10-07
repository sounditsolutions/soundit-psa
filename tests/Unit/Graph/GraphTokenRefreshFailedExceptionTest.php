<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GraphTokenRefreshFailedException carries getToken()'s failure twice: as the public
 * $tokenFailure and, since #5461, as getPrevious(). Both reach whatever reads the exception
 * (a reporter walks getPrevious()), so on every getToken() failure branch neither message may
 * carry the token URL, the tenant, the secret, Graph's 401 text or the identity provider's
 * text (#5450, C-56). Every value here is synthetic; the handler is a scripted MockHandler.
 */
class GraphTokenRefreshFailedExceptionTest extends TestCase
{
    private const TENANT = 'tenant-b4c-synthetic';

    private const SECRET_FIXTURE = 'b4c-synthetic-secret-not-real';

    private const MAILBOX = 'support@example.test';

    private const GRAPH_401_MARKER = 'B4C-SYNTHETIC-GRAPH-401-MARKER';

    private const IDP_MARKER = 'AADSTS7000215-B4C-SYNTHETIC-IDP-MARKER invalid client secret provided';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: \Closure(): (Response|\Throwable), 1: string}> */
    public static function tokenFailures(): array
    {
        return [
            'token endpoint refuses (Guzzle ClientException, 400)' => [
                fn () => new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
                    'error' => 'invalid_client', 'error_description' => self::IDP_MARKER,
                ])),
                'Failed to obtain Graph API token (HTTP 400)',
            ],
            'token endpoint unreachable (Guzzle ConnectException)' => [
                fn () => new ConnectException(
                    'cURL error 6: '.self::IDP_MARKER.' for https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token',
                    new Request('POST', 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token'),
                ),
                'Failed to obtain Graph API token ('.ConnectException::class.')',
            ],
            'token response without access_token' => [
                fn () => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'note' => self::IDP_MARKER, 'tenant' => self::TENANT,
                ])),
                'Graph API token response did not contain access_token',
            ],
            'non-2xx token response (503)' => [
                fn () => new Response(503, ['Content-Type' => 'application/json'], (string) json_encode([
                    'error' => 'temporarily_unavailable', 'error_description' => self::IDP_MARKER,
                ])),
                'Failed to obtain Graph API token (HTTP 503)',
            ],
        ];
    }

    /** A GraphClient with a seeded token, scripted to answer Graph 401 and then $tokenFailure. */
    private function client(Response|\Throwable $tokenFailure): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => ['code' => 'InvalidAuthenticationToken', 'message' => self::GRAPH_401_MARKER],
            ])),
            $tokenFailure,
        ]));
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'seeded-token', 3600);

        return new GraphClient([
            'tenant_id' => self::TENANT,
            'client_id' => 'b4c-client',
            'client_secret' => self::SECRET_FIXTURE,
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache);
    }

    private function refreshFailure(Response|\Throwable $tokenFailure): GraphTokenRefreshFailedException
    {
        $graph = $this->client($tokenFailure);
        try {
            $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');
        } catch (GraphTokenRefreshFailedException $e) {
            $tokenRequests = array_filter($this->history, fn (array $h) => str_ends_with($h['request']->getUri()->getPath(), '/oauth2/v2.0/token'));
            $this->assertCount(1, $tokenRequests, 'positive control: the refresh ran');

            return $e;
        }
        $this->fail('expected GraphTokenRefreshFailedException');
    }

    /** @return list<string> */
    private function needles(): array
    {
        return [self::IDP_MARKER, 'AADSTS', 'invalid_client', 'temporarily_unavailable', self::TENANT, self::SECRET_FIXTURE,
            'login.microsoftonline.com', 'oauth2', self::GRAPH_401_MARKER, 'InvalidAuthenticationToken',
            self::MAILBOX, rawurlencode(self::MAILBOX), 'graph.microsoft.com', 'cURL'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_the_token_failure_message_is_status_only_on_every_get_token_branch(\Closure $tokenFailure, string $expected): void
    {
        $e = $this->refreshFailure($tokenFailure());

        // #5450: the public $tokenFailure is getToken()'s own exception; pin its exact message.
        $this->assertSame(GraphClientException::class, $e->tokenFailure::class);
        $this->assertSame($expected, $e->tokenFailure->getMessage());
        $this->assertSame(0, $e->tokenFailure->getHttpStatus(), 'the token failure is not a Graph status');
        $this->assertNull($e->tokenFailure->getResponseBody(), 'no identity-provider body rides on it');

        foreach ($this->needles() as $needle) {
            $this->assertStringNotContainsString($needle, $e->getMessage());
            $this->assertStringNotContainsString($needle, $e->tokenFailure->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_the_token_failure_is_chained_as_previous_with_a_status_only_message(\Closure $tokenFailure, string $expected): void
    {
        $e = $this->refreshFailure($tokenFailure());

        // #5461: a reporter that walks getPrevious() keeps the cause.
        $this->assertSame(401, $e->getHttpStatus());
        $this->assertSame('Graph API error: GET returned 401 and the token refresh failed', $e->getMessage());
        $this->assertSame($e->tokenFailure, $e->getPrevious());
        $this->assertSame($expected, $e->getPrevious()->getMessage());

        // The whole chain, as a reporter renders it, carries no vendor text.
        $chain = [];
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $chain[] = $t::class.': '.$t->getMessage();
        }
        $this->assertCount(2, $chain, 'getToken() throws a fresh exception with no previous of its own');
        foreach ($this->needles() as $needle) {
            $this->assertStringNotContainsString($needle, implode("\n", $chain));
        }
    }
}
