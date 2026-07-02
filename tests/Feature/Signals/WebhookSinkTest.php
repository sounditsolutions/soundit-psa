<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Jobs\RouteSignalEvent;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Services\Signals\Sinks\WebhookSink;
use App\Support\SafeUrlInspector;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookSinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliver_posts_generic_json_and_marks_success(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $history = [];
        $sink = $this->sink([new Response(204)], $history);

        $sink->deliver($destination, $event, $delivery);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('ticket.created', $body['event']);
        $this->assertSame('New ticket', $body['summary']);
        $this->assertSame(['type' => 'ticket', 'id' => 123], $body['entity']);
        $this->assertSame($delivery->id, $body['delivery_id']);
        $this->assertArrayNotHasKey('@type', $body);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->delivered_at);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
        $this->assertNull($destination->fresh()->last_error);
    }

    public function test_deliver_retries_one_server_error_then_succeeds(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $history = [];
        $sink = $this->sink([
            new Response(500, [], 'temporary body'),
            new Response(200),
        ], $history);

        $sink->deliver($destination, $event, $delivery);

        $this->assertCount(2, $history);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
    }

    public function test_deliver_retries_one_connect_failure_then_succeeds(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $history = [];
        $sink = $this->sink([
            new ConnectException('temporary down', new Request('POST', 'https://hooks.example.com/signal')),
            new Response(200),
        ], $history);

        $sink->deliver($destination, $event, $delivery);

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
    }

    public function test_deliver_failure_records_status_reason_without_response_body(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $history = [];
        $sink = $this->sink([
            new Response(500, [], 'do not leak first body'),
            new Response(503, [], 'do not leak final body'),
        ], $history);

        try {
            $sink->deliver($destination, $event, $delivery);
            $this->fail('Expected webhook delivery failure to throw after marking failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 503 Service Unavailable', $e->getMessage());
        }

        $this->assertCount(2, $history);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('HTTP 503 Service Unavailable', $delivery->fresh()->error);
        $this->assertSame('failed', $destination->fresh()->last_delivery_status);
        $this->assertSame('HTTP 503 Service Unavailable', $destination->fresh()->last_error);
        $this->assertStringNotContainsString('do not leak', $destination->fresh()->last_error);
    }

    public function test_default_handler_stack_names_the_ssrf_pin_middleware(): void
    {
        $stack = WebhookSink::handlerStack(fn (string $host) => ['93.184.216.34']);

        $this->assertStringContainsString('technician_webhook_ssrf_pin', (string) $stack);
    }

    public function test_safe_url_inspector_can_label_webhook_url_errors_without_changing_default(): void
    {
        $custom = SafeUrlInspector::reject('http://hooks.example.com/path', null, 'Alerts Hub webhook URL');
        $default = SafeUrlInspector::reject('http://hooks.example.com/path');

        $this->assertStringContainsString('Alerts Hub webhook URL', $custom);
        $this->assertStringNotContainsString('Tactical API URL', $custom);
        $this->assertStringContainsString('Tactical API URL', $default);
    }

    public function test_deliver_signal_failure_marks_failed_and_emits_self_monitoring_event(): void
    {
        Bus::fake();
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $this->mock(WebhookSink::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliver')->once()->andThrow(new \RuntimeException('transport down'));
        });

        (new DeliverSignal($delivery->id))->handle();

        $this->assertSame('failed', $delivery->fresh()->status);
        $failedEvent = SignalEvent::where('type_key', 'signal.delivery_failed')->firstOrFail();
        $this->assertSame($event->id, $failedEvent->origin_event_id);
        $this->assertSame(['destination_id' => $destination->id], $failedEvent->context);
        Bus::assertDispatched(RouteSignalEvent::class, fn (RouteSignalEvent $job) => $job->eventId === $failedEvent->id);
    }

    private function sink(array $responses, array &$history): WebhookSink
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new WebhookSink(new Client(['handler' => $stack]));
    }

    private function deliveryFixture(): array
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://hooks.example.com/signal',
        ]);
        $route = SignalRoute::create([
            'label' => 'Ops',
            'event_filter' => ['types' => ['ticket.created']],
        ]);
        $event = SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => 'ticket',
            'entity_id' => 123,
            'summary' => 'New ticket',
            'context' => [],
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
