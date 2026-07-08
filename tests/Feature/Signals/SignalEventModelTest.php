<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_delivery_and_inbox_models_round_trip_indexed_fields(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Chet',
            'type' => 'mcp',
            'mcp_token_label' => 'chet-live',
        ]);
        $route = SignalRoute::create([
            'label' => 'Chet wake',
            'event_filter' => ['types' => ['agent.flag_attention']],
        ]);

        $event = SignalEvent::create([
            'type_key' => 'agent.flag_attention',
            'entity_type' => 'ticket',
            'entity_id' => 123,
            'summary' => 'Need a decision',
            'context' => ['category' => 'needs_decision', 'priority' => 2],
            'occurred_at' => now()->subMinute(),
        ]);
        $childEvent = SignalEvent::create([
            'type_key' => 'signal.delivery_failed',
            'entity_type' => SignalDestination::class,
            'entity_id' => $destination->id,
            'summary' => 'Delivery failed',
            'context' => ['destination_id' => $destination->id],
            'origin_event_id' => $event->id,
            'occurred_at' => now(),
        ]);

        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
            'status' => 'pending',
        ]);

        $inbox = SignalInboxEntry::create([
            'destination_id' => $destination->id,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => [
                'event' => 'agent.flag_attention',
                'entity' => ['type' => 'ticket', 'id' => 123],
                'category' => 'needs_decision',
            ],
        ]);

        $this->assertSame([$event->id], SignalEvent::where('type_key', 'agent.flag_attention')->pluck('id')->all());
        $this->assertSame(['category' => 'needs_decision', 'priority' => 2], $event->fresh()->context);
        $this->assertSame($event->id, $childEvent->fresh()->originEvent->id);
        $this->assertSame([$delivery->id], SignalDelivery::where('destination_id', $destination->id)->where('status', 'pending')->pluck('id')->all());
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame($event->id, $delivery->fresh()->event->id);
        $this->assertSame($delivery->id, $inbox->fresh()->delivery->id);
        $this->assertSame('agent.flag_attention', $inbox->fresh()->payload['event']);
    }
}
