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
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * #5671 / C-56: the 'Graph API request failed' record carries an endpoint-free 'operation'
 * label: the canonical spelling of an allowlisted Graph noun or action, the last one among the
 * path's segments matched case-insensitively (#6068), or 'other'. The value is always one of
 * those literals, so it never copies a mailbox, the query, the fragment or a nextLink host
 * (protocol-relative included, #6079). An id that equals a noun can still be picked by the
 * last-match rule (#6078); the label is then a wrong literal, not endpoint text.
 *
 * G-5: GraphClient runs over a scripted MockHandler (the per-call nextLink client too) with
 * Http::preventStrayRequests() on. Every value is synthetic (G-13). Records are read from
 * MessageLogged, which sees every level and every channel written through Laravel's logger.
 */
class GraphClientOperationLabelTest extends TestCase
{
    use FlattensLogContext;

    private const MAILBOX = 'someone@example.test';

    private const MESSAGE_ID = 'AAMkSYNTHETIC5671MSGID';

    private const SKIP_CURSOR = 'SKIPTOKEN5671SYNTHETIC';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

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

    private function graph(Response ...$responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'oplabel-5671-synthetic-access', 3600);

        return new GraphClient([
            'tenant_id' => 'tenant-5671-synthetic',
            'client_id' => 'client-5671',
            'client_secret' => 'oplabel-5671-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache);
    }

    private static function page(string $nextLink): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['value' => [], '@odata.nextLink' => $nextLink]));
    }

    private static function failure(int $status): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['code' => 'x', 'message' => 'for '.self::MAILBOX]]));
    }

    /** @return list<array<string, mixed>> the context of each 'Graph API request failed' record */
    private function failedContexts(): array
    {
        return array_values(array_map(
            fn (MessageLogged $m) => $m->context,
            array_filter($this->logged, fn (MessageLogged $m) => $m->message === 'Graph API request failed'),
        ));
    }

    /** @return list<string> the needles any record carries, in its message or flattened context */
    private function needlesInAnyRecord(array $needles): array
    {
        $text = implode(' ', array_map(fn (MessageLogged $m) => $m->message.' '.self::flattenForScan($m->context), $this->logged));

        return array_values(array_filter($needles, fn (string $n) => str_contains($text, $n)));
    }

    /**
     * [method, endpoint, label]. Most rows are shapes GraphClient's callers build; the rows
     * after 'hosted content' are synthetic probes of the query and fragment cut, case folding,
     * the 'other' fallback and an id that only contains a noun (#6073). A query in an endpoint
     * passed to get() is not sent (get() replaces it with ['query' => $params]); it reaches the
     * label only as written, which is what these rows exercise.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function endpoints(): array
    {
        $m = self::MAILBOX;
        $id = self::MESSAGE_ID;

        return [
            'inbox list' => ['GET', "users/{$m}/mailFolders/inbox/messages", 'messages'],
            'message read' => ['GET', "users/{$m}/messages/{$id}", 'messages'],
            'attachment' => ['GET', "users/{$m}/messages/{$id}/attachments/ATT-1", 'attachments'],
            'sendMail' => ['POST', "users/{$m}/sendMail", 'sendMail'],
            'reply' => ['POST', "users/{$m}/messages/{$id}/reply", 'reply'],
            'photo' => ['GET', "users/{$m}/photo/\$value", 'photo'],
            'event' => ['GET', "users/{$m}/events/EVT-1", 'events'],
            'subscription' => ['PATCH', 'subscriptions/SUB-5671-synthetic', 'subscriptions'],
            'subscriptions' => ['POST', 'subscriptions', 'subscriptions'],
            'chat message' => ['GET', 'chats/19:synthetic5671@thread.v2/messages/1700000000000', 'messages'],
            'hosted content' => ['GET', 'chats/CHAT-1/messages/MSG-1/hostedContents/HC-1/$value', 'hostedContents'],
            // #6069: every allowlisted noun is produced by at least one row
            // (test_every_allowlisted_noun_is_produced_by_a_row checks it).
            'mail folder' => ['GET', "users/{$m}/mailFolders/inbox", 'mailFolders'],
            'schedule' => ['POST', "users/{$m}/calendar/getSchedule", 'calendar'],
            'calendar view' => ['GET', "users/{$m}/calendarView", 'calendarView'],
            'chat' => ['GET', 'chats/CHAT-1', 'chats'],
            'team' => ['GET', 'teams/TEAM-1', 'teams'],
            'channel' => ['GET', 'teams/TEAM-1/channels/CHAN-1', 'channels'],
            'group' => ['GET', 'groups/GRP-1', 'groups'],
            // #6066: the query is cut off. Without the cut, '?x=/messages' would split into a
            // later 'messages' segment, so this row kills a mutant that drops the cut.
            'query after a bare noun' => ['GET', 'subscriptions?x=/messages', 'subscriptions'],
            'query after an id' => ['GET', "users/{$m}/events/EVT-1?x=/attachments", 'events'],
            // #6067: the fragment is cut off the same way.
            'fragment after an id' => ['GET', "users/{$m}/events/EVT-1#/attachments", 'events'],
            // #6068: segments match case-insensitively and the label is the canonical spelling.
            'capitalised webhook resource' => ['GET', "Users/{$m}/Messages/{$id}", 'messages'],
            'upper case' => ['GET', "USERS/{$m}/MAILFOLDERS/inbox", 'mailFolders'],
            'mixed case action' => ['POST', "users/{$m}/SENDMAIL", 'sendMail'],
            'lower case of a camel noun' => ['GET', 'chats/CHAT-1/messages/MSG-1/hostedcontents/HC-1', 'hostedContents'],
            // No allowlisted noun: the literal 'other', not a segment.
            'no noun' => ['GET', "organization/{$id}/branding", 'other'],
            'id that contains a noun' => ['GET', 'users/messages-5671/notAResource', 'users'],
        ];
    }

    /**
     * #6086: every id in endpoints() and the request's other synthetic values; none may reach
     * any record.
     *
     * @return list<string>
     */
    private static function endpointNeedles(): array
    {
        return [self::MAILBOX, self::MESSAGE_ID, 'users/', 'example.test', '?', '#', '$', 'SUB-5671', 'synthetic5671', 'thread.v2',
            '1700000000000', 'CHAT-1', 'MSG-1', 'HC-1', 'EVT-1', 'ATT-1', 'TEAM-1', 'CHAN-1', 'GRP-1', 'messages-5671', 'notAResource',
            'branding', 'inbox', 'getSchedule', 'Users', 'USERS', 'Messages'];
    }

    #[DataProvider('endpoints')]
    public function test_the_failed_request_record_names_an_allowlisted_operation_or_other(string $method, string $endpoint, string $label): void
    {
        $graph = $this->graph(self::failure(500));
        try {
            match ($method) {
                'GET' => $graph->get($endpoint),
                'POST' => $graph->post($endpoint, ['x' => 1]),
                'PATCH' => $graph->patch($endpoint, ['x' => 1]),
            };
            $this->fail('no throw');
        } catch (GraphClientException $e) {
            $this->assertSame("Graph API error: {$method} returned 500", $e->getMessage(), 'the message is unchanged');
        }
        $this->assertCount(1, $this->history, 'positive control: the request was sent');

        // #6086: the only record, at ERROR, with its exact context.
        $this->assertSame(
            [['error', 'Graph API request failed', ['method' => $method, 'operation' => $label, 'status' => 500]]],
            array_map(fn (MessageLogged $m) => [$m->level, $m->message, $m->context], $this->logged),
        );
        $this->assertSame([], $this->needlesInAnyRecord(self::endpointNeedles()));
    }

    /**
     * #6069: each OPERATION_NOUNS entry is the expected label of at least one endpoints() row,
     * so a typo in or deletion of any entry turns that row red. 'other' is produced too.
     */
    public function test_every_allowlisted_noun_is_produced_by_a_row(): void
    {
        $nouns = (new \ReflectionClassConstant(GraphClient::class, 'OPERATION_NOUNS'))->getValue();
        $produced = array_unique(array_column(self::endpoints(), 2));
        $this->assertSame([], array_values(array_diff($nouns, $produced)), 'nouns no row produces');
        $this->assertSame(['other'], array_values(array_diff($produced, $nouns)), 'labels outside the allowlist');
        $this->assertCount(16, $nouns, 'positive control: the allowlist as #5671 landed it');
    }

    /**
     * #6079: a protocol-relative nextLink ('//host/...') has its authority dropped too, so a
     * single-label host spelled as a noun ('teams') is not the label. Guzzle sends it as
     * http://teams/... (positive control below). This also kills a mutant that removes the
     * authority strip without resting on the https single-label test (#6072).
     */
    public function test_a_protocol_relative_next_link_host_is_not_the_label(): void
    {
        $graph = $this->graph(self::page('//teams/v1.0/organization/ORG-5671/branding'), self::failure(500));
        try {
            $graph->getAllPages('users/'.self::MAILBOX.'/messages');
            $this->fail('no throw');
        } catch (GraphClientException) {
        }
        $this->assertSame('teams', $this->history[1]['request']->getUri()->getHost(), 'positive control: the nextLink host was requested');
        $this->assertSame([['method' => 'GET', 'operation' => 'other', 'status' => 500]], $this->failedContexts());
    }

    /**
     * #6080: the calendar paginator's nextLink page (requestJsonAbsolute) labels its failure
     * from the proven graph.microsoft.com URL: 'calendarView', with no host, mailbox or cursor.
     */
    public function test_a_calendar_next_link_failure_is_labelled_calendar_view(): void
    {
        $next = sprintf('https://graph.microsoft.com/v1.0/users/%s/calendarView?$skiptoken=%s', self::MAILBOX, self::SKIP_CURSOR);
        $graph = $this->graph(self::page($next), self::failure(503));
        try {
            $graph->calendarView(self::MAILBOX, '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z');
            $this->fail('no throw');
        } catch (GraphClientException) {
        }
        $this->assertCount(2, $this->history, 'positive control: the nextLink was requested');
        $this->assertSame([['method' => 'GET', 'operation' => 'calendarView', 'status' => 503]], $this->failedContexts());
        $this->assertSame([], $this->needlesInAnyRecord([self::MAILBOX, self::SKIP_CURSOR, 'graph.microsoft.com', 'v1.0']));
    }

    /**
     * The needle case from the brief: a mailbox-bearing message path and an absolute nextLink on
     * another host give labels carrying neither the mailbox, the id nor the host. The query
     * carries a later noun ('/attachments'), so a label taken from it would show. The dotted
     * host is one segment that never equals a noun, so this test does not exercise the
     * authority strip (#6072); the single-label and protocol-relative host tests do.
     */
    public function test_a_next_link_label_ignores_the_host_and_the_query(): void
    {
        $host = 'calendar.chats.example.test';
        $next = sprintf('https://%s/v1.0/users/%s/messages?$skiptoken=%s&x=/attachments', $host, self::MAILBOX, self::SKIP_CURSOR);
        $graph = $this->graph(self::page($next), self::failure(503));
        try {
            $graph->getAllPages('users/'.self::MAILBOX.'/messages/'.self::MESSAGE_ID);
            $this->fail('no throw');
        } catch (GraphClientException) {
        }
        $this->assertCount(2, $this->history);
        $this->assertSame($host, $this->history[1]['request']->getUri()->getHost(), 'positive control: the nextLink host was requested');

        $this->assertSame([['method' => 'GET', 'operation' => 'messages', 'status' => 503]], $this->failedContexts());
        $this->assertSame([], $this->needlesInAnyRecord([self::MAILBOX, self::MESSAGE_ID, self::SKIP_CURSOR, $host, 'example.test', 'https:', 'v1.0']));
    }

    /**
     * The host is dropped before matching. Segments are matched exactly, so a host with dots can
     * never equal a noun; a single-label host spelled as one (synthetic 'teams') with no noun in
     * the path would otherwise become the label. It must be 'other'.
     */
    public function test_a_next_link_host_spelled_as_a_noun_is_not_the_label(): void
    {
        $graph = $this->graph(self::page('https://teams/v1.0/organization/ORG-5671/branding'), self::failure(500));
        try {
            $graph->getAllPages('users/'.self::MAILBOX.'/messages');
            $this->fail('no throw');
        } catch (GraphClientException) {
        }
        $this->assertSame('teams', $this->history[1]['request']->getUri()->getHost(), 'positive control: the nextLink host was requested');
        $this->assertSame([['method' => 'GET', 'operation' => 'other', 'status' => 500]], $this->failedContexts());
    }

    /** No response (status 0) keeps the exception class beside the label. */
    public function test_the_no_response_record_carries_the_label_and_the_exception_class(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('cURL error 7 for users/'.self::MAILBOX.'/messages/'.self::MESSAGE_ID, new \GuzzleHttp\Psr7\Request('GET', 'x')),
        ]));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'oplabel-5671-synthetic-access', 3600);
        $graph = new GraphClient(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'request_timeout' => 5, 'token_timeout' => 5, 'handler' => $stack], $cache);
        try {
            $graph->get('users/'.self::MAILBOX.'/messages/'.self::MESSAGE_ID.'/attachments');
            $this->fail('no throw');
        } catch (GraphClientException) {
        }

        $this->assertSame([['method' => 'GET', 'operation' => 'attachments', 'status' => 0, 'exception' => \GuzzleHttp\Exception\ConnectException::class]], $this->failedContexts());
        $this->assertSame([], $this->needlesInAnyRecord([self::MAILBOX, self::MESSAGE_ID, 'cURL']));
    }

    /** Positive control: the needle scan sees a mailbox, an id and a host in a planted context. */
    public function test_the_needle_scan_fires_on_a_planted_endpoint(): void
    {
        $this->logged = [new MessageLogged('error', 'Graph API request failed', ['operation' => 'https://h.example.test/users/'.self::MAILBOX.'/messages/'.self::MESSAGE_ID])];
        $this->assertSame([self::MAILBOX, self::MESSAGE_ID, 'example.test'], $this->needlesInAnyRecord([self::MAILBOX, self::MESSAGE_ID, 'example.test', 'cURL']));
    }
}
