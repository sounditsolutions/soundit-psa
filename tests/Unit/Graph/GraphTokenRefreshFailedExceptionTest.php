<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * GraphTokenRefreshFailedException carries getToken()'s failure twice: as the public
 * $tokenFailure and, since #5461, as getPrevious(). Both reach whatever reads the exception
 * (a reporter walks getPrevious()), so on each of getToken()'s three failure arms neither
 * message may carry the token URL, the tenant, the secret, Graph's 401 text or the identity
 * provider's text (#5450, C-56).
 *
 * getToken() has three failure arms (#5511): a GuzzleException with a response (message
 * "(HTTP n)"), a GuzzleException without one (message "(<exception class>)"), and a 2xx
 * without access_token (a fixed string). tokenFailures() names the arm of each case; the
 * response and no-response arms each get two inputs.
 *
 * Every value here is synthetic; the handler is a scripted MockHandler.
 */
class GraphTokenRefreshFailedExceptionTest extends TestCase
{
    private const TENANT = 'tenant-b4c-synthetic';

    /** A synthetic client-secret fixture, not a credential (G-13). The token fixtures echo it back (#5517). */
    private const SECRET_FIXTURE = 'b4c-synthetic-secret-not-real';

    private const MAILBOX = 'support@example.test';

    private const GRAPH_401_MARKER = 'B4C-SYNTHETIC-GRAPH-401-MARKER';

    private const IDP_MARKER = 'AADSTS7000215-B4C-SYNTHETIC-IDP-MARKER invalid client secret provided';

    private const TOKEN_URL = 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private ?HandlerStack $stack = null;

    private TestHandler $logs;

    /** @var array<string, string|false> */
    private array $ini = [];

    protected function setUp(): void
    {
        parent::setUp();

        // #5515: GraphClient builds raw Guzzle clients, which Http::preventStrayRequests()
        // (Laravel's Http facade) never sees, so this file does not call it. Two guards stand in.
        // phpunit.xml points HTTP(S)_PROXY at a dead local port and a raw Guzzle client honours
        // it, so a request that escapes the scripted handler fails instead of leaving the box.
        // That is asserted here. client() also checks, BEFORE the first request, that both of
        // GraphClient's clients carry the scripted handler.
        $this->assertSame(
            ['http' => 'http://127.0.0.1:9', 'https' => 'http://127.0.0.1:9'],
            (new Client)->getConfig('proxy'),
            'a raw Guzzle client must default to the dead proxy phpunit.xml sets',
        );

        foreach (['zend.exception_ignore_args', 'zend.exception_string_param_max_len'] as $key) {
            $this->ini[$key] = ini_get($key);
        }
        $this->logs = $this->captureLogs();
    }

    protected function tearDown(): void
    {
        foreach ($this->ini as $key => $value) {
            ini_set($key, (string) $value);
        }
        parent::tearDown();
    }

    /**
     * Cases: [failure, getToken()'s exact message, arm, getToken()'s record context or null].
     *
     * @return array<string, array{0: \Closure(): (Response|\Throwable|\Closure), 1: string, 2: string, 3: array<string, mixed>|null}>
     */
    public static function tokenFailures(): array
    {
        return [
            'response arm: token endpoint refuses (ClientException, 400)' => [
                fn () => self::echoingTokenResponse(400, ['error' => 'invalid_client', 'error_description' => self::IDP_MARKER]),
                'Failed to obtain Graph API token (HTTP 400)',
                'response',
                ['status' => 400, 'exception' => ClientException::class],
            ],
            'response arm: token endpoint unavailable (ServerException, 503)' => [
                fn () => self::echoingTokenResponse(503, ['error' => 'temporarily_unavailable', 'error_description' => self::IDP_MARKER]),
                'Failed to obtain Graph API token (HTTP 503)',
                'response',
                ['status' => 503, 'exception' => ServerException::class],
            ],
            'no-response arm: token endpoint unreachable (ConnectException)' => [
                fn () => new ConnectException('cURL error 6: '.self::IDP_MARKER.' for '.self::TOKEN_URL, new Request('POST', self::TOKEN_URL)),
                'Failed to obtain Graph API token ('.ConnectException::class.')',
                'no-response',
                ['status' => null, 'exception' => ConnectException::class],
            ],
            'no-response arm: token read timed out (RequestException without a response)' => [
                fn () => new RequestException('cURL error 28: '.self::IDP_MARKER.' for '.self::TOKEN_URL, new Request('POST', self::TOKEN_URL)),
                'Failed to obtain Graph API token ('.RequestException::class.')',
                'no-response',
                ['status' => null, 'exception' => RequestException::class],
            ],
            'no-token arm: 2xx without access_token' => [
                fn () => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'note' => self::IDP_MARKER, 'tenant' => self::TENANT,
                ])),
                'Graph API token response did not contain access_token',
                'no-token',
                null,
            ],
        ];
    }

    /**
     * #5517: a token response that echoes the request's form body first, so the secret is in
     * the response body and in the first 120 characters Guzzle summarises into its exception
     * message. A change that copies either into a message or a record now trips SECRET_FIXTURE.
     *
     * @param  array<string, string>  $body
     */
    private static function echoingTokenResponse(int $status, array $body): \Closure
    {
        return fn (RequestInterface $request) => new Response($status, ['Content-Type' => 'application/json'], (string) json_encode(
            ['echo' => (string) $request->getBody()] + $body,
            JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * A GraphClient with a seeded token, scripted to answer Graph 401 and then $tokenFailure.
     * Before any request is made, both of its clients must carry the scripted stack (#5515).
     */
    private function client(Response|\Throwable|\Closure $tokenFailure): GraphClient
    {
        $this->history = [];
        $this->stack = HandlerStack::create(new MockHandler([
            new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => ['code' => 'InvalidAuthenticationToken', 'message' => self::GRAPH_401_MARKER],
            ])),
            $tokenFailure,
        ]));
        $this->stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'seeded-token', 3600);

        $graph = new GraphClient([
            'tenant_id' => self::TENANT,
            'client_id' => 'b4c-client',
            'client_secret' => self::SECRET_FIXTURE,
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $this->stack,
        ], $cache);

        foreach (['http', 'authHttp'] as $property) {
            $client = (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph);
            $this->assertSame($this->stack, $client->getConfig('handler'), "GraphClient::\${$property} must use the scripted handler before any request");
        }
        $this->assertSame([], $this->history, 'nothing was sent while building the client');

        return $graph;
    }

    private function refreshFailure(Response|\Throwable|\Closure $tokenFailure): GraphTokenRefreshFailedException
    {
        $graph = $this->client($tokenFailure);
        try {
            $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');
        } catch (GraphTokenRefreshFailedException $e) {
            $this->assertCount(1, $this->tokenRequests(), 'positive control: the refresh ran');

            return $e;
        }
        $this->fail('expected GraphTokenRefreshFailedException');
    }

    /** @return list<array<string, mixed>> */
    private function tokenRequests(): array
    {
        return array_values(array_filter($this->history, fn (array $h) => str_ends_with($h['request']->getUri()->getPath(), '/oauth2/v2.0/token')));
    }

    /** Route every log channel into one TestHandler so a test reads each record and its level. */
    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler;
        foreach (array_keys(config('logging.channels')) as $name) {
            config(["logging.channels.{$name}" => [
                'driver' => 'custom',
                'via' => fn () => new \Monolog\Logger($name, [$handler]),
            ]]);
            Log::forgetChannel($name);
        }

        return $handler;
    }

    /**
     * A record as Laravel writes it: LogManager's own formatter (LineFormatter with stack
     * traces), which renders an exception object in context with its trace and every
     * [previous exception] (#5510, #5513). json_encode() would render a Throwable as {}.
     */
    private function render(LogRecord $record): string
    {
        /** @var \Monolog\Formatter\FormatterInterface $formatter */
        $formatter = (fn () => $this->formatter())->call(app('log'));

        return $formatter->format($record);
    }

    /** How a reporter that logs ['exception' => $e] (Laravel's handler does) renders $e. */
    private function reported(\Throwable $e): string
    {
        return $this->render(new LogRecord(new \DateTimeImmutable, 'testing', Level::Error, $e->getMessage(), ['exception' => $e]));
    }

    /**
     * Vendor text no message, record or rendered chain may carry. The mailbox needles are
     * separate: under exception_ignore_args=Off a rendered TRACE carries a mailbox prefix
     * on the outer exception already (see the chain test).
     *
     * @return list<string>
     */
    private function vendorNeedles(): array
    {
        return [self::IDP_MARKER, 'AADSTS', 'invalid_client', 'temporarily_unavailable', self::TENANT, self::SECRET_FIXTURE,
            'client_secret', 'login.microsoftonline.com', 'oauth2', self::GRAPH_401_MARKER, 'InvalidAuthenticationToken',
            'graph.microsoft.com', 'cURL'];
    }

    /** @return list<string> */
    private function mailboxNeedles(): array
    {
        return [self::MAILBOX, rawurlencode(self::MAILBOX), 'support@', 'users/'];
    }

    /** @return list<string> */
    private function needles(): array
    {
        return [...$this->vendorNeedles(), ...$this->mailboxNeedles()];
    }

    private function assertCarriesNone(array $needles, string $haystack, string $what): void
    {
        foreach ($needles as $needle) {
            $this->assertFalse(str_contains($haystack, $needle), "{$what} carries '{$needle}'");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_the_token_failure_message_is_status_only_on_each_get_token_arm(\Closure $tokenFailure, string $expected, string $arm): void
    {
        $e = $this->refreshFailure($tokenFailure());

        // #5450: the public $tokenFailure is getToken()'s own exception; pin its exact message.
        $this->assertSame(GraphClientException::class, $e->tokenFailure::class);
        $this->assertSame($expected, $e->tokenFailure->getMessage());
        $this->assertSame(0, $e->tokenFailure->getHttpStatus(), 'the token failure is not a Graph status');
        $this->assertNull($e->tokenFailure->getResponseBody(), 'no identity-provider body rides on it');

        // #5511: each case's message has its arm's shape.
        $this->assertMatchesRegularExpression(match ($arm) {
            'response' => '/^Failed to obtain Graph API token \(HTTP \d{3}\)$/',
            'no-response' => '/^Failed to obtain Graph API token \([A-Za-z\\\\]+Exception\)$/',
            'no-token' => '/^Graph API token response did not contain access_token$/',
        }, $expected);

        $this->assertCarriesNone($this->needles(), $e->getMessage(), 'the outer message');
        $this->assertCarriesNone($this->needles(), $e->tokenFailure->getMessage(), 'the token failure message');
    }

    /** #5511: the cases cover getToken()'s three failure arms, no more and no fewer. */
    public function test_the_cases_cover_the_three_get_token_failure_arms(): void
    {
        $arms = array_values(array_unique(array_column(self::tokenFailures(), 2)));
        sort($arms);
        $this->assertSame(['no-response', 'no-token', 'response'], $arms);
    }

    /**
     * #5517 positive control: the secret needle can fire. On the response arm Guzzle's own
     * exception message (what getToken() catches) carries the secret, the tenant and the
     * identity provider's text, so copying that message anywhere a test reads trips the scan.
     */
    public function test_the_token_fixtures_carry_the_secret_into_what_get_token_catches(): void
    {
        foreach (self::tokenFailures() as $name => [$tokenFailure, , $arm]) {
            $this->refreshFailure($tokenFailure());
            $token = $this->tokenRequests()[0];
            $this->assertStringContainsString('client_secret='.self::SECRET_FIXTURE, (string) $token['request']->getBody(), $name);

            if ($arm === 'no-token') {
                $this->assertNull($token['error'], $name);

                continue;
            }
            // The history middleware sits inside http_errors, so on the response arm it holds the
            // response; RequestException::create() is what http_errors throws from it.
            $caught = $arm === 'response'
                ? RequestException::create($token['request'], $token['response'])
                : $token['error'];
            $this->assertInstanceOf(\Throwable::class, $caught, $name);
            $needles = $arm === 'response' ? [self::SECRET_FIXTURE, self::TENANT, 'client_secret'] : [self::TENANT, self::IDP_MARKER];
            foreach ($needles as $needle) {
                $this->assertTrue(str_contains($caught->getMessage(), $needle), "{$name}: Guzzle's message carries '{$needle}'");
            }
        }
    }

    /**
     * #5516 / #5512: read every record on each arm. getToken()'s ERROR record is status-only
     * (none on the no-token arm), and GraphClient's refresh-failure record carries exactly
     * method, status 401 and token_refresh 'failed'. Records are scanned as Laravel renders
     * them, not as json_encode() sees them (#5510).
     *
     * @param  array<string, mixed>|null  $tokenRecord
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_every_record_on_each_arm_is_read_and_carries_no_vendor_text(\Closure $tokenFailure, string $expected, string $arm, ?array $tokenRecord): void
    {
        $this->refreshFailure($tokenFailure());

        $expectedRecords = [];
        if ($tokenRecord !== null) {
            $expectedRecords[] = [Level::Error, 'Graph API token request failed', $tokenRecord];
        }
        $expectedRecords[] = [Level::Error, 'Graph API request failed', ['method' => 'GET', 'status' => 401, 'token_refresh' => 'failed']];

        $records = $this->logs->getRecords();
        $this->assertSame(
            $expectedRecords,
            array_map(fn (LogRecord $r) => [$r->level, $r->message, $r->context], $records),
            'every record on this arm, its level and its exact context',
        );
        foreach ($records as $record) {
            $this->assertSame([], $record->extra);
            $this->assertCarriesNone($this->needles(), $this->render($record), "the rendered '{$record->message}' record");
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
        $this->assertNull($e->getPrevious()->getPrevious(), 'getToken() throws a fresh exception with no previous of its own');
    }

    /**
     * #5513: the chain as a reporter renders it. LogManager's formatter prints each link's
     * class, message, code, file:line and trace, and walks [previous exception]. Rendered
     * with frame arguments off (zend.exception_ignore_args=On, php.ini-production) and on
     * with no truncation (stricter than PHP's 15-character default).
     *
     * With arguments on, the OUTER trace already prints the users/ endpoint, which holds the
     * mailbox (GraphClient::authenticatedRequest's frame). That exposure is not the chain's:
     * the test pins that the previous link adds no needle the outer link lacks, and that no
     * vendor text appears anywhere.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_the_chain_as_the_reporter_renders_it_carries_no_vendor_text(\Closure $tokenFailure, string $expected): void
    {
        foreach (['arguments off' => '1', 'arguments on' => '0'] as $mode => $ignoreArgs) {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
            ini_set('zend.exception_string_param_max_len', '1000000');

            $rendered = $this->reported($this->refreshFailure($tokenFailure()));

            // Positive controls: this is a full render of both links, traces included.
            $this->assertSame(1, substr_count($rendered, '[previous exception]'), $mode);
            [$outer, $previous] = explode('[previous exception]', $rendered, 2);
            // Class names render JSON-escaped (App\\Services\\...), so match the short name.
            $this->assertTrue(str_contains($outer, 'GraphTokenRefreshFailedException(code: 401)'), "{$mode}: outer link");
            $this->assertTrue(str_contains($outer, '[stacktrace]'), "{$mode}: outer trace");
            $this->assertTrue(str_contains($previous, 'GraphClientException(code: 0): '.addslashes($expected)), "{$mode}: previous link");
            $this->assertTrue(str_contains($previous, '[stacktrace]'), "{$mode}: previous trace");
            $this->assertTrue(str_contains($previous, 'GraphClient->getToken('), "{$mode}: previous trace frames");
            $this->assertSame($ignoreArgs === '0', str_contains($outer, "'users/".self::MAILBOX), "{$mode}: frame arguments render as configured");

            $this->assertCarriesNone($this->vendorNeedles(), $rendered, "{$mode}: the rendered chain");
            foreach ($this->mailboxNeedles() as $needle) {
                if ($ignoreArgs === '1') {
                    $this->assertFalse(str_contains($rendered, $needle), "{$mode}: the rendered chain carries '{$needle}'");
                } elseif (str_contains($previous, $needle)) {
                    $this->assertTrue(str_contains($outer, $needle), "{$mode}: the previous link adds '{$needle}'");
                }
            }
        }
    }

    /** Control for the renderer above: vendor text on a previous link IS seen; json_encode() would not see it. */
    public function test_the_renderer_walks_the_previous_chain(): void
    {
        $leaky = new GraphTokenRefreshFailedException('GET', new GraphClientException('Failed: '.self::IDP_MARKER));

        $this->assertTrue(str_contains($this->reported($leaky), self::IDP_MARKER), 'the reporter render shows a previous link\'s message');
        $this->assertFalse(str_contains((string) json_encode(['exception' => $leaky]), self::IDP_MARKER), 'json_encode() renders a Throwable as {}');
    }
}
