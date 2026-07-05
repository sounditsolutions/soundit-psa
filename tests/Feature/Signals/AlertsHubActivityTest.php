<?php

namespace Tests\Feature\Signals;

use App\Models\SignalConfigLog;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsHubActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_activity_tab_lists_last_100_deliveries_and_last_20_config_changes(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd',
        ]);
        $route = SignalRoute::create([
            'label' => 'Ops',
            'event_filter' => ['types' => ['ticket.created']],
        ]);

        for ($i = 0; $i < 101; $i++) {
            $event = SignalEvent::create([
                'type_key' => 'ticket.created',
                'summary' => "Ticket {$i}",
                'context' => [],
                'occurred_at' => now()->subMinutes(101 - $i),
            ]);
            $delivery = SignalDelivery::create([
                'event_id' => $event->id,
                'route_id' => $route->id,
                'step_order' => 1,
                'destination_id' => $destination->id,
                'status' => $i === 100 ? 'failed' : 'delivered',
                'error' => "delivery-error-{$i}",
            ]);
            $delivery->forceFill(['created_at' => now()->subMinutes(101 - $i)])->save();
        }

        for ($i = 0; $i < 21; $i++) {
            SignalConfigLog::create([
                'user_id' => $this->user->id,
                'action' => "updated-{$i}",
                'subject_type' => SignalDestination::class,
                'subject_id' => $destination->id,
                'changes' => ['label' => "config-change-{$i}"],
                'created_at' => now()->subMinutes(21 - $i),
            ]);
        }

        $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('Ops webhook')
            ->assertSee('ticket.created')
            ->assertSee('failed')
            ->assertSee('delivery-error-100')
            ->assertDontSee('delivery-error-0')
            ->assertSee('updated-20')
            ->assertSee('config-change-20')
            ->assertDontSee('updated-0');
    }

    public function test_activity_warns_on_stale_pending_delivery_only(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd',
        ]);
        $route = SignalRoute::create([
            'label' => 'Ops',
            'event_filter' => ['types' => ['ticket.created']],
        ]);
        $fresh = $this->pendingDelivery($destination, $route);
        $fresh->forceFill(['created_at' => now()->subMinutes(9)])->save();

        $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertDontSee('queue worker may be down');

        $stale = $this->pendingDelivery($destination, $route);
        $stale->forceFill(['created_at' => now()->subMinutes(11)])->save();

        $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('queue worker may be down');
    }

    public function test_landing_shows_two_clickable_lists_create_buttons_and_activity(): void
    {
        $dest = SignalDestination::create(['label' => 'Ops webhook', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/aaaa1111']);
        $route = SignalRoute::create(['label' => 'Ticket alerts', 'event_filter' => ['types' => ['ticket.created']], 'enabled' => true]);

        $this->actingAs($this->user)->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('Destinations')->assertSee('Routes')->assertSee('Activity')
            ->assertSee(route('settings.alerts.destinations.create'), false)
            ->assertSee(route('settings.alerts.routes.create'), false)
            ->assertSee(route('settings.alerts.destinations.show', $dest), false)   // row links to detail
            ->assertSee(route('settings.alerts.routes.show', $route), false);
    }

    private function pendingDelivery(SignalDestination $destination, SignalRoute $route): SignalDelivery
    {
        $event = SignalEvent::create([
            'type_key' => 'ticket.created',
            'summary' => 'Pending ticket',
            'context' => [],
            'occurred_at' => now(),
        ]);

        return SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
            'status' => 'pending',
        ]);
    }
}
