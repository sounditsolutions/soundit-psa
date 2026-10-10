<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * #6120 / #6130: above the transport handler, the Graph bearer is never a string or array frame
 * argument on any send path, on any arm, with exception argument capture on
 * (zend.exception_ignore_args=Off).
 *
 * The scripted handler records a witness exception where the transport handler would be called
 * (the closure the whole Guzzle stack calls last), so its trace holds every frame above it:
 * GraphClient's, Guzzle's Client::send/sendAsync/transfer, and each middleware's. #6188: the
 * production transport handlers (CurlHandler/CurlFactory, StreamHandler) never run here, so
 * nothing is claimed about their frames. #6258: test_the_curl_handler_frames_are_outside_the_claim
 * measures that CurlHandler's can hold it, through a bare Guzzle client; StreamHandler is not
 * measured (source-read only). Every
 * rejection the stack produced and the exception GraphClient finally threw are scanned too.
 * frameArgumentsForScan() writes string and array arguments as they are and names an object
 * argument (the PSR-7 Request that now carries the header) by its class only. So the claim is
 * scoped: no string or array frame argument holds the bearer. The Request object still does,
 * as every sent request must; a reporter that walks object arguments' properties would find it
 * there (and in a RequestException's getRequest(), which throwFromGuzzle() never chains).
 *
 * Arms: 200; 3xx (refused); 4xx; 401 then a refreshed 200; 401 then a refreshed 500; 401 then a
 * failed refresh; 429 then 200; 429 four times (retries exhausted); no response
 * (ConnectException); a non-Guzzle throwable from inside the handler stack (#6120's scenario);
 * and the two absolute-URL senders, requestAbsolute() (getAllPages nextLink) and
 * requestJsonAbsolute() (calendarView nextLink). On each arm the request must still
 * authenticate as before: every Graph request carries 'Bearer <seeded>' until a 401 refresh,
 * and 'Bearer <fresh>' on the retry after it (and on 429 retries after it).
 *
 * G-5: every request goes to a scripted MockHandler set on both of GraphClient's clients (and
 * so on the per-call clients, which take the same config handler), Http::preventStrayRequests()
 * is on, and Sleep::fake() stands in for the 429 back-off. Synthetic values only (G-13).
 */
class GraphClientBearerFrameTest extends TestCase
{
    use FlattensLogContext;

    /** The access token seeded in the cache; synthetic, sent as the first bearer. */
    private const SEEDED_ACCESS_FIXTURE = 'b4n-synthetic-seeded-access';

    /** The access token the scripted refresh issues; synthetic, sent after a 401. */
    private const FRESH_ACCESS_FIXTURE = 'b4n-synthetic-fresh-access';

    private const MAILBOX = 'b4n.mailbox@example.test';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @var list<\Throwable> one per request, created at the innermost handler */
    private array $witnesses = [];

    /** @var list<string> the Authorization header of each Graph request, as the handler got it */
    private array $sentBearers = [];

    /** @var list<\Throwable> every rejection reason the stack produced */
    private array $rejections = [];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
        parent::tearDown();
    }

    /**
     * A GraphClient whose handler answers $queue in order. Each item is a Response, or a
     * closure called with the request (it may return a Response or a Throwable, or throw).
     *
     * @param  list<Response|\Closure>  $queue
     */
    private function graph(array $queue): GraphClient
    {
        $this->history = [];
        $this->witnesses = [];
        $this->sentBearers = [];
        $this->rejections = [];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create(function (RequestInterface $request, array $options) use ($mock) {
            $this->witnesses[] = new \RuntimeException('b4n witness');
            if ($request->getUri()->getHost() === 'graph.microsoft.com') {
                $this->sentBearers[] = $request->getHeaderLine('Authorization');
            }

            return $mock($request, $options);
        });
        $stack->push(Middleware::history($this->history));
        $stack->unshift(fn (callable $next) => fn (RequestInterface $request, array $options) => $next($request, $options)->then(
            null,
            function ($reason) {
                $this->rejections[] = $reason;

                return Create::rejectionFor($reason);
            },
        ), 'b4n_rejections');

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', self::SEEDED_ACCESS_FIXTURE, 3600);

        $graph = new GraphClient([
            'tenant_id' => 'tenant-b4n-synthetic',
            'client_id' => 'b4n-client',
            'client_secret' => 'b4n-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache);
        foreach (['http', 'authHttp'] as $property) {
            $this->assertSame($stack, (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph)->getConfig('handler'), $property);
        }

        return $graph;
    }

    private static function reply(int $status, array $body = ['id' => 'MSG-1']): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function freshToken(): Response
    {
        return self::reply(200, ['access_token' => self::FRESH_ACCESS_FIXTURE, 'expires_in' => 3600]);
    }

    private static function connectFailure(): \Closure
    {
        return fn (RequestInterface $request) => new ConnectException('b4n synthetic connect failure', $request);
    }

    /** @return list<string> */
    private static function bearerNeedles(): array
    {
        return [self::SEEDED_ACCESS_FIXTURE, self::FRESH_ACCESS_FIXTURE, 'Bearer ', 'Authorization'];
    }

    /** Runs $call and returns what it threw (null when it returned). */
    private static function attempt(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * Scans every witness, every rejection and $thrown. Positive controls: there is one witness
     * per request, and the scan reads Guzzle's send frame and the request's endpoint argument.
     */
    private function assertNoBearerInAnyFrame(?\Throwable $thrown, string $arm, string $endpointNeedle): void
    {
        $this->assertNotSame([], $this->witnesses, "{$arm}: positive control: the handler was reached");
        $scans = [];
        foreach ([...$this->witnesses, ...$this->rejections, ...($thrown ? [$thrown] : [])] as $i => $e) {
            $scans[] = self::frameArgumentsForScan($e);
        }
        $all = implode("\n", $scans);
        $this->assertMatchesRegularExpression('/ transfer arg 1: \[/', $all, "{$arm}: positive control: Guzzle's Client::transfer frame and its options argument are scanned");
        $this->assertStringContainsString($endpointNeedle, $all, "{$arm}: positive control: the endpoint argument is scanned");
        foreach (self::bearerNeedles() as $needle) {
            $this->assertFalse(str_contains($all, $needle), "{$arm}: a frame argument carries '{$needle}': ".implode(' || ', array_filter(explode("\n", $all), fn ($l) => str_contains($l, $needle))));
        }
    }

    private static function nextLink(string $resource): string
    {
        return sprintf('https://graph.microsoft.com/v1.0/users/%s/%s?$skip=%d', rawurlencode(self::MAILBOX), $resource, 1);
    }

    /**
     * [the handler's answers, the call, the bearers the Graph requests must carry in order, the
     * class the call must throw or null, a string the endpoint frame argument holds].
     *
     * @return array<string, array{0: \Closure(): list<Response|\Closure>, 1: \Closure(GraphClient): mixed, 2: list<string>, 3: class-string|null, 4: string}>
     */
    public static function arms(): array
    {
        // Symbolic, so the provider's own data (a frame argument of the test method) holds no bearer.
        $seeded = 'seeded';
        $fresh = 'fresh';
        $get = fn (GraphClient $g) => $g->get('users/'.self::MAILBOX.'/messages');
        $post = fn (GraphClient $g) => $g->post('users/'.self::MAILBOX.'/sendMail', ['message' => ['subject' => 'b4n']]);
        $path = 'users/'.self::MAILBOX;

        return [
            '200' => [fn () => [self::reply(200)], $get, [$seeded], null, $path],
            '3xx refused' => [fn () => [new Response(307, ['Location' => 'https://b4n-elsewhere.example.test/x'])], $post, [$seeded], GraphClientException::class, $path],
            '4xx' => [fn () => [self::reply(404, ['error' => ['code' => 'NotFound']])], $get, [$seeded], GraphClientException::class, $path],
            '401, refresh succeeds, 200' => [fn () => [self::reply(401), self::freshToken(), self::reply(200)], $post, [$seeded, $fresh], null, $path],
            '401, refresh succeeds, 500' => [fn () => [self::reply(401), self::freshToken(), self::reply(500)], $get, [$seeded, $fresh], GraphClientException::class, $path],
            '401, refresh succeeds, 429, 200' => [fn () => [self::reply(401), self::freshToken(), self::reply(429), self::reply(200)], $get, [$seeded, $fresh, $fresh], null, $path],
            '401, refresh fails' => [fn () => [self::reply(401), self::reply(400, ['error' => 'invalid_client'])], $get, [$seeded], GraphTokenRefreshFailedException::class, $path],
            '429, then 200' => [fn () => [self::reply(429), self::reply(200)], $get, [$seeded, $seeded], null, $path],
            '429 until the retries run out' => [fn () => [self::reply(429), self::reply(429), self::reply(429), self::reply(429)], $get, [$seeded, $seeded, $seeded, $seeded], GraphClientException::class, $path],
            'no response (ConnectException)' => [fn () => [self::connectFailure()], $get, [$seeded], GraphClientException::class, $path],
            'non-Guzzle throwable inside the handler stack' => [fn () => [function () {
                throw new \RuntimeException('b4n synthetic handler fault');
            }], $get, [$seeded], \RuntimeException::class, $path],
            'requestAbsolute(), nextLink 200' => [fn () => [self::reply(200, ['value' => [], '@odata.nextLink' => self::nextLink('messages')]), self::reply(200, ['value' => []])],
                fn (GraphClient $g) => $g->getAllPages('users/'.self::MAILBOX.'/messages'), [$seeded, $seeded], null, rawurlencode(self::MAILBOX)],
            'requestAbsolute(), nextLink 500' => [fn () => [self::reply(200, ['value' => [], '@odata.nextLink' => self::nextLink('messages')]), self::reply(500)],
                fn (GraphClient $g) => $g->getAllPages('users/'.self::MAILBOX.'/messages'), [$seeded, $seeded], GraphClientException::class, rawurlencode(self::MAILBOX)],
            'requestAbsolute(), nextLink non-Guzzle throwable' => [fn () => [self::reply(200, ['value' => [], '@odata.nextLink' => self::nextLink('messages')]), function () {
                throw new \RuntimeException('b4n synthetic handler fault');
            }], fn (GraphClient $g) => $g->getAllPages('users/'.self::MAILBOX.'/messages'), [$seeded, $seeded], \RuntimeException::class, rawurlencode(self::MAILBOX)],
            'requestJsonAbsolute(), nextLink no response' => [fn () => [self::reply(200, ['value' => [], '@odata.nextLink' => self::nextLink('calendarView')]), self::connectFailure()],
                fn (GraphClient $g) => $g->calendarView(self::MAILBOX, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z'), [$seeded, $seeded], GraphClientException::class, rawurlencode(self::MAILBOX)],
            'requestJsonAbsolute(), nextLink non-Guzzle throwable' => [fn () => [self::reply(200, ['value' => [], '@odata.nextLink' => self::nextLink('calendarView')]), function () {
                throw new \RuntimeException('b4n synthetic handler fault');
            }], fn (GraphClient $g) => $g->calendarView(self::MAILBOX, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z'), [$seeded, $seeded], \RuntimeException::class, rawurlencode(self::MAILBOX)],
        ];
    }

    /**
     * @param  \Closure(): list<Response|\Closure>  $queue
     * @param  \Closure(GraphClient): mixed  $call
     * @param  list<'seeded'|'fresh'>  $bearers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('arms')]
    public function test_no_frame_argument_above_the_transport_handler_holds_the_bearer_and_each_request_still_authenticates(\Closure $queue, \Closure $call, array $bearers, ?string $throws, string $endpointNeedle): void
    {
        $graph = $this->graph($queue());
        $thrown = self::attempt(fn () => $call($graph));

        if ($throws === null) {
            $this->assertNull($thrown, 'the call succeeds: '.($thrown ? $thrown::class.': '.$thrown->getMessage() : ''));
        } else {
            $this->assertInstanceOf($throws, $thrown);
        }
        $expected = array_map(fn (string $b) => 'Bearer '.($b === 'fresh' ? self::FRESH_ACCESS_FIXTURE : self::SEEDED_ACCESS_FIXTURE), $bearers);
        $this->assertSame($expected, $this->sentBearers, 'each Graph request authenticates as before');
        unset($expected);
        $this->assertNoBearerInAnyFrame($thrown, $this->name(), $endpointNeedle);
    }

    /**
     * Positive control for the scan: a frame argument that does hold the bearer (an options
     * array carrying the header, the pre-#6120 shape) is found.
     */
    public function test_the_scan_finds_a_bearer_in_an_options_frame_argument(): void
    {
        $probe = function (array $options): \Throwable {
            return new \RuntimeException('b4n probe');
        };
        $e = $probe(['headers' => ['Authorization' => 'Bearer '.self::FRESH_ACCESS_FIXTURE]]);
        $this->assertStringContainsString(self::FRESH_ACCESS_FIXTURE, self::frameArgumentsForScan($e));
    }

    /**
     * #6259: a bare Guzzle client on the real CurlHandler, whose handle factory is the real
     * CurlFactory behind a guard that never returns a handle, so curl_exec() is never reached.
     *
     * @return array{0: \GuzzleHttp\Client, 1: object{created: int}}
     */
    private static function guardedCurlClient(): array
    {
        $factory = new class(new \GuzzleHttp\Handler\CurlFactory(3)) implements \GuzzleHttp\Handler\CurlFactoryInterface
        {
            public int $created = 0;

            public function __construct(private readonly \GuzzleHttp\Handler\CurlFactory $inner) {}

            public function create(RequestInterface $request, array $options): \GuzzleHttp\Handler\EasyHandle
            {
                $easy = $this->inner->create($request, $options);
                $this->created++;
                $this->inner->release($easy);

                throw new \LogicException('b4n guard: CurlFactory built a handle; refusing to let CurlHandler send it');
            }

            public function release(\GuzzleHttp\Handler\EasyHandle $easy): void
            {
                $this->inner->release($easy);
            }
        };
        $client = new \GuzzleHttp\Client(['handler' => HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler(['handle_factory' => $factory]))]);

        return [$client, $factory];
    }

    /**
     * #6188: the boundary of the claim above, measured. The real CurlHandler, given a CA bundle
     * path that does not exist, throws a plain InvalidArgumentException from
     * CurlFactory::applyHandlerOptions() before any connection is made, and that frame's $conf
     * array argument holds the request headers, the bearer among them. This is why GraphClient's
     * docblock claims nothing below the middleware frames.
     *
     * #6259: no request can leave the process, whatever Guzzle's version does. The CurlHandler's
     * handle factory is the real CurlFactory wrapped in a guard: CurlHandler calls curl_exec()
     * only on a handle the factory returned, and the guard never returns one. If a Guzzle change
     * stops the CA-bundle check from throwing, the guard releases the unused handle and throws a
     * LogicException, so this test fails on the guard's message instead of opening a socket.
     */
    public function test_the_curl_handler_frames_are_outside_the_claim(): void
    {
        [$client, $factory] = self::guardedCurlClient();
        $request = (new \GuzzleHttp\Psr7\Request('GET', 'https://graph.invalid/v1.0/me'))->withHeader('Authorization', 'Bearer '.self::SEEDED_ACCESS_FIXTURE);

        $thrown = self::attempt(fn () => $client->send($request, ['verify' => '/nonexistent/synthetic-ca-bundle.pem']));

        $this->assertSame(0, $factory->created, 'the real CurlFactory threw before building a handle');
        $this->assertInstanceOf(\InvalidArgumentException::class, $thrown);
        $this->assertNotInstanceOf(\GuzzleHttp\Exception\GuzzleException::class, $thrown);
        $this->assertMatchesRegularExpression('/#\d+ applyHandlerOptions arg \d+: \[.*'.preg_quote(self::SEEDED_ACCESS_FIXTURE, '/').'/', self::frameArgumentsForScan($thrown));
    }

    /**
     * #6259 positive control for the guard above: with no CA-bundle path to refuse (verify off),
     * the real CurlFactory builds a handle, and the guard is what stops the send. The handler
     * never reaches curl_exec(), so this request does not leave the process either.
     */
    public function test_the_curl_handler_guard_stops_a_handle_the_factory_built(): void
    {
        $sent = false;
        [$client, $factory] = self::guardedCurlClient();

        $thrown = self::attempt(fn () => $client->send(new \GuzzleHttp\Psr7\Request('GET', 'https://graph.invalid/v1.0/me'), [
            'verify' => false,
            'on_headers' => function () use (&$sent): void {
                $sent = true;
            },
        ]));

        $this->assertSame(1, $factory->created, 'the factory built a handle');
        $this->assertInstanceOf(\LogicException::class, $thrown);
        $this->assertSame('b4n guard: CurlFactory built a handle; refusing to let CurlHandler send it', $thrown->getMessage());
        $this->assertFalse($sent, 'no response headers: nothing was sent');
    }
}
