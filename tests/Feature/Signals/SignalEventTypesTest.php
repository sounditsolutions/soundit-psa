<?php

namespace Tests\Feature\Signals;

use App\Services\Signals\SignalEventTypes;
use Tests\TestCase;

class SignalEventTypesTest extends TestCase
{
    public function test_registry_contains_exact_v1_catalog_with_core_and_routable_flags(): void
    {
        $types = SignalEventTypes::all();

        $this->assertSame([
            'ticket.created',
            'ticket.client_replied',
            'ticket.sla_breached',
            'ticket.sla_approaching',
            'intake.email_received',
            'intake.call_received',
            'intake.email_unresolved',
            'intake.call_transcribed',
            'agent.flag_attention',
            'operator.message',
            'agent.proposal_held',
            'agent.proposal_auto_closed',
            'agent.run_failed',
            'integration.sync_failed',
            'tactical.alert_created',
            'signal.delivery_failed',
            'mesh.allow_rule_unresolved',
            'digest.daily',
            'asset.watch_fired',
            'system.test',
        ], array_keys($types));

        $this->assertTrue(SignalEventTypes::has('agent.flag_attention'));
        $this->assertFalse(SignalEventTypes::has('agent.nope'));
        $this->assertTrue(SignalEventTypes::routable('ticket.created'));
        $this->assertFalse(SignalEventTypes::routable('system.test'));
        // asset.watch_fired (card K3VEcxtw) is delivered to its watch owner only,
        // never through a route, so it is not routable.
        $this->assertFalse(SignalEventTypes::routable('asset.watch_fired'));
        $this->assertSame(['asset.watch_fired', 'system.test'], array_keys(array_filter($types, fn (array $type) => ! $type['routable'])));

        $this->assertSame([
            'ticket.created',
            'ticket.client_replied',
            'agent.flag_attention',
            'signal.delivery_failed',
            'system.test',
        ], array_keys(array_filter($types, fn (array $type) => $type['core'])));

        foreach ($types as $key => $definition) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $key);
            $this->assertNotSame('', $definition['label']);
        }
    }
}
