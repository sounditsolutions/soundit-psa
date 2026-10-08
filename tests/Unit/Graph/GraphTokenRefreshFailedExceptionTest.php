<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * GraphTokenRefreshFailedException carries getToken()'s failure twice: as the public
 * $tokenFailure and, since #5461, as getPrevious(). Both reach whatever reads the exception
 * (a reporter walks getPrevious()), so on each of getToken()'s four failure arms neither
 * message may carry the token URL, the tenant, the secret, Graph's 401 text or the identity
 * provider's text (#5450, C-56).
 *
 * getToken() throws a GraphTokenException (#5728) on four arms (#5511), and their messages
 * differ (#5741): a token response with a 3xx (#5736, redirects are never followed) or a 4xx/5xx
 * status (message "(HTTP n)", the only arm with a status), a GuzzleException without a response
 * (message "(<exception class>)"), a 2xx response with no access_token or a null one (a fixed
 * string with no status; #5620), and a 2xx response whose access_token is present but not a
 * usable bearer (not a string, '', '0', or carrying a control character; #5729, #5738) or whose
 * expires_in is null, not numeric or under one second (#5730, #5721) (a fixed string with no
 * status that names the field; #5659). tokenFailures() names the arm of each case; the response
 * arm gets two inputs, the no-response arm three, built by Guzzle's own curl handler (#5616),
 * and the malformed arm the rest. The 3xx cases are in their own test.
 *
 * Every value here is synthetic; the handler is a scripted MockHandler.
 */
class GraphTokenRefreshFailedExceptionTest extends TestCase
{
    use FlattensLogContext;

    private const TENANT = 'tenant-b4c-synthetic';

    /** A synthetic client-secret fixture, not a credential (G-13). The token fixtures echo it back (#5517). */
    private const SECRET_FIXTURE = 'b4c-synthetic-secret-not-real';

    private const MAILBOX = 'support@example.test';

    private const GRAPH_401_MARKER = 'B4C-SYNTHETIC-GRAPH-401-MARKER';

    private const IDP_MARKER = 'AADSTS7000215-B4C-SYNTHETIC-IDP-MARKER invalid client secret provided';

    /** The access token seeded in the cache; a synthetic fixture, sent as the Graph bearer. */
    private const SEEDED_ACCESS_FIXTURE = 'b4h-synthetic-seeded-access';

    /** A string access token a malformed token response carries beside a bad expires_in (#5659). */
    private const ISSUED_ACCESS_FIXTURE = 'b4j-synthetic-issued-access';

    /** Carried inside a malformed (array) access_token, so a record or message that prints the value trips the scan (#5659). */
    private const MALFORMED_VALUE_MARKER = 'B4J-SYNTHETIC-MALFORMED-VALUE';

    private const CLIENT_ID = 'b4c-client';

    private const TOKEN_URL = 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token';

    /** #5736: a redirect target on another host; a followed 307/308 would re-POST the form there. */
    private const REDIRECT_HOST = 'b4k2-redirect.example.test';

    private const REDIRECT_TARGET = 'https://'.self::REDIRECT_HOST.'/token';

    /** #5739: a Location Psr7\Uri cannot parse (MalformedUriException when a redirect is followed). */
    private const UNPARSEABLE_LOCATION = 'http://[::1';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private ?HandlerStack $stack = null;

    private TestHandler $logs;

    private ?Repository $cache = null;

    /** @var list<MessageLogged> every record Laravel's Logger wrapper wrote, on any logger (#5622) */
    private array $logged = [];

    /** @var list<\Throwable> what the full Guzzle stack threw, i.e. what GraphClient caught (#5617) */
    private array $thrown = [];

    /** @var array<string, string|false> */
    private array $ini = [];

    protected function setUp(): void
    {
        parent::setUp();

        // G-5, three independent guards; none replaces another (#5515, #5611).
        // 1. Http::preventStrayRequests(): a request made through Laravel's Http facade throws
        //    StrayRequestException naming its URL (test_a_facade_request_is_refused_as_stray).
        //    GraphClient builds raw Guzzle clients, which this guard never sees.
        // 2. phpunit.xml points HTTP(S)_PROXY at a dead local port and a raw Guzzle client honours
        //    it, so a raw request that escapes the scripted handler fails instead of leaving the
        //    box. That is asserted here.
        // 3. client() checks, before the first request, that both of GraphClient's constructor
        //    clients carry the scripted handler. Every test that builds a client and makes
        //    requests checks again after them through assertScriptedHandler(): refreshFailure()
        //    for every refresh case (#5669), and the redirect, not-cached, isHealthy and
        //    per-call tests directly (#5740). The per-call clients are covered by
        //    test_the_per_call_clients_use_the_scripted_handler (#5612).
        Http::preventStrayRequests();
        $this->assertSame(
            ['http' => 'http://127.0.0.1:9', 'https' => 'http://127.0.0.1:9'],
            (new Client)->getConfig('proxy'),
            'a raw Guzzle client must default to the dead proxy phpunit.xml sets',
        );

        foreach (['zend.exception_ignore_args', 'zend.exception_string_param_max_len'] as $key) {
            $this->ini[$key] = ini_get($key);
        }
        $this->logs = $this->captureLogs();
        // #5622: Laravel's Logger wrapper fires MessageLogged for every record it writes, on a
        // configured channel or not (Log::build(), the emergency logger), so the 'every record'
        // pin compares this list with the TestHandler's. A bare Monolog Logger or error_log()
        // fires nothing and stays outside both lists.
        Event::listen(MessageLogged::class, function (MessageLogged $logged): void {
            $this->logged[] = $logged;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->ini as $key => $value) {
            ini_set($key, (string) $value);
        }
        parent::tearDown();
    }

    /**
     * Cases: [failure, getToken()'s exact message, arm, getToken()'s record as [message, context], or null].
     *
     * @return array<string, array{0: \Closure(): (Response|\Throwable|\Closure), 1: string, 2: string, 3: array{0: string, 1: array<string, mixed>}|null}>
     */
    public static function tokenFailures(): array
    {
        return [
            'response arm: token endpoint refuses (ClientException, 400)' => [
                fn () => self::echoingTokenResponse(400, ['error' => 'invalid_client', 'error_description' => self::IDP_MARKER]),
                'Failed to obtain Graph API token (HTTP 400)',
                'response',
                ['Graph API token request failed', ['status' => 400, 'exception' => ClientException::class]],
            ],
            'response arm: token endpoint unavailable (ServerException, 503)' => [
                fn () => self::echoingTokenResponse(503, ['error' => 'temporarily_unavailable', 'error_description' => self::IDP_MARKER]),
                'Failed to obtain Graph API token (HTTP 503)',
                'response',
                ['Graph API token request failed', ['status' => 503, 'exception' => ServerException::class]],
            ],
            // #5616: the no-response cases are what Guzzle's own curl handler builds for each errno
            // (curlRejection()). A cURL 28 timeout is a ConnectException there, not a bare
            // RequestException; a receive failure (cURL 56) is a RequestException with no response.
            'no-response arm: token host unresolvable (cURL 6, ConnectException)' => [
                fn () => self::curlRejection(\CURLE_COULDNT_RESOLVE_HOST),
                'Failed to obtain Graph API token ('.ConnectException::class.')',
                'no-response',
                ['Graph API token request failed', ['status' => null, 'exception' => ConnectException::class]],
            ],
            'no-response arm: token request timed out (cURL 28, ConnectException)' => [
                fn () => self::curlRejection(\CURLE_OPERATION_TIMEOUTED),
                'Failed to obtain Graph API token ('.ConnectException::class.')',
                'no-response',
                ['Graph API token request failed', ['status' => null, 'exception' => ConnectException::class]],
            ],
            'no-response arm: token response not received (cURL 56, RequestException without a response)' => [
                fn () => self::curlRejection(\CURLE_RECV_ERROR),
                'Failed to obtain Graph API token ('.RequestException::class.')',
                'no-response',
                ['Graph API token request failed', ['status' => null, 'exception' => RequestException::class]],
            ],
            'no-token arm: 200 without access_token' => [
                fn () => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'note' => self::IDP_MARKER, 'tenant' => self::TENANT,
                ])),
                'Graph API token response did not contain access_token',
                'no-token',
                null,
            ],
            // #5659 / #5665: under coercive typing an int or true token used to be returned as a
            // string and cached, an array token threw a TypeError and stayed cached, and a
            // non-numeric expires_in threw or warned. Each is now refused before caching.
            'malformed arm: array access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => [self::MALFORMED_VALUE_MARKER], 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'array', 'reason' => 'not a string']],
            ],
            'malformed arm: int access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => 735911, 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'int', 'reason' => 'not a string']],
            ],
            'malformed arm: true access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => true, 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'bool', 'reason' => 'not a string']],
            ],
            // #5729: a falsy access_token that is present used to take the no-token arm with no
            // record. It is malformed, with the record naming its type.
            'malformed arm: false access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => false, 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'bool', 'reason' => 'not a string']],
            ],
            'malformed arm: zero access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => 0, 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'int', 'reason' => 'not a string']],
            ],
            'malformed arm: [] access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => [], 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'array', 'reason' => 'not a string']],
            ],
            'malformed arm: empty string access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => '', 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'string', 'reason' => 'empty']],
            ],
            'malformed arm: "0" access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => '0', 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'string', 'reason' => 'empty']],
            ],
            // #5738: a string token with a control character would fail psr7's header check with
            // an exception that prints the whole bearer. Refused before it is cached or sent.
            'malformed arm: control character (CR) access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => self::MALFORMED_VALUE_MARKER."\rx", 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'string', 'reason' => 'control character']],
            ],
            'malformed arm: control character (LF) access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => self::MALFORMED_VALUE_MARKER."\nx", 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'string', 'reason' => 'control character']],
            ],
            'malformed arm: control character (NUL) access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => self::MALFORMED_VALUE_MARKER."\0x", 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'string', 'reason' => 'control character']],
            ],
            'malformed arm: control character (DEL) access_token' => [
                fn () => self::malformedTokenResponse(['access_token' => self::MALFORMED_VALUE_MARKER."\x7Fx", 'expires_in' => 3600]),
                'Graph API token response carried a malformed access_token',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'access_token', 'type' => 'string', 'reason' => 'control character']],
            ],
            // #5730 / #5721: an explicit null expires_in is no longer read as 3600, and an
            // expires_in under one second is no longer cached for the 60-second floor.
            'malformed arm: null expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => null]),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'null', 'reason' => 'not numeric']],
            ],
            'malformed arm: zero expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 0]),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'int', 'reason' => 'under one second']],
            ],
            'malformed arm: negative expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => -100]),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'int', 'reason' => 'under one second']],
            ],
            'malformed arm: "0" expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => '0']),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'string', 'reason' => 'under one second']],
            ],
            'malformed arm: sub-second expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 0.5]),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'float', 'reason' => 'under one second']],
            ],
            'malformed arm: non-numeric expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 'soon']),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'string', 'reason' => 'not numeric']],
            ],
            'malformed arm: leading-numeric expires_in' => [
                fn () => self::malformedTokenResponse(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => '3600s']),
                'Graph API token response carried a malformed expires_in',
                'malformed',
                ['Graph API token response malformed', ['status' => 200, 'field' => 'expires_in', 'type' => 'string', 'reason' => 'not numeric']],
            ],
        ];
    }

    /**
     * #5659: a 200 token response with the given fields, which also echoes the request's form
     * body (the secret) and the identity provider's marker, so a record or message that copies
     * the body trips the scan.
     *
     * @param  array<string, mixed>  $fields
     */
    private static function malformedTokenResponse(array $fields): \Closure
    {
        return fn (RequestInterface $request) => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(
            $fields + ['echo' => (string) $request->getBody(), 'note' => self::IDP_MARKER],
            JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * #5616: the exception Guzzle's curl handler (CurlFactory::createRejection) builds for a
     * transfer that failed with $errno before any response, its error text carrying the
     * identity provider's marker. The installed vendor decides the class, so a fixture cannot
     * pair an errno with a class the producer never builds.
     */
    private static function curlRejection(int $errno): \Throwable
    {
        $easy = (new \ReflectionClass(EasyHandle::class))->newInstanceWithoutConstructor();
        $easy->request = new Request('POST', self::TOKEN_URL);
        $easy->errno = $errno;

        try {
            (new \ReflectionMethod(CurlFactory::class, 'createRejection'))
                ->invoke(null, $easy, ['errno' => $errno, 'error' => self::IDP_MARKER])
                ->wait();
        } catch (\Throwable $e) {
            return $e;
        }
        throw new \LogicException('createRejection() did not reject');
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
     * A GraphClient with a seeded token, scripted to answer Graph 401 and then $tokenFailure
     * (or exactly $responses when given). Before any request is made, both of its clients must
     * carry the scripted stack (#5515); assertScriptedHandler() checks again afterwards.
     */
    private function client(Response|\Throwable|\Closure|null $tokenFailure, Response|\Throwable|\Closure ...$responses): GraphClient
    {
        $this->history = [];
        $this->thrown = [];
        $this->stack = HandlerStack::create(new MockHandler($responses !== [] ? $responses : [
            new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => ['code' => 'InvalidAuthenticationToken', 'message' => self::GRAPH_401_MARKER],
            ])),
            $tokenFailure,
        ]));
        $this->stack->push(Middleware::history($this->history));
        // #5617: the outermost layer, outside http_errors, records each rejection exactly as the
        // client's request() throws it (Promise::wait() rethrows the reason object itself), so a
        // test reads what GraphClient caught instead of rebuilding it.
        $this->stack->unshift(fn (callable $next) => fn (RequestInterface $request, array $options) => $next($request, $options)->then(
            null,
            function ($reason) {
                $this->thrown[] = $reason;

                return Create::rejectionFor($reason);
            },
        ), 'caught_witness');

        $this->cache = $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', self::SEEDED_ACCESS_FIXTURE, 3600);

        $graph = new GraphClient([
            'tenant_id' => self::TENANT,
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::SECRET_FIXTURE,
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $this->stack,
        ], $cache);

        $this->assertScriptedHandler($graph, 'before any request');
        $this->assertSame([], $this->history, 'nothing was sent while building the client');

        return $graph;
    }

    /**
     * Both constructor clients still carry the scripted stack (#5515, #5612). The per-call
     * clients of requestAbsolute()/requestJsonAbsolute() are built at call time and are
     * covered by test_the_per_call_clients_use_the_scripted_handler.
     */
    private function assertScriptedHandler(GraphClient $graph, string $when): void
    {
        foreach (['http', 'authHttp'] as $property) {
            $client = (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph);
            $this->assertSame($this->stack, $client->getConfig('handler'), "GraphClient::\${$property} must use the scripted handler {$when}");
        }
    }

    private function refreshFailure(Response|\Throwable|\Closure $tokenFailure): GraphTokenRefreshFailedException
    {
        $graph = $this->client($tokenFailure);
        try {
            $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');
        } catch (GraphTokenRefreshFailedException $e) {
            $this->assertCount(1, $this->tokenRequests(), 'positive control: the refresh ran');
            $this->assertScriptedHandler($graph, 'after the 401 and the refresh');

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
     *
     * #5618: every frame prints its absolute file path, so the checkout path is replaced by
     * '<base>' before any needle is matched; a checkout under e.g. /home/users/ would otherwise
     * trip the 'users/' needle with nothing leaked.
     */
    private function render(LogRecord $record): string
    {
        /** @var \Monolog\Formatter\FormatterInterface $formatter */
        $formatter = (fn () => $this->formatter())->call(app('log'));

        return self::withoutCheckoutPath($formatter->format($record));
    }

    private static function withoutCheckoutPath(string $rendered): string
    {
        $base = base_path();

        return str_replace([$base, str_replace('/', '\\/', $base)], '<base>', $rendered);
    }

    /**
     * #5613: $e as Laravel's real exception handler reports it. ExceptionHandler::report()
     * merges the exception's own context() and the handler's context() into the record, so
     * anything either adds is rendered and scanned too. Exactly one record is written.
     */
    private function reported(\Throwable $e): string
    {
        $before = count($this->logs->getRecords());
        app(ExceptionHandler::class)->report($e);
        $records = array_slice($this->logs->getRecords(), $before);

        $this->assertCount(1, $records, 'the handler reported $e as exactly one record');
        $this->assertSame($e, $records[0]->context['exception'] ?? null, 'the record carries $e itself');

        return $this->render($records[0]);
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
            'graph.microsoft.com', 'cURL', self::MALFORMED_VALUE_MARKER, '735911', self::ISSUED_ACCESS_FIXTURE, ...$this->credentialNeedles()];
    }

    /**
     * #5621: the bearer access token GraphClient sends, the header that carries it, and the
     * app's client_id. test_the_credential_needles_are_what_graph_client_sends shows each is
     * on the wire and that the scan fires on it.
     *
     * @return list<string>
     */
    private function credentialNeedles(): array
    {
        return [self::SEEDED_ACCESS_FIXTURE, 'Bearer', 'Authorization', self::CLIENT_ID];
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
    public function test_the_token_failure_message_has_its_arms_shape_on_each_get_token_arm(\Closure $tokenFailure, string $expected, string $arm): void
    {
        $e = $this->refreshFailure($tokenFailure());

        // #5450: the public $tokenFailure is getToken()'s own exception; pin its exact message.
        // #5728: a token failure is a GraphTokenException, so a record that keeps only the class
        // tells it from a Graph request that received no response (also status 0).
        $this->assertSame(GraphTokenException::class, $e->tokenFailure::class);
        $this->assertSame($expected, $e->tokenFailure->getMessage());
        $this->assertSame(0, $e->tokenFailure->getHttpStatus(), 'the token failure is not a Graph status');
        $this->assertNull($e->tokenFailure->getResponseBody(), 'no identity-provider body rides on it');

        // #5511 / #5615: the message getToken() produced has the shape of this case's arm.
        $this->assertSame($arm, self::armOf($e->tokenFailure->getMessage()));

        $this->assertCarriesNone($this->needles(), $e->getMessage(), 'the outer message');
        $this->assertCarriesNone($this->needles(), $e->tokenFailure->getMessage(), 'the token failure message');
    }

    /** The getToken() arm a token-failure message has the shape of, or null for none of them. */
    private static function armOf(string $message): ?string
    {
        return match (true) {
            preg_match('/^Failed to obtain Graph API token \(HTTP \d{3}\)$/', $message) === 1 => 'response',
            preg_match('/^Failed to obtain Graph API token \([A-Za-z\\\\]+Exception\)$/', $message) === 1 => 'no-response',
            $message === 'Graph API token response did not contain access_token' => 'no-token',
            preg_match('/^Graph API token response carried a malformed (access_token|expires_in)$/', $message) === 1 => 'malformed',
            default => null,
        };
    }

    /**
     * #5511 / #5615: run through getToken(), the cases reach each of its four GraphClientException
     * arms. This reads production messages, not the provider's labels. It does not show that
     * getToken() has no other exit.
     */
    public function test_the_cases_reach_each_of_the_four_get_token_exception_arms(): void
    {
        $arms = [];
        foreach (self::tokenFailures() as $name => [$tokenFailure]) {
            $arms[] = self::armOf($this->refreshFailure($tokenFailure())->tokenFailure->getMessage()) ?? "none: {$name}";
        }
        $arms = array_values(array_unique($arms));
        sort($arms);
        $this->assertSame(['malformed', 'no-response', 'no-token', 'response'], $arms);
    }

    /**
     * #5736 / #5739: the token POST never follows a redirect. Every 3xx, with a Location to the
     * same host, to another host, with a 307/308 that would replay the form body, with an
     * unparseable Location or with none, is one token request and takes the response arm: the
     * message is "(HTTP 3xx)", the token record is the status alone, and the Location appears
     * in no message or record. Before #5736 a 302/307 to another host was followed and the 307
     * re-sent client_secret there; before #5739 an unparseable Location escaped getToken() as
     * Guzzle's MalformedUriException, which is not a GraphClientException.
     */
    public function test_the_token_request_refuses_every_redirect_by_its_status(): void
    {
        $cases = [
            '302 same host' => [302, ['Location' => self::TOKEN_URL.'?hop=1']],
            '307 another host' => [307, ['Location' => self::REDIRECT_TARGET]],
            '308 another host' => [308, ['Location' => self::REDIRECT_TARGET]],
            '301 another host' => [301, ['Location' => self::REDIRECT_TARGET]],
            '302 unparseable Location' => [302, ['Location' => self::UNPARSEABLE_LOCATION]],
            '302 without Location' => [302, []],
            '300' => [300, []],
            '304' => [304, []],
        ];
        foreach ($cases as $name => [$status, $headers]) {
            $this->logs->clear();
            $this->logged = [];
            $graph = $this->client(null,
                new Response(401, [], '{}'),
                new Response($status, $headers, (string) json_encode(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 3600])),
                // A follow would consume this; a refused redirect leaves it queued.
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 3600])),
            );
            try {
                $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');
                $this->fail("{$name}: expected GraphTokenRefreshFailedException");
            } catch (GraphTokenRefreshFailedException $e) {
                $this->assertSame(GraphTokenException::class, $e->tokenFailure::class, $name);
                $this->assertSame("Failed to obtain Graph API token (HTTP {$status})", $e->tokenFailure->getMessage(), $name);
                $this->assertSame('response', self::armOf($e->tokenFailure->getMessage()), $name);
            }
            $this->assertCount(1, $this->tokenRequests(), "{$name}: one token request, nothing followed");
            $this->assertSame([], array_values(array_filter($this->history, fn (array $h) => $h['request']->getUri()->getHost() !== 'login.microsoftonline.com' && $h['request']->getUri()->getHost() !== 'graph.microsoft.com')), "{$name}: no request left the two hosts");
            $this->assertSame($status, $this->tokenRequests()[0]['response']->getStatusCode(), "{$name}: positive control: the 3xx itself was read");
            $this->assertFalse($this->cache->has('graph_api_token'), "{$name}: nothing cached");
            $this->assertScriptedHandler($graph, "{$name}: after the requests");
            $this->assertSame(
                [
                    [Level::Error, 'Graph API token request failed', ['status' => $status]],
                    [Level::Error, 'Graph API request failed', ['method' => 'GET', 'status' => 401, 'token_refresh' => 'failed']],
                ],
                array_map(fn (LogRecord $r) => [$r->level, $r->message, $r->context], $this->logs->getRecords()),
                "{$name}: the token record is the status alone",
            );
            // #5828: the context is flattened, not json_encode()d, which writes '/' as '\/' (so the
            // unparseable Location needle could never match) and a Throwable as {}.
            foreach ($this->logged as $m) {
                $this->assertCarriesNone([self::REDIRECT_HOST, 'hop=1', self::UNPARSEABLE_LOCATION, 'Location', self::ISSUED_ACCESS_FIXTURE], $m->message.' '.self::flattenForScan($m->context), "{$name}: '{$m->message}'");
            }
            $this->assertCarriesNone([self::REDIRECT_HOST, 'hop=1', self::UNPARSEABLE_LOCATION], $e->getMessage().' '.$e->tokenFailure->getMessage(), "{$name}: the messages");
        }
    }

    /**
     * #5736 / #5739 positive controls: the fixtures above exercise the hazard. A Guzzle client
     * with its default redirect handling, on the same scripted stack, follows the 307 to the
     * other host and re-sends the form body with the secret, and on the unparseable Location
     * throws MalformedUriException, which is not a GuzzleException. The client getToken() posts
     * with has redirects off, and since #5824 so has the Graph data client.
     *
     * #5828 positive control: the record scan above sees the unparseable Location when a context
     * carries it as a string or inside the MalformedUriException, where json_encode() did not.
     */
    public function test_a_default_client_would_follow_and_replay_the_secret(): void
    {
        $graph = $this->client(null,
            new Response(307, ['Location' => self::REDIRECT_TARGET], ''),
            new Response(200, [], '{}'),
            new Response(302, ['Location' => self::UNPARSEABLE_LOCATION], ''),
        );
        $this->assertFalse((new \ReflectionProperty(GraphClient::class, 'authHttp'))->getValue($graph)->getConfig('allow_redirects'));
        $this->assertFalse((new \ReflectionProperty(GraphClient::class, 'http'))->getValue($graph)->getConfig('allow_redirects'));

        $default = new Client(['base_uri' => 'https://login.microsoftonline.com/', 'handler' => $this->stack]);
        $default->post(self::TENANT.'/oauth2/v2.0/token', ['form_params' => ['client_secret' => self::SECRET_FIXTURE]]);
        $this->assertCount(2, $this->history);
        $this->assertSame(self::REDIRECT_HOST, $this->history[1]['request']->getUri()->getHost());
        $this->assertSame('POST', $this->history[1]['request']->getMethod());
        $this->assertStringContainsString(self::SECRET_FIXTURE, (string) $this->history[1]['request']->getBody(), 'a followed 307 replays the secret');

        try {
            $default->post(self::TENANT.'/oauth2/v2.0/token', ['form_params' => ['client_secret' => self::SECRET_FIXTURE]]);
            $this->fail('a followed unparseable Location did not throw');
        } catch (\GuzzleHttp\Psr7\Exception\MalformedUriException $e) {
            $this->assertNotInstanceOf(\GuzzleHttp\Exception\GuzzleException::class, $e);
        }

        // The exception's message holds the part of the Location psr7 could not parse.
        foreach (['the Location as a string' => [self::UNPARSEABLE_LOCATION, ['location' => self::UNPARSEABLE_LOCATION]], 'the exception' => [$e->getMessage(), ['exception' => $e]]] as $what => [$needle, $context]) {
            $this->assertStringNotContainsString($needle, (string) json_encode($context), "json_encode() hides {$what}");
            $this->assertStringContainsString($needle, self::flattenForScan($context), "the scan sees {$what}");
        }
    }

    /**
     * #5659: a malformed 200 from the token endpoint. A body that is not JSON, not an object, or
     * with no access_token or a null one takes the no-token arm. A present access_token that is
     * not a usable bearer, or a bad expires_in, takes the malformed arm (the provider cases above
     * pin its message, chain and record; #5729 moved '' and the other falsy values there). Here: nothing is cached, and the next call requests a new token
     * and succeeds with it instead of failing on a cached value.
     */
    public function test_a_malformed_200_from_the_token_endpoint_is_refused_and_not_cached(): void
    {
        foreach (['not json', '["x"]', '{"access_token":null}', '{"expires_in":3600}'] as $body) {
            $e = $this->refreshFailure(new Response(200, [], $body));
            $this->assertSame('Graph API token response did not contain access_token', $e->tokenFailure->getMessage(), $body);
        }

        foreach (self::tokenFailures() as $name => [$tokenFailure, $expected, $arm]) {
            if ($arm !== 'malformed') {
                continue;
            }
            $graph = $this->client(null,
                new Response(401, [], '{}'),
                $tokenFailure(),
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 3600])),
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => 'MSG-1', 'attachments' => [['id' => 'ATT-1']]])),
            );
            try {
                $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');
                $this->fail("{$name}: expected GraphTokenRefreshFailedException");
            } catch (GraphTokenRefreshFailedException $e) {
                $this->assertSame($expected, $e->tokenFailure->getMessage(), $name);
                $this->assertSame($e->tokenFailure, $e->getPrevious(), "{$name}: chained");
            }
            $this->assertFalse($this->cache->has('graph_api_token'), "{$name}: the malformed response is not cached");

            // The next call: a fresh token request, and the Graph read goes out with that token.
            $this->assertSame([['id' => 'ATT-1']], $graph->getMessageAttachments(self::MAILBOX, 'MSG-1'), "{$name}: the next call succeeds");
            $this->assertCount(2, $this->tokenRequests(), "{$name}: the next call requested a new token");
            $this->assertSame('Bearer '.self::ISSUED_ACCESS_FIXTURE, end($this->history)['request']->getHeaderLine('Authorization'), $name);
            $this->assertSame(self::ISSUED_ACCESS_FIXTURE, $this->cache->get('graph_api_token'), "{$name}: only the well-formed token is cached");
            $this->assertScriptedHandler($graph, "{$name}: after the requests");
        }
    }

    /**
     * #5659: the first call on a cold cache (no 401 first) takes the same malformed arm.
     * isHealthy() catches GraphClientException only, so before the fix an array token or a
     * non-numeric expires_in escaped it as a TypeError.
     */
    public function test_is_healthy_reports_a_malformed_token_response_as_unhealthy(): void
    {
        foreach (self::tokenFailures() as $name => [$tokenFailure, , $arm]) {
            if ($arm !== 'malformed') {
                continue;
            }
            $graph = $this->client(null, $tokenFailure());
            $this->cache->forget('graph_api_token');

            $this->assertFalse($graph->isHealthy(), $name);
            $this->assertCount(1, $this->tokenRequests(), "{$name}: positive control: the token request ran");
            $this->assertFalse($this->cache->has('graph_api_token'), "{$name}: nothing cached");
            $this->assertScriptedHandler($graph, "{$name}: after the token request");
        }
    }

    /**
     * #5517 positive control: the secret needle can fire. On the response arm the exception
     * getToken() caught (read from the outermost layer of the client's stack, #5617) carries
     * the secret, the tenant and the identity provider's text, so copying that message anywhere
     * a test reads trips the scan. On the malformed arm nothing is thrown, so the control reads
     * the 200 body getToken() decoded: it echoes the secret and carries the identity provider's
     * marker, and the array-token and control-character cases carry MALFORMED_VALUE_MARKER
     * (#5727, #5738).
     */
    public function test_the_token_fixtures_carry_the_secret_into_what_get_token_catches(): void
    {
        foreach (self::tokenFailures() as $name => [$tokenFailure, , $arm]) {
            $this->refreshFailure($tokenFailure());
            $token = $this->tokenRequests()[0];
            $this->assertStringContainsString('client_secret='.self::SECRET_FIXTURE, (string) $token['request']->getBody(), $name);

            if ($arm === 'no-token' || $arm === 'malformed') {
                $this->assertNull($token['error'], $name);
                if ($arm === 'malformed') {
                    // #5727: the decoded 200 body carries the needles the malformed-arm scans look for.
                    $body = (string) $token['response']->getBody();
                    $this->assertSame(200, $token['response']->getStatusCode(), $name);
                    $carried = ['client_secret='.self::SECRET_FIXTURE, self::IDP_MARKER];
                    if (str_contains($name, 'array access_token') || str_contains($name, 'control character')) {
                        $carried[] = self::MALFORMED_VALUE_MARKER;
                    }
                    foreach ($carried as $needle) {
                        $this->assertTrue(str_contains($body, $needle), "{$name}: the malformed 200 body carries '{$needle}'");
                    }
                }

                continue;
            }
            // #5617: the object the client threw, not a rebuilt one. On the response arm the
            // history entry (inside http_errors) holds the response, and http_errors threw from it.
            // The first rejection is Graph's 401; the second is the token request's.
            $this->assertCount(2, $this->thrown, $name);
            $this->assertSame(401, $this->thrown[0]->getResponse()?->getStatusCode(), "{$name}: Graph's 401 first");
            $caught = $this->thrown[1];
            $this->assertInstanceOf(TransferException::class, $caught, $name);
            // http_errors sits outside prepare_body, so its request is an earlier copy of the one
            // history holds: same URI, same form body.
            $this->assertSame((string) $token['request']->getUri(), (string) $caught->getRequest()->getUri(), "{$name}: thrown for the token request");
            if ($arm === 'no-response') {
                $this->assertSame($token['error'], $caught, "{$name}: the handler's own rejection");
            } else {
                $this->assertSame($token['response'], $caught->getResponse(), "{$name}: thrown from the token response");
            }
            $needles = $arm === 'response' ? [self::SECRET_FIXTURE, self::TENANT, 'client_secret'] : [self::TENANT, self::IDP_MARKER];
            foreach ($needles as $needle) {
                $this->assertTrue(str_contains($caught->getMessage(), $needle), "{$name}: Guzzle's message carries '{$needle}'");
            }
        }
    }

    /**
     * #5516 / #5512: read every record on each arm. getToken()'s ERROR record is the one the
     * provider pins for the arm: status and exception class on the response and no-response
     * arms (status null on the latter), status, field and PHP type on the malformed arm, and
     * none on the no-token arm (#5741). GraphClient's refresh-failure record carries exactly
     * method, status 401 and token_refresh 'failed'. Records are scanned as Laravel renders
     * them, not as json_encode() sees them (#5510).
     *
     * #5622: the TestHandler sees only configured channels; the MessageLogged list also sees an
     * on-demand logger (Log::build(), Log::stack(), the emergency logger). A Monolog Logger
     * built directly, or error_log(), reaches neither list and is not covered here.
     *
     * @param  array{0: string, 1: array<string, mixed>}|null  $tokenRecord
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_every_record_on_each_arm_is_read_and_carries_no_vendor_text(\Closure $tokenFailure, string $expected, string $arm, ?array $tokenRecord): void
    {
        $this->refreshFailure($tokenFailure());

        $expectedRecords = [];
        if ($tokenRecord !== null) {
            $expectedRecords[] = [Level::Error, $tokenRecord[0], $tokenRecord[1]];
        }
        $expectedRecords[] = [Level::Error, 'Graph API request failed', ['method' => 'GET', 'status' => 401, 'token_refresh' => 'failed']];

        $records = $this->logs->getRecords();
        $this->assertSame(
            $expectedRecords,
            array_map(fn (LogRecord $r) => [$r->level, $r->message, $r->context], $records),
            'every record on this arm, its level and its exact context',
        );
        // #5622: and no record went anywhere else through Laravel's logger.
        $this->assertSame(
            array_map(fn (array $r) => [$r[0]->toPsrLogLevel(), $r[1], $r[2]], $expectedRecords),
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
            'every record Laravel\'s logger wrote, on any channel or on-demand logger',
        );
        foreach ($records as $record) {
            $this->assertSame([], $record->extra);
            $this->assertCarriesNone($this->needles(), $this->render($record), "the rendered '{$record->message}' record");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailures')]
    public function test_the_token_failure_is_chained_as_previous_with_its_arms_message(\Closure $tokenFailure, string $expected): void
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
     * mailbox (GraphClient::authenticatedRequest's frame). That exposure is not the chain's.
     * #5614 / #5675: the test pins that every quoted string argument stringArguments() finds in
     * the previous link's frames (include/require frames excluded; an argument containing a
     * quote is split into pieces) is also found in the outer link's frames, and that no vendor
     * text appears anywhere. The
     * previous link does add its own file:line and frames (getToken() and its caller); their
     * string arguments are what is compared. The render is untruncated; PHP's default
     * zend.exception_string_param_max_len (15) prints only 'users/support@e'.
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
            $this->assertTrue(str_contains($previous, 'GraphTokenException(code: 0): '.addslashes($expected)), "{$mode}: previous link");
            $this->assertTrue(str_contains($previous, '[stacktrace]'), "{$mode}: previous trace");
            $this->assertTrue(str_contains($previous, 'GraphClient->getToken('), "{$mode}: previous trace frames");
            $this->assertSame($ignoreArgs === '0', str_contains($outer, "'users/".self::MAILBOX), "{$mode}: frame arguments render as configured");

            $this->assertCarriesNone($this->vendorNeedles(), $rendered, "{$mode}: the rendered chain");
            if ($ignoreArgs === '1') {
                $this->assertCarriesNone($this->mailboxNeedles(), $rendered, "{$mode}: the rendered chain");
                $this->assertSame([], self::stringArguments($rendered), "{$mode}: no frame prints a string argument");

                continue;
            }
            $previousArgs = self::stringArguments($previous);
            $this->assertContains("'users/".self::MAILBOX."/messages/MSG-1'", $previousArgs, "{$mode}: positive control: the previous trace prints string arguments");
            $this->assertSame([], array_values(array_diff($previousArgs, self::stringArguments($outer))), "{$mode}: string arguments only the previous link prints");
        }
    }

    /**
     * Every quoted string argument a rendered trace prints in its frames ("#n file(line):
     * Class->method('a', ...)"), deduplicated. include/require frames are skipped: PHP prints
     * the included file's path whatever exception_ignore_args says, and which of them appear
     * depends on the test runner's entry script.
     *
     * @return list<string>
     */
    private static function stringArguments(string $rendered): array
    {
        preg_match_all('/^#\d+ (?!.*: (?:include|require)(?:_once)?\().*$/m', $rendered, $frames);
        preg_match_all("/'(?:[^'\\\\]|\\\\.)*'/", implode("\n", $frames[0]), $args);

        return array_values(array_unique($args[0]));
    }

    /**
     * #5611 control: Http::preventStrayRequests() is on, so a request through Laravel's Http
     * facade throws StrayRequestException naming its URL instead of reaching the dead proxy.
     */
    public function test_a_facade_request_is_refused_as_stray(): void
    {
        try {
            Http::get('https://graph.microsoft.com/v1.0/b4h-stray-probe');
            $this->fail('a facade request was not refused');
        } catch (StrayRequestException $e) {
            $this->assertStringContainsString('b4h-stray-probe', $e->getMessage());
        }
    }

    /**
     * #5612: requestAbsolute() and requestJsonAbsolute() build a new Client per call. Each
     * nextLink below is answered from the scripted stack, with the seeded bearer, so both
     * per-call clients carry the scripted handler. Without it the request goes to the dead
     * proxy and the read throws.
     */
    public function test_the_per_call_clients_use_the_scripted_handler(): void
    {
        $next = 'https://graph.microsoft.com/v1.0/users/'.rawurlencode(self::MAILBOX).'/calendarView?$skip=1';
        $graph = $this->client(null,
            new Response(200, [], (string) json_encode(['value' => [['id' => 'm1']], '@odata.nextLink' => $next])),
            new Response(200, [], (string) json_encode(['value' => [['id' => 'm2']]])),
        );
        $this->assertSame(['m1', 'm2'], array_column($graph->getMailboxMessages(self::MAILBOX), 'id'), 'requestAbsolute() read page 2');
        $this->assertSame($next, (string) $this->history[1]['request']->getUri());
        // #5662: client() resets the history, so read this pass's bearers before the next one.
        $this->assertSame(
            ['Bearer '.self::SEEDED_ACCESS_FIXTURE, 'Bearer '.self::SEEDED_ACCESS_FIXTURE],
            array_map(fn (array $sent) => $sent['request']->getHeaderLine('Authorization'), $this->history),
            'requestAbsolute(): both pages carry the seeded bearer',
        );
        $this->assertScriptedHandler($graph, 'after the requestAbsolute() reads');

        $graph = $this->client(null,
            new Response(200, [], (string) json_encode(['value' => [], '@odata.nextLink' => $next])),
            new Response(200, [], (string) json_encode(['value' => []])),
        );
        $this->assertSame([], $graph->calendarView(self::MAILBOX, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z'), 'requestJsonAbsolute() read page 2');
        $this->assertSame($next, (string) $this->history[1]['request']->getUri());

        $this->assertSame(
            ['Bearer '.self::SEEDED_ACCESS_FIXTURE, 'Bearer '.self::SEEDED_ACCESS_FIXTURE],
            array_map(fn (array $sent) => $sent['request']->getHeaderLine('Authorization'), $this->history),
            'requestJsonAbsolute(): both pages carry the seeded bearer',
        );
        $this->assertScriptedHandler($graph, 'after the requestJsonAbsolute() reads');
    }

    /**
     * #5621 positive control: each credential needle is on the wire GraphClient sends (the
     * bearer on the Graph request, the client_id in the token form), and the scans fire on it.
     */
    public function test_the_credential_needles_are_what_graph_client_sends(): void
    {
        $e = $this->refreshFailure(self::echoingTokenResponse(400, ['error' => 'invalid_client']));

        $graphRequest = $this->history[0]['request'];
        // #5676: the wire is what the requests carry, header names included, with no text the
        // test adds itself.
        $headers = '';
        foreach ($graphRequest->getHeaders() as $name => $values) {
            $headers .= $name.': '.implode(', ', $values)."\n";
        }
        $wire = $headers.(string) $this->tokenRequests()[0]['request']->getBody();
        $this->assertSame('Bearer '.self::SEEDED_ACCESS_FIXTURE, $graphRequest->getHeaderLine('Authorization'));
        $this->assertStringContainsString('client_id='.self::CLIENT_ID, $wire);

        $leaky = $this->reported(new GraphTokenRefreshFailedException('GET', new GraphClientException($wire)));
        foreach ($this->credentialNeedles() as $needle) {
            $this->assertContains($needle, $this->needles(), 'the scans use it');
            $this->assertStringContainsString($needle, $wire, 'the needle is on the wire');
            $this->assertFalse(str_contains($this->reported($e), $needle), "the real chain carries no '{$needle}'");
            // #5664: the helper the arm tests rely on fails on the leaky render, naming this needle.
            try {
                $this->assertCarriesNone([$needle], $leaky, 'the leaky render');
                $this->fail("assertCarriesNone() did not fire on '{$needle}'");
            } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                $this->assertStringContainsString("the leaky render carries '{$needle}'", $failure->getMessage());
            }
        }
        // #5664: and with the full needle list, as the arm tests call it.
        try {
            $this->assertCarriesNone($this->needles(), $leaky, 'the leaky render');
            $this->fail('assertCarriesNone() did not fire on the full list');
        } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
            $this->assertStringContainsString('the leaky render carries', $failure->getMessage());
        }
    }

    /**
     * #5622 control: a record written through an on-demand logger (Log::build()) never reaches
     * the TestHandler, and the MessageLogged list does see it.
     */
    public function test_the_record_list_sees_an_on_demand_logger(): void
    {
        Log::build(['driver' => 'monolog', 'handler' => NullHandler::class])->error('b4h on-demand probe', ['k' => 'v']);

        $this->assertSame([], $this->logs->getRecords(), 'the TestHandler alone misses it');
        $this->assertSame([['error', 'b4h on-demand probe', ['k' => 'v']]], array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged));
    }

    /**
     * #5613 control: reported() goes through Laravel's ExceptionHandler, which merges an
     * exception's own context() into the record, so the scan sees what context() adds.
     */
    public function test_the_reporter_render_includes_the_exceptions_own_context(): void
    {
        $withContext = new class('status-only') extends GraphClientException
        {
            /** @return array<string, string> */
            public function context(): array
            {
                return ['endpoint' => 'users/support@example.test/b4h-context-probe'];
            }
        };

        $this->assertStringContainsString('b4h-context-probe', $this->reported($withContext));
    }

    /** Control for the renderer above: vendor text on a previous link IS seen; json_encode() would not see it. */
    public function test_the_renderer_walks_the_previous_chain(): void
    {
        $leaky = new GraphTokenRefreshFailedException('GET', new GraphClientException('Failed: '.self::IDP_MARKER));

        $this->assertTrue(str_contains($this->reported($leaky), self::IDP_MARKER), 'the reporter render shows a previous link\'s message');
        $this->assertFalse(str_contains((string) json_encode(['exception' => $leaky]), self::IDP_MARKER), 'json_encode() renders a Throwable as {}');
    }
}
