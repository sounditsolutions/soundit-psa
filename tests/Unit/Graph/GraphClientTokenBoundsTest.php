<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * Bounds on what GraphClient takes from a vendor response (card e0keTpVk, burner b4j2):
 * - #5823: expires_in is clamped to one day before the TTL is computed, and a failing cache
 *   write is recorded by its class only and the token returned uncached;
 * - #5826 / #5827: the expires_in boundary cases 1, 60 and 61;
 * - #5825: the 429 back-off wait is clamped, and a zero, negative or non-numeric Retry-After
 *   takes the default back-off. Sleep::fake() stands in for the wait, so nothing really sleeps.
 *
 * G-5: every request goes to a scripted MockHandler, and the GraphClient is built with that
 * handler on both of its clients (asserted in graph()). Synthetic values only (G-13).
 */
class GraphClientTokenBoundsTest extends TestCase
{
    use FlattensLogContext;

    private const MAILBOX = 'b4j2.mailbox@example.test';

    /** A synthetic bearer the scripted token endpoint issues; the needle for the leak scans. */
    private const BEARER_FIXTURE = 'b4j2-synthetic-issued-bearer-NEEDLE';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logged[] = $m;
        });
    }

    private function graph(Repository $cache, Response ...$responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $graph = new GraphClient([
            'tenant_id' => 'tenant-b4j2-synthetic',
            'client_id' => 'b4j2-client',
            'client_secret' => 'b4j2-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache);
        foreach (['http', 'authHttp'] as $property) {
            $this->assertSame($stack, (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph)->getConfig('handler'), $property);
        }

        return $graph;
    }

    private static function token(mixed $expiresIn): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => self::BEARER_FIXTURE, 'expires_in' => $expiresIn]));
    }

    private static function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => 'MSG-1']));
    }

    /** A cache whose put() records the TTL it was given, then stores as usual. */
    private static function recordingCache(): Repository
    {
        return new class(new ArrayStore) extends Repository
        {
            /** @var list<mixed> */
            public array $ttls = [];

            public function put($key, $value, $ttl = null)
            {
                $this->ttls[] = $ttl;

                return parent::put($key, $value, $ttl);
            }
        };
    }

    /**
     * #5823 / #5826 / #5827: [expires_in, the TTL the cache is given]. An expires_in over one day
     * is clamped to 86400 before the TTL is computed (86400 less the 60-second margin). At
     * 1 and 60 the TTL is expires_in (no margin, #5827); at 61 it is the 60-second floor.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function boundedExpiries(): array
    {
        return [
            '400000000, clamped' => [400000000, 86340],
            'numeric string 1e20, saturates under a bare cast' => ['1e20', 86340],
            'float 1e20, wraps under a bare cast' => [1e20, 86340],
            '86401, one over the ceiling' => [86401, 86340],
            '86400, at the ceiling' => [86400, 86340],
            '1, the smallest accepted' => [1, 1],
            '1 as a string' => ['1', 1],
            '60, no margin' => [60, 60],
            '61, the floor' => [61, 60],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundedExpiries')]
    public function test_expires_in_is_clamped_and_the_ttl_the_cache_receives(mixed $expiresIn, int $ttl): void
    {
        $cache = self::recordingCache();
        $graph = $this->graph($cache, self::token($expiresIn), self::ok());

        $this->assertSame(['id' => 'MSG-1'], $graph->get('users/'.self::MAILBOX.'/messages/MSG-1'));
        $this->assertSame([$ttl], $cache->ttls);
        $this->assertSame(self::BEARER_FIXTURE, $cache->get('graph_api_token'));
        $this->assertSame([], $this->logged, 'accepted, so no malformed record');
    }

    /** #5826: just under one second is still refused; the boundary is 1. */
    public function test_an_expires_in_under_one_second_is_still_refused(): void
    {
        foreach ([0.99, '0.5', 0, -1, -1e20] as $expiresIn) {
            $cache = self::recordingCache();
            $graph = $this->graph($cache, self::token($expiresIn));
            try {
                $graph->get('users/'.self::MAILBOX.'/messages/MSG-1');
                $this->fail('expected a refusal for '.var_export($expiresIn, true));
            } catch (GraphClientException $e) {
                $this->assertSame('Graph API token response carried a malformed expires_in', $e->getMessage());
            }
            $this->assertSame([], $cache->ttls, 'nothing cached');
        }
    }

    /**
     * #5823: the cache write throws an exception whose message holds the token, as a database
     * store's QueryException does (its message interpolates the bindings). getToken() still
     * returns the token, uncached, and the one record carries the exception class only.
     */
    public function test_a_failing_cache_write_returns_the_token_uncached_and_records_the_class_only(): void
    {
        $thrown = [];
        $cache = new class(new ArrayStore, $thrown) extends Repository
        {
            public function __construct(ArrayStore $store, private array &$thrown)
            {
                parent::__construct($store);
            }

            public function put($key, $value, $ttl = null)
            {
                $e = new QueryException('mariadb', 'insert into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)', [$key, serialize($value), 2199999999], new \RuntimeException('SQLSTATE[22003]: Numeric value out of range'));
                $this->thrown[] = $e;

                throw $e;
            }
        };
        $graph = $this->graph($cache, self::token(3600), self::ok(), self::token(3600), self::ok());

        $this->assertSame(['id' => 'MSG-1'], $graph->get('users/'.self::MAILBOX.'/messages/MSG-1'));
        $this->assertSame('Bearer '.self::BEARER_FIXTURE, $this->history[1]['request']->getHeaderLine('Authorization'));

        // Positive control: the thrown exception really carries the needle.
        $this->assertCount(1, $thrown);
        $this->assertStringContainsString(self::BEARER_FIXTURE, $thrown[0]->getMessage());
        $this->assertStringContainsString(self::BEARER_FIXTURE, self::flattenForScan(['e' => $thrown[0]]), 'the scan can see the needle');

        $this->assertSame(
            [['error', 'Graph API token cache write failed', ['exception' => QueryException::class]]],
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
        );
        $this->assertStringNotContainsString(self::BEARER_FIXTURE, $this->allRecords());

        // Uncached: the next call requests a token again.
        $graph->get('users/'.self::MAILBOX.'/messages/MSG-1');
        $this->assertSame(['login.microsoftonline.com', 'graph.microsoft.com', 'login.microsoftonline.com', 'graph.microsoft.com'], array_map(fn (array $h) => $h['request']->getUri()->getHost(), $this->history));
    }

    /**
     * #5825: [Retry-After on each of three 429s, the three waits]. A positive numeric value is
     * used, clamped to 60 seconds; zero, negative, non-numeric or absent takes the default
     * 10, 20 and 30 seconds. Before #5825, 86400 waited a day per retry and -1 reached sleep(),
     * which throws ValueError.
     *
     * @return array<string, array{0: ?string, 1: list<int>}>
     */
    public static function retryAfters(): array
    {
        return [
            '86400, clamped' => ['86400', [60, 60, 60]],
            '1e9, clamped' => ['1e9', [60, 60, 60]],
            '61, clamped' => ['61', [60, 60, 60]],
            '60, at the ceiling' => ['60', [60, 60, 60]],
            '5, used' => ['5', [5, 5, 5]],
            '0.5, rounded up' => ['0.5', [1, 1, 1]],
            '-1, default' => ['-1', [10, 20, 30]],
            '-86400, default' => ['-86400', [10, 20, 30]],
            '0, default' => ['0', [10, 20, 30]],
            '0.0, default' => ['0.0', [10, 20, 30]],
            'HTTP-date, default' => ['Wed, 21 Oct 2026 07:28:00 GMT', [10, 20, 30]],
            'absent, default' => [null, [10, 20, 30]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryAfters')]
    public function test_the_429_wait_is_clamped_and_never_negative(?string $retryAfter, array $waits): void
    {
        $tooMany = fn () => new Response(429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter], '{}');
        $cache = self::recordingCache();
        $cache->put('graph_api_token', 'b4j2-synthetic-cached-bearer', 3600);
        $graph = $this->graph($cache, $tooMany(), $tooMany(), $tooMany(), self::ok());

        $this->assertSame(['id' => 'MSG-1'], $graph->get('users/'.self::MAILBOX.'/messages/MSG-1'));
        $this->assertCount(4, $this->history);
        Sleep::assertSequence(array_map(fn (int $s) => Sleep::for($s)->seconds(), $waits));
        $this->assertSame($waits, array_map(fn (MessageLogged $m) => $m->context['wait_seconds'], $this->logged), 'the back-off record names the wait it took');
    }

    /** Every record, message and context, flattened the way the leak scans read it. */
    private function allRecords(): string
    {
        return implode("\n", array_map(fn (MessageLogged $m) => $m->level.' '.$m->message.' '.self::flattenForScan($m->context), $this->logged));
    }
}
