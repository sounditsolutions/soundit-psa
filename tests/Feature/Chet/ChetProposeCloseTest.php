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
 * in the cockpit — nothing closes without a human tap. Non-held Chet
 * write denial stays hard-coded server-side regardless of token scope.
 */
class ChetProposeCloseTest extends TestCase
{
    use RefreshDatabase;

    private function chetToken(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
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

    public function test_other_chet_write_denials_hold_even_when_scoped(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->create(['client_id' => $client->id]);

        $denied = ['create_ticket', 'close_ticket', 'tactical_run_diagnostic'];
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
