<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Signals\DerivedRecipientResolver;
use App\Services\Signals\DerivedRecipients;
use App\Services\Signals\SignalRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SignalRouterDerivedRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_derived_owner_step_delivers_to_the_ticket_owners_destination(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $route = $this->derivedRoute();

        app(SignalRouter::class)->route($this->ticketEvent($this->ticketFor($owner)));

        $delivery = SignalDelivery::where('route_id', $route->id)->sole();
        $this->assertSame('pending', $delivery->status);

        $destination = SignalDestination::find($delivery->destination_id);
        $this->assertSame($owner->id, $destination->user_id);
        $this->assertSame('owner@example.test', $destination->address);
        Bus::assertDispatched(DeliverSignal::class);
    }

    public function test_ticket_without_owner_creates_no_delivery(): void
    {
        $route = $this->derivedRoute();

        app(SignalRouter::class)->route($this->ticketEvent($this->ticketFor(null)));

        $this->assertSame(0, SignalDelivery::where('route_id', $route->id)->count());
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_non_ticket_event_on_an_all_events_derived_route_is_skipped(): void
    {
        $route = $this->derivedRoute(['types' => 'all']);

        $event = SignalEvent::create([
            'type_key' => 'integration.sync_failed',
            'entity_type' => null,
            'entity_id' => null,
            'summary' => 'sync failed',
            'context' => [],
            'occurred_at' => now(),
        ]);

        app(SignalRouter::class)->route($event);

        $this->assertSame(0, SignalDelivery::where('route_id', $route->id)->count());
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_disabled_per_user_destination_suppresses_without_dispatch(): void
    {
        $owner = User::factory()->create();
        // Pre-provision then disable the owner's auto-managed destination.
        $destination = app(DerivedRecipientResolver::class)->provisionForUser($owner);
        $destination->forceFill(['enabled' => false])->save();

        $route = $this->derivedRoute();
        app(SignalRouter::class)->route($this->ticketEvent($this->ticketFor($owner)));

        $delivery = SignalDelivery::where('route_id', $route->id)->sole();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('destination-disabled', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_cooldown_suppression_resolves_the_derived_destination(): void
    {
        $owner = User::factory()->create();
        $route = $this->derivedRoute(cooldownSeconds: 300);
        $destination = app(DerivedRecipientResolver::class)->provisionForUser($owner);

        // Seed a recent non-suppressed delivery for the same route/entity to trip cooldown.
        $ticket = $this->ticketFor($owner);
        $priorEvent = $this->ticketEvent($ticket);
        $prior = SignalDelivery::create([
            'event_id' => $priorEvent->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
            'status' => 'delivered',
        ]);
        SignalDelivery::whereKey($prior->id)->update(['created_at' => now()->subMinute()]);

        // A second event for the same ticket should be suppressed by cooldown,
        // and the suppressed row must carry the resolved (non-null) destination.
        $secondEvent = SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => $ticket->getMorphClass(),
            'entity_id' => $ticket->id,
            'summary' => 'Ticket touched again',
            'context' => [],
            'occurred_at' => now(),
        ]);

        app(SignalRouter::class)->route($secondEvent);

        $delivery = SignalDelivery::where('event_id', $secondEvent->id)->sole();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('cooldown', $delivery->error);
        $this->assertSame($destination->id, $delivery->destination_id);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    private function derivedRoute(array $filter = ['types' => ['ticket.created']], int $cooldownSeconds = 300): SignalRoute
    {
        $route = SignalRoute::create([
            'label' => 'Owner route '.SignalRoute::count(),
            'event_filter' => $filter,
            'enabled' => true,
            'cooldown_seconds' => $cooldownSeconds,
        ]);

        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => null,
            'derived_from' => DerivedRecipients::TICKET_OWNER,
        ]);

        return $route->fresh('steps');
    }

    private function ticketFor(?User $assignee): Ticket
    {
        return Model::withoutEvents(fn () => Ticket::factory()->create([
            'assignee_id' => $assignee?->id,
        ]));
    }

    private function ticketEvent(Ticket $ticket): SignalEvent
    {
        return SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => $ticket->getMorphClass(),
            'entity_id' => $ticket->id,
            'summary' => 'Ticket created',
            'context' => [],
            'occurred_at' => now(),
        ]);
    }
}
