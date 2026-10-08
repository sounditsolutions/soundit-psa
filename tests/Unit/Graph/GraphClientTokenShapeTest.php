<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * GraphClient::getToken() beyond the refresh path GraphTokenRefreshFailedExceptionTest covers:
 * - #5720: a token read from the cache passes the same access_token check as a fresh one;
 * - #5735: a numeric-string expires_in is accepted, and the TTL the token is cached for;
 * - #5728: on a cold cache a token failure is a GraphTokenException, which a record that keeps
 *   only the class tells apart from a Graph request that received no response;
 * - #5734: a 429 on the last retry ends in throwFromGuzzle's 429, not another message.
 *
 * G-5: a scripted MockHandler with a history, Http::preventStrayRequests(). Synthetic values only.
 */
class GraphClientTokenShapeTest extends TestCase
{
    private const MAILBOX = 'b4k2.mailbox@example.test';

    /** A synthetic bearer the scripted token endpoint issues (G-13). */
    private const ISSUED_BEARER_FIXTURE = 'b4k2-synthetic-issued-bearer';

    /** Carried inside a malformed cached value, so a record that prints the value trips the scan. */
    private const CACHED_VALUE_MARKER = 'B4K2-SYNTHETIC-CACHED-VALUE';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private Repository $cache;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logged[] = $m;
        });
    }

    private function graph(Response|\Throwable ...$responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $this->cache = new Repository(new ArrayStore);

        $graph = new GraphClient([
            'tenant_id' => 'tenant-b4k2-synthetic',
            'client_id' => 'b4k2-client',
            'client_secret' => 'b4k2-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $this->cache);
        foreach (['http', 'authHttp'] as $property) {
            $this->assertSame($stack, (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph)->getConfig('handler'), $property);
        }

        return $graph;
    }

    private static function token(mixed $expiresIn = 3600): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => self::ISSUED_BEARER_FIXTURE, 'expires_in' => $expiresIn]));
    }

    private static function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => 'MSG-1']));
    }

    /** @return list<string> the request hosts, in order */
    private function hosts(): array
    {
        return array_map(fn (array $h) => $h['request']->getUri()->getHost(), $this->history);
    }

    /** @return array<string, array{0: mixed, 1: string, 2: string}> [cached value, the type and the reason the record names] */
    public static function malformedCachedTokens(): array
    {
        return [
            'array' => [[self::CACHED_VALUE_MARKER], 'array', 'not a string'],
            'int' => [735911, 'int', 'not a string'],
            'true' => [true, 'bool', 'not a string'],
            'empty string' => ['', 'string', 'empty'],
            'CR' => [self::CACHED_VALUE_MARKER."\rx", 'string', 'control character'],
            'LF' => [self::CACHED_VALUE_MARKER."\nx", 'string', 'control character'],
            'tab' => [self::CACHED_VALUE_MARKER."\tx", 'string', 'control character'],
        ];
    }

    /**
     * #5720: a malformed value already in the cache is dropped with a warning naming its type,
     * a new token is requested, and the Graph request carries the new bearer. Before #5720 an
     * array, or a string with a control character other than TAB, threw a non-GraphClientException
     * on every call until the TTL ran out, and an int was sent as the bearer. #5835: a TAB string
     * did not throw (psr7 accepts TAB); it was sent as the bearer. #5840: the record names the
     * reason as well as the type.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedCachedTokens')]
    public function test_a_malformed_cached_token_is_dropped_and_a_new_one_requested(mixed $cached, string $type, string $reason): void
    {
        $graph = $this->graph(self::token(), self::ok());
        $this->cache->put('graph_api_token', $cached, 3600);

        $this->assertSame(['id' => 'MSG-1'], $graph->get('users/'.self::MAILBOX.'/messages/MSG-1'));
        $this->assertSame(['login.microsoftonline.com', 'graph.microsoft.com'], $this->hosts(), 'a token request, then the Graph read');
        $this->assertSame('Bearer '.self::ISSUED_BEARER_FIXTURE, $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame(self::ISSUED_BEARER_FIXTURE, $this->cache->get('graph_api_token'), 'the new token replaced it');
        $this->assertSame(
            [['warning', 'Graph API cached token malformed, requesting a new one', ['type' => $type, 'reason' => $reason]]],
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
        );
    }

    /** #5720 control: a well-formed cached token is sent as it is, with no token request and no record. */
    public function test_a_well_formed_cached_token_is_used_without_a_token_request(): void
    {
        $graph = $this->graph(self::ok());
        $this->cache->put('graph_api_token', 'b4k2-synthetic-cached-bearer', 3600);

        $graph->get('users/'.self::MAILBOX.'/messages/MSG-1');
        $this->assertSame(['graph.microsoft.com'], $this->hosts());
        $this->assertSame('Bearer b4k2-synthetic-cached-bearer', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame([], $this->logged);
    }

    /**
     * #5735: expires_in as a number or a numeric string is accepted. The token is cached for
     * expires_in less the 60-second margin, never under 60 seconds and never past expires_in.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function acceptedExpiries(): array
    {
        return [
            'int 3600' => [3600, 3540],
            'numeric string "3599"' => ['3599', 3539],
            'float 3600.0' => [3600.0, 3540],
            'absent' => [null, 3540],
            'int 90 (floor)' => [90, 60],
            'int 30 (below the floor, capped at expires_in)' => [30, 30],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedExpiries')]
    public function test_an_accepted_expires_in_and_the_ttl_the_token_is_cached_for(mixed $expiresIn, int $ttl): void
    {
        $body = ['access_token' => self::ISSUED_BEARER_FIXTURE] + ($expiresIn === null ? [] : ['expires_in' => $expiresIn]);
        $graph = $this->graph(new Response(200, [], (string) json_encode($body)), self::ok());
        $this->freezeTime();

        $graph->get('users/'.self::MAILBOX.'/messages/MSG-1');
        $this->assertSame([], $this->logged, 'no malformed record');
        $this->assertSame(self::ISSUED_BEARER_FIXTURE, $this->cache->get('graph_api_token'));

        $this->travel($ttl - 1)->seconds();
        $this->assertTrue($this->cache->has('graph_api_token'), "cached one second before {$ttl}s");
        $this->travel(1)->seconds();
        $this->assertFalse($this->cache->has('graph_api_token'), "expired at {$ttl}s");
    }

    /**
     * #5728: on a cold cache the first token failure reaches the caller unwrapped. It is a
     * GraphTokenException with status 0, and a Graph request that received no response is a
     * plain GraphClientException with status 0, so a record of [status, class] still tells them
     * apart (GraphWebhookManager::failure(), GraphWebhookController).
     */
    public function test_a_cold_cache_token_failure_is_distinguishable_from_no_response(): void
    {
        $classes = [];
        foreach ([
            'malformed token' => [new Response(200, [], (string) json_encode(['access_token' => 123, 'expires_in' => 3600]))],
            'no token' => [new Response(200, [], '{}')],
            'token endpoint 400' => [new Response(400, [], '{}')],
            'token endpoint unreachable' => [new ConnectException('cURL error 7', new Request('POST', 'x'))],
            'Graph no response' => [self::token(), new ConnectException('cURL error 7', new Request('GET', 'x'))],
        ] as $name => $responses) {
            $graph = $this->graph(...$responses);
            try {
                $graph->get('users/'.self::MAILBOX.'/messages/MSG-1');
                $this->fail("{$name}: expected a GraphClientException");
            } catch (GraphClientException $e) {
                $this->assertSame(0, $e->getHttpStatus(), $name);
                $classes[$name] = $e::class;
            }
        }
        $this->assertSame([
            'malformed token' => GraphTokenException::class,
            'no token' => GraphTokenException::class,
            'token endpoint 400' => GraphTokenException::class,
            'token endpoint unreachable' => GraphTokenException::class,
            'Graph no response' => GraphClientException::class,
        ], $classes);
    }

    /**
     * #5734: authenticatedRequest has no exit after its loop. A 429 on every attempt is retried
     * three times and the fourth 429 is thrown by throwFromGuzzle with its status.
     *
     * #5836: this is a pin on the loop end, not a red-at-base test: at base the fourth 429 also
     * reached throwFromGuzzle. It does not count toward a failing-at-base figure.
     */
    public function test_a_429_on_every_attempt_ends_in_the_429(): void
    {
        // #5825: a zero Retry-After takes the default 10, 20 and 30 second back-off; Sleep::fake()
        // stands in for the waits, so the test does not really sleep.
        Sleep::fake();
        $tooMany = fn () => new Response(429, ['Retry-After' => '0'], '{}');
        $graph = $this->graph($tooMany(), $tooMany(), $tooMany(), $tooMany());
        $this->cache->put('graph_api_token', 'b4k2-synthetic-cached-bearer', 3600);

        try {
            $graph->get('users/'.self::MAILBOX.'/messages/MSG-1');
            $this->fail('expected a GraphClientException');
        } catch (GraphClientException $e) {
            $this->assertSame('Graph API error: GET returned 429', $e->getMessage());
            $this->assertSame(429, $e->getHttpStatus());
        }
        $this->assertCount(4, $this->history, 'the first request and three retries');
        Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(20)->seconds(), Sleep::for(30)->seconds()]);
    }
}
