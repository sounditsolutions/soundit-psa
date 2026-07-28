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

    public function test_get_schedule_screams_on_a_per_mailbox_error(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['value' => [
            ['scheduleId' => 'charlie@soundit.co', 'availabilityView' => '000'],
            ['scheduleId' => 'justin@soundit.co', 'availabilityView' => '', 'error' => ['message' => 'x', 'responseCode' => 'y']],
        ]]))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getSchedule('charlie@soundit.co', ['charlie@soundit.co', 'justin@soundit.co'], '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');
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

    public function test_get_event_screams_on_a_malformed_event_body(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['subject' => 'no id here']))]);

        $this->expectException(GraphShapeDriftException::class);
        $client->getEvent('charlie@soundit.co', 'AAA');
    }
}
