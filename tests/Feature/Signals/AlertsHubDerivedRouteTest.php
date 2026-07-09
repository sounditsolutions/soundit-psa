<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDestination;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\User;
use App\Services\Signals\DerivedRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsHubDerivedRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_route_create_page_offers_the_derived_ticket_owner_option(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.alerts.routes.create'))
            ->assertOk()
            ->assertSee('Derived recipients')
            ->assertSee('value="derived:ticket_owner"', false)
            ->assertSee('Ticket owner (assignee)');
    }

    public function test_store_persists_a_derived_ticket_owner_step(): void
    {
        $this->actingAs($this->user)->post(route('settings.alerts.routes.store'), [
            'label' => 'Owned ticket alerts',
            'event_filter' => ['types' => ['ticket.created']],
            'cooldown_seconds' => 300,
            'steps' => [
                ['destination_id' => 'derived:ticket_owner'],
            ],
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.routes.show', SignalRoute::firstOrFail()));

        $step = SignalRouteStep::sole();
        $this->assertNull($step->destination_id);
        $this->assertSame(DerivedRecipients::TICKET_OWNER, $step->derived_from);
    }

    public function test_store_rejects_an_unknown_derived_kind(): void
    {
        $this->actingAs($this->user)->post(route('settings.alerts.routes.store'), [
            'label' => 'Bad route',
            'event_filter' => ['types' => ['ticket.created']],
            'cooldown_seconds' => 300,
            'steps' => [
                ['destination_id' => 'derived:not_a_kind'],
            ],
        ])->assertSessionHasErrors('steps.0.destination_id');

        $this->assertDatabaseCount('signal_routes', 0);
    }

    public function test_auto_provisioned_per_user_destinations_are_hidden_from_the_fixed_picker(): void
    {
        // A manual destination is offered; an auto-provisioned per-user one is not.
        // Owner is a distinct user so its label can't collide with the acting
        // user's name in the chrome.
        $owner = User::factory()->create(['name' => 'Zzz Distinct Owner']);
        SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/ops',
        ]);
        SignalDestination::create([
            'user_id' => $owner->id,
            'label' => 'User: Zzz Distinct Owner',
            'type' => 'email',
            'address' => 'someone@example.test',
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.alerts.routes.create'))
            ->assertOk()
            ->assertSee('Ops webhook')
            ->assertDontSee('User: Zzz Distinct Owner');
    }

    public function test_derived_step_renders_selected_on_the_route_edit_page(): void
    {
        $route = SignalRoute::create([
            'label' => 'Owner alerts',
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => false,
            'cooldown_seconds' => 300,
        ]);
        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => null,
            'derived_from' => DerivedRecipients::TICKET_OWNER,
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.alerts.routes.show', $route))
            ->assertOk()
            ->assertSee('Ticket owner (assignee)')
            // the derived option is pre-selected in the step dropdown
            ->assertSee('value="derived:ticket_owner" selected', false);
    }
}
