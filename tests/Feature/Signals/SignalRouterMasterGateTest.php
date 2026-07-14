<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalEventTypeSetting;
use App\Models\SignalRoute;
use App\Services\Signals\SignalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalRouterMasterGateTest extends TestCase
{
    use RefreshDatabase;

    private function enabledWebhookRouteForTicketCreated(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Hook',
            'type' => 'webhook',
            'address' => 'https://example.test/hook',
            'enabled' => true,
        ]);
        $route = SignalRoute::create([
            'label' => 'Ticket created route',
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => true,
            'cooldown_seconds' => 0,
        ]);
        $route->steps()->create(['step_order' => 1, 'destination_id' => $destination->id]);
    }

    private function emitTicketCreated(): SignalEvent
    {
        return SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => 'ticket',
            'entity_id' => 1,
            'summary' => 'Ticket #1 created',
            'context' => [],
            'occurred_at' => now(),
        ]);
    }

    /** Baseline: with the type globally enabled (default), a matching route delivers. */
    public function test_a_matching_route_delivers_when_the_type_is_globally_enabled(): void
    {
        $this->enabledWebhookRouteForTicketCreated();

        app(SignalRouter::class)->route($this->emitTicketCreated());

        $this->assertGreaterThanOrEqual(1, SignalDelivery::count());
    }

    /**
     * The D4 master gate: a globally-disabled type does not deliver through ANY route, even
     * one explicitly enabled and matching. Per-cell/route config is untouched (non-lossy) —
     * re-enabling restores delivery.
     */
    public function test_a_globally_disabled_type_is_not_routed_at_all(): void
    {
        $this->enabledWebhookRouteForTicketCreated();
        SignalEventTypeSetting::setGlobalEnabled('ticket.created', false);

        app(SignalRouter::class)->route($this->emitTicketCreated());

        $this->assertSame(0, SignalDelivery::count());

        // Non-lossy: the route still carries the type, so re-enabling restores delivery.
        SignalEventTypeSetting::setGlobalEnabled('ticket.created', true);
        app(SignalRouter::class)->route($this->emitTicketCreated());
        $this->assertGreaterThanOrEqual(1, SignalDelivery::count());
    }

    /** wouldReachMcpDestination also honors the master gate — a disabled type reaches nobody. */
    public function test_would_reach_mcp_destination_respects_the_master_gate(): void
    {
        \App\Support\McpConfig::rotateStaffToken(['poll_signals'], 'Chet');
        app(\App\Services\Signals\SignalRelayMatrix::class)->setRelay('Chet', 'ticket.created', true);

        $router = app(SignalRouter::class);
        $this->assertTrue($router->wouldReachMcpDestination('ticket.created'));

        SignalEventTypeSetting::setGlobalEnabled('ticket.created', false);
        $this->assertFalse($router->wouldReachMcpDestination('ticket.created'));
    }
}
