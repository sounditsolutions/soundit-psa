<?php

namespace Tests\Feature\Signals;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollSignalsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_signals_is_listed_as_sensitive_bridge_tool(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'chet');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);

        $response->assertOk();
        $listed = array_column($response->json('result.tools'), 'name');
        $this->assertContains('poll_signals', $listed);

        $bridgeNames = array_column(McpToolRegistry::groups()['bridge']['tools'], 'name');
        $this->assertContains('poll_signals', $bridgeNames);
        $this->assertTrue(McpToolRegistry::groups()['bridge']['sensitive']);
    }

    public function test_poll_returns_only_unacked_rows_for_the_calling_token(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'chet');
        McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'other');

        $own = $this->seedSignal('chet', entityId: 1001);
        $this->seedSignal('other', entityId: 2002);

        $out = $this->poll($token, ['limit' => 50]);

        $this->assertCount(1, $out['signals']);
        $this->assertSame($own->id, $out['signals'][0]['inbox_id']);
        $this->assertSame('ticket.created', $out['signals'][0]['event']);
        $this->assertSame(['type' => 'ticket', 'id' => 1001], $out['signals'][0]['entity']);
        $this->assertSame('security', $out['signals'][0]['category']);
        $this->assertArrayNotHasKey('summary', $out['signals'][0]);
        $this->assertSame($own->id, $out['cursor']);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'poll_signals',
            'status' => 'success',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_cursor_ack_updates_inbox_and_delivery_for_this_token_only(): void
    {
        $tokenA = McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'chet');
        $tokenB = McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'other');
        $own = $this->seedSignal('chet', entityId: 1001);
        $other = $this->seedSignal('other', entityId: 2002);

        $this->poll($tokenB, ['cursor' => $own->id]);

        $this->assertNull($own->fresh()->acked_at);
        $this->assertSame('delivered', $own->delivery->fresh()->status);
        $this->assertNull($own->delivery->fresh()->acked_at);
        $this->assertNull($other->fresh()->acked_at);

        $first = $this->poll($tokenA);
        $second = $this->poll($tokenA, ['cursor' => $first['cursor']]);

        $this->assertSame([], $second['signals']);
        $this->assertNotNull($own->fresh()->acked_at);
        $this->assertSame('acked', $own->delivery->fresh()->status);
        $this->assertNotNull($own->delivery->fresh()->acked_at);
        $this->assertNull($other->fresh()->acked_at);
    }

    public function test_poll_signals_requires_scoped_token_label(): void
    {
        $out = app(OperatorBridgeToolExecutor::class)->execute('poll_signals', [], null);

        $this->assertSame(['error' => 'poll_signals requires a scoped token'], $out);
    }

    public function test_token_without_poll_scope_is_denied_and_audited(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'poll_signals', 'arguments' => []],
            ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'poll_signals',
            'status' => 'error',
        ]);
    }

    private function poll(string $token, array $args = []): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'poll_signals', 'arguments' => $args],
            ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function seedSignal(string $tokenLabel, int $entityId): SignalInboxEntry
    {
        $destination = SignalDestination::create([
            'label' => "{$tokenLabel} inbox",
            'type' => 'mcp',
            'mcp_token_label' => $tokenLabel,
        ]);
        $event = SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => 'ticket',
            'entity_id' => $entityId,
            'summary' => 'This summary must not be returned to MCP',
            'context' => ['category' => 'security'],
            'occurred_at' => now()->startOfSecond(),
        ]);
        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => null,
            'step_order' => 0,
            'destination_id' => $destination->id,
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        return SignalInboxEntry::create([
            'destination_id' => $destination->id,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => [
                'event' => 'ticket.created',
                'entity' => ['type' => 'ticket', 'id' => $entityId],
                'category' => 'security',
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ],
        ]);
    }
}
