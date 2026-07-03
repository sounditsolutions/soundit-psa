<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpStaffProposeCloseTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'psa-mcp-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(); // service-account actor fallback
        Setting::setEncrypted('mcp_staff_token', self::TOKEN);
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function toolText(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    public function test_mcp_propose_close_records_held_proposal_even_when_auto_threshold_would_close(): void
    {
        Setting::setValue('propose_close_auto_threshold', '0.95');

        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);
        $reason = 'No client response in 45 days; prior technician note says the printer is working.';

        $response = $this->callTool(self::TOKEN, 'propose_close', [
            'ticket_id' => $ticket->id,
            'reason' => $reason,
            'confidence' => 0.99,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));
        $this->assertStringContainsString('held for approval', $this->toolText($response));

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($ticket->client_id, $run->client_id);
        $this->assertSame($reason, $run->proposed_content);
        $this->assertEqualsWithDelta(0.99, $run->confidence, 0.001);

        $this->assertSame(TicketStatus::PendingClient, $ticket->refresh()->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'result_status' => 'awaiting_approval',
            'tier' => 'approve',
        ]);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'result_status' => 'executed',
        ]);

        $audit = McpAuditLog::where('method', 'tools/call')
            ->where('tool_name', 'propose_close')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('success', $audit->status);
        $this->assertSame('mcp-legacy', $audit->actor_label);
        $this->assertSame($ticket->id, $audit->arguments['ticket_id']);
        $this->assertSame($reason, $audit->arguments['reason']);
        $this->assertSame(0.99, $audit->arguments['confidence']);
    }

    public function test_mcp_propose_close_derives_client_from_ticket_not_caller_supplied_client_id(): void
    {
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::PendingThirdParty,
        ]);

        $response = $this->callTool(self::TOKEN, 'propose_close', [
            'ticket_id' => $ticket->id,
            'client_id' => $otherClient->id,
            'reason' => 'Vendor has been quiet for a month; human should decide whether to close.',
            'confidence' => 0.97,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame($client->id, $run->client_id);
        $this->assertNotSame($otherClient->id, $run->client_id);
        $this->assertSame(TicketStatus::PendingThirdParty, $ticket->refresh()->status);
    }

    public function test_scoped_token_lists_and_calls_only_allowed_tools(): void
    {
        $token = McpConfig::rotateStaffToken(
            allowedTools: ['get_ticket_detail', 'propose_close'],
            label: 'office-city',
        );

        $list = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);

        $list->assertOk();
        $names = collect($list->json('result.tools'))->pluck('name')->all();
        $this->assertContains('get_ticket_detail', $names);
        $this->assertContains('propose_close', $names);
        $this->assertNotContains('create_ticket', $names);

        $client = Client::factory()->create();
        $before = Ticket::count();

        $denied = $this->callTool($token, 'create_ticket', [
            'client_id' => $client->id,
            'subject' => 'Should not be created',
            'description' => 'Scoped token must not have this mutator.',
        ]);

        $denied->assertOk();
        $this->assertTrue((bool) $denied->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', $this->toolText($denied));
        $this->assertSame($before, Ticket::count());

        $sendReplyDenied = $this->callTool($token, 'send_reply', [
            'ticket_id' => 123,
            'body' => 'This must stay out of the Office token scope.',
        ]);
        $sendReplyDenied->assertOk();
        $this->assertTrue((bool) $sendReplyDenied->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', $this->toolText($sendReplyDenied));

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'create_ticket',
            'status' => 'error',
            'actor_label' => 'mcp-staff:office-city',
        ]);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'send_reply',
            'status' => 'error',
            'actor_label' => 'mcp-staff:office-city',
        ]);
    }

    public function test_scoped_token_can_submit_held_propose_close(): void
    {
        $token = McpConfig::rotateStaffToken(
            allowedTools: ['get_ticket_detail', 'propose_close'],
            label: 'office-city',
        );
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);

        $response = $this->callTool($token, 'propose_close', [
            'ticket_id' => $ticket->id,
            'reason' => 'Client has not replied after repeated follow-ups.',
            'confidence' => 0.96,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));

        $this->assertDatabaseHas('technician_runs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'state' => TechnicianRunState::AwaitingApproval->value,
        ]);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'propose_close',
            'status' => 'success',
            'actor_label' => 'mcp-staff:office-city',
        ]);
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)
            ->where('result_status', 'executed')
            ->count());
    }
}
