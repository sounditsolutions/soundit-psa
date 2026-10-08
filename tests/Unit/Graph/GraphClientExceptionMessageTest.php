<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
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
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * #5679 / C-56: the GraphClientException messages GraphClient throws carry the method and the
 * status (or, with no response, that none was received; #5722), never the endpoint, the mailbox
 * or the nextLink URL. A caller or Laravel's reporter
 * logs that message, so it must not carry what GraphClient's own record leaves out.
 * getHttpStatus() and getResponseBody() are unchanged.
 *
 * #5661: throwFromGuzzle's record on a failure with no response (status 0) is pinned too: the
 * method, the operation label (#5671), status 0 and the Guzzle exception class (#5673),
 * nothing else (#6071). It is read from the
 * configured channels' TestHandler and from a MessageLogged listener, which also sees an
 * on-demand or stacked logger (#5742, as #5622), and scanned through FlattensLogContext so a
 * 'users/' value or a Throwable in context is visible (#5733).
 *
 * G-5: a scripted MockHandler, Http::preventStrayRequests(), synthetic values only (G-13).
 */
class GraphClientExceptionMessageTest extends TestCase
{
    use FlattensLogContext;

    private const MAILBOX = 'support@example.test';

    private const GRAPH_ERROR_TEXT = 'B4J-SYNTHETIC-GRAPH-ERROR-TEXT';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private TestHandler $logs;

    /** @var list<MessageLogged> every record Laravel's Logger wrapper wrote, on any logger (#5742) */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->logs = new TestHandler;
        foreach (array_keys(config('logging.channels')) as $name) {
            config(["logging.channels.{$name}" => [
                'driver' => 'custom',
                'via' => fn () => new \Monolog\Logger($name, [$this->logs]),
            ]]);
            Log::forgetChannel($name);
        }
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logged[] = $m;
        });
    }

    private function graph(Response|\Throwable ...$responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'b4j-synthetic-seeded-access', 3600);

        return new GraphClient([
            'tenant_id' => 'tenant-b4j-synthetic',
            'client_id' => 'b4j-client',
            'client_secret' => 'b4j-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache);
    }

    /** @return list<string> */
    private static function mailboxNeedles(): array
    {
        return [self::MAILBOX, rawurlencode(self::MAILBOX), 'support@', 'support%40', 'users/', 'graph.microsoft.com', '$skip', self::GRAPH_ERROR_TEXT];
    }

    /** @return list<string> Guzzle's own text, looked for in a record's message and context alike (#5778) */
    private static function guzzleNeedles(): array
    {
        return ['cURL'];
    }

    /** @return list<string> the needles $text carries */
    private static function carried(string $text): array
    {
        return array_values(array_filter(self::mailboxNeedles(), fn (string $n) => str_contains($text, $n)));
    }

    private static function nextLink(): string
    {
        return 'https://graph.microsoft.com/v1.0/users/'.rawurlencode(self::MAILBOX).'/calendarView?$skip=10';
    }

    private static function graph500(): Response
    {
        return new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]]));
    }

    private static function page(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['value' => [], '@odata.nextLink' => self::nextLink()]));
    }

    /**
     * [call, scripted responses, expected message, expected getHttpStatus()].
     *
     * @return array<string, array{0: \Closure(GraphClient): mixed, 1: \Closure(): list<Response|\Throwable>, 2: string, 3: int}>
     */
    public static function failures(): array
    {
        $mailbox = self::MAILBOX;

        return [
            'throwFromGuzzle, response (get)' => [fn (GraphClient $g) => $g->get("users/{$mailbox}/messages"), fn () => [self::graph500()], 'Graph API error: GET returned 500', 500],
            'throwFromGuzzle, response (post)' => [fn (GraphClient $g) => $g->post("users/{$mailbox}/sendMail", ['x' => 1]), fn () => [self::graph500()], 'Graph API error: POST returned 500', 500],
            'throwFromGuzzle, no response' => [fn (GraphClient $g) => $g->getMessageAttachments($mailbox, 'MSG-1'), fn () => [
                new ConnectException('cURL error 7: refused for https://graph.microsoft.com/v1.0/users/'.$mailbox.'/messages/MSG-1', new Request('GET', 'users/'.$mailbox.'/messages/MSG-1')),
            ], 'Graph API error: GET received no response', 0],
            'throwFromGuzzle, nextLink page (requestAbsolute)' => [fn (GraphClient $g) => $g->getAllPages("users/{$mailbox}/messages"), fn () => [self::page(), self::graph500()], 'Graph API error: GET returned 500', 500],
            'throwFromGuzzle, nextLink page (requestJsonAbsolute)' => [fn (GraphClient $g) => $g->calendarView($mailbox, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z'), fn () => [self::page(), self::graph500()], 'Graph API error: GET returned 500', 500],
            'invalid JSON (request)' => [fn (GraphClient $g) => $g->get("users/{$mailbox}/messages"), fn () => [new Response(200, [], 'not json')], 'Invalid JSON response from Graph API: GET returned 200', 200],
            'invalid JSON (requestJson)' => [fn (GraphClient $g) => $g->getEvent($mailbox, 'EVT-1'), fn () => [new Response(200, [], 'not json')], 'Invalid JSON response from Graph API: GET returned 200', 200],
            'invalid JSON (requestAbsolute)' => [fn (GraphClient $g) => $g->getAllPages("users/{$mailbox}/messages"), fn () => [self::page(), new Response(200, [], 'not json')], 'Invalid JSON response from Graph API: GET returned 200', 200],
            'invalid JSON (requestJsonAbsolute)' => [fn (GraphClient $g) => $g->calendarView($mailbox, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z'), fn () => [self::page(), new Response(200, [], 'not json')], 'Invalid JSON response from Graph API: GET returned 200', 200],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_the_exception_message_carries_method_and_status_only(\Closure $call, \Closure $responses, string $expected, int $status): void
    {
        $graph = $this->graph(...$responses());
        try {
            $call($graph);
            $this->fail('the failing Graph call did not throw');
        } catch (GraphClientException $e) {
            $this->assertSame($expected, $e->getMessage());
            $this->assertSame([], self::carried($e->getMessage()), 'the message carries no endpoint, mailbox or Graph text');
            $this->assertSame($status, $e->getCode());
        }

        // Positive control: the request that failed did carry the mailbox in its URI, so the
        // scan above had something to find had the message copied it.
        $sent = urldecode((string) end($this->history)['request']->getUri());
        $this->assertContains(self::MAILBOX, self::carried($sent), $sent);
    }

    /** getHttpStatus() and getResponseBody() are unchanged by #5679. */
    public function test_status_and_response_body_are_kept(): void
    {
        $graph = $this->graph(self::graph500());
        try {
            $graph->get('users/'.self::MAILBOX.'/messages');
            $this->fail('no throw');
        } catch (GraphClientException $e) {
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertSame(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]], $e->getResponseBody());
        }
    }

    /** @return list<array{0: string, 1: list<string>}> [level message, needles] for each MessageLogged record that carries one */
    private function recordsCarryingANeedle(): array
    {
        $out = [];
        foreach ($this->logged as $m) {
            $text = $m->message.' '.self::flattenForScan($m->context);
            $carried = [...self::carried($text), ...array_values(array_filter(self::guzzleNeedles(), fn (string $n) => str_contains($text, $n)))];
            if ($carried !== []) {
                $out[] = ["{$m->level} {$m->message}", $carried];
            }
        }

        return $out;
    }

    /**
     * #5661 / #5673: on a failure with no response, throwFromGuzzle writes exactly one record:
     * the method, the operation label (#5671; 'messages', the query cut off), status 0 and the
     * Guzzle exception class. Guzzle's message (cURL text and the
     * request URI) is in no part of it, and no other record is written on any logger (#5742).
     */
    public function test_the_no_response_record_is_method_operation_status_zero_and_exception_class(): void
    {
        $graph = $this->graph(new ConnectException('cURL error 7: refused for https://graph.microsoft.com/v1.0/users/'.self::MAILBOX.'/messages/MSG-1', new Request('GET', 'x')));
        try {
            $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');
            $this->fail('no throw');
        } catch (GraphClientException) {
        }

        $records = $this->logs->getRecords();
        $this->assertSame(
            [['ERROR', 'Graph API request failed', ['method' => 'GET', 'operation' => 'messages', 'status' => 0, 'exception' => ConnectException::class]]],
            array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message, $r->context], $records),
        );
        $this->assertSame(
            [['error', 'Graph API request failed', ['method' => 'GET', 'operation' => 'messages', 'status' => 0, 'exception' => ConnectException::class]]],
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
            'every record on any logger (#5742)',
        );
        $this->assertSame([], $this->recordsCarryingANeedle());
        $this->assertNotNull($this->history[0]['error'] ?? null, 'positive control: the request was rejected without a response');
    }

    /**
     * #5742 / #5780 positive control: a record written through an on-demand logger built with
     * its own handler (Log::build()), so it is outside the configured channels and never
     * reaches the TestHandler, still reaches the MessageLogged list and the scan.
     */
    public function test_the_record_list_sees_an_on_demand_logger(): void
    {
        Log::build(['driver' => 'monolog', 'handler' => NullHandler::class])->error('Graph API request failed', ['endpoint' => 'users/'.self::MAILBOX.'/messages']);
        $this->assertSame([], $this->logs->getRecords(), 'the TestHandler alone misses it');
        $this->assertSame(['error Graph API request failed'], array_map(fn (MessageLogged $m) => "{$m->level} {$m->message}", $this->logged));
        $this->assertSame([['error Graph API request failed', [self::MAILBOX, 'support@', 'users/']]], $this->recordsCarryingANeedle());
    }

    /** #5778 control: Guzzle's cURL text in a record's message, with nothing in its context, turns the scan red. */
    public function test_the_record_scan_fails_on_curl_text_in_the_message(): void
    {
        $this->logged = [new MessageLogged('error', 'Graph API request failed: cURL error 7: connection refused', ['method' => 'GET'])];
        $this->assertSame([['error Graph API request failed: cURL error 7: connection refused', ['cURL']]], $this->recordsCarryingANeedle());
    }

    /**
     * #5733 positive control: the scan goes red on the endpoint in a context and on the Guzzle
     * exception in a context. json_encode() sees neither ('\/' escaping; a Throwable is {}).
     */
    public function test_the_record_scan_fails_on_an_endpoint_or_a_throwable_in_context(): void
    {
        $endpoint = ['method' => 'GET', 'status' => 0, 'endpoint' => 'users/x/messages'];
        $thrown = ['method' => 'GET', 'status' => 0, 'error' => new ConnectException('cURL error 7: refused for users/x/messages', new Request('GET', 'x'))];
        foreach (['endpoint' => [$endpoint, ['users/']], 'Throwable' => [$thrown, ['users/', 'cURL']]] as $name => [$context, $expected]) {
            $this->assertSame([], self::carried((string) json_encode($context)), "{$name}: json_encode cannot see it");
            $this->logged = [new MessageLogged('error', 'Graph API request failed', $context)];
            $this->assertSame([['error Graph API request failed', $expected]], $this->recordsCarryingANeedle(), $name);
        }
    }

    /** Control: the needle scan fires on the message shape #5679 removed. */
    public function test_the_needle_scan_fires_on_the_old_message_shape(): void
    {
        $old = 'Graph API error: GET users/'.self::MAILBOX.'/messages returned 500';
        $this->assertContains(self::MAILBOX, self::carried($old));
        $this->assertContains('$skip', self::carried('Invalid JSON response from Graph API: GET '.self::nextLink()));
    }
}
