<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use App\Services\Signals\Sinks\McpSink;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpSinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliver_writes_reference_only_inbox_payload_and_marks_success(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture(context: [
            'category' => 'needs_decision',
            'client_name' => 'Acme Corp',
        ]);

        app(McpSink::class)->deliver($destination, $event, $delivery);

        $entry = SignalInboxEntry::firstOrFail();
        $this->assertSame($destination->id, $entry->destination_id);
        $this->assertSame($delivery->id, $entry->delivery_id);
        $this->assertSame([
            'event' => 'agent.flag_attention',
            'entity' => ['type' => 'ticket', 'id' => 123],
            'category' => 'needs_decision',
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ], $entry->payload);
        $this->assertStringNotContainsString('New ticket for Acme Corp', json_encode($entry->payload));
        $this->assertStringNotContainsString('Acme Corp', json_encode($entry->payload));
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
    }

    public function test_wake_url_posts_signed_doorbell_with_pending_count(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture(wake: true);
        $history = [];
        $sink = $this->sink([new Response(202)], $history);

        $sink->deliver($destination, $event, $delivery);

        $body = (string) $history[0]['request']->getBody();
        $this->assertSame(['destination_id' => $destination->id, 'pending_count' => 1], json_decode($body, true));
        $this->assertSame(
            'sha256='.hash_hmac('sha256', $body, 'wake-secret'),
            $history[0]['request']->getHeaderLine('X-SoundPSA-Signature'),
        );
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertNull($destination->fresh()->last_error);
    }

    public function test_doorbell_failure_keeps_delivery_successful_and_records_destination_error(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture(wake: true);
        $history = [];
        $sink = $this->sink([new Response(500, [], 'body is not stored')], $history);

        $sink->deliver($destination, $event, $delivery);

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
        $this->assertSame('doorbell: HTTP 500 Internal Server Error', $destination->fresh()->last_error);
        $this->assertStringNotContainsString('body is not stored', $destination->fresh()->last_error);
    }

    public function test_doorbell_exception_records_safe_error_without_wake_url_details(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture(wake: true);
        $history = [];
        $sink = $this->sink([
            new ConnectException(
                'cURL error 6 for https://wake.example.com/doorbell/private-token',
                new Request('POST', 'https://wake.example.com/doorbell/private-token'),
            ),
        ], $history);

        $sink->deliver($destination, $event, $delivery);

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('doorbell: connect failed', $destination->fresh()->last_error);
        $this->assertStringNotContainsString('wake.example.com', $destination->fresh()->last_error);
        $this->assertStringNotContainsString('private-token', $destination->fresh()->last_error);
    }

    public function test_acknowledged_inbox_retention_prunes_only_this_destination(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        [$otherDestination, $otherEvent, $otherDelivery] = $this->deliveryFixture(label: 'Other Chet');
        $oldSameDestination = SignalInboxEntry::create([
            'destination_id' => $destination->id,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => ['event' => 'old'],
            'acked_at' => now()->subDays(31),
        ]);
        $oldOtherDestination = SignalInboxEntry::create([
            'destination_id' => $otherDestination->id,
            'event_id' => $otherEvent->id,
            'delivery_id' => $otherDelivery->id,
            'payload' => ['event' => 'old-other'],
            'acked_at' => now()->subDays(31),
        ]);

        app(McpSink::class)->deliver($destination, $event, $delivery);

        $this->assertDatabaseMissing('signal_inbox', ['id' => $oldSameDestination->id]);
        $this->assertDatabaseHas('signal_inbox', ['id' => $oldOtherDestination->id]);
    }

    public function test_deliver_signal_dispatches_mcp_destination_to_mcp_sink(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();

        (new DeliverSignal($delivery->id))->handle();

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertDatabaseHas('signal_inbox', [
            'destination_id' => $destination->id,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
        ]);
    }

    private function sink(array $responses, array &$history): McpSink
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new McpSink(new Client(['handler' => $stack]));
    }

    private function deliveryFixture(array $context = [], bool $wake = false, string $label = 'Chet'): array
    {
        $destination = SignalDestination::create([
            'label' => $label,
            'type' => 'mcp',
            'mcp_token_label' => strtolower(str_replace(' ', '-', $label)),
            'wake_url' => $wake ? 'https://wake.example.com/doorbell' : null,
            'wake_secret' => $wake ? 'wake-secret' : null,
        ]);
        $route = SignalRoute::create([
            'label' => $label.' route',
            'event_filter' => ['types' => ['agent.flag_attention']],
            'enabled' => true,
        ]);
        $event = SignalEvent::create([
            'type_key' => 'agent.flag_attention',
            'entity_type' => 'ticket',
            'entity_id' => 123,
            'summary' => 'New ticket for Acme Corp',
            'context' => $context,
            'occurred_at' => now()->startOfSecond(),
        ]);
        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
            'status' => 'pending',
        ]);

        return [$destination, $event, $delivery];
    }
}
