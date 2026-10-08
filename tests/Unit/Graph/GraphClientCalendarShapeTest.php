<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphShapeDriftException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirms GraphClient WIRES the fail-loud CalendarGraphShapes validator on the wire path
 * (psa-abl0i.2) — raw vendor-shape JSON in, GraphShapeDriftException out on drift, never a
 * silent empty/all-free grid. The validator's exhaustive drift cases live in
 * CalendarGraphShapesTest; this proves the transport methods actually call it.
 *
 * G-5 (#5781): Http::preventStrayRequests(), and client() checks that both constructor clients
 * carry the scripted stack before any request; tearDown() checks that every scripted response
 * was consumed and that every request went through the stack's history, so a request built on
 * a per-call client without the handler fails here by name. Synthetic mailboxes only (G-13).
 */
class GraphClientCalendarShapeTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private ?MockHandler $queue = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->queue !== null) {
            $this->assertSame(0, $this->queue->count(), 'every scripted response was consumed through the scripted stack');
            $this->assertNotSame([], $this->history, 'positive control: the request went through the scripted stack');
            foreach ($this->history as $sent) {
                $this->assertSame('graph.microsoft.com', $sent['request']->getUri()->getHost());
            }
        }
        parent::tearDown();
    }

    private function client(array $responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create($this->queue = new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'b4k2-synthetic-seeded-bearer', 3600);

        $graph = new GraphClient([
            'tenant_id' => 'tenant-b4k2-synthetic', 'client_id' => 'b4k2-client', 'client_secret' => 'b4k2-synthetic-secret-not-real',
            'request_timeout' => 15, 'token_timeout' => 10, 'handler' => $stack,
        ], $cache);
        foreach (['http', 'authHttp'] as $property) {
            $this->assertSame($stack, (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph)->getConfig('handler'), "GraphClient::\${$property} must use the scripted handler");
        }

        return $graph;
    }

    /** A valid scheduleInformation row (documented shape) for wire fixtures. */
    private function scheduleRow(string $upn): array
    {
        return [
            'scheduleId' => $upn,
            'availabilityView' => '002200',
            'scheduleItems' => [[
                'status' => 'busy',
                'start' => ['dateTime' => '2026-07-28T14:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-07-28T15:00:00.0000000', 'timeZone' => 'UTC'],
            ]],
        ];
    }

    public function test_get_schedule_screams_on_a_per_mailbox_error(): void
    {
        $errored = $this->scheduleRow('b4k2.second@synthetic.test');
        $errored['error'] = ['message' => 'x', 'responseCode' => 'y'];
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            $this->scheduleRow('b4k2.first@synthetic.test'), // valid, so the ONLY drift is the error below
            $errored,
        ]]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getSchedule('b4k2.first@synthetic.test', ['b4k2.first@synthetic.test', 'b4k2.second@synthetic.test'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
    }

    /**
     * #5732: through getSchedule(), the drift message a caller receives names the row and the
     * request position, never the mailbox (the MCP calendar tool surfaces this message).
     */
    public function test_get_schedule_drift_messages_carry_no_mailbox(): void
    {
        $errored = $this->scheduleRow('b4k1.second@synthetic.test');
        $errored['error'] = ['message' => 'x', 'responseCode' => 'y'];
        $cases = [
            'per-mailbox error' => [[$this->scheduleRow('b4k1.first@synthetic.test'), $errored], 'Microsoft Graph getSchedule returned an error for requested mailbox 1 (zero-based position in the request; row 1 of the response); its availability is unknown, so the whole free/busy read is refused rather than shown as free.'],
            'missing mailbox' => [[$this->scheduleRow('b4k1.first@synthetic.test')], 'Microsoft Graph getSchedule did not return availability for requested mailbox 1 (zero-based position in the request) — a grid missing a requested mailbox must not be read as complete.'],
        ];
        foreach ($cases as $name => [$rows, $expected]) {
            $client = $this->client([new Response(200, [], json_encode(['value' => $rows]))]);
            try {
                $client->getSchedule('b4k1.first@synthetic.test', ['b4k1.first@synthetic.test', 'b4k1.second@synthetic.test'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
                $this->fail("{$name}: expected drift");
            } catch (GraphShapeDriftException $e) {
                $this->assertSame($expected, $e->getMessage(), $name);
                $this->assertStringNotContainsString('synthetic.test', $e->getMessage(), $name);
            }
        }
    }

    public function test_get_schedule_screams_on_a_row_with_no_availability_data(): void
    {
        // A row carrying only scheduleId used to project as availability_view=null + busy_blocks=[]
        // and read as all-clear (psa-abl0i.4/.5). It must now scream.
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            ['scheduleId' => 'b4k2.first@synthetic.test'],
        ]]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getSchedule('b4k2.first@synthetic.test', ['b4k2.first@synthetic.test'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
    }

    public function test_get_schedule_returns_validated_rows_on_a_clean_grid(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            ['scheduleId' => 'b4k2.first@synthetic.test', 'availabilityView' => '002200', 'scheduleItems' => []],
        ]]))]);

        $rows = $client->getSchedule('b4k2.first@synthetic.test', ['b4k2.first@synthetic.test'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
        $this->assertSame('b4k2.first@synthetic.test', $rows[0]['scheduleId']);
    }

    public function test_calendar_view_screams_on_silent_truncation(): void
    {
        // One page returned, cap = 1, but a nextLink is still pending => truncated window.
        $client = $this->client([new Response(200, [], json_encode([
            'value' => [['id' => 'e1']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/b4k2.first%40synthetic.test/calendarView?$skip=1',
        ]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->calendarView('b4k2.first@synthetic.test', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 1);
    }

    public function test_calendar_view_returns_events_when_not_truncated(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['value' => [['id' => 'e1'], ['id' => 'e2']]]))]);

        $events = $client->calendarView('b4k2.first@synthetic.test', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 5);
        $this->assertCount(2, $events);
    }

    public function test_calendar_view_follows_a_valid_graph_next_link_across_pages(): void
    {
        // Page 1 carries a valid https graph.microsoft.com nextLink; page 2 ends the list. Proves
        // provenNextLink accepts a real cursor and requestJsonAbsolute follows it.
        $client = $this->client([
            new Response(200, [], json_encode(['value' => [['id' => 'e1']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/b4k2.first%40synthetic.test/calendarView?$skip=1'])),
            new Response(200, [], json_encode(['value' => [['id' => 'e2']]])),
        ]);

        $events = $client->calendarView('b4k2.first@synthetic.test', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 5);
        $this->assertSame(['e1', 'e2'], array_column($events, 'id'));
    }

    public function test_calendar_view_screams_on_a_value_object_not_a_list(): void
    {
        // "value": {} assoc-decodes to [] and used to read as an empty calendar.
        $client = $this->client([new Response(200, [], '{"value":{}}')]);

        $this->expectException(GraphShapeDriftException::class);
        $client->calendarView('b4k2.first@synthetic.test', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
    }

    public function test_calendar_view_screams_on_a_foreign_next_link(): void
    {
        // A truthy but non-Graph cursor must not be followed with the app bearer.
        $client = $this->client([new Response(200, [], json_encode([
            'value' => [['id' => 'e1']],
            '@odata.nextLink' => 'https://evil.example/v1.0/steal',
        ]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->calendarView('b4k2.first@synthetic.test', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 5);
    }

    public function test_get_event_screams_on_a_malformed_event_body(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['subject' => 'no id here']))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getEvent('b4k2.first@synthetic.test', 'AAA');
    }
}
