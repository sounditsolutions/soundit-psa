<?php

namespace Tests\Feature\Signals;

use App\Models\SignalConfigLog;
use App\Models\SignalDestination;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsHubRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_index_renders_routes_tab_from_registry_without_non_routable_system_test(): void
    {
        SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd',
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('Routes')
            ->assertSee('ticket.*')
            ->assertSee('agent.*')
            ->assertSee('value="ticket.created"', false)
            ->assertSee('value="agent.flag_attention"', false)
            ->assertDontSee('value="system.test"', false);
    }

    public function test_store_rejects_unknown_or_non_routable_event_types(): void
    {
        $destination = $this->destination('Ops webhook');

        foreach (['not.real', 'system.test'] as $type) {
            $this->actingAs($this->user)
                ->post(route('settings.alerts.routes.store'), [
                    'label' => 'Bad route',
                    'event_filter' => ['types' => [$type]],
                    'steps' => [
                        ['destination_id' => $destination->id],
                    ],
                ])
                ->assertSessionHasErrors('event_filter.types');
        }

        $this->assertSame(0, SignalRoute::count());
    }

    public function test_store_creates_disabled_route_with_normalized_filter_steps_and_config_log(): void
    {
        $chet = $this->destination('Chet', 'mcp');
        $ops = $this->destination('Ops webhook');
        $manager = $this->destination('Manager email', 'email');

        $this->actingAs($this->user)
            ->post(route('settings.alerts.routes.store'), [
                'label' => 'Chet then humans',
                'event_filter' => [
                    'types' => ['ticket.created', 'agent.flag_attention'],
                    'categories' => ['security', 'needs_decision'],
                    'min_priority' => 2,
                    'client_ids' => ['7', '11'],
                ],
                'cooldown_seconds' => 600,
                'steps' => [
                    [
                        'destination_id' => $chet->id,
                        'wait_for_ack_seconds' => 600,
                        'resolve_within_seconds' => 1800,
                        'non_suppressible' => '1',
                    ],
                    [
                        'destination_id' => $ops->id,
                        'simultaneous' => '1',
                    ],
                    [
                        'destination_id' => $manager->id,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.index'));

        $route = SignalRoute::with('steps')->firstOrFail();
        $this->assertSame('Chet then humans', $route->label);
        $this->assertFalse($route->enabled);
        $this->assertSame(600, $route->cooldown_seconds);
        $this->assertSame([
            'types' => ['ticket.created', 'agent.flag_attention'],
            'categories' => ['security', 'needs_decision'],
            'min_priority' => 2,
            'client_ids' => [7, 11],
        ], $route->event_filter);
        $this->assertSame([$chet->id, $ops->id, $manager->id], $route->steps->pluck('destination_id')->all());
        $this->assertSame([1, 1, 2], $route->steps->pluck('step_order')->all());
        $this->assertSame(600, $route->steps->first()->wait_for_ack_seconds);
        $this->assertSame(1800, $route->steps->first()->resolve_within_seconds);
        $this->assertTrue($route->steps->first()->non_suppressible);

        $log = SignalConfigLog::firstOrFail();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame('created', $log->action);
        $this->assertSame(SignalRoute::class, $log->subject_type);
        $this->assertSame($route->id, $log->subject_id);
        $this->assertSame('Chet then humans', $log->changes['label']);
    }

    public function test_update_replaces_steps_transactionally(): void
    {
        $old = $this->destination('Old destination');
        $new = $this->destination('New destination');
        $route = SignalRoute::create([
            'label' => 'Old route',
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => true,
            'cooldown_seconds' => 300,
        ]);
        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $old->id,
        ]);

        $this->actingAs($this->user)
            ->put(route('settings.alerts.routes.update', $route), [
                'label' => 'Broken route',
                'event_filter' => ['types' => ['agent.flag_attention']],
                'steps' => [
                    ['destination_id' => 999999],
                ],
            ])
            ->assertSessionHasErrors('steps.0.destination_id');

        $this->assertSame('Old route', $route->fresh()->label);
        $this->assertSame([$old->id], $route->fresh()->steps->pluck('destination_id')->all());

        $this->actingAs($this->user)
            ->put(route('settings.alerts.routes.update', $route), [
                'label' => 'All events to new',
                'event_filter' => ['types' => ['all']],
                'cooldown_seconds' => 45,
                'steps' => [
                    ['destination_id' => $new->id, 'resolve_within_seconds' => 900],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.routes.show', $route));

        $route->refresh()->load('steps');
        $this->assertSame('All events to new', $route->label);
        $this->assertTrue($route->enabled, 'updating must not implicitly disable an enabled route');
        $this->assertSame(['types' => 'all'], $route->event_filter);
        $this->assertSame(45, $route->cooldown_seconds);
        $this->assertSame(1, $route->steps->count());
        $this->assertSame($new->id, $route->steps->first()->destination_id);
        $this->assertSame(900, $route->steps->first()->resolve_within_seconds);

        $this->assertDatabaseHas('signal_config_log', [
            'user_id' => $this->user->id,
            'action' => 'updated',
            'subject_type' => SignalRoute::class,
            'subject_id' => $route->id,
        ]);
    }

    public function test_toggle_route_records_enabled_and_disabled_actions(): void
    {
        $route = SignalRoute::create([
            'label' => 'Ops',
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => false,
            'cooldown_seconds' => 300,
        ]);

        $this->actingAs($this->user)
            ->from(route('settings.alerts.index'))
            ->post(route('settings.alerts.routes.toggle', $route))
            ->assertRedirect(route('settings.alerts.index'));

        $this->assertTrue($route->fresh()->enabled);
        $this->assertDatabaseHas('signal_config_log', [
            'action' => 'enabled',
            'subject_type' => SignalRoute::class,
            'subject_id' => $route->id,
        ]);

        $this->actingAs($this->user)
            ->from(route('settings.alerts.index'))
            ->post(route('settings.alerts.routes.toggle', $route))
            ->assertRedirect(route('settings.alerts.index'));

        $this->assertFalse($route->fresh()->enabled);
        $this->assertDatabaseHas('signal_config_log', [
            'action' => 'disabled',
            'subject_type' => SignalRoute::class,
            'subject_id' => $route->id,
        ]);
    }

    private function destination(string $label, string $type = 'webhook'): SignalDestination
    {
        return SignalDestination::create([
            'label' => $label,
            'type' => $type,
            'address' => $type === 'mcp' ? null : 'https://93.184.216.34/hooks/'.strtolower(str_replace(' ', '-', $label)),
            'mcp_token_label' => $type === 'mcp' ? 'chet' : null,
        ]);
    }
}
