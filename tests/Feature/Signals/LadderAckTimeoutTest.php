<?php

namespace Tests\Feature\Signals;

use App\Jobs\CheckSignalStepAcks;
use App\Jobs\DeliverSignal;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Services\Signals\SignalRouter;
use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LadderAckTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        Bus::fake();
    }

    public function test_router_dispatches_first_step_deliveries_and_schedules_ack_timeout(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 90],
            ['step_order' => 1, 'wait_for_ack_seconds' => 90],
            ['step_order' => 2],
        ]);
        $event = $this->event('ticket.created');

        app(SignalRouter::class)->route($event);

        $firstStepDeliveries = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->where('step_order', 1)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $firstStepDeliveries);
        $this->assertSame(['pending', 'pending'], $firstStepDeliveries->pluck('status')->all());
        Bus::assertDispatchedTimes(DeliverSignal::class, 2);
        foreach ($firstStepDeliveries as $delivery) {
            Bus::assertDispatched(
                DeliverSignal::class,
                fn (DeliverSignal $job): bool => $job->deliveryId === $delivery->id,
            );
        }
        Bus::assertDispatchedTimes(CheckSignalStepAcks::class, 1);
        $this->assertAckCheckDispatched($route, $event, 1, 90);
    }

    public function test_ack_timeout_marks_current_non_terminal_deliveries_timed_out_and_advances_to_next_step(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
        ]);
        $event = $this->event('ticket.created');
        $steps = $route->steps->groupBy('step_order');
        $currentSteps = $steps->get(1);

        $pending = $this->delivery($event, $route, $currentSteps[0], 'pending');
        $delivered = $this->delivery($event, $route, $currentSteps[1], 'delivered');

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $this->assertSame('timed_out', $pending->fresh()->status);
        $this->assertSame('timed_out', $delivered->fresh()->status);

        $nextDeliveries = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->where('step_order', 2)
            ->orderBy('destination_id')
            ->get();

        $this->assertCount(2, $nextDeliveries);
        $this->assertSame(['pending', 'pending'], $nextDeliveries->pluck('status')->all());
        Bus::assertDispatchedTimes(DeliverSignal::class, 2);
        foreach ($nextDeliveries as $delivery) {
            Bus::assertDispatched(
                DeliverSignal::class,
                fn (DeliverSignal $job): bool => $job->deliveryId === $delivery->id,
            );
        }
        Bus::assertDispatchedTimes(CheckSignalStepAcks::class, 1);
        $this->assertAckCheckDispatched($route, $event, 2, 45);
    }

    public function test_disabled_route_stops_scheduled_ack_check_without_advancing_ladder(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
        ]);
        $event = $this->event('ticket.created');
        $current = $this->delivery($event, $route, $route->steps->first(), 'pending');
        $route->forceFill(['enabled' => false])->save();

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $this->assertSame('suppressed', $current->fresh()->status);
        $this->assertSame('route-disabled', $current->fresh()->error);
        $this->assertSame(
            0,
            SignalDelivery::query()
                ->where('route_id', $route->id)
                ->where('event_id', $event->id)
                ->where('step_order', 2)
                ->count(),
        );
        Bus::assertNotDispatched(DeliverSignal::class);
        Bus::assertNotDispatched(CheckSignalStepAcks::class);
    }

    public function test_disabled_later_destination_is_suppressed_without_dispatching_or_scheduling_ack(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45, 'destination_enabled' => false],
        ]);
        $event = $this->event('ticket.created');
        $steps = $route->steps->groupBy('step_order');
        $current = $this->delivery($event, $route, $steps->get(1)->first(), 'pending');

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $this->assertSame('timed_out', $current->fresh()->status);

        $next = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->where('step_order', 2)
            ->sole();

        $this->assertSame('suppressed', $next->status);
        $this->assertSame('destination-disabled', $next->error);
        Bus::assertNotDispatched(DeliverSignal::class);
        Bus::assertNotDispatched(CheckSignalStepAcks::class);
    }

    public function test_upstream_ack_suppresses_later_suppressible_steps_without_dispatching_next_step(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 2],
            ['step_order' => 3],
        ]);
        $event = $this->event('ticket.created');
        $steps = $route->steps->groupBy('step_order');

        $this->delivery($event, $route, $steps->get(1)->first(), 'acked', ackedAt: now());

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $laterDeliveries = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->whereIn('step_order', [2, 3])
            ->orderBy('step_order')
            ->get();

        $this->assertCount(2, $laterDeliveries);
        $this->assertSame(['suppressed', 'suppressed'], $laterDeliveries->pluck('status')->all());
        $this->assertSame(['acked-upstream', 'acked-upstream'], $laterDeliveries->pluck('error')->all());
        Bus::assertNotDispatched(DeliverSignal::class);
        Bus::assertNotDispatched(CheckSignalStepAcks::class);
    }

    public function test_non_suppressible_later_step_fires_despite_upstream_ack(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 2],
            ['step_order' => 3, 'wait_for_ack_seconds' => 120, 'non_suppressible' => true],
        ]);
        $event = $this->event('ticket.created');
        $steps = $route->steps->groupBy('step_order');

        $this->delivery($event, $route, $steps->get(1)->first(), 'acked', ackedAt: now());

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $this->assertDatabaseHas('signal_deliveries', [
            'route_id' => $route->id,
            'event_id' => $event->id,
            'step_order' => 2,
            'status' => 'suppressed',
            'error' => 'acked-upstream',
        ]);

        $nonSuppressibleDelivery = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->where('step_order', 3)
            ->sole();

        $this->assertSame('pending', $nonSuppressibleDelivery->status);
        Bus::assertDispatchedTimes(DeliverSignal::class, 1);
        Bus::assertDispatched(
            DeliverSignal::class,
            fn (DeliverSignal $job): bool => $job->deliveryId === $nonSuppressibleDelivery->id,
        );
        Bus::assertDispatchedTimes(CheckSignalStepAcks::class, 1);
        $this->assertAckCheckDispatched($route, $event, 3, 120);
    }

    public function test_any_single_ack_in_simultaneous_fan_out_counts_as_ack_for_the_group(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 1, 'wait_for_ack_seconds' => 30],
            ['step_order' => 2],
        ]);
        $event = $this->event('ticket.created');
        $steps = $route->steps->groupBy('step_order');
        $currentSteps = $steps->get(1);

        $acked = $this->delivery($event, $route, $currentSteps[0], 'acked', ackedAt: now());
        $sibling = $this->delivery($event, $route, $currentSteps[1], 'delivered');

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $this->assertSame('acked', $acked->fresh()->status);
        $this->assertSame('delivered', $sibling->fresh()->status);
        $this->assertDatabaseHas('signal_deliveries', [
            'route_id' => $route->id,
            'event_id' => $event->id,
            'step_order' => 2,
            'status' => 'suppressed',
            'error' => 'acked-upstream',
        ]);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    /**
     * @param  array<int, array{step_order:int, wait_for_ack_seconds?:int|null, non_suppressible?:bool, destination_enabled?:bool}>  $steps
     */
    private function route(array $steps, array $filter = ['types' => ['ticket.created']]): SignalRoute
    {
        $route = SignalRoute::create([
            'label' => 'Route '.SignalRoute::count(),
            'event_filter' => $filter,
            'enabled' => true,
            'cooldown_seconds' => 300,
        ]);

        foreach ($steps as $index => $step) {
            $destination = SignalDestination::create([
                'label' => "Destination {$route->id}-{$index}",
                'type' => 'webhook',
                'address' => "https://x{$route->id}{$index}.example/hook",
                'enabled' => $step['destination_enabled'] ?? true,
            ]);

            SignalRouteStep::create([
                'route_id' => $route->id,
                'step_order' => $step['step_order'],
                'destination_id' => $destination->id,
                'wait_for_ack_seconds' => $step['wait_for_ack_seconds'] ?? null,
                'non_suppressible' => $step['non_suppressible'] ?? false,
            ]);
        }

        return $route->fresh('steps');
    }

    private function event(
        string $typeKey,
        array $context = [],
        ?string $entityType = 'ticket',
        ?int $entityId = 123,
        ?int $originEventId = null,
        mixed $occurredAt = null,
    ): SignalEvent {
        return SignalEvent::create([
            'type_key' => $typeKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => 'Signal event',
            'context' => $context,
            'origin_event_id' => $originEventId,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function delivery(
        SignalEvent $event,
        SignalRoute $route,
        SignalRouteStep $step,
        string $status,
        mixed $ackedAt = null,
    ): SignalDelivery {
        return SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $route->id,
            'step_order' => $step->step_order,
            'destination_id' => $step->destination_id,
            'status' => $status,
            'acked_at' => $ackedAt,
        ]);
    }

    private function assertAckCheckDispatched(
        SignalRoute $route,
        SignalEvent $event,
        int $stepOrder,
        int $delaySeconds,
    ): void {
        Bus::assertDispatched(
            CheckSignalStepAcks::class,
            fn (object $job): bool => ($job->routeId ?? null) === $route->id
                && ($job->eventId ?? null) === $event->id
                && ($job->stepOrder ?? null) === $stepOrder
                && $this->jobDelayIs($job, $delaySeconds),
        );
    }

    private function jobDelayIs(object $job, int $seconds): bool
    {
        $delay = $job->delay ?? null;

        if ($delay instanceof DateTimeInterface) {
            return $delay->getTimestamp() === now()->copy()->addSeconds($seconds)->getTimestamp();
        }

        if ($delay instanceof DateInterval) {
            return now()->copy()->add($delay)->getTimestamp() === now()->copy()->addSeconds($seconds)->getTimestamp();
        }

        return $delay === $seconds;
    }
}
