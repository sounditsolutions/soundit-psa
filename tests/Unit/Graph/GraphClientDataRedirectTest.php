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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * #5824: the Graph data clients (the constructor's $http and the per-call clients that follow an
 *
 * @odata.nextLink) never follow a redirect. A 3xx is refused by its status alone: one request,
 * a GraphClientException "Graph API error: <method> returned <status>" with that status, and
 * a 'Graph API request failed' record with the method, the operation label and the status
 * (#6075: the same keys throwFromGuzzle's record carries, without its status-0 exception
 * class). The Location appears in no
 * message or record, a 307/308 POST body is never replayed, and an unparseable Location no
 * longer escapes as psr7's MalformedUriException (which is not a GuzzleException).
 *
 * G-5: every request goes to a scripted MockHandler, and both constructor clients are checked
 * to carry it before any request. Synthetic values only (G-13).
 */
class GraphClientDataRedirectTest extends TestCase
{
    use FlattensLogContext;

    private const MAILBOX = 'b4j2.mailbox@example.test';

    private const REDIRECT_HOST = 'b4j2-redirect.example.test';

    private const REDIRECT_TARGET = 'https://'.self::REDIRECT_HOST.'/v1.0/users/elsewhere';

    private const UNPARSEABLE_LOCATION = 'http://[::1/users/b4j2';

    /** Carried in a POST body, so a replayed body is visible in the history. */
    private const BODY_MARKER = 'B4J2-SYNTHETIC-POST-BODY';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logged[] = $m;
        });
    }

    private function graph(Response ...$responses): GraphClient
    {
        $this->history = [];
        $this->logged = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'b4j2-synthetic-cached-bearer', 3600);

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

    private static function ok(array $body = ['id' => 'MSG-1']): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** @return array<string, array{0: int, 1: array<string, string>}> */
    public static function redirects(): array
    {
        return [
            '302 another host' => [302, ['Location' => self::REDIRECT_TARGET]],
            '307 another host' => [307, ['Location' => self::REDIRECT_TARGET]],
            '308 another host' => [308, ['Location' => self::REDIRECT_TARGET]],
            '301 another host' => [301, ['Location' => self::REDIRECT_TARGET]],
            '302 unparseable Location' => [302, ['Location' => self::UNPARSEABLE_LOCATION]],
            '302 without Location' => [302, []],
            '304 not modified' => [304, []],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: \Closure(GraphClient): mixed, 2: string}> [method, call, label]. One per
     *                                                                                     public data path; the
     *                                                                                     nextLink case reaches
     *                                                                                     the per-call client.
     */
    private static function calls(): array
    {
        return [
            'get' => ['GET', fn (GraphClient $g) => $g->get('users/'.self::MAILBOX.'/messages/MSG-1'), 'messages'],
            'post (sendMail body)' => ['POST', fn (GraphClient $g) => $g->post('users/'.self::MAILBOX.'/sendMail', ['message' => ['subject' => self::BODY_MARKER]]), 'sendMail'],
            'getRaw' => ['GET', fn (GraphClient $g) => $g->getRaw('users/'.self::MAILBOX.'/photo/$value'), 'photo'],
            'getMessageAttachmentRaw' => ['GET', fn (GraphClient $g) => $g->getMessageAttachmentRaw(self::MAILBOX, 'MSG-1', 'ATT-1'), 'attachments'],
            'createEvent (requestJson)' => ['POST', fn (GraphClient $g) => $g->createEvent(self::MAILBOX, ['subject' => self::BODY_MARKER]), 'events'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirects')]
    public function test_a_graph_data_request_refuses_a_redirect_by_its_status(int $status, array $headers): void
    {
        foreach (self::calls() as $name => [$method, $call, $label]) {
            $graph = $this->graph(new Response($status, $headers, ''), self::ok(), self::ok());
            try {
                $call($graph);
                $this->fail("{$name}: expected a GraphClientException");
            } catch (GraphClientException $e) {
                $this->assertSame(GraphClientException::class, $e::class, $name);
                $this->assertSame("Graph API error: {$method} returned {$status}", $e->getMessage(), $name);
                $this->assertSame($status, $e->getHttpStatus(), $name);
            }
            $this->assertCount(1, $this->history, "{$name}: one request, nothing followed");
            $this->assertSame('graph.microsoft.com', $this->history[0]['request']->getUri()->getHost(), $name);
            $this->assertSame($status, $this->history[0]['response']->getStatusCode(), "{$name}: positive control: the 3xx itself was read");

            $records = array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged);
            $expected = $name === 'getMessageAttachmentRaw' ? [] : [['error', 'Graph API request failed', ['method' => $method, 'operation' => $label, 'status' => $status]]];
            $this->assertSame($expected, $records, "{$name}: the record is a failed request's");

            $scan = $e->getMessage().' '.implode(' ', array_map(fn (MessageLogged $m) => $m->message.' '.self::flattenForScan($m->context), $this->logged));
            foreach ([self::REDIRECT_HOST, '[::1', 'Location', self::MAILBOX, 'elsewhere'] as $needle) {
                $this->assertStringNotContainsString($needle, $scan, "{$name}: carries '{$needle}'");
            }
        }
    }

    /** #5824: a 3xx on an @odata.nextLink page is refused the same way by the per-call client. */
    public function test_a_next_link_page_refuses_a_redirect_by_its_status(): void
    {
        $page1 = self::ok(['value' => [['id' => 'A']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/'.self::MAILBOX.'/messages?$skip=1']);
        $graph = $this->graph($page1, new Response(307, ['Location' => self::REDIRECT_TARGET], ''), self::ok());
        try {
            $graph->getAllPages('users/'.self::MAILBOX.'/messages');
            $this->fail('expected a GraphClientException');
        } catch (GraphClientException $e) {
            $this->assertSame('Graph API error: GET returned 307', $e->getMessage());
            $this->assertSame(307, $e->getHttpStatus());
        }
        $this->assertCount(2, $this->history, 'the first page and the refused nextLink; nothing followed');
        $this->assertSame(
            [['error', 'Graph API request failed', ['method' => 'GET', 'operation' => 'messages', 'status' => 307]]],
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
        );
    }

    /** #5824: the same on a calendarView nextLink page (requestJsonAbsolute). */
    public function test_a_calendar_next_link_page_refuses_a_redirect_by_its_status(): void
    {
        $page1 = self::ok(['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/'.self::MAILBOX.'/calendarView?$skip=1']);
        $graph = $this->graph($page1, new Response(302, ['Location' => self::UNPARSEABLE_LOCATION], ''), self::ok());
        try {
            $graph->calendarView(self::MAILBOX, '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
            $this->fail('expected a GraphClientException');
        } catch (GraphClientException $e) {
            $this->assertSame('Graph API error: GET returned 302', $e->getMessage());
        }
        $this->assertCount(2, $this->history);
        // #6075: the refused requestJsonAbsolute page's record names the calendarView operation.
        $this->assertSame(
            [['error', 'Graph API request failed', ['method' => 'GET', 'operation' => 'calendarView', 'status' => 302]]],
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
        );
    }

    /**
     * #5824 positive control: the fixtures exercise the hazard. With redirects back on, the
     * same 307 is followed to the other host with the POST body, so the refusal above is what
     * stops it.
     */
    public function test_a_following_client_would_replay_the_post_body(): void
    {
        $graph = $this->graph(new Response(307, ['Location' => self::REDIRECT_TARGET], ''), self::ok());
        $http = (new \ReflectionProperty(GraphClient::class, 'http'))->getValue($graph);
        $this->assertFalse($http->getConfig('allow_redirects'));

        $http->request('POST', 'users/'.self::MAILBOX.'/sendMail', ['json' => ['subject' => self::BODY_MARKER], 'allow_redirects' => ['max' => 5, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https'], 'track_redirects' => false]]);
        $this->assertCount(2, $this->history);
        $this->assertSame(self::REDIRECT_HOST, $this->history[1]['request']->getUri()->getHost());
        $this->assertStringContainsString(self::BODY_MARKER, (string) $this->history[1]['request']->getBody());
    }
}
