<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsHubRouteDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_show_renders_composer_and_recent_fires(): void
    {
        $dest = SignalDestination::create(['label' => 'Ops', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/aaaa1111']);
        $route = SignalRoute::create(['label' => 'Ticket alerts', 'event_filter' => ['types' => ['ticket.created']], 'enabled' => true, 'cooldown_seconds' => 300]);
        SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 1, 'destination_id' => $dest->id]);
        $event = SignalEvent::create(['type_key' => 'ticket.created', 'summary' => 'x', 'context' => [], 'occurred_at' => now()]);
        SignalDelivery::create(['event_id' => $event->id, 'route_id' => $route->id, 'destination_id' => $dest->id, 'step_order' => 1, 'status' => 'delivered', 'delivered_at' => now()]);

        $this->actingAs($this->user)->get(route('settings.alerts.routes.show', $route))
            ->assertOk()->assertSee('Ticket alerts')->assertSee('ticket.created')
            ->assertSee('Ops')->assertSee('delivered')->assertSee('All routes');
    }
}
