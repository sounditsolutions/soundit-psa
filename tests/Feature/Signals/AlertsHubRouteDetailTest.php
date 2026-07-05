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

    public function test_show_renders_all_steps_when_route_has_more_than_three(): void
    {
        $dest1 = SignalDestination::create(['label' => 'Dest One', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/d1']);
        $dest2 = SignalDestination::create(['label' => 'Dest Two', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/d2']);
        $dest3 = SignalDestination::create(['label' => 'Dest Three', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/d3']);
        $dest4 = SignalDestination::create(['label' => 'Dest Four', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/d4']);

        $route = SignalRoute::create([
            'label' => 'Four step route',
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => true,
            'cooldown_seconds' => 300,
        ]);

        SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 1, 'destination_id' => $dest1->id]);
        SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 2, 'destination_id' => $dest2->id]);
        SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 3, 'destination_id' => $dest3->id]);
        SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 4, 'destination_id' => $dest4->id]);

        // Guard against the >3-step truncation regression: the 4th step slot
        // (index 3) must render and must be pre-selected with the 4th
        // destination, or an edit-and-save would silently drop steps 4+.
        $this->actingAs($this->user)->get(route('settings.alerts.routes.show', $route))
            ->assertOk()
            ->assertSee('name="steps[3][destination_id]"', false)
            ->assertSee('<option value="'.$dest4->id.'" selected>Dest Four</option>', false);
    }
}
