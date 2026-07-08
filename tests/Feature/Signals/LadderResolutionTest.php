<?php

namespace Tests\Feature\Signals;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\CheckSignalResolution;
use App\Jobs\CheckSignalStepAcks;
use App\Jobs\DeliverSignal;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Signals\SignalResolutions;
use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LadderResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        Bus::fake();
    }

    public function test_ack_check_schedules_resolution_deadline_for_acked_step_with_resolve_window(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'resolve_within_seconds' => 600],
        ]);
        $event = $this->event('agent.flag_attention');

        $this->delivery($event, $route, $route->steps->first(), 'acked', ackedAt: now());

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        Bus::assertDispatchedTimes(CheckSignalResolution::class, 1);
        $this->assertResolutionCheckDispatched($route, $event, 1, 600);
    }

    public function test_ack_with_resolution_deadline_defers_suppressible_later_steps_until_unresolved_deadline(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'resolve_within_seconds' => 120],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
        ]);
        $ticket = $this->ticket(TicketStatus::InProgress);
        $this->flagRun($ticket);
        $event = $this->eventForTicket($ticket);

        $this->delivery($event, $route, $route->steps->first(), 'acked', ackedAt: now());

        (new CheckSignalStepAcks($event->id, $route->id, 1))->handle();

        $this->assertNoNextStepDeliveries($route, $event, 2);
        Bus::assertDispatchedTimes(CheckSignalResolution::class, 1);

        Bus::fake();

        (new CheckSignalResolution($event->id, $route->id, 1))->handle();

        $nextDelivery = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->where('step_order', 2)
            ->first();

        $this->assertNotNull($nextDelivery);
        $this->assertSame('pending', $nextDelivery->status);
        Bus::assertDispatchedTimes(DeliverSignal::class, 1);
        Bus::assertDispatchedTimes(CheckSignalStepAcks::class, 1);
        $this->assertAckCheckDispatched($route, $event, 2, 45);
    }

    public function test_resolution_deadline_re_escalates_acked_unresolved_flag_attention(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'resolve_within_seconds' => 120],
            ['step_order' => 1, 'resolve_within_seconds' => 120],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
        ]);
        $ticket = $this->ticket(TicketStatus::InProgress);
        $this->flagRun($ticket);
        $event = $this->eventForTicket($ticket);
        $steps = $route->steps->groupBy('step_order');
        $currentSteps = $steps->get(1);

        $acked = $this->delivery($event, $route, $currentSteps[0], 'acked', ackedAt: now());
        $delivered = $this->delivery($event, $route, $currentSteps[1], 'delivered');

        (new CheckSignalResolution($event->id, $route->id, 1))->handle();

        $this->assertSame('acked', $acked->fresh()->status);
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

    public function test_resolution_deadline_does_nothing_when_flag_attention_run_is_resolved(): void
    {
        foreach ([TechnicianRunState::Done, TechnicianRunState::Denied] as $state) {
            Bus::fake();

            $route = $this->route([
                ['step_order' => 1, 'resolve_within_seconds' => 120],
                ['step_order' => 1, 'resolve_within_seconds' => 120],
                ['step_order' => 2, 'wait_for_ack_seconds' => 45],
            ]);
            $ticket = $this->ticket(TicketStatus::InProgress);
            $this->flagRun($ticket, $state);
            $event = $this->eventForTicket($ticket);
            $currentSteps = $route->steps->groupBy('step_order')->get(1);

            $acked = $this->delivery($event, $route, $currentSteps[0], 'acked', ackedAt: now());
            $delivered = $this->delivery($event, $route, $currentSteps[1], 'delivered');

            (new CheckSignalResolution($event->id, $route->id, 1))->handle();

            $this->assertSame('acked', $acked->fresh()->status);
            $this->assertSame('delivered', $delivered->fresh()->status);
            $this->assertNoNextStepDeliveries($route, $event, 2);
            Bus::assertNotDispatched(DeliverSignal::class);
            Bus::assertNotDispatched(CheckSignalStepAcks::class);
        }
    }

    public function test_resolution_deadline_does_nothing_when_ticket_is_terminal(): void
    {
        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $status) {
            Bus::fake();

            $route = $this->route([
                ['step_order' => 1, 'resolve_within_seconds' => 120],
                ['step_order' => 1, 'resolve_within_seconds' => 120],
                ['step_order' => 2, 'wait_for_ack_seconds' => 45],
            ]);
            $ticket = $this->ticket($status);
            $this->flagRun($ticket);
            $event = $this->eventForTicket($ticket);
            $currentSteps = $route->steps->groupBy('step_order')->get(1);

            $acked = $this->delivery($event, $route, $currentSteps[0], 'acked', ackedAt: now());
            $delivered = $this->delivery($event, $route, $currentSteps[1], 'delivered');

            (new CheckSignalResolution($event->id, $route->id, 1))->handle();

            $this->assertSame('acked', $acked->fresh()->status);
            $this->assertSame('delivered', $delivered->fresh()->status);
            $this->assertNoNextStepDeliveries($route, $event, 2);
            Bus::assertNotDispatched(DeliverSignal::class);
            Bus::assertNotDispatched(CheckSignalStepAcks::class);
        }
    }

    public function test_signal_resolutions_treats_unknown_event_types_as_resolved(): void
    {
        $event = $this->event('vendor.unknown');

        $this->assertTrue(app(SignalResolutions::class)->isResolved($event));
    }

    public function test_resolution_deadline_does_not_re_escalate_unknown_event_types(): void
    {
        $route = $this->route([
            ['step_order' => 1, 'resolve_within_seconds' => 120],
            ['step_order' => 1, 'resolve_within_seconds' => 120],
            ['step_order' => 2, 'wait_for_ack_seconds' => 45],
        ], ['types' => 'all']);
        $event = $this->event('vendor.unknown');
        $currentSteps = $route->steps->groupBy('step_order')->get(1);

        $acked = $this->delivery($event, $route, $currentSteps[0], 'acked', ackedAt: now());
        $delivered = $this->delivery($event, $route, $currentSteps[1], 'delivered');

        (new CheckSignalResolution($event->id, $route->id, 1))->handle();

        $this->assertSame('acked', $acked->fresh()->status);
        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertNoNextStepDeliveries($route, $event, 2);
        Bus::assertNotDispatched(DeliverSignal::class);
        Bus::assertNotDispatched(CheckSignalStepAcks::class);
    }

    /**
     * @param  array<int, array{step_order:int, wait_for_ack_seconds?:int|null, resolve_within_seconds?:int|null, non_suppressible?:bool}>  $steps
     */
    private function route(array $steps, array $filter = ['types' => ['agent.flag_attention']]): SignalRoute
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
            ]);

            SignalRouteStep::create([
                'route_id' => $route->id,
                'step_order' => $step['step_order'],
                'destination_id' => $destination->id,
                'wait_for_ack_seconds' => $step['wait_for_ack_seconds'] ?? null,
                'resolve_within_seconds' => $step['resolve_within_seconds'] ?? null,
                'non_suppressible' => $step['non_suppressible'] ?? false,
            ]);
        }

        return $route->fresh('steps');
    }

    private function ticket(TicketStatus $status): Ticket
    {
        return Ticket::withoutEvents(fn (): Ticket => Ticket::factory()->create([
            'status' => $status->value,
            'opened_at' => now()->subHour(),
            'resolved_at' => $status === TicketStatus::Resolved ? now() : null,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ]));
    }

    private function flagRun(
        Ticket $ticket,
        TechnicianRunState $state = TechnicianRunState::Flagged,
    ): TechnicianRun {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'flag_attention',
            'content_hash' => str_repeat('f', 64),
            'state' => $state,
            'proposed_meta' => [
                'category' => 'needs_decision',
                'reason' => 'This ticket needs human attention',
            ],
        ]);
    }

    private function eventForTicket(Ticket $ticket): SignalEvent
    {
        return $this->event(
            'agent.flag_attention',
            ['client_id' => $ticket->client_id],
            $ticket->getMorphClass(),
            $ticket->id,
        );
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

    private function assertNoNextStepDeliveries(SignalRoute $route, SignalEvent $event, int $stepOrder): void
    {
        $this->assertSame(
            0,
            SignalDelivery::query()
                ->where('route_id', $route->id)
                ->where('event_id', $event->id)
                ->where('step_order', $stepOrder)
                ->count(),
        );
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

    private function assertResolutionCheckDispatched(
        SignalRoute $route,
        SignalEvent $event,
        int $stepOrder,
        int $delaySeconds,
    ): void {
        Bus::assertDispatched(
            CheckSignalResolution::class,
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
