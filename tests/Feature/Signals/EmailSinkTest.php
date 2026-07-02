<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Models\Email;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Services\EmailService;
use App\Services\Signals\Sinks\EmailSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailSinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliver_sends_email_payload_and_marks_success(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $this->mock(EmailService::class, function (MockInterface $mock) use ($destination, $event, $delivery): void {
            $mock->shouldReceive('sendNew')->once()
                ->withArgs(function (string $to, string $subject, string $body, mixed $toName, mixed $cc, mixed $userId) use ($destination, $event, $delivery): bool {
                    return $to === $destination->address
                        && $subject === 'Sound PSA signal: ticket.created'
                        && str_contains($body, 'Event: ticket.created')
                        && str_contains($body, 'Summary: New ticket')
                        && str_contains($body, 'Entity: ticket #123')
                        && str_contains($body, 'Delivery: '.$delivery->id)
                        && str_contains($body, $event->occurred_at->toIso8601String())
                        && $toName === null
                        && $cc === null
                        && $userId === null;
                })
                ->andReturn(new Email);
        });

        app(EmailSink::class)->deliver($destination, $event, $delivery);

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->delivered_at);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
        $this->assertNull($destination->fresh()->last_error);
    }

    public function test_destination_hourly_email_rate_limit_suppresses_without_sending(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendNew')->never();
        });

        for ($i = 0; $i < 10; $i++) {
            $sent = SignalDelivery::create([
                'event_id' => $event->id,
                'route_id' => $delivery->route_id,
                'step_order' => 1,
                'destination_id' => $destination->id,
                'status' => 'delivered',
                'delivered_at' => now()->subMinutes(30),
            ]);
            SignalDelivery::whereKey($sent->id)->update(['created_at' => now()->subMinutes(30)]);
        }

        app(EmailSink::class)->deliver($destination, $event, $delivery);

        $this->assertSame('suppressed', $delivery->fresh()->status);
        $this->assertSame('email-rate-limit', $delivery->fresh()->error);
        $this->assertSame('suppressed', $destination->fresh()->last_delivery_status);
        $this->assertSame('email-rate-limit', $destination->fresh()->last_error);
    }

    public function test_deliver_signal_dispatches_email_destination_to_email_sink(): void
    {
        [$destination, $event, $delivery] = $this->deliveryFixture();
        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendNew')->once()->andReturn(new Email);
        });

        (new DeliverSignal($delivery->id))->handle();

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $destination->fresh()->last_delivery_status);
    }

    private function deliveryFixture(): array
    {
        $destination = SignalDestination::create([
            'label' => 'Ops email',
            'type' => 'email',
            'address' => 'ops@example.com',
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
