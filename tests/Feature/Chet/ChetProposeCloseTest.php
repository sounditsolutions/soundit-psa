<?php

namespace Tests\Feature\Chet;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Spike-2 of the Chet re-homing: a chet-labeled scoped token may hold
 * propose_close. Held-by-construction: the MCP path lands in
 * ProposeCloseTool::executeHeld (forceHeld), so a proposal only ever waits
 * in the cockpit — nothing closes without a human tap. Other write names
 * stay unavailable unless they are real published staff-MCP tools.
 */
class ChetProposeCloseTest extends TestCase
{
    use RefreshDatabase;

    private function chetToken(array $tools): string
    {
        return McpConfig::rotateStaffToken(
            allowedTools: $tools,
            label: 'chet',
            aiActor: true,
            requireExplicitClientScope: true,
        );
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

    /** @return array<int, string> */
    private function listToolNames(string $token): array
    {
        return collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools'))->pluck('name')->all();
    }

    public function test_chet_token_with_propose_close_scope_submits_a_held_proposal(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::New,
        ]);
        $token = $this->chetToken(['propose_close']);

        $this->assertContains('propose_close', $this->listToolNames($token));

        $response = $this->callTool($token, 'propose_close', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Resolved on 2026-06-28; client confirmed the fix in their last reply.',
            'confidence' => 0.95,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // Held means held: the ticket itself is untouched.
        $this->assertSame(TicketStatus::New, $ticket->fresh()->status);
    }

    public function test_chet_propose_close_requires_client_id(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $token = $this->chetToken(['propose_close']);

        $response = $this->callTool($token, 'propose_close', [
            'ticket_id' => $ticket->id,
            'reason' => 'Looks resolved.',
            'confidence' => 0.95,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    public function test_chet_propose_close_rejects_a_ticket_from_another_client(): void
    {
        $requestingClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $otherTicket = Ticket::factory()->create(['client_id' => $otherClient->id]);
        $token = $this->chetToken(['propose_close']);

        $response = $this->callTool($token, 'propose_close', [
            'client_id' => $requestingClient->id,
            'ticket_id' => $otherTicket->id,
            'reason' => 'Looks resolved.',
            'confidence' => 0.95,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $otherTicket->id)->count());
    }

    public function test_non_chet_token_with_explicit_scope_requires_client_id_and_ticket_membership(): void
    {
        $requestingClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $otherTicket = Ticket::factory()->create(['client_id' => $otherClient->id]);
        $token = McpConfig::rotateStaffToken(
            allowedTools: ['propose_close'],
            label: 'office-bot',
            requireExplicitClientScope: true,
        );

        $missing = $this->callTool($token, 'propose_close', [
            'ticket_id' => $otherTicket->id,
            'reason' => 'Missing client scope.',
            'confidence' => 0.95,
        ]);
        $missing->assertOk();
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $missing->json('result.content.0.text'));

        $crossClient = $this->callTool($token, 'propose_close', [
            'client_id' => $requestingClient->id,
            'ticket_id' => $otherTicket->id,
            'reason' => 'Cross-client write.',
            'confidence' => 0.95,
        ]);
        $crossClient->assertOk();
        $this->assertTrue((bool) $crossClient->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $crossClient->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $otherTicket->id)->count());
    }

    public function test_non_chet_token_without_explicit_scope_keeps_derived_ticket_scope(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);
        $otherClient = Client::factory()->create();
        $token = McpConfig::rotateStaffToken(
            allowedTools: ['propose_close'],
            label: 'staff-trust',
            requireExplicitClientScope: false,
        );

        $response = $this->callTool($token, 'propose_close', [
            'client_id' => $otherClient->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Staff-trust token derives client from the ticket.',
            'confidence' => 0.95,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_runs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'state' => TechnicianRunState::AwaitingApproval->value,
        ]);
    }

    public function test_chet_token_without_propose_close_scope_is_denied(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $token = $this->chetToken(['add_ticket_note']);

        $response = $this->callTool($token, 'propose_close', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Looks resolved.',
            'confidence' => 0.95,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::query()->count());
    }

    public function test_unpublished_chet_write_tools_remain_unavailable_even_when_scoped(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->create(['client_id' => $client->id]);

        $denied = ['close_ticket', 'tactical_run_diagnostic'];
        $token = $this->chetToken($denied);

        foreach ($denied as $tool) {
            $response = $this->callTool($token, $tool, ['client_id' => $client->id]);

            $response->assertOk();
            $this->assertTrue(
                (bool) $response->json('result.isError'),
                "{$tool} must stay denied for chet tokens even when scoped."
            );
            $this->assertStringContainsString('Tool not allowed', (string) $response->json('result.content.0.text'));
        }
    }
}
