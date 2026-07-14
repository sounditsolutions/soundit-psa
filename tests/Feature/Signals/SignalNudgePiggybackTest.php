<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use App\Services\Signals\SignalRelayMatrix;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end: the payload piggyback rides the NORMAL response of a PSA tool call
 * (psa-0j6i, D1). Verified through the real staff MCP endpoint.
 */
class SignalNudgePiggybackTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = McpConfig::rotateStaffToken(['poll_signals'], 'Chet');
        $matrix = app(SignalRelayMatrix::class);
        $matrix->setRelay('Chet', 'ticket.created', true);
        $matrix->setNudge('Chet', 'ticket.created', true);
    }

    private function seedUnackedNudgeAlert(): void
    {
        $destinationId = (int) SignalDestination::where('mcp_token_label', 'Chet')->firstOrFail()->id;
        $routeId = (int) SignalRoute::where('managed_token_label', 'Chet')->firstOrFail()->id;
        $event = SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => 'ticket',
            'entity_id' => 1,
            'summary' => 'evt',
            'context' => [],
            'occurred_at' => now(),
        ]);
        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $routeId,
            'step_order' => 1,
            'destination_id' => $destinationId,
            'status' => 'delivered',
        ]);
        SignalInboxEntry::create([
            'destination_id' => $destinationId,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => ['event' => 'ticket.created'],
        ]);
    }

    private function mcpCall(string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    public function test_a_normal_tool_call_piggybacks_the_unread_alert_notice(): void
    {
        $this->seedUnackedNudgeAlert();

        $response = $this->mcpCall('whoami');

        $response->assertOk();
        $content = $response->json('result.content');
        $this->assertCount(2, $content); // tool result + piggybacked notice
        $this->assertStringContainsString('poll_signals', $content[1]['text']);
        $this->assertStringContainsString('unread alert', $content[1]['text']);
    }

    public function test_no_piggyback_when_there_are_no_unread_nudge_alerts(): void
    {
        // No inbox entry seeded — nothing nudge-worthy.
        $response = $this->mcpCall('whoami');

        $response->assertOk();
        $this->assertCount(1, $response->json('result.content')); // only the tool result
    }

    public function test_poll_signals_itself_does_not_piggyback(): void
    {
        $this->seedUnackedNudgeAlert();

        $response = $this->mcpCall('poll_signals');

        $response->assertOk();
        // The drain call must not carry the awareness notice — it IS the drain.
        $this->assertCount(1, $response->json('result.content'));
    }
}
