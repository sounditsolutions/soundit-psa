<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphShapeDriftException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

/**
 * Confirms GraphClient WIRES the fail-loud CalendarGraphShapes validator on the wire path
 * (psa-abl0i.2) — raw vendor-shape JSON in, GraphShapeDriftException out on drift, never a
 * silent empty/all-free grid. The validator's exhaustive drift cases live in
 * CalendarGraphShapesTest; this proves the transport methods actually call it.
 */
class GraphClientCalendarShapeTest extends TestCase
{
    private function client(array $responses): GraphClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);

        return new GraphClient([
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's',
            'request_timeout' => 15, 'token_timeout' => 10, 'handler' => $stack,
        ], $cache);
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
        $errored = $this->scheduleRow('justin@soundit.co');
        $errored['error'] = ['message' => 'x', 'responseCode' => 'y'];
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            $this->scheduleRow('charlie@soundit.co'), // valid, so the ONLY drift is the error below
            $errored,
        ]]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getSchedule('charlie@soundit.co', ['charlie@soundit.co', 'justin@soundit.co'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
    }

    public function test_get_schedule_screams_on_a_row_with_no_availability_data(): void
    {
        // A row carrying only scheduleId used to project as availability_view=null + busy_blocks=[]
        // and read as all-clear (psa-abl0i.4/.5). It must now scream.
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            ['scheduleId' => 'charlie@soundit.co'],
        ]]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getSchedule('charlie@soundit.co', ['charlie@soundit.co'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
    }

    public function test_get_schedule_returns_validated_rows_on_a_clean_grid(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            ['scheduleId' => 'charlie@soundit.co', 'availabilityView' => '002200', 'scheduleItems' => []],
        ]]))]);

        $rows = $client->getSchedule('charlie@soundit.co', ['charlie@soundit.co'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
        $this->assertSame('charlie@soundit.co', $rows[0]['scheduleId']);
    }

    public function test_calendar_view_screams_on_silent_truncation(): void
    {
        // One page returned, cap = 1, but a nextLink is still pending => truncated window.
        $client = $this->client([new Response(200, [], json_encode([
            'value' => [['id' => 'e1']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/charlie%40soundit.co/calendarView?$skip=1',
        ]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->calendarView('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 1);
    }

    public function test_calendar_view_returns_events_when_not_truncated(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['value' => [['id' => 'e1'], ['id' => 'e2']]]))]);

        $events = $client->calendarView('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 5);
        $this->assertCount(2, $events);
    }

    public function test_calendar_view_follows_a_valid_graph_next_link_across_pages(): void
    {
        // Page 1 carries a valid https graph.microsoft.com nextLink; page 2 ends the list. Proves
        // provenNextLink accepts a real cursor and requestJsonAbsolute follows it.
        $client = $this->client([
            new Response(200, [], json_encode(['value' => [['id' => 'e1']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/charlie%40soundit.co/calendarView?$skip=1'])),
            new Response(200, [], json_encode(['value' => [['id' => 'e2']]])),
        ]);

        $events = $client->calendarView('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 5);
        $this->assertSame(['e1', 'e2'], array_column($events, 'id'));
    }

    public function test_calendar_view_screams_on_a_value_object_not_a_list(): void
    {
        // "value": {} assoc-decodes to [] and used to read as an empty calendar.
        $client = $this->client([new Response(200, [], '{"value":{}}')]);

        $this->expectException(GraphShapeDriftException::class);
        $client->calendarView('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
    }

    public function test_calendar_view_screams_on_a_foreign_next_link(): void
    {
        // A truthy but non-Graph cursor must not be followed with the app bearer.
        $client = $this->client([new Response(200, [], json_encode([
            'value' => [['id' => 'e1']],
            '@odata.nextLink' => 'https://evil.example/v1.0/steal',
        ]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->calendarView('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z', maxPages: 5);
    }

    public function test_get_event_screams_on_a_malformed_event_body(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['subject' => 'no id here']))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getEvent('charlie@soundit.co', 'AAA');
    }
}
