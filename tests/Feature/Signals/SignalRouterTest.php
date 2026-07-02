<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Jobs\RouteSignalEvent;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Services\Signals\SignalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SignalRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_enabled_matching_route_dispatches_lowest_step_order_deliveries_only(): void
    {
        $route = $this->route(['types' => ['ticket.created']], stepOrders: [2, 1, 1]);
        $disabled = $this->route(['types' => ['ticket.created']], enabled: false);
        $event = $this->event('ticket.created');

        app(SignalRouter::class)->route($event);

        $this->assertSame(2, SignalDelivery::where('route_id', $route->id)->where('status', 'pending')->count());
        $this->assertSame(0, SignalDelivery::where('route_id', $disabled->id)->count());
        $this->assertSame([1, 1], SignalDelivery::where('route_id', $route->id)->pluck('step_order')->all());
        Bus::assertDispatchedTimes(DeliverSignal::class, 2);
    }

    public function test_route_signal_event_job_invokes_router(): void
    {
        $this->route(['types' => ['ticket.created']]);
        $event = $this->event('ticket.created');

        (new RouteSignalEvent($event->id))->handle();

        $this->assertDatabaseHas('signal_deliveries', [
            'event_id' => $event->id,
            'status' => 'pending',
        ]);
        Bus::assertDispatched(DeliverSignal::class);
    }

    public function test_filter_matching_supports_all_types_category_priority_and_client_id(): void
    {
        $allRoute = $this->route(['types' => 'all']);
        $narrowRoute = $this->route([
            'types' => ['ticket.created'],
            'categories' => ['security'],
            'min_priority' => 2,
            'client_ids' => [7],
        ]);
        $this->route(['types' => ['ticket.client_replied']]);
        $this->route(['types' => ['ticket.created'], 'categories' => ['billing']]);
        $this->route(['types' => ['ticket.created'], 'min_priority' => 1], label: 'P1 miss');
        $this->route(['types' => ['ticket.created'], 'client_ids' => [8]]);

        app(SignalRouter::class)->route($this->event('ticket.created', [
            'category' => 'security',
            'priority' => '2',
            'client_id' => '7',
        ]));

        $this->assertSame(
            [$allRoute->id, $narrowRoute->id],
            SignalDelivery::query()->orderBy('route_id')->pluck('route_id')->all(),
        );
        Bus::assertDispatchedTimes(DeliverSignal::class, 2);
    }

    public function test_route_with_category_does_not_match_event_without_category(): void
    {
        $this->route(['types' => ['ticket.created'], 'categories' => ['security']]);

        app(SignalRouter::class)->route($this->event('ticket.created'));

        $this->assertDatabaseCount('signal_deliveries', 0);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_non_routable_event_types_never_match_even_with_all_filter(): void
    {
        $this->route(['types' => 'all']);
        $this->route(['types' => ['system.test']]);

        app(SignalRouter::class)->route($this->event('system.test'));

        $this->assertDatabaseCount('signal_deliveries', 0);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_cooldown_creates_suppressed_rows_and_dispatches_nothing(): void
    {
        $route = $this->route(['types' => ['ticket.created']], cooldownSeconds: 300);
        $previousEvent = $this->event('ticket.created', entityType: 'ticket', entityId: 55);
        $previous = SignalDelivery::create([
            'event_id' => $previousEvent->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $route->steps->first()->destination_id,
            'status' => 'delivered',
        ]);
        SignalDelivery::whereKey($previous->id)->update(['created_at' => now()->subMinute()]);

        app(SignalRouter::class)->route($this->event('ticket.created', entityType: 'ticket', entityId: 55));

        $delivery = SignalDelivery::where('event_id', '!=', $previousEvent->id)->firstOrFail();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('cooldown', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_causal_depth_over_three_is_suppressed(): void
    {
        $route = $this->route(['types' => ['signal.delivery_failed']]);
        $root = $this->event('ticket.created');
        $second = $this->event('signal.delivery_failed', originEventId: $root->id);
        $third = $this->event('signal.delivery_failed', originEventId: $second->id);
        $fourth = $this->event('signal.delivery_failed', originEventId: $third->id);
        $tooDeep = $this->event('signal.delivery_failed', originEventId: $fourth->id);

        app(SignalRouter::class)->route($tooDeep);

        $delivery = SignalDelivery::where('route_id', $route->id)->firstOrFail();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('causal-depth', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_per_type_hourly_rate_limit_is_suppressed(): void
    {
        $route = $this->route(['types' => ['ticket.created']]);
        for ($i = 0; $i < 60; $i++) {
            $this->event('ticket.created', entityId: $i, occurredAt: now()->subMinutes(30));
        }
        $current = $this->event('ticket.created', entityId: 999);

        app(SignalRouter::class)->route($current);

        $delivery = SignalDelivery::where('route_id', $route->id)->firstOrFail();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('rate-limit', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    private function route(
        array $filter,
        array $stepOrders = [1],
        bool $enabled = true,
        int $cooldownSeconds = 300,
        string $label = 'Route',
    ): SignalRoute {
        $route = SignalRoute::create([
            'label' => $label.' '.SignalRoute::count(),
            'event_filter' => $filter,
            'enabled' => $enabled,
            'cooldown_seconds' => $cooldownSeconds,
        ]);

        foreach ($stepOrders as $index => $stepOrder) {
            $destination = SignalDestination::create([
                'label' => "Destination {$route->id}-{$index}",
                'type' => 'webhook',
                'address' => "https://x{$route->id}{$index}.example/hook",
            ]);
            SignalRouteStep::create([
                'route_id' => $route->id,
                'step_order' => $stepOrder,
                'destination_id' => $destination->id,
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
}
