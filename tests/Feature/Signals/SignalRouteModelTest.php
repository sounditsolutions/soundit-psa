<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDestination;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalRouteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_casts_filter_and_orders_steps_by_step_order(): void
    {
        $firstDestination = SignalDestination::create([
            'label' => 'Chet',
            'type' => 'mcp',
            'mcp_token_label' => 'chet-live',
        ]);
        $secondDestination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://x.example/hook',
        ]);

        $filter = [
            'types' => ['ticket.created', 'agent.flag_attention'],
            'categories' => ['needs_decision'],
            'min_priority' => 2,
            'client_ids' => [10, 20],
        ];

        $route = SignalRoute::create([
            'label' => 'Chet then ops',
            'event_filter' => $filter,
            'enabled' => true,
            'cooldown_seconds' => 600,
        ]);

        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 2,
            'destination_id' => $secondDestination->id,
            'resolve_within_seconds' => 3600,
            'non_suppressible' => true,
        ]);
        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $firstDestination->id,
            'wait_for_ack_seconds' => 600,
        ]);

        $fresh = $route->fresh();

        $this->assertTrue($fresh->enabled);
        $this->assertSame(600, $fresh->cooldown_seconds);
        $this->assertSame($filter, $fresh->event_filter);
        $this->assertSame([1, 2], $fresh->steps->pluck('step_order')->all());
        $this->assertSame(
            [$firstDestination->id, $secondDestination->id],
            $fresh->steps->pluck('destination_id')->all(),
        );
        $this->assertTrue($fresh->steps->last()->non_suppressible);
    }
}
